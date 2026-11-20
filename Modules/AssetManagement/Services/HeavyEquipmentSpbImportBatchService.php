<?php

namespace Modules\AssetManagement\Services;

use App\Services\UserImportBatchService;
use App\UserImportBatch;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Modules\AssetManagement\Entities\HeavyEquipmentRoute;
use Modules\AssetManagement\Entities\HeavyEquipmentRouteRate;
use Modules\AssetManagement\Entities\HeavyEquipmentTrip;

class HeavyEquipmentSpbImportBatchService
{
    public function __construct(
        private HeavyEquipmentSpbImportService $importService,
        private HeavyEquipmentTripService $tripService,
        private UserImportBatchService $batchService
    ) {
    }

    public function processPreview(int $batchId): void
    {
        $updated = UserImportBatch::where('id', $batchId)
            ->where('importer_key', 'spb')
            ->where('status', 'queued_preview')
            ->update([
                'status' => 'analyzing',
                'stage' => 'reading_workbook',
                'last_message' => 'Workbook sedang dibaca di background.',
                'started_at' => now(),
                'heartbeat_at' => now(),
                'updated_at' => now(),
            ]);
        if ($updated < 1) {
            return;
        }

        $batch = UserImportBatch::where('id', $batchId)->where('importer_key', 'spb')->first();
        try {
            if (! $batch || ! $batch->staging_path || ! Storage::disk('local')->exists($batch->staging_path)) {
                throw new \RuntimeException('File staging import SPB tidak ditemukan.');
            }

            $preview = $this->importService->previewPath(
                Storage::disk('local')->path($batch->staging_path),
                (string) $batch->file_name,
                (int) $batch->business_id,
                function (int $current, int $total, string $sheet, $rowNumber) use ($batchId): void {
                    $percent = $total > 0 ? min(90, (int) floor(90 * $current / $total)) : 0;
                    UserImportBatch::whereKey($batchId)->update([
                        'stage' => 'analyzing',
                        'progress_current' => $current,
                        'progress_total' => $total,
                        'progress_percent' => $percent,
                        'last_message' => 'Menganalisis sheet '.$sheet.' baris '.$rowNumber.'.',
                        'heartbeat_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            );
            $sourceSnapshot = $preview['source_snapshot'] ?? ['sheets' => []];
            $fatalError = $preview['fatal_error'] ?? null;
            $analysisTotal = (int) ($preview['analysis_total_rows'] ?? 0);
            unset($preview['source_snapshot'], $preview['fatal_error'], $preview['analysis_total_rows']);

            $this->batchService->storePayload($batch, 'source_snapshot', $sourceSnapshot);
            $this->batchService->storePayload($batch, 'preview', $preview);
            $summary = $preview['summary'] ?? [];
            $isFailed = $fatalError !== null;
            $batch->forceFill([
                'status' => $isFailed ? 'failed' : 'preview_ready',
                'stage' => $isFailed ? 'failed' : 'preview_ready',
                'progress_current' => $analysisTotal,
                'progress_total' => $analysisTotal,
                'progress_percent' => 100,
                'summary_json' => json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'last_message' => $isFailed ? $fatalError : 'Preview siap diperiksa.',
                'error_message' => $isFailed ? $fatalError : null,
                'completed_at' => now(),
                'failed_at' => $isFailed ? now() : null,
                'heartbeat_at' => now(),
                'updated_at' => now(),
            ])->save();
        } catch (\Throwable $exception) {
            report($exception);
            $this->markFailed($batchId, $exception->getMessage());
        } finally {
            if ($batch && $batch->staging_path) {
                Storage::disk('local')->delete($batch->staging_path);
                UserImportBatch::whereKey($batchId)->update(['staging_path' => null, 'updated_at' => now()]);
            }
        }
    }

    public function processImport(int $batchId): void
    {
        $updated = UserImportBatch::where('id', $batchId)
            ->where('importer_key', 'spb')
            ->where('status', 'queued_import')
            ->update([
                'status' => 'importing',
                'stage' => 'saving_rows',
                'last_message' => 'Import SPB sedang berjalan di background.',
                'started_at' => now(),
                'failed_at' => null,
                'heartbeat_at' => now(),
                'updated_at' => now(),
            ]);
        if ($updated < 1) {
            return;
        }

        $batch = UserImportBatch::where('id', $batchId)->where('importer_key', 'spb')->firstOrFail();
        try {
            $preview = $this->batchService->readPayload($batch, 'preview');
            $rows = array_values($preview['rows'] ?? []);
            $options = json_decode((string) $batch->options_json, true) ?: [];
            $defaultRouteId = ! empty($options['default_route_id']) ? (int) $options['default_route_id'] : null;
            if ($defaultRouteId) {
                HeavyEquipmentRoute::where('business_id', $batch->business_id)->findOrFail($defaultRouteId);
            }

            $results = $this->readResults($batch);
            $totalRows = count($rows);
            $businessId = (int) $batch->business_id;
            $userId = (int) $batch->created_by;
            UserImportBatch::whereKey($batchId)->update([
                'progress_current' => 0,
                'progress_total' => $totalRows,
                'progress_percent' => 0,
                'summary_json' => json_encode(['source_rows' => $totalRows], JSON_UNESCAPED_UNICODE),
                'updated_at' => now(),
            ]);

            foreach ($rows as $index => $row) {
                $sourceKey = (string) ($row['source_fingerprint'] ?? 'row-'.$index);
                if (($results[$sourceKey]['status'] ?? null) === 'imported') {
                    $this->updateProgress($batchId, $index + 1, $totalRows, $results);
                    continue;
                }

                $existingTrip = HeavyEquipmentTrip::where('business_id', $businessId)
                    ->where('import_batch_id', $batchId)
                    ->where('source_fingerprint', $sourceKey)
                    ->first();
                if ($existingTrip) {
                    $results[$sourceKey] = $this->importedResult($row, $existingTrip, null);
                    $this->batchService->storePayload($batch, 'results', $results);
                    $this->updateProgress($batchId, $index + 1, $totalRows, $results);
                    continue;
                }

                if (! empty($row['row_errors'])) {
                    $results[$sourceKey] = [
                        'status' => 'error',
                        'source_sheet' => $row['source_sheet'] ?? '',
                        'source_row' => $row['source_row'] ?? null,
                        'ticket_no' => $row['ticket_no'] ?? null,
                        'error' => implode(' ', $row['row_errors']),
                    ];
                    $this->batchService->storePayload($batch, 'results', $results);
                    $this->updateProgress($batchId, $index + 1, $totalRows, $results);
                    continue;
                }

                try {
                    $created = DB::transaction(function () use ($row, $businessId, $userId, $defaultRouteId, $batchId) {
                        $prepared = $this->importService->prepareForImport($row, $businessId, $defaultRouteId);
                        if (! empty($prepared['row_errors'])) {
                            throw new \RuntimeException(implode(' ', $prepared['row_errors']));
                        }
                        if (empty($prepared['route_id']) || empty($prepared['asset_id']) || empty($prepared['driver_id'])) {
                            throw new \RuntimeException('Rute, unit, dan sopir wajib cocok sebelum import.');
                        }
                        $prepared['import_batch_id'] = $batchId;
                        if (! empty($prepared['_created_route_id'])) {
                            HeavyEquipmentRoute::where('business_id', $businessId)
                                ->whereKey($prepared['_created_route_id'])
                                ->whereNull('source_import_batch_id')
                                ->update(['source_import_batch_id' => $batchId]);
                        }

                        return [
                            'trip' => $this->tripService->create($prepared, $businessId, $userId),
                            'created_route_id' => $prepared['_created_route_id'] ?? null,
                        ];
                    });
                    $results[$sourceKey] = $this->importedResult($row, $created['trip'], $created['created_route_id']);
                } catch (\Throwable $rowException) {
                    report($rowException);
                    $results[$sourceKey] = [
                        'status' => 'error',
                        'source_sheet' => $row['source_sheet'] ?? '',
                        'source_row' => $row['source_row'] ?? null,
                        'ticket_no' => $row['ticket_no'] ?? null,
                        'error' => $rowException->getMessage(),
                    ];
                }

                $this->batchService->storePayload($batch, 'results', $results);
                $this->updateProgress($batchId, $index + 1, $totalRows, $results);
            }

            $importedCount = count(array_filter($results, fn (array $result) => ($result['status'] ?? null) === 'imported'));
            $errorCount = count(array_filter($results, fn (array $result) => ($result['status'] ?? null) === 'error'));
            $status = $errorCount === 0 ? 'imported' : ($importedCount > 0 ? 'partial' : 'failed');
            $message = $status === 'imported'
                ? $importedCount.' SPB berhasil diimpor.'
                : $importedCount.' SPB diimpor; '.$errorCount.' baris perlu diperbaiki.';
            UserImportBatch::whereKey($batchId)->update([
                'status' => $status,
                'stage' => $status,
                'progress_current' => $totalRows,
                'progress_total' => $totalRows,
                'progress_percent' => 100,
                'summary_json' => json_encode([
                    'source_rows' => $totalRows,
                    'imported_rows' => $importedCount,
                    'error_rows' => $errorCount,
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'last_message' => $message,
                'error_message' => $errorCount > 0 ? $this->formatResultErrors($results) : null,
                'completed_at' => now(),
                'failed_at' => $status === 'failed' ? now() : null,
                'heartbeat_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (\Throwable $exception) {
            report($exception);
            $this->markFailed($batchId, $exception->getMessage());
        }
    }

    public function markFailed(int $batchId, string $message): void
    {
        $batch = UserImportBatch::whereKey($batchId)->where('importer_key', 'spb')->first();
        if (! $batch || in_array($batch->status, ['imported', 'partial', 'reverted'], true)) {
            return;
        }

        $batch->forceFill([
            'status' => 'failed',
            'stage' => 'failed',
            'last_message' => 'Proses import gagal.',
            'error_message' => mb_substr($message, 0, 60000, 'UTF-8'),
            'failed_at' => now(),
            'heartbeat_at' => now(),
            'updated_at' => now(),
        ])->save();
    }

    public function revert(UserImportBatch $batch, int $businessId): array
    {
        if (! in_array($batch->status, ['imported', 'partial'], true)) {
            throw new \RuntimeException('Hanya batch yang selesai atau selesai sebagian yang dapat di-revert.');
        }

        return DB::transaction(function () use ($batch, $businessId) {
            $lockedBatch = UserImportBatch::where('business_id', $businessId)
                ->where('importer_key', 'spb')->lockForUpdate()->findOrFail($batch->id);
            if (! in_array($lockedBatch->status, ['imported', 'partial'], true)) {
                throw new \RuntimeException('Status batch berubah; muat ulang riwayat sebelum revert.');
            }

            $results = $this->readResults($lockedBatch);
            $imported = array_filter($results, fn (array $result) => ($result['status'] ?? null) === 'imported');
            $tripIds = array_values(array_unique(array_map(fn (array $result) => (int) ($result['trip_id'] ?? 0), $imported)));
            $tripIds = array_values(array_filter($tripIds));
            $trips = HeavyEquipmentTrip::where('business_id', $businessId)
                ->where('import_batch_id', $lockedBatch->id)
                ->whereIn('id', $tripIds)
                ->lockForUpdate()
                ->get();

            $blocked = [];
            foreach ($trips as $trip) {
                $expected = null;
                foreach ($imported as $result) {
                    if ((int) ($result['trip_id'] ?? 0) === (int) $trip->id) {
                        $expected = $result['post_import_fingerprint'] ?? null;
                        break;
                    }
                }
                if (! $trip->canBeChanged() || ! $expected || ! hash_equals($expected, $this->tripFingerprint($trip))) {
                    $blocked[] = $trip->trip_no;
                }
            }
            if (count($trips) !== count($tripIds)) {
                $blocked[] = 'SPB yang sudah tidak tersedia';
            }
            if ($blocked !== []) {
                throw new \RuntimeException('Revert diblokir; ada data SPB yang berubah, tidak dapat diubah, sudah ditagihkan, atau masuk payroll: '.implode(', ', array_unique($blocked)).'.');
            }

            // Route provenance is stored in the database with the trip transaction so
            // a worker retry can still revert routes if it stopped before saving results.
            $createdRouteIds = HeavyEquipmentRoute::where('business_id', $businessId)
                ->where('source_import_batch_id', $lockedBatch->id)
                ->pluck('id')->map(fn ($id) => (int) $id)->all();
            if ($createdRouteIds !== []) {
                $dependentRouteIds = HeavyEquipmentRouteRate::whereIn('route_id', $createdRouteIds)->pluck('route_id')->all();
                if ($dependentRouteIds !== []) {
                    throw new \RuntimeException('Revert diblokir; rute impor sudah dipakai tarif SPB: '.implode(', ', $dependentRouteIds).'.');
                }
                $otherTrips = HeavyEquipmentTrip::where('business_id', $businessId)
                    ->whereIn('route_id', $createdRouteIds)
                    ->whereNotIn('id', $tripIds)
                    ->pluck('trip_no')->all();
                if ($otherTrips !== []) {
                    throw new \RuntimeException('Revert diblokir; rute impor sudah dipakai SPB lain: '.implode(', ', $otherTrips).'.');
                }
                $otherVendorLoadings = DB::table('heavy_equipment_vendor_loadings')
                    ->where('business_id', $businessId)
                    ->whereIn('route_id', $createdRouteIds)
                    ->pluck('loading_no')->all();
                if ($otherVendorLoadings !== []) {
                    throw new \RuntimeException('Revert diblokir; rute impor sudah dipakai Loading Vendor: '.implode(', ', $otherVendorLoadings).'.');
                }
            }

            foreach ($trips as $trip) {
                $trip->delete();
            }
            foreach ($createdRouteIds as $routeId) {
                HeavyEquipmentRoute::where('business_id', $businessId)->whereKey($routeId)->delete();
            }

            $lockedBatch->forceFill([
                'status' => 'reverted',
                'stage' => 'reverted',
                'last_message' => count($trips).' SPB berhasil di-revert.',
                'error_message' => null,
                'reverted_at' => now(),
                'heartbeat_at' => now(),
                'updated_at' => now(),
            ])->save();

            return ['reverted_trips' => count($trips), 'trip_numbers' => $trips->pluck('trip_no')->all()];
        });
    }

    private function readResults(UserImportBatch $batch): array
    {
        if (! $batch->results_path || ! Storage::disk('local')->exists($batch->results_path)) {
            return [];
        }

        return $this->batchService->readPayload($batch, 'results');
    }

    private function importedResult(array $row, HeavyEquipmentTrip $trip, ?int $createdRouteId): array
    {
        return [
            'status' => 'imported',
            'source_sheet' => $row['source_sheet'] ?? '',
            'source_row' => $row['source_row'] ?? null,
            'ticket_no' => $row['ticket_no'] ?? null,
            'trip_id' => (int) $trip->id,
            'trip_no' => (string) $trip->trip_no,
            'created_route_id' => $createdRouteId,
            'post_import_fingerprint' => $this->tripFingerprint($trip),
        ];
    }

    private function tripFingerprint(HeavyEquipmentTrip $trip): string
    {
        // The freshly-created Eloquent instance may not contain database defaults.
        // Revert later reads the persisted row, so fingerprint the same representation
        // that the safety check will compare.
        if ($trip->exists) {
            $trip->refresh();
        }

        $attributes = $trip->getAttributes();
        unset($attributes['created_at'], $attributes['updated_at']);
        ksort($attributes);

        return hash('sha256', json_encode($attributes, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION));
    }

    private function updateProgress(int $batchId, int $current, int $total, array $results): void
    {
        $imported = count(array_filter($results, fn (array $result) => ($result['status'] ?? null) === 'imported'));
        $errors = count(array_filter($results, fn (array $result) => ($result['status'] ?? null) === 'error'));
        UserImportBatch::whereKey($batchId)->update([
            'progress_current' => $current,
            'progress_total' => $total,
            'progress_percent' => $total > 0 ? (int) floor(100 * $current / $total) : 100,
            'summary_json' => json_encode(['source_rows' => $total, 'imported_rows' => $imported, 'error_rows' => $errors], JSON_UNESCAPED_UNICODE),
            'last_message' => "Memproses baris {$current} dari {$total}; {$imported} berhasil, {$errors} perlu perhatian.",
            'heartbeat_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function formatResultErrors(array $results): string
    {
        $errors = [];
        foreach ($results as $result) {
            if (($result['status'] ?? null) !== 'error') {
                continue;
            }
            $errors[] = 'Sheet '.($result['source_sheet'] ?? '-').' baris '.($result['source_row'] ?? '-').': '.($result['error'] ?? 'Import gagal.');
        }

        return implode("\n", array_slice($errors, 0, 100));
    }
}
