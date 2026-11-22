<?php

namespace App\Services;

use App\Business;
use App\BusinessLocation;
use App\Product;
use App\TaxRate;
use App\UserImportBatch;
use App\Variation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Facades\Excel;

class PurchaseProductLineImportBatchService
{
    public const IMPORTER_KEY = 'purchase_product_lines';

    public function __construct(private UserImportBatchService $batches)
    {
    }

    public function history(Request $request, int $businessId): array
    {
        $table = $this->batches->dataTable($request, $businessId, self::IMPORTER_KEY);
        $table['data'] = collect($table['data'])->map(function (array $row) {
            $progress = Cache::get($this->progressKey((int) $row['id']), []);
            $row['progress_percent'] = (int) ($progress['progress_percent'] ?? $row['progress_percent']);
            $row['progress_current'] = (int) ($progress['progress_current'] ?? $row['progress_current']);
            $row['progress_total'] = (int) ($progress['progress_total'] ?? $row['progress_total']);
            $row['last_message'] = $progress['last_message'] ?? $row['last_message'];
            $row['detail_url'] = route('purchase.product-import.history.detail', $row['id']);
            $row['restore_url'] = $row['has_snapshot'] ? route('purchase.product-import.history.source', $row['id']) : null;
            $row['retry_url'] = route('purchase.product-import.history.retry', $row['id']);
            $row['revert_url'] = route('purchase.product-import.history.revert', $row['id']);
            $row['can_retry'] = $row['status'] === 'failed' && $row['has_snapshot'];
            $row['can_revert'] = $row['status'] === 'preview_ready';
            $row['active'] = in_array($row['status'], ['queued_preview', 'analyzing'], true);

            return $row;
        })->values()->all();

        return $table;
    }

    public function process(UserImportBatch $batch, string $workbookPath): array
    {
        $sheets = Excel::toArray([], $workbookPath);
        $sheet = $sheets[0] ?? [];
        if (count($sheet) < 2) {
            throw new \RuntimeException('Workbook Purchase tidak memiliki baris produk.');
        }
        $totalRows = count($sheet) - 1;
        $this->updateProgress($batch, 0, $totalRows, 'Menganalisis baris produk Purchase.');

        $options = json_decode((string) $batch->options_json, true) ?: [];
        $locationId = (int) ($options['location_id'] ?? 0);
        abort_unless(BusinessLocation::where('business_id', $batch->business_id)->whereKey($locationId)->exists(), 422, 'Lokasi Purchase tidak valid untuk bisnis aktif.');

        $accepted = [];
        $outcomes = [];
        foreach (array_slice($sheet, 1) as $index => $row) {
            $sourceRow = $index + 2;
            if (count(array_filter($row, fn ($value) => $value !== null && $value !== '')) === 0) {
                $this->updateProgress($batch, $index + 1, $totalRows, 'Baris kosong dilewati.');
                continue;
            }
            $sku = trim((string) ($row[0] ?? ''));
            try {
                if ($sku === '') {
                    throw new \RuntimeException(__('lang_v1.product_not_found_exception', ['row' => $sourceRow, 'sku' => $sku]));
                }
                $variation = Variation::query()
                    ->join('products', 'products.id', '=', 'variations.product_id')
                    ->where('products.business_id', $batch->business_id)
                    ->where('variations.sub_sku', $sku)
                    ->with(['product_variation', 'variation_location_details' => fn ($query) => $query->where('location_id', $locationId)])
                    ->select('variations.*')->first();
                if (! $variation) {
                    throw new \RuntimeException(__('lang_v1.product_not_found_exception', ['row' => $sourceRow, 'sku' => $sku]));
                }
                $product = Product::where('business_id', $batch->business_id)->with('unit')->find($variation->product_id);
                if (! $product || ! $product->unit) {
                    throw new \RuntimeException("Produk SKU {$sku} tidak memiliki unit yang valid pada baris {$sourceRow}.");
                }
                $quantity = $row[1] ?? null;
                if (! is_numeric($quantity) || (float) $quantity <= 0) {
                    throw new \RuntimeException(__('lang_v1.quantity_required', ['row' => $sourceRow]));
                }
                $cost = ($row[2] ?? null) !== null && $row[2] !== '' ? $row[2] : $variation->default_purchase_price;
                $discount = ($row[3] ?? null) !== null && $row[3] !== '' ? $row[3] : 0;
                if (! is_numeric($cost) || (float) $cost < 0 || ! is_numeric($discount) || (float) $discount < 0 || (float) $discount > 100) {
                    throw new \RuntimeException("Harga/discount tidak valid pada baris {$sourceRow}.");
                }

                $taxId = null;
                if (filled($row[4] ?? null)) {
                    $taxId = TaxRate::where('business_id', $batch->business_id)->where('name', 'like', '%'.trim((string) $row[4]).'%')->value('id');
                }
                $productUtil = app(\App\Utils\ProductUtil::class);
                $accepted[] = [
                    'source_row' => $sourceRow,
                    'variation_id' => (int) $variation->id,
                    'product_id' => (int) $product->id,
                    'quantity' => $quantity,
                    'unit_cost_before_discount' => $cost,
                    'discount_percent' => $discount,
                    'tax_id' => $taxId,
                    'lot_number' => filled($row[5] ?? null) ? (string) $row[5] : null,
                    'mfg_date' => filled($row[6] ?? null) ? $productUtil->format_date($row[6]) : null,
                    'exp_date' => filled($row[7] ?? null) ? $productUtil->format_date($row[7]) : null,
                ];
                $outcomes[] = ['source_row' => $sourceRow, 'sku' => $sku, 'status' => 'ready', 'message' => null];
            } catch (\Throwable $exception) {
                $outcomes[] = ['source_row' => $sourceRow, 'sku' => $sku, 'status' => 'error', 'message' => $exception->getMessage()];
            }
            $this->updateProgress($batch, $index + 1, $totalRows, "Baris {$sourceRow} dari {$totalRows} dianalisis.");
        }

        if ($outcomes === []) {
            throw new \RuntimeException('Workbook Purchase tidak memiliki baris data.');
        }

        $result = DB::transaction(function () use ($batch, $accepted, $outcomes) {
            $result = [
                'summary' => [
                    'source_rows' => count($outcomes),
                    'ready_rows' => count($accepted),
                    'error_rows' => count(array_filter($outcomes, fn ($row) => $row['status'] === 'error')),
                ],
                'rows' => $accepted,
                'row_outcomes' => $outcomes,
            ];
            $this->batches->storePayload($batch, 'results', $result);
            $batch->forceFill([
                'status' => 'preview_ready',
                'stage' => 'preview_ready',
                'progress_current' => count($outcomes),
                'progress_total' => count($outcomes),
                'progress_percent' => 100,
                'summary_json' => json_encode($result['summary'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                'search_text' => $batch->file_name.' '.implode(' ', array_column($outcomes, 'sku')),
                'last_message' => count($accepted).' baris siap dimasukkan ke form Purchase; '.($result['summary']['error_rows']).' baris perlu diperiksa.',
                'error_message' => null,
                'heartbeat_at' => null,
                'completed_at' => now(),
                'failed_at' => null,
            ])->save();

            return $result;
        });
        Cache::forget($this->progressKey((int) $batch->id));

        return $result;
    }

    public function detail(UserImportBatch $batch, int $businessId): array
    {
        $batch = $this->batches->findForBusiness($batch->id, $businessId, self::IMPORTER_KEY);
        $result = $batch->results_path ? $this->batches->readPayload($batch, 'results') : [];
        $options = json_decode((string) $batch->options_json, true) ?: [];
        $progress = Cache::get($this->progressKey((int) $batch->id), []);

        return [
            'batch' => [
                'id' => (int) $batch->id,
                'file_name' => $batch->file_name,
                'status' => $batch->status,
                'progress_percent' => (int) ($progress['progress_percent'] ?? $batch->progress_percent),
                'progress_current' => (int) ($progress['progress_current'] ?? $batch->progress_current),
                'progress_total' => (int) ($progress['progress_total'] ?? $batch->progress_total),
                'last_message' => $progress['last_message'] ?? $batch->last_message,
                'error_message' => $batch->error_message,
                'created_at' => optional($batch->created_at)->toDateTimeString(),
            ],
            'summary' => $result['summary'] ?? [],
            'rows' => $result['row_outcomes'] ?? [],
            'html' => $batch->status === 'preview_ready' ? $this->renderRows($result['rows'] ?? [], $batch, (int) ($options['row_count'] ?? 0)) : null,
        ];
    }

    public function renderRows(array $rows, UserImportBatch $batch, int $rowCount): string
    {
        $business = Business::findOrFail($batch->business_id);
        $locationId = (int) ((json_decode((string) $batch->options_json, true) ?: [])['location_id'] ?? 0);
        $formattedData = [];
        foreach ($rows as $row) {
            $variation = Variation::query()->whereHas('product', fn ($query) => $query->where('business_id', $batch->business_id))
                ->with(['product_variation', 'variation_location_details' => fn ($query) => $query->where('location_id', $locationId)])
                ->findOrFail((int) $row['variation_id']);
            $product = Product::where('business_id', $batch->business_id)->with('unit')->findOrFail((int) $row['product_id']);
            $formattedData[] = [
                'variation' => $variation,
                'product' => $product,
                'sub_units' => app(\App\Utils\ProductUtil::class)->getSubUnits($batch->business_id, $product->unit->id, false, $product->id),
                'quantity' => $row['quantity'],
                'unit_cost_before_discount' => $row['unit_cost_before_discount'],
                'discount_percent' => $row['discount_percent'],
                'tax_id' => $row['tax_id'],
                'lot_number' => $row['lot_number'],
                'mfg_date' => $row['mfg_date'],
                'exp_date' => $row['exp_date'],
            ];
        }
        $taxes = TaxRate::where('business_id', $batch->business_id)->ExcludeForTaxGroup()->get();
        $currencyDetails = app(\App\Utils\TransactionUtil::class)->purchaseCurrencyDetails($batch->business_id);
        $hideTax = $business->enable_inline_tax == 1 ? '' : 'hide';

        return view('purchase.partials.imported_purchase_product_rows')->with([
            'formatted_data' => $formattedData,
            'taxes' => $taxes,
            'currency_details' => $currencyDetails,
            'hide_tax' => $hideTax,
            'row_count' => $rowCount,
        ])->render();
    }

    public function fail(UserImportBatch $batch, \Throwable $exception): void
    {
        $batch->forceFill([
            'status' => 'failed', 'stage' => 'failed',
            'last_message' => mb_substr($exception->getMessage(), 0, 2000),
            'error_message' => mb_substr($exception->getMessage(), 0, 65000),
            'heartbeat_at' => null, 'completed_at' => now(), 'failed_at' => now(),
        ])->save();
        Cache::forget($this->progressKey((int) $batch->id));
    }

    public function revert(UserImportBatch $batch): void
    {
        abort_unless($batch->status === 'preview_ready', 422, 'Batch ini tidak lagi berupa preview Purchase.');
        $batch->forceFill([
            'status' => 'reverted', 'stage' => 'reverted', 'last_message' => 'Preview baris Purchase dibatalkan; tidak ada transaksi bisnis yang diubah.',
            'progress_percent' => 100, 'reverted_at' => now(),
        ])->save();
    }

    public function progressKey(int $batchId): string
    {
        return 'purchase_product_line_progress_'.$batchId;
    }

    private function updateProgress(UserImportBatch $batch, int $current, int $total, string $message): void
    {
        $percent = $total > 0 ? min(95, (int) floor(($current / $total) * 95)) : 5;
        $progress = [
            'progress_current' => $current,
            'progress_total' => $total,
            'progress_percent' => $percent,
            'last_message' => $message,
        ];
        Cache::put($this->progressKey((int) $batch->id), $progress + ['heartbeat_at' => now()->toDateTimeString()], 3600);
        UserImportBatch::where('business_id', $batch->business_id)->where('importer_key', self::IMPORTER_KEY)->whereKey($batch->id)->update($progress + [
            'heartbeat_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
