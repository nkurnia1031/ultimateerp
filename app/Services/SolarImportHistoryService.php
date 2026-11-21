<?php

namespace App\Services;

use App\UserImportBatch;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class SolarImportHistoryService
{
    public const IMPORTER_KEY = 'solar';

    public function __construct(private UserImportBatchService $batchService)
    {
    }

    public function createForDomainBatch(
        string $domainBatchId,
        int $businessId,
        ?int $createdBy,
        ?string $fileName,
        ?string $stagingPath = null
    ): UserImportBatch {
        return $this->ensureDomainEnvelope($domainBatchId, $businessId, $createdBy, $fileName, $stagingPath);
    }

    public function ensureHistoryEnvelopes(int $businessId): void
    {
        Cache::rememberForever($this->seedCacheKey($businessId), function () use ($businessId) {
            DB::table('solar_import_batches')
                ->where('business_id', $businessId)
                ->orderBy('id')
                ->chunk(100, function ($batches) use ($businessId): void {
                    foreach ($batches as $batch) {
                        $this->syncFromDomainBatch((string) $batch->id, $businessId);
                    }
                });

            return true;
        });
    }

    public function ensureDomainEnvelope(
        string $domainBatchId,
        int $businessId,
        ?int $createdBy = null,
        ?string $fileName = null,
        ?string $stagingPath = null
    ): UserImportBatch {
        $shared = DB::transaction(function () use ($domainBatchId, $businessId, $createdBy, $fileName, $stagingPath) {
            $domain = DB::table('solar_import_batches')
                ->where('id', $domainBatchId)
                ->where('business_id', $businessId)
                ->lockForUpdate()
                ->first();
            abort_if(! $domain, 404, 'Batch import Solar tidak ditemukan.');

            $shared = null;
            if (! empty($domain->user_import_batch_id)) {
                $shared = UserImportBatch::where('business_id', $businessId)
                    ->where('importer_key', self::IMPORTER_KEY)
                    ->find((int) $domain->user_import_batch_id);
            }

            $sourceKey = 'domain:'.$domainBatchId;
            $shared = $shared ?: UserImportBatch::where('business_id', $businessId)
                ->where('importer_key', self::IMPORTER_KEY)
                ->where('source_key', $sourceKey)
                ->first();

            if (! $shared) {
                $shared = UserImportBatch::create([
                    'business_id' => $businessId,
                    'created_by' => $createdBy ?? $domain->created_by,
                    'importer_key' => self::IMPORTER_KEY,
                    'source_key' => $sourceKey,
                    'file_name' => $fileName ?: ($domain->file_name ?: 'Workbook Solar'),
                    'staging_path' => $stagingPath ?: ($domain->file_path ?: null),
                    'status' => $this->sharedStatus($domain),
                    'stage' => $domain->stage ?: $domain->process_state,
                    'progress_current' => (int) ($domain->progress_current ?? 0),
                    'progress_total' => (int) ($domain->progress_total ?? 0),
                    'progress_percent' => (int) ($domain->progress_percent ?? 0),
                    'summary_json' => $this->summaryJson($domain),
                    'search_text' => $this->searchText($domain),
                    'last_message' => $domain->progress_message ?: 'Batch Solar tersedia di riwayat.',
                    'error_message' => $domain->error_message,
                    'heartbeat_at' => $domain->heartbeat_at,
                    'started_at' => $domain->started_at,
                    'completed_at' => $domain->completed_at,
                    'failed_at' => $domain->failed_at,
                    'reverted_at' => $domain->rolled_back_at,
                    'created_at' => $domain->created_at,
                    'updated_at' => $domain->updated_at,
                ]);
            } else {
                $shared->forceFill([
                    'file_name' => $fileName ?: ($shared->file_name ?: $domain->file_name),
                    'staging_path' => $stagingPath ?: ($domain->file_path ?: $shared->staging_path),
                ])->save();
            }

            if ((int) $domain->user_import_batch_id !== (int) $shared->id) {
                DB::table('solar_import_batches')
                    ->where('id', $domainBatchId)
                    ->where('business_id', $businessId)
                    ->update(['user_import_batch_id' => $shared->id, 'updated_at' => now()]);
            }

            return $shared->fresh();
        });

        return $this->syncFromDomainBatch($domainBatchId, $businessId, $shared);
    }

    public function syncFromDomainBatch(string $domainBatchId, int $businessId, ?UserImportBatch $knownShared = null): UserImportBatch
    {
        $domain = DB::table('solar_import_batches')
            ->where('id', $domainBatchId)
            ->where('business_id', $businessId)
            ->first();
        abort_if(! $domain, 404, 'Batch import Solar tidak ditemukan.');

        $shared = $knownShared ?: $this->findSharedForDomain($domain, $businessId);
        if (! $shared) {
            $shared = $this->ensureDomainEnvelope(
                $domainBatchId,
                $businessId,
                $domain->created_by ? (int) $domain->created_by : null,
                $domain->file_name,
                $domain->file_path
            );
        }

        $shared->forceFill([
            'file_name' => $domain->file_name ?: $shared->file_name,
            'staging_path' => $domain->file_path,
            'status' => $this->sharedStatus($domain),
            'stage' => $domain->stage ?: $domain->process_state,
            'progress_current' => (int) ($domain->progress_current ?? 0),
            'progress_total' => (int) ($domain->progress_total ?? 0),
            'progress_percent' => (int) ($domain->progress_percent ?? 0),
            'summary_json' => $this->summaryJson($domain),
            'search_text' => $this->searchText($domain),
            'last_message' => $domain->progress_message ?: ($shared->last_message ?: 'Batch Solar tersedia di riwayat.'),
            'error_message' => $domain->error_message,
            'heartbeat_at' => $domain->heartbeat_at,
            'started_at' => $domain->started_at,
            'completed_at' => $domain->completed_at,
            'failed_at' => $domain->failed_at,
            'reverted_at' => $domain->rolled_back_at,
        ])->save();

        return $shared->fresh();
    }

    public function dataTable(Request $request, int $businessId): array
    {
        $this->ensureHistoryEnvelopes($businessId);
        $result = $this->batchService->dataTable($request, $businessId, self::IMPORTER_KEY);
        $sharedIds = collect($result['data'])->pluck('id')->map(fn ($id) => (int) $id)->all();
        $sharedRows = UserImportBatch::where('business_id', $businessId)
            ->where('importer_key', self::IMPORTER_KEY)
            ->whereIn('id', $sharedIds)
            ->get(['id', 'source_snapshot_path'])
            ->keyBy('id');
        $domains = DB::table('solar_import_batches')
            ->where('business_id', $businessId)
            ->whereIn('user_import_batch_id', $sharedIds)
            ->get()
            ->keyBy('user_import_batch_id');

        $result['data'] = collect($result['data'])->map(function (array $row) use ($domains, $sharedRows, $businessId) {
            $domain = $domains->get((int) $row['id']);
            if (! $domain) {
                return $row;
            }

            $summary = json_decode((string) ($domain->counts ?? ''), true) ?: [];
            $counts = $this->ledgerCounts($summary);
            $processState = $domain->process_state ?: 'completed';
            $operation = $domain->operation ?: 'preview';
            $snapshotPath = $sharedRows->get((int) $row['id'])->source_snapshot_path ?? null;
            $snapshot = $snapshotPath && Storage::disk('local')->exists($snapshotPath);
            $hasSource = $snapshot || ($domain->file_path && Storage::disk('local')->exists($domain->file_path));
            $progress = [
                'percent' => (int) $row['progress_percent'],
                'current' => (int) $row['progress_current'],
                'total' => (int) $row['progress_total'],
                'message' => $row['last_message'],
                'error' => $row['error_message'],
            ];
            if (in_array($processState, ['queued', 'processing'], true)) {
                $runtime = app(SolarImportProcessService::class)->status((string) $domain->id, $businessId);
                $processState = $runtime['state'];
                $operation = $runtime['operation'];
                $progress = array_merge($progress, [
                    'percent' => $runtime['percent'],
                    'current' => $runtime['current'],
                    'total' => $runtime['total'],
                    'message' => $runtime['message'],
                    'error' => $runtime['error'],
                ]);
            }
            $runtimeStatus = $this->sharedStatus((object) [
                'status' => $domain->status,
                'process_state' => $processState,
                'operation' => $operation,
            ]);

            return array_merge($row, [
                'status' => $runtimeStatus,
                'has_snapshot' => (bool) $snapshot,
                'stage' => $domain->stage ?: $processState,
                'progress_percent' => (int) $progress['percent'],
                'progress_current' => (int) $progress['current'],
                'progress_total' => (int) $progress['total'],
                'last_message' => $progress['error'] ?: $progress['message'],
                'error_message' => $progress['error'],
                'domain_batch_id' => (string) $domain->id,
                'domain_status' => $domain->status,
                'operation' => $operation,
                'process_state' => $processState,
                'status_label' => $this->statusLabel($runtimeStatus),
                'status_class' => $this->statusClass($runtimeStatus),
                'counts' => $counts,
                'status_url' => route('solar.imports.status', $domain->id),
                'detail_url' => route('solar.imports.detail', $domain->id),
                'preview_url' => $domain->status === 'preview' && $operation === 'preview'
                    && in_array($processState, ['completed', 'failed', 'cancelled'], true)
                    ? route('solar.imports.reconcile', $domain->id)
                    : null,
                'restore_url' => $snapshot ? route('solar.imports.restore', $domain->id) : null,
                'rollback_url' => $domain->status === 'imported' ? route('solar.imports.rollback', $domain->id) : null,
                'retry_url' => in_array($processState, ['failed', 'cancelled'], true) && $domain->status === 'preview'
                    && $hasSource
                    ? route('solar.imports.retry', $domain->id)
                    : null,
                'cancel_url' => $processState === 'queued' ? route('solar.imports.cancel', $domain->id) : null,
                'active' => in_array($processState, ['queued', 'processing'], true),
            ]);
        })->values();

        return $result;
    }

    public function sharedBatchForDomainBatch(string $domainBatchId, int $businessId): UserImportBatch
    {
        $domain = DB::table('solar_import_batches')
            ->where('id', $domainBatchId)
            ->where('business_id', $businessId)
            ->first();
        abort_if(! $domain, 404, 'Batch import Solar tidak ditemukan.');

        return $this->ensureDomainEnvelope(
            $domainBatchId,
            $businessId,
            $domain->created_by ? (int) $domain->created_by : null,
            $domain->file_name,
            $domain->file_path
        );
    }

    public function captureSourceSnapshot(string $domainBatchId, int $businessId, string $absolutePath): UserImportBatch
    {
        $shared = $this->sharedBatchForDomainBatch($domainBatchId, $businessId);
        if (! $shared->hasSourceSnapshot()) {
            $this->batchService->captureWorkbook($shared, $absolutePath);
        }

        return $this->syncFromDomainBatch($domainBatchId, $businessId);
    }

    /** @return array{0:string,1:bool} Absolute workbook path and whether it is a private temporary reconstruction. */
    public function sourceWorkbookPath(string $domainBatchId, int $businessId): array
    {
        $domain = DB::table('solar_import_batches')
            ->where('id', $domainBatchId)
            ->where('business_id', $businessId)
            ->first();
        abort_if(! $domain, 404, 'Batch import Solar tidak ditemukan.');

        if ($domain->file_path && Storage::disk('local')->exists($domain->file_path)) {
            return [Storage::disk('local')->path($domain->file_path), false];
        }

        $shared = $this->sharedBatchForDomainBatch($domainBatchId, $businessId);
        if (! $shared->hasSourceSnapshot()) {
            throw new RuntimeException('Workbook sumber Solar tidak tersedia untuk diproses ulang. Batch lama ini tidak memiliki snapshot sumber.');
        }

        return [$this->batchService->restoredWorkbookPath($shared), true];
    }

    public function deleteStaging(string $domainBatchId, int $businessId, ?string $stagingPath = null): bool
    {
        $domain = DB::table('solar_import_batches')
            ->where('id', $domainBatchId)
            ->where('business_id', $businessId)
            ->first(['file_path', 'user_import_batch_id']);
        if (! $domain) {
            return true;
        }

        $path = $stagingPath ?: $domain->file_path;
        $expectedDirectory = 'solar-imports/'.$businessId.'/';
        if ($path && ! str_starts_with($path, $expectedDirectory)) {
            Log::warning('Solar import staging cleanup refused an unexpected path.', [
                'batch_id' => $domainBatchId,
                'business_id' => $businessId,
                'path' => $path,
            ]);

            return false;
        }
        if ($path && Storage::disk('local')->exists($path) && ! Storage::disk('local')->delete($path)) {
            Log::warning('Solar import staging file could not be deleted.', [
                'batch_id' => $domainBatchId,
                'business_id' => $businessId,
                'path' => $path,
            ]);

            return false;
        }

        DB::table('solar_import_batches')
            ->where('id', $domainBatchId)
            ->where('business_id', $businessId)
            ->when($stagingPath !== null, fn ($query) => $query->where('file_path', $stagingPath))
            ->update(['file_path' => null, 'updated_at' => now()]);

        if ($domain->user_import_batch_id) {
            UserImportBatch::where('business_id', $businessId)
                ->where('importer_key', self::IMPORTER_KEY)
                ->whereKey((int) $domain->user_import_batch_id)
                ->update(['staging_path' => null, 'updated_at' => now()]);
        }

        return true;
    }

    public function cleanupTemporaryWorkbook(string $path, bool $temporary): void
    {
        if (! $temporary || basename($path) !== 'source.xlsx'
            || ! str_starts_with(basename(dirname($path)), 'ultimateerp-import-')) {
            return;
        }

        if (is_file($path)) {
            @unlink($path);
        }
        if (is_dir(dirname($path))) {
            @rmdir(dirname($path));
        }
    }

    public function hasSourceSnapshot(string $domainBatchId, int $businessId): bool
    {
        $shared = $this->sharedBatchForDomainBatch($domainBatchId, $businessId);

        return $shared->hasSourceSnapshot()
            && Storage::disk('local')->exists((string) $shared->source_snapshot_path);
    }

    private function findSharedForDomain(object $domain, int $businessId): ?UserImportBatch
    {
        if (! empty($domain->user_import_batch_id)) {
            $shared = UserImportBatch::where('business_id', $businessId)
                ->where('importer_key', self::IMPORTER_KEY)
                ->find((int) $domain->user_import_batch_id);
            if ($shared) {
                return $shared;
            }
        }

        return UserImportBatch::where('business_id', $businessId)
            ->where('importer_key', self::IMPORTER_KEY)
            ->where('source_key', 'domain:'.$domain->id)
            ->first();
    }

    private function sharedStatus(object $domain): string
    {
        if (($domain->status ?? null) === 'rolled_back') {
            return 'reverted';
        }
        if (($domain->status ?? null) === 'imported') {
            return 'imported';
        }
        $state = $domain->process_state ?: 'completed';
        if ($state === 'failed') {
            return 'failed';
        }
        if ($state === 'cancelled') {
            return 'cancelled';
        }
        if ($state === 'queued') {
            return ($domain->operation ?: 'preview') === 'import' ? 'queued_import' : 'queued_preview';
        }
        if ($state === 'processing') {
            return ($domain->operation ?: 'preview') === 'import' ? 'importing' : 'analyzing';
        }
        return 'preview_ready';
    }

    private function statusLabel(string $status): string
    {
        return [
            'queued_preview' => 'Menunggu preview',
            'analyzing' => 'Menganalisis',
            'preview_ready' => 'Preview siap',
            'queued_import' => 'Menunggu import',
            'importing' => 'Mengimpor',
            'imported' => 'Selesai',
            'failed' => 'Gagal',
            'cancelled' => 'Dibatalkan',
            'reverted' => 'Rollback',
        ][$status] ?? ucfirst($status);
    }

    private function statusClass(string $status): string
    {
        return match ($status) {
            'imported', 'preview_ready' => 'success',
            'failed' => 'danger',
            'cancelled', 'reverted' => 'default',
            default => 'warning',
        };
    }

    private function summaryJson(object $domain): string
    {
        $counts = json_decode((string) ($domain->counts ?? ''), true) ?: [];
        $sheets = json_decode((string) ($domain->source_sheets ?? ''), true) ?: [];

        return json_encode([
            'counts' => $counts,
            'sheets' => $sheets,
            'warning_count' => count(json_decode((string) ($domain->preview_warnings ?? ''), true) ?: []),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function searchText(object $domain): string
    {
        $sheets = json_decode((string) ($domain->source_sheets ?? ''), true) ?: [];
        $sheetTitles = collect($sheets)->map(fn ($sheet) => $sheet['title'] ?? $sheet['sheet_key'] ?? '')->all();

        return mb_substr(implode(' ', array_filter([
            $domain->file_name ?? '',
            $domain->operation ?? '',
            $domain->stage ?? '',
            $domain->progress_message ?? '',
            $domain->error_message ?? '',
            implode(' ', $sheetTitles),
            (string) ($domain->counts ?? ''),
        ])), 0, 20000);
    }

    private function ledgerCounts(array $summary): array
    {
        $counts = ['stock' => 0, 'pool' => 0, 'mess' => 0, 'usage' => 0];
        if (! empty($summary['sheets']) && is_array($summary['sheets'])) {
            foreach ($summary['sheets'] as $sheet) {
                $ledger = strtolower((string) ($sheet['ledger'] ?? ''));
                if (array_key_exists($ledger, $counts)) {
                    $counts[$ledger] += (int) ($sheet['total'] ?? 0);
                }
            }

            return $counts;
        }

        foreach ($counts as $ledger => $value) {
            $counts[$ledger] = (int) ($summary[$ledger] ?? 0);
        }

        return $counts;
    }

    private function seedCacheKey(int $businessId): string
    {
        return 'solar-import-history-seeded:v1:'.$businessId;
    }
}
