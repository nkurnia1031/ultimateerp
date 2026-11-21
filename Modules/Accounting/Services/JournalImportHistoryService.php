<?php

namespace Modules\Accounting\Services;

use App\Services\UserImportBatchService;
use App\UserImportBatch;
use Illuminate\Http\Request;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Modules\Accounting\Entities\JournalImportBatchStatus;

class JournalImportHistoryService
{
    private const IMPORTER_KEY = 'journal';

    public function __construct(private UserImportBatchService $batches)
    {
    }

    public function linkBatch(int $journalImportId, int $businessId, ?int $userId = null): ?UserImportBatch
    {
        $domain = DB::table('accounting_journal_imports')
            ->where('business_id', $businessId)
            ->where('id', $journalImportId)
            ->first();
        if (empty($domain) || ($domain->import_origin ?? 'web') !== 'web') {
            return null;
        }

        $shared = null;
        if (! empty($domain->user_import_batch_id)) {
            $shared = UserImportBatch::where('business_id', $businessId)
                ->where('importer_key', self::IMPORTER_KEY)
                ->find((int) $domain->user_import_batch_id);
        }

        if (! $shared) {
            $sourceKey = 'domain:'.$journalImportId;
            $shared = UserImportBatch::where('business_id', $businessId)
                ->where('importer_key', self::IMPORTER_KEY)
                ->where('source_key', $sourceKey)
                ->first();

            if (! $shared) {
                try {
                    $shared = $this->batches->create([
                        'business_id' => $businessId,
                        'created_by' => $userId ?: ($domain->created_by ?? null),
                        'importer_key' => self::IMPORTER_KEY,
                        'source_key' => $sourceKey,
                        'file_name' => $domain->file_name,
                        'staging_path' => $domain->file_path,
                        'status' => $this->sharedStatus((string) $domain->status),
                        'created_at' => $domain->created_at ?: now(),
                        'updated_at' => $domain->updated_at ?: now(),
                    ]);
                } catch (QueryException $exception) {
                    $shared = UserImportBatch::where('business_id', $businessId)
                        ->where('importer_key', self::IMPORTER_KEY)
                        ->where('source_key', $sourceKey)
                        ->first();
                    if (! $shared) {
                        throw $exception;
                    }
                }
            }

            DB::table('accounting_journal_imports')
                ->where('business_id', $businessId)
                ->where('id', $journalImportId)
                ->whereNull('user_import_batch_id')
                ->update(['user_import_batch_id' => $shared->id]);

            $domain = DB::table('accounting_journal_imports')
                ->where('business_id', $businessId)
                ->where('id', $journalImportId)
                ->first();
        }

        return $this->syncBatch($domain, $shared);
    }

    public function syncBusiness(int $businessId): void
    {
        DB::table('accounting_journal_imports')
            ->where('business_id', $businessId)
            ->where(function ($query) {
                $query->whereNull('import_origin')->orWhere('import_origin', 'web');
            })
            ->orderBy('id')
            ->chunkById(100, function ($rows) use ($businessId) {
                foreach ($rows as $row) {
                    $this->linkBatch((int) $row->id, $businessId);
                }
            }, 'id');
    }

    public function syncBatch(object $domain, ?UserImportBatch $shared = null): UserImportBatch
    {
        $shared = $shared ?: UserImportBatch::where('business_id', (int) $domain->business_id)
            ->where('importer_key', self::IMPORTER_KEY)
            ->findOrFail((int) $domain->user_import_batch_id);

        $progressData = $this->decode($domain->progress_data ?? null);
        $phase = is_string($progressData['phase'] ?? null) ? $progressData['phase'] : null;
        $phaseProgress = $phase && is_array($progressData['phases'][$phase] ?? null)
            ? $progressData['phases'][$phase]
            : [];
        $status = $this->sharedStatus((string) $domain->status);
        $total = max(0, (int) ($phaseProgress['total'] ?? $domain->total_entries ?? 0));
        $current = max(0, (int) ($phaseProgress['processed'] ?? $domain->processed_entries ?? 0));
        $percent = $total > 0 ? min(100, (int) round(($current / $total) * 100)) : 0;
        if ($status === 'preview_ready' || $status === 'imported' || $status === 'reverted') {
            $percent = 100;
        }

        $domainSummary = $this->decode($domain->summary ?? null);
        $summary = array_merge($domainSummary, [
            'domain_batch_id' => (int) $domain->id,
            'domain_status' => (string) $domain->status,
            'total_entries' => (int) ($domain->total_entries ?? 0),
            'imported_entries' => (int) ($domain->imported_entries ?? 0),
            'skipped_entries' => (int) ($domain->skipped_entries ?? 0),
            'processed_entries' => (int) ($domain->processed_entries ?? 0),
        ]);
        $searchText = implode(' ', array_filter([
            $domain->file_name ?? null,
            $domain->source_date_range ?? null,
            'Journal #'.(int) $domain->id,
            $domain->last_message ?? null,
            $domain->error_message ?? null,
        ], fn ($value) => filled($value)));
        $now = now();

        $shared->forceFill([
            'file_name' => $domain->file_name ?: $shared->file_name,
            'staging_path' => $domain->file_path,
            'status' => $status,
            'stage' => $phase ?: (string) $domain->status,
            'progress_current' => $current,
            'progress_total' => $total,
            'progress_percent' => $percent,
            'summary_json' => json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'search_text' => $searchText,
            'last_message' => $domain->last_message,
            'error_message' => $domain->error_message,
            'heartbeat_at' => JournalImportBatchStatus::isActive((string) $domain->status)
                ? ($domain->updated_at ?: $now)
                : null,
            'started_at' => JournalImportBatchStatus::isActive((string) $domain->status)
                ? ($shared->started_at ?: ($domain->created_at ?: $now))
                : $shared->started_at,
            'completed_at' => in_array($status, ['preview_ready', 'imported', 'partial', 'failed', 'cancelled', 'reverted'], true)
                ? ($domain->updated_at ?: $now)
                : null,
            'failed_at' => $status === 'failed' ? ($domain->updated_at ?: $now) : null,
            'reverted_at' => $status === 'reverted' ? ($domain->updated_at ?: $now) : null,
            'updated_at' => $domain->updated_at ?: $now,
        ])->save();

        return $shared->fresh();
    }

    public function dataTable(Request $request, int $businessId, bool $canRevert): array
    {
        $this->syncBusiness($businessId);
        $table = $this->batches->dataTable($request, $businessId, self::IMPORTER_KEY);
        $sharedIds = collect($table['data'])->pluck('id')->map(fn ($id) => (int) $id)->all();
        if ($sharedIds === []) {
            return $table;
        }

        $domainRows = DB::table('accounting_journal_imports')
            ->where('business_id', $businessId)
            ->whereIn('user_import_batch_id', $sharedIds)
            ->get()
            ->keyBy('user_import_batch_id');
        $domainIds = $domainRows->pluck('id')->map(fn ($id) => (int) $id)->all();
        $outcomes = $this->rowOutcomeCounts($domainIds, $businessId);

        $table['data'] = collect($table['data'])->map(function (array $row) use ($domainRows, $outcomes, $canRevert) {
            $domain = $domainRows->get($row['id']);
            if (! $domain) {
                return $row;
            }

            $row['domain_batch_id'] = (int) $domain->id;
            $row['domain_status'] = (string) $domain->status;
            $row['period'] = $domain->source_date_range;
            $row['total_entries'] = (int) $domain->total_entries;
            $row['imported_entries'] = (int) $domain->imported_entries;
            $row['skipped_entries'] = (int) $domain->skipped_entries;
            $row['processed_entries'] = (int) $domain->processed_entries;
            $row['phase_progress'] = $this->currentPhase($this->decode($domain->progress_data ?? null));
            $row['last_message'] = $domain->last_message;
            $row['error_message'] = $domain->error_message;
            $row['updated_at'] = $domain->updated_at;
            $row['row_outcomes'] = $outcomes[(int) $domain->id] ?? [];
            $row['cancel_requested'] = ! empty($domain->cancel_requested_at);
            $row['can_review'] = in_array($domain->status, ['analyzed', 'partially_imported', 'failed', 'cancelled'], true);
            $row['can_revert'] = $canRevert && $domain->status === 'imported';
            $row['can_revert_permission'] = $canRevert;
            $row['can_terminate'] = JournalImportBatchStatus::isActive((string) $domain->status);
            $row['restore_url'] = $row['has_snapshot']
                ? url('accounting/journal-entry/import/history/'.$row['id'].'/download')
                : null;
            $row['detail_url'] = url('accounting/journal-entry/import/history/'.$row['id']);
            $row['review_url'] = url('accounting/journal-entry/import/'.$domain->id.'/review');
            $row['revert_url'] = url('accounting/journal-entry/import/'.$domain->id.'/revert');
            $row['terminate_url'] = url('accounting/journal-entry/import/'.$domain->id.'/terminate');

            return $row;
        })->values()->all();

        return $table;
    }

    public function detail(UserImportBatch $batch): array
    {
        $domain = DB::table('accounting_journal_imports')
            ->where('business_id', $batch->business_id)
            ->where('user_import_batch_id', $batch->id)
            ->first();
        if (! $domain) {
            abort(404);
        }

        $this->syncBatch($domain, $batch);
        $outcomes = $this->rowOutcomeCounts([(int) $domain->id], (int) $batch->business_id);
        $rows = DB::table('accounting_journal_import_rows')
            ->where('business_id', $batch->business_id)
            ->where('journal_import_id', $domain->id)
            ->whereIn('processing_status', ['pending', 'queued', 'running', 'failed'])
            ->orderBy('entry_index')
            ->limit(100)
            ->get([
                'entry_index', 'source_type', 'source_number', 'processing_status',
                'processing_message', 'processing_error',
            ]);
        $progressData = $this->decode($domain->progress_data ?? null);

        return [
            'id' => (int) $batch->id,
            'domain_batch_id' => (int) $domain->id,
            'file_name' => $domain->file_name,
            'domain_status' => $domain->status,
            'status' => $this->sharedStatus((string) $domain->status),
            'source_date_range' => $domain->source_date_range,
            'total_entries' => (int) $domain->total_entries,
            'imported_entries' => (int) $domain->imported_entries,
            'skipped_entries' => (int) $domain->skipped_entries,
            'summary' => $this->decode($domain->summary ?? null),
            'phase' => $progressData['phase'] ?? null,
            'phase_progress' => $this->currentPhase($progressData),
            'phase_history' => $progressData['phases'] ?? [],
            'row_outcomes' => $outcomes[(int) $domain->id] ?? [],
            'problem_rows' => $rows->map(fn ($row) => [
                'entry_index' => (int) $row->entry_index,
                'source_type' => $row->source_type,
                'source_number' => $row->source_number,
                'status' => $row->processing_status,
                'message' => $row->processing_message,
                'error' => $row->processing_error,
            ])->all(),
            'last_message' => $domain->last_message,
            'error_message' => $domain->error_message,
            'has_snapshot' => $batch->hasSourceSnapshot(),
            'restore_url' => $batch->hasSourceSnapshot()
                ? url('accounting/journal-entry/import/history/'.$batch->id.'/download')
                : null,
            'review_url' => in_array($domain->status, ['analyzed', 'partially_imported', 'failed', 'cancelled'], true)
                ? url('accounting/journal-entry/import/'.$domain->id.'/review')
                : null,
        ];
    }

    public function captureSourceSnapshot(int $journalImportId, int $businessId, string $absolutePath): UserImportBatch
    {
        $shared = $this->linkBatch($journalImportId, $businessId);
        if (! $shared) {
            throw new \RuntimeException('Batch Journal Import tidak tersedia untuk riwayat bersama.');
        }
        if (! $shared->hasSourceSnapshot()) {
            $this->batches->captureWorkbook($shared, $absolutePath);
        }

        return $shared->fresh();
    }

    private function rowOutcomeCounts(array $domainIds, int $businessId): array
    {
        if ($domainIds === []) {
            return [];
        }

        $counts = [];
        DB::table('accounting_journal_import_rows')
            ->where('business_id', $businessId)
            ->whereIn('journal_import_id', $domainIds)
            ->select('journal_import_id', 'processing_status', DB::raw('COUNT(*) as row_count'))
            ->groupBy('journal_import_id', 'processing_status')
            ->get()
            ->each(function ($row) use (&$counts) {
                $counts[(int) $row->journal_import_id][$row->processing_status] = (int) $row->row_count;
            });

        return $counts;
    }

    private function sharedStatus(string $status): string
    {
        return match ($status) {
            'queued' => 'queued_preview',
            'analyzing' => 'analyzing',
            'analyzed' => 'preview_ready',
            'preparing_import', 'queued_import' => 'queued_import',
            'importing', 'reconciling', 'processing' => 'importing',
            'imported' => 'imported',
            'partially_imported' => 'partial',
            'failed' => 'failed',
            'cancelled' => 'cancelled',
            'reverted' => 'reverted',
            default => 'failed',
        };
    }

    private function decode(?string $json): array
    {
        $decoded = $json ? json_decode($json, true) : null;

        return is_array($decoded) ? $decoded : [];
    }

    private function currentPhase(array $progress): ?array
    {
        $phase = $progress['phase'] ?? null;
        if (! is_string($phase) || ! is_array($progress['phases'][$phase] ?? null)) {
            return null;
        }

        return array_merge(['phase' => $phase], $progress['phases'][$phase]);
    }
}
