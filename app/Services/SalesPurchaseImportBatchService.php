<?php

namespace App\Services;

use App\BusinessLocation;
use App\Product;
use App\Transaction;
use App\UserImportBatch;
use App\Utils\TransactionUtil;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

class SalesPurchaseImportBatchService
{
    private const CONTACT_REFERENCE_COLUMNS = [
        ['bookings', 'contact_id'],
        ['crm_proposals', 'contact_id'],
        ['crm_lead_users', 'contact_id'],
        ['crm_schedules', 'contact_id'],
        ['surat_kontrak', 'contact_id'],
        ['projects', 'contact_id'],
        ['journal_entries', 'contact_id'],
        ['user_contact_access', 'contact_id'],
        ['users', 'crm_contact_id'],
        ['heavy_equipment_trips', 'customer_id'],
        ['heavy_equipment_vendor_loading_documents', 'customer_id'],
        ['transactions', 'contact_id'],
    ];

    public function __construct(private UserImportBatchService $batches)
    {
    }

    public function importerKey(string $kind): string
    {
        abort_unless(in_array($kind, ['sales', 'purchase'], true), 404);

        return 'sales_'.$kind;
    }

    public function history(Request $request, int $businessId, string $kind): array
    {
        $table = $this->batches->dataTable($request, $businessId, $this->importerKey($kind));
        $prefix = $kind === 'purchase' ? 'purchase.import.batch' : 'sales.import.batch';
        $table['data'] = collect($table['data'])->map(function (array $row) use ($prefix) {
            $row['detail_url'] = route($prefix.'.detail', $row['id']);
            $row['preview_url'] = route($prefix.'.preview', $row['id']);
            $row['source_url'] = $row['has_snapshot'] ? route($prefix.'.source', $row['id']) : null;
            $row['retry_url'] = route($prefix.'.retry', $row['id']);
            $row['revert_url'] = route($prefix.'.revert', $row['id']);
            $row['can_retry'] = $row['status'] === 'failed' && $row['has_snapshot'];
            $row['can_revert'] = $row['status'] === 'imported';
            $row['active'] = in_array($row['status'], ['queued_preview', 'analyzing', 'queued_import', 'importing'], true);

            return $row;
        })->values()->all();

        return $table;
    }

    public function queuePreview(Request $request, string $kind)
    {
        $request->validate(['sales' => 'required|file|mimes:csv,xls,xlsx|max:51200']);
        $businessId = (int) $request->session()->get('user.business_id');
        $file = $request->file('sales');
        $stagingPath = $file->storeAs('user-imports/staging', (string) \Illuminate\Support\Str::uuid().'.'.$file->getClientOriginalExtension(), 'local');
        $batch = $this->batches->create([
            'business_id' => $businessId,
            'created_by' => (int) $request->session()->get('user.id'),
            'importer_key' => $this->importerKey($kind),
            'source_key' => (string) \Illuminate\Support\Str::uuid(),
            'file_name' => $file->getClientOriginalName(),
            'staging_path' => $stagingPath,
            'status' => 'queued_preview',
            'stage' => 'queued_preview',
            'options_json' => json_encode([
                'operation' => 'preview',
                'csrf_token' => (string) $request->session()->token(),
                'session_context' => ['business' => array_intersect_key((array) $request->session()->get('business', []), array_flip(['date_format', 'currency_id', 'currency_symbol_placement', 'currency_precision', 'quantity_precision']))],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            'last_message' => 'Workbook menunggu analisis worker.',
        ]);
        \App\Jobs\ProcessSalesPurchaseImportBatch::dispatch($batch->id, $businessId, $kind, 'preview');

        return response()->json([
            'success' => true, 'queued' => true, 'batch_id' => (int) $batch->id,
            'status_url' => route(($kind === 'purchase' ? 'purchase.import.batch' : 'sales.import.batch').'.detail', $batch->id),
            'preview_url' => route(($kind === 'purchase' ? 'purchase.import.batch' : 'sales.import.batch').'.preview', $batch->id),
            'msg' => 'Upload diterima dan workbook sedang dianalisis.',
        ], 202);
    }

    public function queueImport(Request $request, int $batchId, string $kind)
    {
        $businessId = (int) $request->session()->get('user.business_id');
        $batch = $this->batches->findForBusiness($batchId, $businessId, $this->importerKey($kind));
        abort_unless($batch->status === 'preview_ready', 422, 'Preview batch belum siap atau sudah dikonfirmasi.');
        $fields = $request->input('import_fields', []);
        abort_unless(is_array($fields) && $fields !== [], 422, 'Pemetaan kolom import tidak tersedia.');
        $allowedFields = ['invoice_no', 'customer_name', 'customer_phone_number', 'customer_email', 'date', 'product', 'sku', 'quantity', 'unit', 'unit_price', 'item_tax', 'item_discount', 'item_description', 'order_total', 'paid_on', 'method', 'account_id', 'note', 'tags', 'lampiran', 'types_of_service', 'service_custom_field1', 'service_custom_field2', 'service_custom_field3', 'service_custom_field4'];
        foreach ($fields as $field) {
            abort_unless(is_string($field) && in_array($field, $allowedFields, true), 422, 'Pemetaan field import tidak valid.');
        }
        abort_if(count($fields) !== count(array_unique($fields)), 422, 'Satu field tidak dapat dipetakan dua kali.');
        abort_unless(in_array('customer_phone_number', $fields, true) || in_array('customer_email', $fields, true), 422, 'Pemetaan nomor telepon atau email pelanggan wajib tersedia.');
        abort_unless(in_array('product', $fields, true) || in_array('sku', $fields, true), 422, 'Pemetaan nama produk atau SKU wajib tersedia.');
        abort_unless(in_array('quantity', $fields, true) && in_array('unit_price', $fields, true), 422, 'Pemetaan quantity dan unit price wajib tersedia.');
        abort_unless(is_numeric($request->input('group_by')), 422, 'Field pengelompokan transaksi wajib dipilih.');
        $locationId = (int) $request->input('location_id');
        abort_unless(BusinessLocation::where('business_id', $businessId)->whereKey($locationId)->exists(), 422, 'Lokasi tidak valid untuk bisnis aktif.');
        $permitted = auth()->user()->permitted_locations();
        abort_unless($permitted === 'all' || in_array($locationId, $permitted, true) || in_array((string) $locationId, $permitted, true), 403, 'Lokasi tidak diizinkan.');

        $options = json_decode((string) $batch->options_json, true) ?: [];
        $options['operation'] = 'import';
        $options['import_fields'] = $fields;
        $options['group_by'] = $request->input('group_by');
        $options['location_id'] = $locationId;
        $updated = UserImportBatch::where('business_id', $businessId)->where('importer_key', $this->importerKey($kind))
            ->whereKey($batchId)->where('status', 'preview_ready')->update([
                'status' => 'queued_import', 'stage' => 'queued_import', 'options_json' => json_encode($options, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                'progress_current' => 0, 'progress_total' => 0, 'progress_percent' => 0, 'error_message' => null,
                'last_message' => 'Konfirmasi diterima; transaksi menunggu worker.', 'updated_at' => now(),
            ]);
        abort_unless($updated, 422, 'Konfirmasi batch hanya dapat diproses satu kali.');
        \App\Jobs\ProcessSalesPurchaseImportBatch::dispatch($batchId, $businessId, $kind, 'import');

        return response()->json([
            'success' => true,
            'status_url' => route(($kind === 'purchase' ? 'purchase.import.batch' : 'sales.import.batch').'.detail', $batchId),
            'history_url' => $kind === 'purchase' ? url('/import-pembelian') : url('/import-sales'),
            'msg' => 'Konfirmasi diterima; transaksi sedang diproses.',
        ], 202);
    }

    public function previewPageData(int $batchId, int $businessId, string $kind): array
    {
        $batch = $this->batches->findForBusiness($batchId, $businessId, $this->importerKey($kind));
        abort_unless(in_array($batch->status, ['preview_ready', 'queued_import', 'importing', 'imported'], true), 422, 'Preview workbook belum tersedia.');
        $data = $this->batches->readPayload($batch, 'preview');
        $data['batch_id'] = (int) $batch->id;

        return $data;
    }

    public function detail(int $batchId, int $businessId, string $kind): array
    {
        $batch = $this->batches->findForBusiness($batchId, $businessId, $this->importerKey($kind));
        $result = $batch->results_path ? $this->batches->readPayload($batch, 'results') : [];
        $progress = $batch->status === 'importing' ? Cache::get($this->progressKey($batchId)) : null;

        return [
            'batch' => [
                'id' => (int) $batch->id, 'file_name' => $batch->file_name, 'status' => $batch->status,
                'progress_percent' => (int) ($progress['progress_percent'] ?? $batch->progress_percent),
                'progress_current' => (int) ($progress['progress_current'] ?? $batch->progress_current),
                'progress_total' => (int) ($progress['progress_total'] ?? $batch->progress_total),
                'last_message' => $progress['last_message'] ?? $batch->last_message,
                'error_message' => $batch->error_message, 'created_at' => optional($batch->created_at)->toDateTimeString(),
            ],
            'summary' => $result['summary'] ?? [],
            'rows' => $result['row_outcomes'] ?? [],
            'records' => $result['records'] ?? [],
        ];
    }

    public function completePreview(UserImportBatch $batch, array $viewData): void
    {
        $viewData = array_intersect_key($viewData, array_flip(['parsed_array', 'import_fields', 'file_name', 'business_locations', 'match_array']));
        $viewData['link'] = $batch->importer_key === $this->importerKey('purchase') ? 'import-pembelian' : 'import-sales';
        $viewData['judul'] = $batch->importer_key === $this->importerKey('purchase') ? 'Preview Import Purchases' : 'Preview Imported Sales';
        $viewData['file_name'] = $batch->file_name;
        $viewData['batch_id'] = (int) $batch->id;
        $this->batches->storePayload($batch, 'preview', $viewData);
        $totalRows = max(0, count($viewData['parsed_array'] ?? []) - 1);
        $batch->forceFill([
            'status' => 'preview_ready', 'stage' => 'preview_ready', 'progress_current' => $totalRows,
            'progress_total' => $totalRows, 'progress_percent' => 100,
            'summary_json' => json_encode(['source_rows' => $totalRows], JSON_THROW_ON_ERROR),
            'last_message' => 'Preview siap; petakan kolom lalu konfirmasi import.', 'error_message' => null,
            'heartbeat_at' => null, 'completed_at' => now(), 'failed_at' => null,
        ])->save();
        Cache::forget($this->progressKey((int) $batch->id));
    }

    public function prepareImport(UserImportBatch $batch, string $path): array
    {
        $options = json_decode((string) $batch->options_json, true) ?: [];
        $sheet = \Excel::toArray([], $path)[0] ?? [];
        $sourceRows = max(0, count($sheet) - 1);
        $options['source_row_count'] = $sourceRows;
        $batch->options_json = json_encode($options, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $batch->save();

        return [
            'source_rows' => $sourceRows,
            'options' => $options,
        ];
    }

    public function completeImport(UserImportBatch $batch, array $before, array $tracked = []): array
    {
        $kind = str_ends_with($batch->importer_key, '_purchase') ? 'purchase' : 'sales';
        $type = $kind === 'purchase' ? 'purchase' : 'sell';
        $trackedTransactionIds = array_values(array_unique(array_map('intval', $tracked['transaction_ids'] ?? [])));
        $transactions = $trackedTransactionIds === [] ? [] : $this->keyed(DB::table('transactions')
            ->where('business_id', $batch->business_id)->where('type', $type)->whereIn('id', $trackedTransactionIds)->orderBy('id')->get());
        abort_unless(count($transactions) === count($trackedTransactionIds), 422, 'Worker tidak dapat memverifikasi seluruh transaksi hasil import.');
        $transactionIds = array_map('intval', array_keys($transactions));
        $lineTable = $kind === 'purchase' ? 'purchase_lines' : 'transaction_sell_lines';
        $lines = Schema::hasTable($lineTable)
            ? $this->keyed(DB::table($lineTable)->whereIn('transaction_id', $transactionIds)->orderBy('id')->get()) : [];
        $payments = Schema::hasTable('transaction_payments')
            ? $this->keyed(DB::table('transaction_payments')->whereIn('transaction_id', $transactionIds)->orderBy('id')->get()) : [];
        $accountTransactions = Schema::hasTable('account_transactions')
            ? $this->keyed(DB::table('account_transactions')->whereIn('transaction_id', $transactionIds)->orderBy('id')->get()) : [];
        $variationIds = collect($lines)->pluck('variation_id')->filter()->unique()->values()->all();
        $locationId = (int) (($before['options']['location_id'] ?? 0));
        $stock = $this->stockSnapshot($variationIds, $locationId);
        $records = [];
        foreach ($transactions as $id => $transaction) {
            $records[] = [
                'id' => (int) $id,
                'invoice_no' => $transaction['invoice_no'] ?? null,
                'ref_no' => $transaction['ref_no'] ?? null,
                'type' => $type,
                'url' => $kind === 'purchase' ? url('/purchases/'.$id) : url('/sells/'.$id),
            ];
        }
        $trackedContactIds = array_values(array_unique(array_map('intval', $tracked['contact_ids'] ?? [])));
        $createdContacts = Schema::hasTable('contacts') && $trackedContactIds !== []
            ? $this->keyed(DB::table('contacts')->where('business_id', $batch->business_id)->whereIn('id', $trackedContactIds)->orderBy('id')->get()) : [];
        abort_unless(count($createdContacts) === count($trackedContactIds), 422, 'Worker tidak dapat memverifikasi seluruh kontak hasil import.');
        $createdProducts = [];
        $trackedProductIds = array_values(array_unique(array_map('intval', $tracked['product_ids'] ?? [])));
        foreach (Product::where('business_id', $batch->business_id)->whereIn('id', $trackedProductIds)->orderBy('id')->get() as $product) {
            $createdProducts[(string) $product->id] = $this->productSnapshot($product);
        }
        abort_unless(count($createdProducts) === count($trackedProductIds), 422, 'Worker tidak dapat memverifikasi seluruh produk hasil import.');
        $outcomes = array_values($tracked['row_outcomes'] ?? []);
        $result = [
            'summary' => ['source_rows' => (int) $before['source_rows'], 'created_transactions' => count($transactions)],
            'records' => $records, 'row_outcomes' => $outcomes,
            'transactions' => $transactions, 'lines' => $lines, 'line_table' => $lineTable,
            'payments' => $payments, 'after_stock' => $stock,
            'account_transactions' => $accountTransactions,
            'contacts' => $createdContacts,
            'created_products' => $createdProducts,
            'kind' => $kind,
        ];
        $this->batches->storePayload($batch, 'results', $result);
        $batch->forceFill([
            'status' => 'imported', 'stage' => 'imported', 'progress_current' => $before['source_rows'],
            'progress_total' => $before['source_rows'], 'progress_percent' => 100,
            'summary_json' => json_encode($result['summary'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            'search_text' => $batch->file_name.' '.json_encode($records, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'last_message' => 'Import selesai: '.$result['summary']['created_transactions'].' transaksi dibuat.',
            'error_message' => null, 'heartbeat_at' => null, 'completed_at' => now(), 'failed_at' => null,
        ])->save();
        Cache::forget($this->progressKey((int) $batch->id));

        return $result;
    }

    public function fail(UserImportBatch $batch, \Throwable $exception): void
    {
        $options = json_decode((string) $batch->options_json, true) ?: [];
        if (($options['operation'] ?? null) === 'import' && (int) ($options['source_row_count'] ?? 0) > 0) {
            $message = mb_substr($exception->getMessage(), 0, 2000);
            $failedSourceRow = null;
            if (preg_match('/(?:row|baris)\s*(?:no\.?\s*)?(\d+)/i', $message, $matches)) {
                $failedSourceRow = (int) $matches[1];
            }
            $rowOutcomes = [];
            for ($sourceRow = 2; $sourceRow <= (int) $options['source_row_count'] + 1; $sourceRow++) {
                $isFailedRow = $failedSourceRow === $sourceRow;
                $rowOutcomes[] = [
                    'source_row' => $sourceRow,
                    'status' => $isFailedRow ? 'failed' : 'rolled_back',
                    'message' => $isFailedRow ? $message : 'Baris tidak disimpan karena seluruh transaksi batch dibatalkan.',
                ];
            }
            $this->batches->storePayload($batch, 'results', [
                'summary' => [
                    'source_rows' => count($rowOutcomes),
                    'failed_rows' => count(array_filter($rowOutcomes, fn ($row) => $row['status'] === 'failed')),
                    'rolled_back_rows' => count(array_filter($rowOutcomes, fn ($row) => $row['status'] === 'rolled_back')),
                ],
                'row_outcomes' => $rowOutcomes,
                'records' => [],
            ]);
        }
        $batch->forceFill([
            'status' => 'failed', 'stage' => 'failed', 'last_message' => mb_substr($exception->getMessage(), 0, 2000),
            'error_message' => mb_substr($exception->getMessage(), 0, 65000), 'heartbeat_at' => null,
            'completed_at' => now(), 'failed_at' => now(),
        ])->save();
        Cache::forget($this->progressKey((int) $batch->id));
    }

    public function retry(int $batchId, int $businessId, string $kind): void
    {
        $batch = $this->batches->findForBusiness($batchId, $businessId, $this->importerKey($kind));
        abort_unless($batch->hasSourceSnapshot(), 422, 'Snapshot workbook tidak tersedia untuk retry.');
        $options = json_decode((string) $batch->options_json, true) ?: [];
        $operation = $options['operation'] ?? 'preview';
        $status = $operation === 'import' ? 'queued_import' : 'queued_preview';
        $updated = UserImportBatch::where('business_id', $businessId)->where('importer_key', $this->importerKey($kind))
            ->whereKey($batchId)->where('status', 'failed')->update([
                'status' => $status, 'stage' => $status, 'progress_current' => 0, 'progress_total' => 0,
                'progress_percent' => 0, 'error_message' => null, 'failed_at' => null, 'completed_at' => null,
                'last_message' => 'Retry batch masuk antrean.', 'updated_at' => now(),
            ]);
        abort_unless($updated, 422, 'Batch tidak dapat di-retry dari status saat ini.');
        \App\Jobs\ProcessSalesPurchaseImportBatch::dispatch($batchId, $businessId, $kind, $operation);
    }

    public function revert(UserImportBatch $batch): void
    {
        abort_unless($batch->status === 'imported', 422, 'Batch tidak berada pada status imported.');
        $result = $this->batches->readPayload($batch, 'results');
        DB::transaction(function () use ($batch, $result) {
            $locked = UserImportBatch::where('business_id', $batch->business_id)->where('importer_key', $batch->importer_key)->lockForUpdate()->findOrFail($batch->id);
            abort_unless($locked->status === 'imported', 422, 'Status batch sudah berubah.');
            $ids = array_map('intval', array_keys($result['transactions'] ?? []));
            $transactions = $this->keyed(DB::table('transactions')->where('business_id', $batch->business_id)->whereIn('id', $ids)->orderBy('id')->get());
            abort_unless($transactions === ($result['transactions'] ?? []), 422, 'Transaksi berubah setelah import; revert diblokir.');
            $lineTable = (string) ($result['line_table'] ?? '');
            $lines = $lineTable !== '' && Schema::hasTable($lineTable)
                ? $this->keyed(DB::table($lineTable)->whereIn('transaction_id', $ids)->orderBy('id')->get()) : [];
            abort_unless($lines === ($result['lines'] ?? []), 422, 'Baris transaksi berubah atau telah dipakai; revert diblokir.');
            $payments = Schema::hasTable('transaction_payments')
                ? $this->keyed(DB::table('transaction_payments')->whereIn('transaction_id', $ids)->orderBy('id')->get()) : [];
            abort_unless($payments === ($result['payments'] ?? []), 422, 'Pembayaran transaksi berubah; revert diblokir.');
            $accountTransactions = Schema::hasTable('account_transactions')
                ? $this->keyed(DB::table('account_transactions')->whereIn('transaction_id', $ids)->orderBy('id')->get()) : [];
            abort_unless($accountTransactions === ($result['account_transactions'] ?? []), 422, 'Transaksi akun berubah; revert diblokir.');
            $contacts = Schema::hasTable('contacts') && ! empty($result['contacts'])
                ? $this->keyed(DB::table('contacts')->whereIn('id', array_map('intval', array_keys($result['contacts'])))->where('business_id', $batch->business_id)->orderBy('id')->get()) : [];
            abort_unless($contacts === ($result['contacts'] ?? []), 422, 'Kontak hasil import berubah; revert diblokir.');
            if ($contacts !== []) {
                $this->assertNoExternalContactReferences(array_map('intval', array_keys($contacts)), $ids);
            }
            $createdProducts = $result['created_products'] ?? [];
            $lineIds = array_map('intval', array_keys($result['lines'] ?? []));
            foreach ($createdProducts as $productId => $snapshot) {
                $productId = (int) $productId;
                $product = Product::where('business_id', $batch->business_id)->find($productId);
                abort_unless($product, 422, 'Produk hasil import tidak ditemukan; revert diblokir.');
                $variationIds = Schema::hasTable('variations')
                    ? DB::table('variations')->where('product_id', $productId)->pluck('id')->map(fn ($id) => (int) $id)->all()
                    : [];
                if ($variationIds !== [] && Schema::hasTable('inventory_products')) {
                    abort_if(DB::table('inventory_products')->whereIn('variation_id', $variationIds)->exists(), 422, 'Produk hasil import sudah digunakan pada catatan inventaris; revert diblokir.');
                }
                abort_unless($this->productSnapshot($product) === $snapshot, 422, 'Produk hasil import atau relasinya berubah; revert diblokir.');
                if ($variationIds !== [] && Schema::hasTable('mfg_recipe_ingredients')) {
                    abort_if(DB::table('mfg_recipe_ingredients')->whereIn('variation_id', $variationIds)->exists(), 422, 'Produk hasil import sudah dipakai sebagai bahan resep; revert diblokir.');
                }
                if (Schema::hasTable('transaction_sell_lines')) {
                    abort_if(DB::table('transaction_sell_lines')->where(function ($query) use ($productId, $variationIds) {
                        $query->where('product_id', $productId);
                        if ($variationIds !== []) {
                            $query->orWhereIn('variation_id', $variationIds);
                        }
                    })->whereNotIn('transaction_id', $ids)->exists(), 422, 'Produk hasil import sudah dipakai penjualan lain; revert diblokir.');
                }
                if (Schema::hasTable('purchase_lines')) {
                    abort_if(DB::table('purchase_lines')->where('product_id', $productId)->whereNotIn('id', $lineIds)->exists(), 422, 'Produk hasil import memiliki pembelian di luar batch; revert diblokir.');
                }
            }
            $variationIds = collect($lines)->pluck('variation_id')->filter()->map(fn ($id) => (int) $id)->unique()->values()->all();
            $locationId = (int) ($transactions ? reset($transactions)['location_id'] : 0);
            abort_unless($this->stockSnapshot($variationIds, $locationId) === ($result['after_stock'] ?? []), 422, 'Stok berubah setelah import; revert diblokir.');
            if (($result['kind'] ?? '') === 'purchase' && Schema::hasTable('transaction_sell_lines_purchase_lines')) {
                $purchaseLineIds = array_map('intval', array_keys($lines));
                abort_if(DB::table('transaction_sell_lines_purchase_lines')->whereIn('purchase_line_id', $purchaseLineIds)->exists(), 422, 'Pembelian sudah dipakai untuk penjualan; revert diblokir.');
            }
            if (($result['kind'] ?? '') === 'sales') {
                abort_if(DB::table('transactions')->where('business_id', $batch->business_id)->where('type', 'sell_return')->whereIn('return_parent_id', $ids)->exists(), 422, 'Penjualan memiliki retur; revert diblokir.');
            }

            $transactionUtil = app(TransactionUtil::class);
            foreach ($ids as $id) {
                if (($result['kind'] ?? '') === 'purchase') {
                    $transactionUtil->deletePurchase((int) $batch->business_id, $id);
                } else {
                    $transactionUtil->deleteSale((int) $batch->business_id, $id);
                }
            }
            if ($contacts !== []) {
                DB::table('contacts')->whereIn('id', array_map('intval', array_keys($contacts)))->where('business_id', $batch->business_id)->delete();
            }
            foreach (array_keys($createdProducts) as $productId) {
                $this->deleteProductRelations((int) $productId);
                Product::where('business_id', $batch->business_id)->whereKey((int) $productId)->delete();
            }
            $locked->forceFill(['status' => 'reverted', 'stage' => 'reverted', 'last_message' => 'Transaksi batch berhasil direvert.', 'progress_percent' => 100, 'heartbeat_at' => null, 'reverted_at' => now()])->save();
        });
    }

    private function productSnapshot(Product $product): array
    {
        $attributes = json_decode(json_encode($product->getAttributes(), JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
        $productId = (int) $product->id;
        $variationIds = Schema::hasTable('variations') && Schema::hasColumn('variations', 'product_id')
            ? DB::table('variations')->where('product_id', $productId)->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->all()
            : [];
        $children = [];
        foreach ([
            ['variations', 'product_id', [$productId]],
            ['product_variations', 'product_id', [$productId]],
            ['variation_location_details', 'product_id', [$productId]],
            ['product_locations', 'product_id', [$productId]],
            ['product_racks', 'product_id', [$productId]],
            ['stock_adjustment_lines', 'product_id', [$productId]],
            ['res_product_modifier_sets', 'product_id', [$productId]],
            ['res_product_modifier_sets', 'modifier_set_id', [$productId]],
            ['variation_group_prices', 'variation_id', $variationIds],
            ['inventory_products', 'variation_id', $variationIds],
        ] as [$table, $column, $ids]) {
            $children[$table.':'.$column] = $this->rowsByForeignKey($table, $column, $ids);
        }

        return ['id' => $productId, 'attributes' => $attributes, 'children' => $children];
    }

    private function rowsByForeignKey(string $table, string $column, array $ids): array
    {
        if ($ids === [] || ! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
            return [];
        }
        $query = DB::table($table)->whereIn($column, $ids);
        if (Schema::hasColumn($table, 'id')) {
            $query->orderBy('id');
        } else {
            foreach (Schema::getColumnListing($table) as $sortColumn) {
                $query->orderBy($sortColumn);
            }
        }

        return $query->get()->map(fn ($row) => get_object_vars($row))->all();
    }

    private function assertNoExternalContactReferences(array $contactIds, array $transactionIds): void
    {
        foreach (self::CONTACT_REFERENCE_COLUMNS as [$table, $column]) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
                continue;
            }

            $query = DB::table($table)->whereIn($column, $contactIds);
            if ($table === 'transactions' && $transactionIds !== []) {
                $query->whereNotIn('id', $transactionIds);
            }
            abort_if($query->exists(), 422, 'Kontak hasil import sudah dipakai data bisnis lain; revert diblokir.');
        }

        if (Schema::hasTable('crm_campaigns') && Schema::hasColumn('crm_campaigns', 'contact_ids')) {
            $contactIds = array_map('strval', $contactIds);
            foreach (DB::table('crm_campaigns')->whereNotNull('contact_ids')->pluck('contact_ids') as $storedIds) {
                $campaignContactIds = preg_split('/[^0-9]+/', (string) $storedIds, -1, PREG_SPLIT_NO_EMPTY) ?: [];
                abort_if(array_intersect($contactIds, $campaignContactIds) !== [], 422, 'Kontak hasil import sudah dipakai kampanye CRM; revert diblokir.');
            }
        }
    }

    private function deleteProductRelations(int $productId): void
    {
        $variationIds = Schema::hasTable('variations') ? DB::table('variations')->where('product_id', $productId)->pluck('id')->all() : [];
        foreach ([['variation_location_details', 'product_id'], ['product_locations', 'product_id'], ['product_racks', 'product_id']] as [$table, $column]) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, $column)) {
                DB::table($table)->where($column, $productId)->delete();
            }
        }
        if (Schema::hasTable('res_product_modifier_sets')) {
            DB::table('res_product_modifier_sets')->where('product_id', $productId)->orWhere('modifier_set_id', $productId)->delete();
        }
        if ($variationIds !== [] && Schema::hasTable('variation_group_prices')) {
            DB::table('variation_group_prices')->whereIn('variation_id', $variationIds)->delete();
        }
        if (Schema::hasTable('variations')) {
            DB::table('variations')->where('product_id', $productId)->delete();
        }
        if (Schema::hasTable('product_variations')) {
            DB::table('product_variations')->where('product_id', $productId)->delete();
        }
    }

    public function stockSnapshot(array $variationIds, int $locationId): array
    {
        if ($variationIds === [] || $locationId === 0 || ! Schema::hasTable('variation_location_details')) {
            return [];
        }

        return $this->keyed(DB::table('variation_location_details')->whereIn('variation_id', $variationIds)
            ->where('location_id', $locationId)->orderBy('variation_id')->get());
    }

    public function progressKey(int $batchId): string
    {
        return 'sales_purchase_import_progress_'.$batchId;
    }

    private function keyed($rows): array
    {
        $keyed = [];
        foreach ($rows as $row) {
            $attributes = get_object_vars($row);
            $keyed[(string) $attributes['id']] = $attributes;
        }
        uksort($keyed, fn ($a, $b) => (int) $a <=> (int) $b);

        return $keyed;
    }
}
