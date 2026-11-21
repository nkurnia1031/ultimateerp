<?php

namespace App\Services;

use App\SellingPriceGroup;
use App\UserImportBatch;
use App\Variation;
use App\VariationGroupPrice;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Maatwebsite\Excel\Facades\Excel;

class SellingPriceGroupImportBatchService
{
    public const IMPORTER_KEY = 'selling_price_group';

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
            $row['detail_url'] = route('selling-price-group.import.history.detail', $row['id']);
            $row['restore_url'] = $row['has_snapshot'] ? route('selling-price-group.import.history.source', $row['id']) : null;
            $row['retry_url'] = route('selling-price-group.import.history.retry', $row['id']);
            $row['revert_url'] = route('selling-price-group.import.history.revert', $row['id']);
            $row['can_retry'] = $row['status'] === 'failed' && $row['has_snapshot'];
            $row['can_revert'] = $row['status'] === 'imported';
            $row['active'] = in_array($row['status'], ['queued_preview', 'analyzing', 'importing'], true);

            return $row;
        })->values()->all();

        return $table;
    }

    public function process(UserImportBatch $batch, string $workbookPath): array
    {
        $sheets = Excel::toArray([], $workbookPath);
        $headers = $sheets[0][0] ?? [];
        if ($headers === []) {
            throw new \RuntimeException('Workbook harga tidak memiliki header.');
        }
        $rows = array_slice($sheets[0], 1);
        $totalRows = count($rows);
        $priceGroups = SellingPriceGroup::where('business_id', $batch->business_id)->active()->get();
        $importedGroups = [];
        foreach ($headers as $column => $name) {
            if ($column > 2 && filled($name)) {
                $importedGroups[$column] = trim((string) $name);
            }
        }

        $this->updateProgress($batch, 0, $totalRows, 'Menganalisis baris harga Selling Price Group.');
        $result = DB::transaction(function () use ($batch, $rows, $importedGroups, $priceGroups, $totalRows) {
            $transactionMaxId = (int) DB::table('transactions')->where('business_id', $batch->business_id)->max('id');
            $variationBefore = [];
            $groupPriceBefore = [];
            $rowOutcomes = [];

            foreach ($rows as $index => $row) {
                $sourceRow = $index + 2;
                $sku = trim((string) ($row[1] ?? ''));
                $variation = Variation::query()
                    ->join('products as p', 'variations.product_id', '=', 'p.id')
                    ->where('p.business_id', $batch->business_id)
                    ->where('variations.sub_sku', $sku)
                    ->select('variations.*')
                    ->first();
                if (! $variation) {
                    throw new \RuntimeException(__('lang_v1.product_not_found_exception', ['sku' => $sku, 'row' => $sourceRow]));
                }

                $variationBefore[$variation->id] ??= $this->variationPriceAttributes($variation);
                $changed = false;
                $basePrice = $row[2] ?? null;
                if ($basePrice !== null && $variation->sell_price_inc_tax != $basePrice) {
                    if (! is_numeric($basePrice)) {
                        throw new \RuntimeException("Harga jual dasar pada baris {$sourceRow} harus berupa angka.");
                    }
                    $taxPercent = (float) optional($variation->product->product_tax()->first())->amount;
                    $util = app(\App\Utils\Util::class);
                    $variation->sell_price_inc_tax = $basePrice;
                    $variation->default_sell_price = $util->calc_percentage_base($basePrice, $taxPercent);
                    $variation->profit_percent = $util->get_percent($variation->default_purchase_price, $variation->default_sell_price);
                    $variation->save();
                    $changed = true;
                }

                foreach ($importedGroups as $column => $groupName) {
                    $matchingGroups = $priceGroups->filter(fn (SellingPriceGroup $group) => mb_strtolower(trim($group->name)) === mb_strtolower(trim($groupName)));
                    if ($matchingGroups->isEmpty()) {
                        throw new \RuntimeException(__('lang_v1.price_group_not_found_exception', ['pg' => $groupName, 'row' => $sourceRow]));
                    }
                    if ($matchingGroups->count() > 1) {
                        throw new \RuntimeException("Nama price group '{$groupName}' memiliki lebih dari satu kecocokan pada baris {$sourceRow}.");
                    }
                    $group = $matchingGroups->first();
                    $value = $row[$column] ?? null;
                    if ($value === null || $value === '') {
                        continue;
                    }
                    if (! is_numeric($value)) {
                        throw new \RuntimeException(__('lang_v1.price_group_non_numeric_exception', ['row' => $sourceRow]));
                    }

                    $key = $variation->id.':'.$group->id;
                    if (! array_key_exists($key, $groupPriceBefore)) {
                        $prior = VariationGroupPrice::where('variation_id', $variation->id)
                            ->where('price_group_id', $group->id)->first();
                        $groupPriceBefore[$key] = $prior ? $this->modelAttributes($prior) : null;
                    }
                    VariationGroupPrice::updateOrCreate(
                        ['variation_id' => $variation->id, 'price_group_id' => $group->id],
                        ['price_inc_tax' => $value]
                    );
                    $changed = true;
                }

                $rowOutcomes[] = [
                    'source_sheet' => 'Sheet 1',
                    'source_row' => $sourceRow,
                    'sku' => $sku,
                    'variation_id' => (int) $variation->id,
                    'status' => $changed ? 'updated' : 'unchanged',
                ];
                $this->updateProgress($batch, $index + 1, $totalRows, 'Baris harga '.($index + 1).' dari '.$totalRows.' selesai diproses.');
            }

            $variations = [];
            foreach ($variationBefore as $id => $before) {
                $variation = Variation::findOrFail($id);
                $variations[] = ['id' => (int) $id, 'before' => $before, 'after' => $this->variationPriceAttributes($variation)];
            }
            $groupPrices = [];
            foreach ($groupPriceBefore as $key => $before) {
                [$variationId, $groupId] = array_map('intval', explode(':', $key));
                $current = VariationGroupPrice::where('variation_id', $variationId)->where('price_group_id', $groupId)->first();
                $groupPrices[] = [
                    'variation_id' => $variationId,
                    'price_group_id' => $groupId,
                    'before' => $before,
                    'after' => $current ? $this->modelAttributes($current) : null,
                ];
            }

            $result = [
                'summary' => ['source_rows' => count($rowOutcomes), 'updated_rows' => count(array_filter($rowOutcomes, fn ($row) => $row['status'] === 'updated'))],
                'row_outcomes' => $rowOutcomes,
                'variations' => $variations,
                'group_prices' => $groupPrices,
                'transaction_max_id' => $transactionMaxId,
            ];
            $this->batches->storePayload($batch, 'results', $result);
            $batch->forceFill([
                'status' => 'imported',
                'stage' => 'imported',
                'progress_current' => count($rowOutcomes),
                'progress_total' => count($rowOutcomes),
                'progress_percent' => 100,
                'summary_json' => json_encode($result['summary'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                'search_text' => $batch->file_name.' '.implode(' ', array_column($rowOutcomes, 'sku')),
                'last_message' => 'Import harga Selling Price Group selesai.',
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

    public function detail(int $batchId, int $businessId): array
    {
        $batch = $this->batches->findForBusiness($batchId, $businessId, self::IMPORTER_KEY);
        $progress = Cache::get($this->progressKey($batchId), []);

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
            'results' => $batch->results_path ? $this->batches->readPayload($batch, 'results') : [],
        ];
    }

    public function fail(UserImportBatch $batch, \Throwable $exception): void
    {
        $batch->forceFill([
            'status' => 'failed',
            'stage' => 'failed',
            'last_message' => mb_substr($exception->getMessage(), 0, 2000),
            'error_message' => mb_substr($exception->getMessage(), 0, 65000),
            'heartbeat_at' => null,
            'completed_at' => now(),
            'failed_at' => now(),
        ])->save();
        Cache::forget($this->progressKey((int) $batch->id));
    }

    public function revert(UserImportBatch $batch): void
    {
        abort_unless($batch->status === 'imported', 422, 'Batch harga ini tidak berada pada status imported.');
        $results = $this->batches->readPayload($batch, 'results');

        DB::transaction(function () use ($batch, $results) {
            $locked = UserImportBatch::where('business_id', $batch->business_id)
                ->where('importer_key', self::IMPORTER_KEY)->lockForUpdate()->findOrFail($batch->id);
            abort_unless($locked->status === 'imported', 422, 'Batch harga ini sudah berubah status.');

            $variationIds = [];
            foreach (($results['variations'] ?? []) as $snapshot) {
                $variation = Variation::query()->whereHas('product', fn ($query) => $query->where('business_id', $batch->business_id))
                    ->lockForUpdate()->find((int) $snapshot['id']);
                abort_unless($variation && $this->variationPriceAttributes($variation) === ($snapshot['after'] ?? []), 422, 'Harga produk sudah berubah setelah import. Revert diblokir.');
                $variationIds[] = (int) $variation->id;
            }

            $this->assertNoLaterSales($batch, $variationIds, (int) ($results['transaction_max_id'] ?? 0));

            foreach (($results['group_prices'] ?? []) as $snapshot) {
                $query = VariationGroupPrice::where('variation_id', $snapshot['variation_id'])->where('price_group_id', $snapshot['price_group_id']);
                $current = $query->lockForUpdate()->first();
                abort_unless(($current ? $this->modelAttributes($current) : null) === ($snapshot['after'] ?? null), 422, 'Harga grup sudah berubah setelah import. Revert diblokir.');
                if ($snapshot['before'] === null) {
                    $current?->delete();
                } else {
                    $current->price_inc_tax = $snapshot['before']['price_inc_tax'];
                    $current->save();
                }
            }

            foreach (($results['variations'] ?? []) as $snapshot) {
                $variation = Variation::findOrFail((int) $snapshot['id']);
                $variation->forceFill($snapshot['before'])->save();
            }

            $locked->forceFill([
                'status' => 'reverted',
                'stage' => 'reverted',
                'last_message' => 'Import harga berhasil direvert.',
                'progress_percent' => 100,
                'heartbeat_at' => null,
                'reverted_at' => now(),
            ])->save();
        });
    }

    public function progressKey(int $batchId): string
    {
        return 'selling_price_group_progress_'.$batchId;
    }

    private function updateProgress(UserImportBatch $batch, int $current, int $total, string $message): void
    {
        $percent = $total > 0 ? min(95, (int) floor(($current / $total) * 95)) : 5;
        $progress = [
            'progress_current' => $current,
            'progress_total' => $total,
            'progress_percent' => $percent,
            'last_message' => $message,
            'heartbeat_at' => now()->toDateTimeString(),
        ];
        Cache::put($this->progressKey((int) $batch->id), $progress, 3600);
        UserImportBatch::where('business_id', $batch->business_id)->where('importer_key', self::IMPORTER_KEY)->whereKey($batch->id)->update([
            'progress_current' => $current,
            'progress_total' => $total,
            'progress_percent' => $percent,
            'last_message' => $message,
            'heartbeat_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function assertNoLaterSales(UserImportBatch $batch, array $variationIds, int $transactionMaxId): void
    {
        if ($variationIds === [] || ! Schema::hasTable('transaction_sell_lines')) {
            return;
        }
        $dependent = DB::table('transaction_sell_lines as lines')
            ->join('transactions', 'transactions.id', '=', 'lines.transaction_id')
            ->where('transactions.business_id', $batch->business_id)
            ->where('transactions.id', '>', $transactionMaxId)
            ->whereIn('lines.variation_id', $variationIds)
            ->exists();
        abort_if($dependent, 422, 'Produk telah dipakai pada transaksi setelah import. Revert diblokir.');
    }

    private function variationPriceAttributes(Variation $variation): array
    {
        return [
            'sell_price_inc_tax' => $variation->sell_price_inc_tax,
            'default_sell_price' => $variation->default_sell_price,
            'profit_percent' => $variation->profit_percent,
            'updated_at' => (string) $variation->updated_at,
        ];
    }

    private function modelAttributes($model): array
    {
        return json_decode(json_encode($model->getAttributes(), JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
    }
}
