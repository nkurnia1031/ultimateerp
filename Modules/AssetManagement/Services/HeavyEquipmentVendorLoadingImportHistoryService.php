<?php

namespace Modules\AssetManagement\Services;

use App\Services\UserImportBatchService;
use App\UserImportBatch;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class HeavyEquipmentVendorLoadingImportHistoryService
{
    public const IMPORTER_KEY = 'vendor_loading';

    public function __construct(private UserImportBatchService $batchService)
    {
    }

    public function createForDomainBatch(int $domainBatchId, int $businessId, int $userId, string $fileName): UserImportBatch
    {
        return DB::transaction(function () use ($domainBatchId, $businessId, $userId, $fileName) {
            $domainBatch = DB::table('heavy_equipment_vendor_loading_import_batches')
                ->where('business_id', $businessId)
                ->lockForUpdate()
                ->find($domainBatchId);
            abort_if(! $domainBatch, 404, 'Batch Vendor Loading tidak ditemukan.');

            if ($domainBatch->user_import_batch_id) {
                return $this->batchService->findForBusiness((int) $domainBatch->user_import_batch_id, $businessId, self::IMPORTER_KEY);
            }

            $batch = $this->batchService->create([
                'business_id' => $businessId,
                'created_by' => $userId,
                'importer_key' => self::IMPORTER_KEY,
                'file_name' => $fileName,
                'status' => 'queued_preview',
                'stage' => 'queued',
                'last_message' => 'File diterima. Menunggu analisis workbook di queue imports.',
            ]);

            DB::table('heavy_equipment_vendor_loading_import_batches')
                ->where('id', $domainBatchId)
                ->whereNull('user_import_batch_id')
                ->update(['user_import_batch_id' => $batch->id, 'updated_at' => now()]);

            return $batch;
        });
    }

    public function ensureHistoryEnvelopes(int $businessId): void
    {
        DB::table('heavy_equipment_vendor_loading_import_batches')
            ->where('business_id', $businessId)
            ->whereNull('user_import_batch_id')
            ->orderBy('id')
            ->chunkById(100, function ($domainBatches) use ($businessId): void {
                foreach ($domainBatches as $domainBatch) {
                    $batch = $this->createForDomainBatch(
                        (int) $domainBatch->id,
                        $businessId,
                        (int) $domainBatch->created_by,
                        (string) $domainBatch->file_name
                    );
                    $this->copyLegacyPayloads($domainBatch, $batch);
                    $this->syncFromDomainBatch((int) $domainBatch->id);
                }
            });
    }

    public function copyLegacyPayloads(object $domainBatch, UserImportBatch $batch): void
    {
        try {
            if (! $batch->preview_path && ! empty($domainBatch->preview_path)
                && Storage::disk('user_imports')->exists($domainBatch->preview_path)) {
                $preview = json_decode(Storage::disk('user_imports')->get($domainBatch->preview_path), true, 512, JSON_THROW_ON_ERROR);
                $this->batchService->storePayload($batch, 'preview', $preview);
            }

            if (! $batch->results_path && ! empty($domainBatch->results_json)) {
                $results = json_decode($domainBatch->results_json, true, 512, JSON_THROW_ON_ERROR) ?: [];
                $this->batchService->storePayload($batch, 'results', $results);
            }
        } catch (\Throwable $exception) {
            report($exception);
        }
    }

    public function storePayloadForDomainBatch(int $domainBatchId, string $key, array $payload): void
    {
        $batch = $this->sharedBatchForDomainBatch($domainBatchId);
        if ($batch) {
            $this->batchService->storePayload($batch, $key, $payload);
        }
    }

    public function syncFromDomainBatch(int $domainBatchId, array $liveProgress = []): ?UserImportBatch
    {
        $domainBatch = DB::table('heavy_equipment_vendor_loading_import_batches')->find($domainBatchId);
        if (! $domainBatch || ! $domainBatch->user_import_batch_id) {
            return null;
        }

        $batch = UserImportBatch::where('business_id', $domainBatch->business_id)
            ->where('importer_key', self::IMPORTER_KEY)
            ->find($domainBatch->user_import_batch_id);
        if (! $batch) {
            return null;
        }

        $domainStatus = (string) $domainBatch->status;
        $status = $domainStatus === 'reversed' ? 'reverted' : $domainStatus;
        $total = (int) ($domainBatch->total_rows ?: $domainBatch->total_sheets);
        $current = (int) ($liveProgress['processed_rows'] ?? ($domainBatch->processed_rows ?: $domainBatch->processed_sheets));
        $terminal = in_array($status, ['imported', 'partial', 'failed', 'expired', 'reverted'], true);
        $percent = $terminal ? 100 : ($total > 0 ? min(99, (int) floor(100 * $current / $total)) : 0);
        $summary = [
            'sheets' => (int) $domainBatch->total_sheets,
            'source_rows' => (int) $domainBatch->total_rows,
            'ready_rows' => (int) $domainBatch->ready_rows,
            'issue_rows' => (int) $domainBatch->issue_rows,
            'imported_documents' => (int) $domainBatch->imported_documents,
            'imported_lines' => (int) $domainBatch->imported_lines,
            'skipped_rows' => (int) $domainBatch->skipped_rows,
            'reversed_documents' => (int) ($domainBatch->reversed_documents ?? 0),
            'reversed_lines' => (int) ($domainBatch->reversed_lines ?? 0),
        ];
        $finishedAt = $domainBatch->finished_at ?? null;
        $heartbeat = $liveProgress['heartbeat_at'] ?? $domainBatch->last_heartbeat_at;
        if (is_numeric($heartbeat)) {
            $heartbeat = Carbon::createFromTimestamp((int) $heartbeat);
        }

        $batch->forceFill([
            'status' => $status,
            'stage' => $liveProgress['stage'] ?? $domainBatch->stage,
            'progress_current' => min($total, $current),
            'progress_total' => $total,
            'progress_percent' => $percent,
            'summary_json' => json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'last_message' => $liveProgress['last_message'] ?? $domainBatch->last_message,
            'error_message' => $domainBatch->error_message,
            'heartbeat_at' => $heartbeat,
            'started_at' => $domainBatch->started_at ?: $batch->started_at,
            'completed_at' => in_array($status, ['imported', 'partial', 'reverted'], true) ? ($finishedAt ?: now()) : $batch->completed_at,
            'failed_at' => $status === 'failed' ? ($finishedAt ?: now()) : null,
            'reverted_at' => $domainBatch->reversed_at ?? null,
            'updated_at' => $domainBatch->updated_at ?: now(),
        ])->save();

        return $batch->fresh();
    }

    public function sharedBatchForDomainBatch(int $domainBatchId): ?UserImportBatch
    {
        $sharedId = DB::table('heavy_equipment_vendor_loading_import_batches')
            ->where('id', $domainBatchId)
            ->value('user_import_batch_id');
        if (! $sharedId) {
            return null;
        }

        return UserImportBatch::where('importer_key', self::IMPORTER_KEY)->find($sharedId);
    }

    public function domainBatchForSharedBatch(int $sharedBatchId, int $businessId): object
    {
        $shared = $this->batchService->findForBusiness($sharedBatchId, $businessId, self::IMPORTER_KEY);
        $domain = DB::table('heavy_equipment_vendor_loading_import_batches')
            ->where('business_id', $businessId)
            ->where('user_import_batch_id', $shared->id)
            ->first();
        abort_if(! $domain, 404, 'Batch Vendor Loading tidak ditemukan.');

        return $domain;
    }

    public function dataTable(Request $request, int $businessId, bool $canReverse): array
    {
        $this->ensureHistoryEnvelopes($businessId);
        $data = $this->batchService->dataTable($request, $businessId, self::IMPORTER_KEY);
        $sharedIds = collect($data['data'])->pluck('id')->all();
        $domainBySharedId = DB::table('heavy_equipment_vendor_loading_import_batches')
            ->where('business_id', $businessId)
            ->whereIn('user_import_batch_id', $sharedIds)
            ->get()->keyBy('user_import_batch_id');
        $domainIds = $domainBySharedId->pluck('id')->map(fn ($id) => (int) $id)->all();
        $domainBatchesWithDocuments = $domainIds === [] ? [] : DB::table('heavy_equipment_vendor_loading_documents')
            ->where('business_id', $businessId)
            ->whereIn('import_batch_id', $domainIds)
            ->pluck('import_batch_id')->map(fn ($id) => (int) $id)->all();

        $data['data'] = collect($data['data'])->map(function (array $row) use ($domainBySharedId, $domainBatchesWithDocuments, $canReverse) {
            $domain = $domainBySharedId->get($row['id']);
            $row['domain_batch_id'] = (int) ($domain->id ?? 0);
            $row['can_preview'] = $domain && in_array($domain->status, ['preview_ready', 'queued_import', 'importing'], true);
            $row['can_retry'] = $domain
                && $domain->status === 'failed'
                && filled($domain->preview_path)
                && Storage::disk('user_imports')->exists($domain->preview_path)
                && (int) $domain->imported_documents === 0
                && ! in_array((int) $domain->id, $domainBatchesWithDocuments, true);
            $row['can_revert'] = $canReverse && $domain && in_array($domain->status, ['imported', 'partial'], true);
            $summary = $row['summary'] ?? [];
            $row['result_summary'] = $domain && $domain->status === 'reversed'
                ? ($summary['reversed_documents'] ?? 0).' dokumen / '.($summary['reversed_lines'] ?? 0).' line dihapus'
                : ($summary['imported_documents'] ?? 0).' dokumen / '.($summary['imported_lines'] ?? 0).' line / '.($summary['skipped_rows'] ?? 0).' dilewati';
            return $row;
        })->values();

        return $data;
    }
}
