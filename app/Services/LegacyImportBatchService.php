<?php

namespace App\Services;

use App\Product;
use App\UserImportBatch;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Maatwebsite\Excel\Facades\Excel;

class LegacyImportBatchService
{
    public function __construct(private UserImportBatchService $batches)
    {
    }

    public function history(Request $request, int $businessId, string $importerKey): array
    {
        $table = $this->batches->dataTable($request, $businessId, $importerKey);
        $prefix = $this->routePrefix($importerKey);
        $table['data'] = collect($table['data'])->map(function (array $row) use ($prefix) {
            $row['detail_url'] = route($prefix.'.detail', $row['id']);
            $row['source_url'] = $row['has_snapshot'] ? route($prefix.'.source', $row['id']) : null;
            $row['retry_url'] = route($prefix.'.retry', $row['id']);
            $row['revert_url'] = route($prefix.'.revert', $row['id']);
            $row['can_retry'] = $row['status'] === 'failed' && $row['has_snapshot'];
            $row['can_revert'] = $row['status'] === 'imported';
            $row['active'] = in_array($row['status'], ['queued_import', 'importing'], true);

            return $row;
        })->values()->all();

        return $table;
    }

    public function detail(int $batchId, int $businessId, string $importerKey): array
    {
        $batch = $this->batches->findForBusiness($batchId, $businessId, $importerKey);
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

    public function queue(Request $request, string $importerKey, array $sessionContext = [])
    {
        $validated = $request->validate([
            'products_csv' => 'required|file|mimes:csv,xls,xlsx|max:51200',
        ]);
        $businessId = (int) $request->session()->get('user.business_id');
        $file = $validated['products_csv'];
        $stagingPath = $file->storeAs('user-imports/staging', (string) \Illuminate\Support\Str::uuid().'.'.$file->getClientOriginalExtension(), 'user_imports');
        $batch = $this->batches->create([
            'business_id' => $businessId,
            'created_by' => (int) $request->session()->get('user.id'),
            'importer_key' => $importerKey,
            'source_key' => (string) \Illuminate\Support\Str::uuid(),
            'file_name' => $file->getClientOriginalName(),
            'staging_path' => $stagingPath,
            'status' => 'queued_import',
            'stage' => 'queued_import',
            'options_json' => json_encode(['session_context' => $sessionContext], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            'progress_percent' => 0,
            'last_message' => 'Upload diterima; import menunggu worker.',
        ]);
        \App\Jobs\ProcessLegacyImportBatch::dispatch($batch->id, $businessId, $importerKey);

        return response()->json([
            'success' => true, 'queued' => true, 'batch_id' => (int) $batch->id,
            'status_url' => route($this->routePrefix($importerKey).'.detail', $batch->id),
            'msg' => 'Workbook diterima dan masuk antrean import.',
        ], 202);
    }

    public function retry(int $batchId, int $businessId, string $importerKey): void
    {
        $batch = $this->batches->findForBusiness($batchId, $businessId, $importerKey);
        abort_unless($batch->hasSourceSnapshot(), 422, 'Snapshot workbook tidak tersedia untuk retry.');
        $updated = UserImportBatch::where('business_id', $businessId)->where('importer_key', $importerKey)
            ->whereKey($batchId)->where('status', 'failed')->update([
                'status' => 'queued_import', 'stage' => 'queued_import', 'progress_current' => 0, 'progress_total' => 0,
                'progress_percent' => 0, 'error_message' => null, 'failed_at' => null, 'completed_at' => null,
                'last_message' => 'Retry import masuk antrean.', 'updated_at' => now(),
            ]);
        abort_unless($updated, 422, 'Batch tidak dapat di-retry dari status saat ini.');
        \App\Jobs\ProcessLegacyImportBatch::dispatch($batchId, $businessId, $importerKey);
    }

    public function prepare(UserImportBatch $batch, string $path): array
    {
        $sheet = Excel::toArray([], $path)[0] ?? [];
        $rows = array_slice($sheet, 1);
        $options = json_decode((string) $batch->options_json, true) ?: [];
        if ($batch->importer_key === 'products') {
            return ['source_rows' => count($rows), 'rows' => $rows];
        }

        $targets = $this->openingStockTargets($rows, $batch->business_id);
        $before = $this->captureStockState($targets, $batch->business_id);
        $options['affected_targets'] = $targets;
        $batch->options_json = json_encode($options, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $batch->save();

        return ['source_rows' => count($rows), 'rows' => $rows, 'targets' => $targets, 'before_stock' => $before];
    }

    public function complete(UserImportBatch $batch, array $beforeState, array $createdProductIds = []): array
    {
        if ($batch->importer_key === 'products') {
            $createdProductIds = array_values(array_unique(array_map('intval', $createdProductIds)));
            $products = Product::where('business_id', $batch->business_id)->whereIn('id', $createdProductIds)->orderBy('id')->get();
            abort_unless($products->count() === count($createdProductIds), 422, 'Produk yang dicatat oleh worker tidak lagi tersedia pada bisnis aktif.');
            $records = [];
            foreach ($products as $product) {
                $records[] = [
                    'id' => (int) $product->id,
                    'attributes' => $this->attributes($product),
                    'children' => $this->productChildren((int) $product->id),
                    'sku' => $product->sku,
                    'name' => $product->name,
                ];
            }
            $transactionIds = DB::table('transactions')->where('business_id', $batch->business_id)->where('type', 'opening_stock')
                ->whereIn('opening_stock_product_id', $products->pluck('id')->all())->pluck('id')->map(fn ($id) => (int) $id)->all();
            $stockTransactions = $this->rowsById('transactions', $transactionIds);
            $stockLines = $this->rowsById('purchase_lines', DB::table('purchase_lines')->whereIn('transaction_id', $transactionIds)->pluck('id')->all());
            $result = [
                'summary' => ['source_rows' => (int) ($beforeState['source_rows'] ?? 0), 'created_products' => count($records)],
                'records' => $records,
                'created_transactions' => $stockTransactions,
                'created_purchase_lines' => $stockLines,
                'row_outcomes' => $this->productOutcomes($beforeState['rows'] ?? [], $products),
            ];
        } else {
            $targets = $beforeState['targets'] ?? [];
            $after = $this->captureStockState($targets, $batch->business_id);
            $result = [
                'summary' => ['source_rows' => (int) ($beforeState['source_rows'] ?? 0), 'stock_targets' => count($targets)],
                'records' => $this->stockOutcomes($beforeState['rows'] ?? [], $targets, $beforeState['before_stock'] ?? [], $after),
                'row_outcomes' => $this->stockOutcomes($beforeState['rows'] ?? [], $targets, $beforeState['before_stock'] ?? [], $after),
                'before_stock' => $beforeState['before_stock'] ?? [],
                'after_stock' => $after,
            ];
        }

        $this->batches->storePayload($batch, 'results', $result);
        $batch->forceFill([
            'status' => 'imported', 'stage' => 'imported', 'progress_current' => $result['summary']['source_rows'],
            'progress_total' => $result['summary']['source_rows'], 'progress_percent' => 100,
            'summary_json' => json_encode($result['summary'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            'search_text' => $batch->file_name.' '.json_encode($result['row_outcomes'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'last_message' => 'Import selesai: '.$result['summary']['source_rows'].' baris sumber diproses.',
            'error_message' => null, 'heartbeat_at' => null, 'completed_at' => now(), 'failed_at' => null,
        ])->save();
        Cache::forget($this->progressKey((int) $batch->id));

        return $result;
    }

    public function fail(UserImportBatch $batch, \Throwable $exception): void
    {
        $batch->forceFill([
            'status' => 'failed', 'stage' => 'failed', 'last_message' => mb_substr($exception->getMessage(), 0, 2000),
            'error_message' => mb_substr($exception->getMessage(), 0, 65000), 'heartbeat_at' => null,
            'completed_at' => now(), 'failed_at' => now(),
        ])->save();
        Cache::forget($this->progressKey((int) $batch->id));
    }

    public function revert(UserImportBatch $batch): void
    {
        abort_unless($batch->status === 'imported', 422, 'Batch tidak berada pada status imported.');
        $result = $this->batches->readPayload($batch, 'results');
        DB::transaction(function () use ($batch, $result) {
            $locked = UserImportBatch::where('business_id', $batch->business_id)->where('importer_key', $batch->importer_key)->lockForUpdate()->findOrFail($batch->id);
            abort_unless($locked->status === 'imported', 422, 'Status batch sudah berubah.');
            if ($batch->importer_key === 'products') {
                $this->revertProducts($batch, $result);
            } else {
                $this->revertOpeningStock($batch, $result);
            }
            $locked->forceFill(['status' => 'reverted', 'stage' => 'reverted', 'last_message' => 'Import berhasil direvert dengan aman.', 'progress_percent' => 100, 'heartbeat_at' => null, 'reverted_at' => now()])->save();
        });
    }

    private function revertProducts(UserImportBatch $batch, array $result): void
    {
        $transactionIds = array_map('intval', array_keys($result['created_transactions'] ?? []));
        $lineIds = array_map('intval', array_keys($result['created_purchase_lines'] ?? []));
        foreach (($result['records'] ?? []) as $record) {
            $product = Product::where('business_id', $batch->business_id)->find((int) $record['id']);
            abort_unless($product && $this->attributes($product) === ($record['attributes'] ?? []), 422, 'Produk telah berubah sejak import; revert diblokir.');
            $variationIds = $product->variations()->pluck('id')->map(fn ($id) => (int) $id)->all();
            if ($variationIds !== [] && Schema::hasTable('inventory_products')) {
                abort_if(DB::table('inventory_products')->whereIn('variation_id', $variationIds)->exists(), 422, 'Produk digunakan dalam catatan inventaris; revert diblokir.');
            }
            $currentChildren = $this->productChildren((int) $product->id);
            if (array_key_exists('children', $record)) {
                abort_unless($currentChildren === $record['children'], 422, 'Relasi atau harga turunan produk berubah sejak import; revert diblokir.');
            } else {
                abort_if(collect($currentChildren)->contains(fn ($rows) => $rows !== []), 422, 'Snapshot relasi produk tidak tersedia; revert diblokir demi keamanan.');
            }
            if (Schema::hasTable('transaction_sell_lines')) {
                abort_if(DB::table('transaction_sell_lines')->where('product_id', $product->id)->orWhereIn('variation_id', $variationIds)->exists(), 422, 'Produk sudah dipakai pada penjualan; revert diblokir.');
            }
            if (Schema::hasTable('purchase_lines')) {
                $lines = DB::table('purchase_lines')->where('product_id', $product->id)->get();
                foreach ($lines as $line) {
                    abort_unless(in_array((int) $line->id, $lineIds, true), 422, 'Produk memiliki baris pembelian di luar batch; revert diblokir.');
                    abort_if((float) ($line->quantity_sold ?? 0) > 0 || (float) ($line->quantity_adjusted ?? 0) > 0, 422, 'Stok produk sudah dipakai atau disesuaikan; revert diblokir.');
                }
            }
            if (Schema::hasTable('transactions')) {
                $otherOpeningStock = DB::table('transactions')->where('business_id', $batch->business_id)
                    ->where('opening_stock_product_id', $product->id)->whereNotIn('id', $transactionIds)->exists();
                abort_if($otherOpeningStock, 422, 'Produk memiliki transaksi stok lain; revert diblokir.');
            }
            if (Schema::hasTable('mfg_recipe_ingredients') && $variationIds !== []) {
                abort_if(DB::table('mfg_recipe_ingredients')->whereIn('variation_id', $variationIds)->exists(), 422, 'Produk digunakan sebagai bahan resep; revert diblokir.');
            }
        }

        foreach (($result['created_transactions'] ?? []) as $id => $after) {
            abort_unless($this->rowById('transactions', (int) $id) === $after, 422, 'Transaksi stok awal telah berubah; revert diblokir.');
        }
        foreach (($result['created_purchase_lines'] ?? []) as $id => $after) {
            abort_unless($this->rowById('purchase_lines', (int) $id) === $after, 422, 'Baris stok awal telah berubah; revert diblokir.');
        }
        foreach ($transactionIds as $id) {
            if (Schema::hasTable('transaction_payments')) {
                abort_if(DB::table('transaction_payments')->where('transaction_id', $id)->exists(), 422, 'Transaksi stok awal memiliki pembayaran; revert diblokir.');
            }
            DB::table('transactions')->where('id', $id)->where('business_id', $batch->business_id)->delete();
        }
        foreach (($result['records'] ?? []) as $record) {
            $productId = (int) $record['id'];
            $variationIds = DB::table('variations')->where('product_id', $productId)->pluck('id')->map(fn ($id) => (int) $id)->all();
            if ($variationIds !== [] && Schema::hasTable('variation_group_prices')) {
                DB::table('variation_group_prices')->whereIn('variation_id', $variationIds)->delete();
            }
            if (Schema::hasTable('variation_location_details')) {
                DB::table('variation_location_details')->where('product_id', $productId)->delete();
            }
            if (Schema::hasTable('product_locations')) {
                DB::table('product_locations')->where('product_id', $productId)->delete();
            }
            if (Schema::hasTable('product_racks')) {
                DB::table('product_racks')->where('product_id', $productId)->delete();
            }
            if (Schema::hasTable('res_product_modifier_sets')) {
                DB::table('res_product_modifier_sets')->where('product_id', $productId)->orWhere('modifier_set_id', $productId)->delete();
            }
            if (Schema::hasTable('variations')) {
                DB::table('variations')->where('product_id', $productId)->delete();
            }
            if (Schema::hasTable('product_variations')) {
                DB::table('product_variations')->where('product_id', $productId)->delete();
            }
            Product::where('business_id', $batch->business_id)->whereKey($productId)->delete();
        }
    }

    private function productChildren(int $productId): array
    {
        $children = [];
        $variationIds = Schema::hasTable('variations') && Schema::hasColumn('variations', 'product_id')
            ? DB::table('variations')->where('product_id', $productId)->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->all()
            : [];

        foreach ([
            ['variations', 'product_id', $productId],
            ['product_variations', 'product_id', $productId],
            ['variation_location_details', 'product_id', $productId],
            ['product_locations', 'product_id', $productId],
            ['product_racks', 'product_id', $productId],
            ['stock_adjustment_lines', 'product_id', $productId],
            ['res_product_modifier_sets', 'product_id', $productId],
            ['res_product_modifier_sets', 'modifier_set_id', $productId],
        ] as [$table, $column, $value]) {
            $children[$table.':'.$column] = $this->rowsByForeignKey($table, $column, [$value]);
        }

        $children['variation_group_prices:variation_id'] = $this->rowsByForeignKey('variation_group_prices', 'variation_id', $variationIds);
        $children['inventory_products:variation_id'] = $this->rowsByForeignKey('inventory_products', 'variation_id', $variationIds);

        return $children;
    }

    private function rowsByForeignKey(string $table, string $column, array $values): array
    {
        if ($values === [] || ! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
            return [];
        }
        $query = DB::table($table)->whereIn($column, $values);
        if (Schema::hasColumn($table, 'id')) {
            $query->orderBy('id');
        } else {
            foreach (Schema::getColumnListing($table) as $sortColumn) {
                $query->orderBy($sortColumn);
            }
        }

        return $query->get()->map(fn ($row) => get_object_vars($row))->all();
    }

    private function revertOpeningStock(UserImportBatch $batch, array $result): void
    {
        $before = $result['before_stock'] ?? [];
        $after = $result['after_stock'] ?? [];
        abort_unless($this->captureStockState($before['targets'] ?? [], $batch->business_id) === $after, 422, 'Transaksi atau kuantitas stok berubah setelah import; revert diblokir.');

        foreach (($after['pairs'] ?? []) as $pairKey => $pairAfter) {
            $pairBefore = $before['pairs'][$pairKey] ?? ['transactions' => [], 'purchase_lines' => [], 'variation_locations' => [], 'product_locations' => []];
            $beforeTransactionIds = array_map('intval', array_keys($pairBefore['transactions'] ?? []));
            $beforeLineIds = array_map('intval', array_keys($pairBefore['purchase_lines'] ?? []));
            foreach (array_keys($pairAfter['purchase_lines'] ?? []) as $lineId) {
                if (! in_array((int) $lineId, $beforeLineIds, true)) {
                    DB::table('purchase_lines')->where('id', (int) $lineId)->delete();
                }
            }
            foreach (array_keys($pairAfter['transactions'] ?? []) as $transactionId) {
                if (! in_array((int) $transactionId, $beforeTransactionIds, true)) {
                    DB::table('transactions')->where('business_id', $batch->business_id)->where('id', (int) $transactionId)->delete();
                } elseif (isset($pairBefore['transactions'][$transactionId])) {
                    $this->restoreRow('transactions', (int) $transactionId, $pairBefore['transactions'][$transactionId]);
                }
            }
            $target = $before['targets'][$pairKey];
            $this->restorePairTable('variation_location_details', $target, $pairBefore['variation_locations'] ?? [], ['product_id', 'location_id']);
            $this->restorePairTable('product_locations', $target, $pairBefore['product_locations'] ?? [], ['product_id', 'location_id']);
        }
    }

    private function openingStockTargets(array $rows, int $businessId): array
    {
        $targets = [];
        foreach ($rows as $index => $row) {
            $sku = trim((string) ($row[0] ?? ''));
            if ($sku === '') {
                continue;
            }
            $product = DB::table('variations')->join('products', 'products.id', '=', 'variations.product_id')
                ->where('products.business_id', $businessId)->where('variations.sub_sku', $sku)
                ->select('products.id as product_id', 'variations.id as variation_id')->first();
            if (! $product) {
                continue;
            }
            $locationName = trim((string) ($row[1] ?? ''));
            $locationQuery = DB::table('business_locations')->where('business_id', $businessId);
            if ($locationName !== '') {
                $locationQuery->where('name', $locationName);
            } else {
                $locationQuery->orderBy('id');
            }
            $locationId = $locationQuery->value('id');
            if (! $locationId) {
                continue;
            }
            $key = (int) $product->product_id.':'.(int) $locationId;
            $targets[$key] = ['product_id' => (int) $product->product_id, 'variation_id' => (int) $product->variation_id, 'location_id' => (int) $locationId];
        }

        return $targets;
    }

    private function captureStockState(array $targets, int $businessId): array
    {
        $state = ['targets' => $targets, 'pairs' => []];
        foreach ($targets as $key => $target) {
            $transactions = DB::table('transactions')->where('business_id', $businessId)->where('type', 'opening_stock')
                ->where('location_id', $target['location_id'])->where('opening_stock_product_id', $target['product_id'])->get();
            $transactionIds = $transactions->pluck('id')->map(fn ($id) => (int) $id)->all();
            $state['pairs'][$key] = [
                'transactions' => $this->keyed($transactions),
                'purchase_lines' => $this->rowsById('purchase_lines', DB::table('purchase_lines')->whereIn('transaction_id', $transactionIds)->pluck('id')->all()),
                'variation_locations' => Schema::hasTable('variation_location_details')
                    ? $this->keyed(DB::table('variation_location_details')->where('product_id', $target['product_id'])->where('location_id', $target['location_id'])->get()) : [],
                'product_locations' => Schema::hasTable('product_locations')
                    ? $this->keyed(DB::table('product_locations')->where('product_id', $target['product_id'])->where('location_id', $target['location_id'])->get()) : [],
            ];
        }

        return $state;
    }

    private function productOutcomes(array $rows, $products): array
    {
        $records = $products->values();
        $outcomes = [];
        foreach ($rows as $index => $row) {
            $sourceRow = $index + 2;
            $name = trim((string) ($row[0] ?? ''));
            $sku = trim((string) ($row[5] ?? ''));
            $matched = $records->first(fn ($product) => ($sku !== '' && $product->sku === $sku) || ($sku === '' && $product->name === $name));
            $outcomes[] = ['source_row' => $sourceRow, 'name' => $name, 'sku' => $sku, 'status' => $matched ? 'created' : (strtolower(trim((string) ($row[13] ?? ''))) === 'combo' ? 'skipped' : 'not_created'), 'product_id' => $matched ? (int) $matched->id : null];
        }

        return $outcomes;
    }

    private function stockOutcomes(array $rows, array $targets, array $before, array $after): array
    {
        $outcomes = [];
        foreach ($rows as $index => $row) {
            $sourceRow = $index + 2;
            $sku = trim((string) ($row[0] ?? ''));
            $target = null;
            foreach ($targets as $candidate) {
                $variation = DB::table('variations')->where('id', $candidate['variation_id'])->value('sub_sku');
                if ($variation === $sku) {
                    $target = $candidate;
                    break;
                }
            }
            $outcomes[] = ['source_row' => $sourceRow, 'sku' => $sku, 'status' => $target ? 'imported' : 'error', 'product_id' => $target['product_id'] ?? null, 'variation_id' => $target['variation_id'] ?? null, 'location_id' => $target['location_id'] ?? null];
        }

        return $outcomes;
    }

    private function restorePairTable(string $table, array $target, array $before, array $scope): void
    {
        if (! Schema::hasTable($table)) {
            return;
        }
        $query = DB::table($table)->where('product_id', $target['product_id'])->where('location_id', $target['location_id']);
        $query->delete();
        foreach ($before as $row) {
            DB::table($table)->insert($row);
        }
    }

    private function restoreRow(string $table, int $id, array $attributes): void
    {
        unset($attributes['id']);
        DB::table($table)->where('id', $id)->update($attributes);
    }

    private function rowsById(string $table, array $ids): array
    {
        if ($ids === [] || ! Schema::hasTable($table)) {
            return [];
        }

        return $this->keyed(DB::table($table)->whereIn('id', $ids)->get());
    }

    private function rowById(string $table, int $id): ?array
    {
        if (! Schema::hasTable($table)) {
            return null;
        }
        $row = DB::table($table)->where('id', $id)->first();

        return $row ? get_object_vars($row) : null;
    }

    private function keyed($rows): array
    {
        $keyed = [];
        foreach ($rows as $row) {
            $attributes = get_object_vars($row);
            $key = (string) ($attributes['id'] ?? ($attributes['transaction_id'] ?? '').':'.($attributes['variation_id'] ?? '').':'.($attributes['location_id'] ?? ''));
            $keyed[$key] = $attributes;
        }

        ksort($keyed);

        return $keyed;
    }

    private function attributes($model): array
    {
        return json_decode(json_encode($model->getAttributes(), JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
    }

    private function routePrefix(string $importerKey): string
    {
        return $importerKey === 'products' ? 'import.products.history' : 'import.opening-stock.history';
    }

    public function progressKey(int $batchId): string
    {
        return 'legacy_import_progress_'.$batchId;
    }
}
