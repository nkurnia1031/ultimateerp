<?php

namespace Modules\AssetManagement\Services;

use App\Services\UserImportBatchService;
use App\UserImportBatch;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Modules\AssetManagement\Entities\Asset;
use Modules\AssetManagement\Entities\HeavyEquipmentRateBatch;
use Modules\AssetManagement\Entities\HeavyEquipmentRoute;
use Modules\AssetManagement\Entities\HeavyEquipmentRouteRate;
use PhpOffice\PhpSpreadsheet\IOFactory;

class HeavyEquipmentRateImportHistoryService
{
    public const IMPORTER_KEY = 'heavy_equipment_rate';

    public function __construct(
        private UserImportBatchService $batchService,
        private HeavyEquipmentRateService $rateService
    ) {
    }

    public function createUploadBatch(UploadedFile $file, string $effectiveFrom, int $businessId, int $userId): UserImportBatch
    {
        $batch = $this->batchService->create([
            'business_id' => $businessId,
            'created_by' => $userId,
            'importer_key' => self::IMPORTER_KEY,
            'file_name' => $file->getClientOriginalName(),
            'status' => 'queued_preview',
            'stage' => 'queued',
            'progress_total' => 1,
            'options_json' => json_encode(['effective_from' => $effectiveFrom], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'last_message' => 'Upload diterima. Menunggu analisis workbook tarif.',
        ]);
        $extension = strtolower($file->getClientOriginalExtension());
        $relativeDirectory = 'heavy-equipment-rate-imports/'.$businessId.'/'.$userId;
        $stagingPath = Storage::disk('user_imports')->putFileAs($relativeDirectory, $file, $batch->id.'.'.$extension);
        if (! $stagingPath) {
            $batch->forceFill([
                'status' => 'failed',
                'stage' => 'failed',
                'last_message' => 'File upload gagal disimpan ke staging privat.',
                'error_message' => 'File upload gagal disimpan ke staging privat.',
                'failed_at' => now(),
            ])->save();
            throw new \RuntimeException('File upload gagal disimpan ke staging privat.');
        }

        $batch->forceFill(['staging_path' => $stagingPath, 'heartbeat_at' => now()])->save();

        return $batch->fresh();
    }

    public function processPreview(int $sharedBatchId): void
    {
        $batch = DB::transaction(function () use ($sharedBatchId) {
            $locked = UserImportBatch::where('importer_key', self::IMPORTER_KEY)
                ->lockForUpdate()->find($sharedBatchId);
            if (! $locked || $locked->status !== 'queued_preview') {
                return null;
            }

            $locked->forceFill([
                'status' => 'analyzing',
                'stage' => 'reading_workbook',
                'started_at' => $locked->started_at ?: now(),
                'progress_current' => 0,
                'progress_total' => 4,
                'progress_percent' => 5,
                'last_message' => 'Membaca workbook tarif.',
                'heartbeat_at' => now(),
            ])->save();

            return $locked->fresh();
        });
        if (! $batch) {
            return;
        }

        $stagingPath = (string) $batch->staging_path;
        try {
            if ($stagingPath === '' || ! Storage::disk('user_imports')->exists($stagingPath)) {
                throw new \RuntimeException('File upload tidak tersedia untuk dianalisis. Upload ulang workbook tarif.');
            }

            if (! $batch->hasSourceSnapshot()) {
                $snapshot = $this->sourceSnapshot(Storage::disk('user_imports')->path($stagingPath));
                $this->batchService->storePayload($batch, 'source_snapshot', $snapshot);
            }
            $batch->forceFill([
                'stage' => 'validating_rows',
                'progress_current' => 1,
                'progress_percent' => 25,
                'last_message' => 'Snapshot sumber tersimpan. Memeriksa baris tarif dan unit.',
                'heartbeat_at' => now(),
            ])->save();

            $options = json_decode((string) $batch->options_json, true) ?: [];
            $effectiveFrom = Carbon::createFromFormat('!Y-m-d', (string) ($options['effective_from'] ?? ''));
            $path = Storage::disk('user_imports')->path($stagingPath);
            $upload = new UploadedFile(
                $path,
                (string) $batch->file_name,
                null,
                UPLOAD_ERR_OK,
                true
            );
            $rateBatch = $this->rateService->import(
                $upload,
                $effectiveFrom,
                (int) $batch->business_id,
                (int) $batch->created_by,
                (int) $batch->id
            );

            $batch->refresh();
            $preview = [
                'rate_batch_id' => (int) $rateBatch->id,
                'source_rows' => (int) $rateBatch->source_row_count,
                'valid_rows' => (int) $rateBatch->valid_row_count,
                'unit_count' => (int) $rateBatch->unit_count,
                'route_count' => (int) $rateBatch->route_count,
                'errors' => $this->batchErrors($rateBatch),
            ];
            $this->batchService->storePayload($batch, 'preview', $preview);
            $rowErrorText = collect($preview['errors'])->map(function (array $error) {
                $message = (string) ($error['message'] ?? '');
                $sourceRow = (int) ($error['source_row'] ?? 0);

                return $message === '' ? '' : ($sourceRow > 0 ? 'Baris '.$sourceRow.': ' : '').$message;
            })->filter()->implode(' ');
            $this->syncShared(
                $batch,
                'preview_ready',
                'preview_ready',
                $preview,
                'Preview tarif siap. Cocokkan unit yang belum terhubung ke Asset, lalu konfirmasi batch.',
                $rowErrorText !== '' ? $rowErrorText : null
            );
        } catch (\Throwable $exception) {
            report($exception);
            $this->syncShared($batch, 'failed', 'failed', [], $exception->getMessage(), $exception->getMessage());
        } finally {
            $this->deleteStaging($batch, $stagingPath);
        }
    }

    public function markPreviewFailed(int $sharedBatchId, string $message): void
    {
        $batch = UserImportBatch::where('importer_key', self::IMPORTER_KEY)->find($sharedBatchId);
        if (! $batch || in_array($batch->status, ['preview_ready', 'queued_import', 'importing', 'imported', 'reverted'], true)) {
            return;
        }

        $this->syncShared($batch, 'failed', 'failed', [], 'Analisis workbook gagal.', $message);
        $this->deleteStaging($batch, (string) $batch->staging_path);
    }

    public function processImport(int $sharedBatchId): void
    {
        $batch = DB::transaction(function () use ($sharedBatchId) {
            $locked = UserImportBatch::where('importer_key', self::IMPORTER_KEY)
                ->lockForUpdate()->find($sharedBatchId);
            if (! $locked || $locked->status !== 'queued_import') {
                return null;
            }

            $locked->forceFill([
                'status' => 'importing',
                'stage' => 'activating_rates',
                'progress_current' => 0,
                'progress_total' => 1,
                'progress_percent' => 10,
                'last_message' => 'Mengaktifkan tarif dan memperbarui masa berlaku tarif sebelumnya.',
                'heartbeat_at' => now(),
            ])->save();

            return $locked->fresh();
        });
        if (! $batch) {
            return;
        }

        $domain = $this->domainForSharedBatch($batch);
        if (! $domain) {
            $this->syncShared($batch, 'failed', 'failed', [], 'Batch tarif tidak ditemukan.', 'Batch tarif tidak ditemukan untuk history import ini.');
            return;
        }
        if ($domain->status === 'active') {
            $this->markImported($batch, $domain);

            return;
        }
        if ($domain->status !== 'draft') {
            $this->syncShared($batch, 'failed', 'failed', [], 'Batch tarif tidak lagi berstatus draft.', 'Batch tarif tidak lagi berstatus draft.');
            return;
        }

        try {
            $this->rateService->confirm($domain);
            $this->markImported($batch->fresh(), $domain->fresh());
        } catch (\Throwable $exception) {
            report($exception);
            $domain->refresh();
            if ($domain->status === 'active') {
                $this->markImported($batch->fresh(), $domain);
                return;
            }
            $this->syncShared($batch->fresh(), 'failed', 'failed', [], 'Aktivasi batch gagal. Perbaiki detail lalu gunakan Coba lagi.', $exception->getMessage());
        }
    }

    public function markImportFailed(int $sharedBatchId, string $message): void
    {
        $batch = UserImportBatch::where('importer_key', self::IMPORTER_KEY)->find($sharedBatchId);
        if (! $batch || in_array($batch->status, ['imported', 'reverted'], true)) {
            return;
        }
        $domain = $this->domainForSharedBatch($batch);
        if ($domain && $domain->status === 'active') {
            $this->markImported($batch, $domain);
            return;
        }

        $this->syncShared($batch, 'failed', 'failed', [], 'Aktivasi batch gagal.', $message);
    }

    public function ensureHistoryEnvelopes(int $businessId): void
    {
        HeavyEquipmentRateBatch::where('business_id', $businessId)
            ->whereNull('user_import_batch_id')
            ->orderBy('id')
            ->chunkById(100, function ($batches): void {
                foreach ($batches as $domainBatch) {
                    $this->ensureHistoryEnvelope($domainBatch);
                }
            });
    }

    public function ensureHistoryEnvelope(HeavyEquipmentRateBatch $domainBatch): UserImportBatch
    {
        return DB::transaction(function () use ($domainBatch) {
            $locked = HeavyEquipmentRateBatch::where('business_id', $domainBatch->business_id)
                ->lockForUpdate()->findOrFail($domainBatch->id);
            if ($locked->user_import_batch_id) {
                return $this->batchService->findForBusiness(
                    (int) $locked->user_import_batch_id,
                    (int) $locked->business_id,
                    self::IMPORTER_KEY
                );
            }

            $status = match ($locked->status) {
                'active' => 'imported',
                'cancelled' => 'reverted',
                default => 'preview_ready',
            };
            $summary = [
                'rate_batch_id' => (int) $locked->id,
                'source_rows' => (int) $locked->source_row_count,
                'valid_rows' => (int) $locked->valid_row_count,
                'unit_count' => (int) $locked->unit_count,
                'route_count' => (int) $locked->route_count,
                'legacy' => true,
            ];
            $shared = $this->batchService->create([
                'business_id' => (int) $locked->business_id,
                'created_by' => $locked->uploaded_by,
                'importer_key' => self::IMPORTER_KEY,
                'file_name' => $locked->file_name,
                'status' => $status,
                'stage' => $status,
                'progress_current' => in_array($status, ['imported', 'reverted'], true) ? 1 : 0,
                'progress_total' => 1,
                'progress_percent' => in_array($status, ['imported', 'reverted'], true) ? 100 : 0,
                'summary_json' => json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'last_message' => $status === 'imported' ? 'Batch tarif lama sudah aktif.' : ($status === 'reverted' ? 'Batch tarif lama sudah dibatalkan.' : 'Batch draft tarif lama.'),
                'error_message' => $locked->error_message,
                'created_at' => $locked->created_at,
                'updated_at' => $locked->updated_at,
                'completed_at' => in_array($status, ['imported', 'reverted'], true) ? ($locked->imported_at ?: $locked->updated_at) : null,
                'reverted_at' => $status === 'reverted' ? $locked->updated_at : null,
            ]);
            $locked->update(['user_import_batch_id' => $shared->id]);

            return $shared;
        });
    }

    public function syncFromDomainBatch(int $domainBatchId): ?UserImportBatch
    {
        $domain = HeavyEquipmentRateBatch::find($domainBatchId);
        if (! $domain) {
            return null;
        }
        $shared = $domain->user_import_batch_id
            ? UserImportBatch::where('business_id', $domain->business_id)->where('importer_key', self::IMPORTER_KEY)->find($domain->user_import_batch_id)
            : $this->ensureHistoryEnvelope($domain);
        if (! $shared) {
            return null;
        }

        $status = match ($domain->status) {
            'active' => 'imported',
            'cancelled' => 'reverted',
            default => in_array($shared->status, ['queued_preview', 'analyzing', 'queued_import', 'importing'], true) ? $shared->status : 'preview_ready',
        };
        $summary = $this->summaryForDomain($domain);
        $shared->forceFill([
            'status' => $status,
            'stage' => $status,
            'summary_json' => json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'progress_current' => in_array($status, ['imported', 'reverted'], true) ? max(1, (int) $domain->valid_row_count) : 0,
            'progress_total' => max(1, (int) $domain->source_row_count),
            'progress_percent' => in_array($status, ['imported', 'reverted'], true) ? 100 : 0,
            'last_message' => $status === 'imported' ? 'Batch tarif aktif.' : ($status === 'reverted' ? 'Batch tarif dibatalkan.' : 'Preview tarif siap.'),
            'error_message' => $domain->error_message,
            'completed_at' => $status === 'imported' ? ($domain->imported_at ?: now()) : null,
            'reverted_at' => $status === 'reverted' ? $domain->updated_at : null,
            'updated_at' => $domain->updated_at ?: now(),
        ])->save();

        return $shared->fresh();
    }

    public function dataTable(Request $request, int $businessId, bool $canManage): array
    {
        $this->ensureHistoryEnvelopes($businessId);
        $data = $this->batchService->dataTable($request, $businessId, self::IMPORTER_KEY);
        $sharedIds = collect($data['data'])->pluck('id')->all();
        $sharedById = UserImportBatch::whereIn('id', $sharedIds)->get()->keyBy('id');
        $domainBySharedId = HeavyEquipmentRateBatch::where('business_id', $businessId)
            ->whereIn('user_import_batch_id', $sharedIds)->get()->keyBy('user_import_batch_id');

        $data['data'] = collect($data['data'])->map(function (array $row) use ($domainBySharedId, $sharedById, $canManage) {
            $domain = $domainBySharedId->get($row['id']);
            $shared = $sharedById->get($row['id']);
            $row['domain_batch_id'] = (int) ($domain->id ?? 0);
            $row['domain_status'] = $domain->status ?? null;
            $row['effective_from'] = $domain?->effective_from?->format('Y-m-d');
            $row['active'] = $shared?->isActive() ?? false;
            $hasSnapshot = (bool) $row['has_snapshot'] && filled($shared?->source_snapshot_path)
                && Storage::disk('user_imports')->exists((string) $shared->source_snapshot_path);
            $row['has_snapshot'] = $hasSnapshot;
            $row['can_download'] = $hasSnapshot;
            $row['can_preview'] = (bool) $domain && $domain->status === 'draft' && $row['status'] === 'preview_ready';
            $row['can_confirm'] = $canManage && (bool) $domain && $domain->status === 'draft' && $row['status'] === 'preview_ready';
            $row['can_cancel'] = $canManage && (bool) $domain && $domain->status === 'draft' && $row['status'] === 'preview_ready';
            $row['can_retry'] = $canManage && (bool) $domain && $domain->status === 'draft'
                && $row['status'] === 'failed' && filled($row['preview_path']) && $hasSnapshot;
            $row['can_revert'] = $canManage && (bool) $domain && $domain->status === 'active'
                && $row['status'] === 'imported' && filled($domain->revert_snapshot_json);
            $row['can_details'] = (bool) $domain;
            $row['result_summary'] = $domain
                ? ((int) $domain->valid_row_count).' tarif / '.((int) $domain->unit_count).' unit / '.((int) $domain->source_row_count).' baris sumber'
                : null;

            return $row;
        })->values();

        return $data;
    }

    public function domainBatchForSharedBatch(int $sharedBatchId, int $businessId): HeavyEquipmentRateBatch
    {
        $shared = $this->batchService->findForBusiness($sharedBatchId, $businessId, self::IMPORTER_KEY);
        $domain = HeavyEquipmentRateBatch::where('business_id', $businessId)
            ->where('user_import_batch_id', $shared->id)->first();
        abort_if(! $domain, 404, 'Batch tarif tidak ditemukan.');

        return $domain;
    }

    public function details(int $sharedBatchId, int $businessId): array
    {
        $shared = $this->batchService->findForBusiness($sharedBatchId, $businessId, self::IMPORTER_KEY);
        $domain = $this->domainBatchForSharedBatch($sharedBatchId, $businessId);
        $errors = $this->batchErrors($domain);
        $rows = HeavyEquipmentRouteRate::with('units')
            ->where('business_id', $businessId)->where('batch_id', $domain->id)
            ->orderBy('source_row')->orderBy('id')->get();
        $routeNames = HeavyEquipmentRoute::where('business_id', $businessId)
            ->whereIn('id', $rows->pluck('route_id')->unique()->values()->all())
            ->pluck('name', 'id');
        $assetNames = Asset::where('business_id', $businessId)
            ->whereIn('id', $rows->flatMap(fn (HeavyEquipmentRouteRate $rate) => $rate->units->pluck('asset_id'))->filter()->unique()->values()->all())
            ->pluck('name', 'id');
        $rows = $rows->map(function (HeavyEquipmentRouteRate $rate) use ($errors, $routeNames, $assetNames) {
                $rowErrors = array_values(array_filter($errors, fn ($error) => (int) ($error['source_row'] ?? 0) === (int) $rate->source_row));

                return [
                    'source_row' => (int) $rate->source_row,
                    'route' => $routeNames->get($rate->route_id),
                    'unit_source' => $rate->source_unit_text,
                    'units' => $rate->units->map(fn ($unit) => [
                        'label' => $unit->unit_label,
                        'asset_id' => $unit->asset_id && $assetNames->has($unit->asset_id) ? (int) $unit->asset_id : null,
                        'asset' => $unit->asset_id ? $assetNames->get($unit->asset_id) : null,
                    ])->values(),
                    'driver_rate_per_ton' => $rate->driver_rate_per_ton,
                    'travel_allowance' => $rate->travel_allowance,
                    'sale_rate_per_ton' => $rate->sale_rate_per_ton,
                    'notes' => $rate->notes,
                    'status' => $rate->status,
                    'errors' => $rowErrors,
                ];
            })->values()->all();
        foreach ($errors as $error) {
            if (empty($error['source_row']) || ! collect($rows)->contains(fn (array $row) => (int) $row['source_row'] === (int) $error['source_row'])) {
                $rows[] = ['source_row' => $error['source_row'], 'status' => 'error', 'errors' => [$error]];
            }
        }

        return [
            'batch' => [
                'id' => (int) $shared->id,
                'domain_batch_id' => (int) $domain->id,
                'file_name' => $shared->file_name,
                'status' => $shared->status,
                'summary' => json_decode((string) $shared->summary_json, true) ?: [],
                'message' => $shared->last_message,
                'error' => $shared->error_message,
                'created_at' => optional($shared->created_at)->format('Y-m-d H:i:s'),
            ],
            'rows' => collect($rows)->sortBy('source_row')->values()->all(),
        ];
    }

    public function cancelOrRevert(int $sharedBatchId, int $businessId): UserImportBatch
    {
        $domain = $this->domainBatchForSharedBatch($sharedBatchId, $businessId);
        $this->rateService->cancel($domain);

        return $this->syncFromDomainBatch((int) $domain->id)
            ?: $this->batchService->findForBusiness($sharedBatchId, $businessId, self::IMPORTER_KEY);
    }

    public function batchErrors(HeavyEquipmentRateBatch $batch): array
    {
        $errors = json_decode((string) $batch->error_message, true);
        if (! is_array($errors)) {
            return [];
        }

        return array_values(array_map(function ($error) {
            $message = is_array($error) ? (string) ($error['message'] ?? '') : (string) $error;
            preg_match('/^Baris\s+(\d+):\s*(.*)$/u', $message, $matches);

            return [
                'source_row' => isset($matches[1]) ? (int) $matches[1] : null,
                'message' => $matches[2] ?? $message,
            ];
        }, $errors));
    }

    private function sourceSnapshot(string $path): array
    {
        $spreadsheet = IOFactory::load($path);
        try {
            $sheets = [];
            foreach ($spreadsheet->getAllSheets() as $worksheet) {
                $sheets[] = [
                    'title' => $worksheet->getTitle(),
                    'rows' => $worksheet->toArray(null, true, true, true),
                ];
            }

            return ['sheets' => $sheets];
        } finally {
            $spreadsheet->disconnectWorksheets();
        }
    }

    private function domainForSharedBatch(UserImportBatch $batch): ?HeavyEquipmentRateBatch
    {
        return HeavyEquipmentRateBatch::where('business_id', $batch->business_id)
            ->where('user_import_batch_id', $batch->id)->first();
    }

    private function summaryForDomain(HeavyEquipmentRateBatch $domain): array
    {
        return [
            'rate_batch_id' => (int) $domain->id,
            'source_rows' => (int) $domain->source_row_count,
            'valid_rows' => (int) $domain->valid_row_count,
            'unit_count' => (int) $domain->unit_count,
            'route_count' => (int) $domain->route_count,
            'error_count' => count($this->batchErrors($domain)),
        ];
    }

    private function markImported(UserImportBatch $batch, HeavyEquipmentRateBatch $domain): void
    {
        $summary = $this->summaryForDomain($domain);
        $total = max(1, (int) $domain->source_row_count);
        $batch->forceFill([
            'status' => 'imported',
            'stage' => 'imported',
            'progress_current' => $total,
            'progress_total' => $total,
            'progress_percent' => 100,
            'summary_json' => json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'last_message' => 'Semua tarif pada batch berhasil diaktifkan.',
            'error_message' => null,
            'completed_at' => $domain->imported_at ?: now(),
            'failed_at' => null,
            'heartbeat_at' => now(),
        ])->save();
    }

    private function syncShared(UserImportBatch $batch, string $status, string $stage, array $summary, string $message, ?string $error = null): void
    {
        $complete = in_array($status, ['preview_ready', 'imported', 'reverted'], true);
        $progressTotal = max(1, (int) ($summary['source_rows'] ?? $batch->progress_total ?? 1));
        $batch->forceFill([
            'status' => $status,
            'stage' => $stage,
            'progress_current' => $complete ? (int) ($summary['source_rows'] ?? $progressTotal) : (int) ($batch->progress_current ?? 0),
            'progress_total' => $progressTotal,
            'progress_percent' => $complete ? 100 : (int) ($batch->progress_percent ?? 0),
            'summary_json' => json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'last_message' => $message,
            'error_message' => $error,
            'heartbeat_at' => now(),
            'completed_at' => $status === 'imported' ? now() : null,
            'failed_at' => $status === 'failed' ? now() : null,
        ])->save();
    }

    private function deleteStaging(UserImportBatch $batch, string $path): void
    {
        if ($path !== '' && Storage::disk('user_imports')->exists($path)) {
            try {
                if (! Storage::disk('user_imports')->delete($path)) {
                    throw new \RuntimeException('File staging tarif gagal dihapus: '.$path);
                }
            } catch (\Throwable $exception) {
                report($exception);
            }
        }
        $batch->forceFill(['staging_path' => null, 'heartbeat_at' => now()])->save();
    }
}
