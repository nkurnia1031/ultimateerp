<?php

namespace App\Services;

use App\User;
use App\UserImportBatch;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class ExpenseImportHistoryService
{
    public const IMPORTER_KEY = 'expense';

    public static function seedCacheKey(int $businessId): string
    {
        return 'expense_import_history_seeded:v2:'.$businessId;
    }

    public function __construct(private UserImportBatchService $batchService)
    {
    }

    public function ensureHistoryEnvelopes(int $businessId): void
    {
        Cache::rememberForever(self::seedCacheKey($businessId), function () use ($businessId) {
            DB::table('expense_import_batches')
                ->where('business_id', $businessId)
                ->whereNull('user_import_batch_id')
                ->orderBy('id')
                ->chunkById(100, function ($domainBatches) use ($businessId): void {
                    foreach ($domainBatches as $domainBatch) {
                        $this->ensureDomainEnvelope((string) $domainBatch->id, $businessId, false);
                    }
                });

            $referencesByBatchFile = $this->referencesByBatchFile($businessId);
            $domainBatches = DB::table('expense_import_batches')->where('business_id', $businessId)->get();
            $sharedBatches = UserImportBatch::where('business_id', $businessId)
                ->where('importer_key', self::IMPORTER_KEY)
                ->get()->keyBy('id');
            foreach ($domainBatches as $domain) {
                $shared = $sharedBatches->get($domain->user_import_batch_id);
                if (! $shared) {
                    continue;
                }
                $summary = json_decode((string) $domain->summary, true) ?: [];
                $number = (int) ($summary['legacy_import_batch_number'] ?? 0);
                $key = $number > 0 ? $this->referenceGroupKey($number, (string) $domain->file_name) : null;
                $this->syncDomainRecord($domain, $shared, $key ? ($referencesByBatchFile[$key] ?? []) : []);
            }

            $this->backfillLegacyBatches($businessId, $referencesByBatchFile);

            return true;
        });
    }

    public function ensureDomainEnvelope(string $domainBatchId, int $businessId, bool $sync = true): UserImportBatch
    {
        $shared = DB::transaction(function () use ($domainBatchId, $businessId) {
            $domain = DB::table('expense_import_batches')
                ->where('business_id', $businessId)
                ->lockForUpdate()
                ->where('id', $domainBatchId)
                ->first();
            abort_if(! $domain, 404, 'Batch import Expense tidak ditemukan.');

            if ($domain->user_import_batch_id) {
                $linked = UserImportBatch::where('business_id', $businessId)
                    ->where('importer_key', self::IMPORTER_KEY)->find($domain->user_import_batch_id);
                if ($linked) {
                    return $linked;
                }
            }

            $sourceKey = 'domain:'.$domain->id;
            $batch = UserImportBatch::where('business_id', $businessId)
                ->where('importer_key', self::IMPORTER_KEY)
                ->where('source_key', $sourceKey)
                ->first();
            if (! $batch) {
                $batch = $this->batchService->create([
                    'business_id' => $businessId,
                    'created_by' => $domain->created_by,
                    'importer_key' => self::IMPORTER_KEY,
                    'source_key' => $sourceKey,
                    'file_name' => $domain->file_name,
                    'staging_path' => $domain->file_path,
                    'status' => $this->sharedStatus($domain),
                    'stage' => $domain->stage ?: $domain->process_state,
                    'progress_current' => (int) $domain->progress_current,
                    'progress_total' => (int) $domain->progress_total,
                    'progress_percent' => (int) $domain->progress_percent,
                    'summary_json' => json_encode(json_decode((string) $domain->summary, true) ?: [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'last_message' => $domain->progress_message,
                    'error_message' => $domain->error_message,
                    'heartbeat_at' => $domain->heartbeat_at,
                    'started_at' => $domain->started_at,
                    'completed_at' => $domain->completed_at,
                    'failed_at' => $domain->failed_at,
                    'created_at' => $domain->created_at,
                    'updated_at' => $domain->updated_at,
                ]);
            }

            DB::table('expense_import_batches')
                ->where('business_id', $businessId)
                ->where('id', $domainBatchId)
                ->update(['user_import_batch_id' => $batch->id, 'updated_at' => now()]);

            return $batch;
        });

        return $sync ? ($this->syncFromDomainBatch($domainBatchId) ?: $shared->fresh()) : $shared->fresh();
    }

    public function syncFromDomainBatch(string $domainBatchId): ?UserImportBatch
    {
        $domain = DB::table('expense_import_batches')->where('id', $domainBatchId)->first();
        if (! $domain) {
            return null;
        }
        $shared = $domain->user_import_batch_id
            ? UserImportBatch::where('business_id', $domain->business_id)
                ->where('importer_key', self::IMPORTER_KEY)->find($domain->user_import_batch_id)
            : null;
        if (! $shared) {
            $shared = $this->ensureDomainEnvelope($domainBatchId, (int) $domain->business_id, false);
            $domain = DB::table('expense_import_batches')->where('id', $domainBatchId)->first();
        }

        $summary = json_decode((string) $domain->summary, true) ?: [];
        $importBatchNumber = (int) ($summary['legacy_import_batch_number'] ?? 0);
        $references = $importBatchNumber > 0
            ? $this->referencesForImport((int) $domain->business_id, $importBatchNumber, (string) $domain->file_name)
            : [];
        return $this->syncDomainRecord($domain, $shared, $references);
    }

    private function syncDomainRecord(object $domain, UserImportBatch $shared, array $references): UserImportBatch
    {
        $summary = json_decode((string) $domain->summary, true) ?: [];
        $importBatchNumber = (int) ($summary['legacy_import_batch_number'] ?? 0);
        $summary['reference_count'] = count($references);
        $status = $this->sharedStatus($domain);
        $terminal = in_array($status, ['preview_ready', 'imported', 'partial', 'failed', 'cancelled', 'reverted'], true);
        $shared->forceFill([
            'file_name' => $domain->file_name,
            'staging_path' => $domain->file_path,
            'status' => $status,
            'stage' => $domain->stage ?: $domain->process_state,
            'progress_current' => (int) $domain->progress_current,
            'progress_total' => (int) $domain->progress_total,
            'progress_percent' => $terminal ? max((int) $domain->progress_percent, 100) : (int) $domain->progress_percent,
            'summary_json' => json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'search_text' => json_encode($references, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'last_message' => $domain->progress_message ?: $this->statusMessage($status),
            'error_message' => $domain->error_message,
            'heartbeat_at' => $domain->heartbeat_at,
            'started_at' => $domain->started_at,
            'completed_at' => in_array($status, ['preview_ready', 'imported', 'partial', 'reverted'], true)
                ? ($domain->completed_at ?: $domain->confirmed_at ?: $domain->updated_at)
                : null,
            'failed_at' => $status === 'failed' ? ($domain->failed_at ?: $domain->updated_at) : null,
            'reverted_at' => $status === 'reverted' ? ($domain->rolled_back_at ?: $domain->updated_at) : null,
            'updated_at' => $domain->updated_at ?: now(),
        ])->save();

        return $shared->fresh();
    }

    public function dataTable(Request $request, int $businessId, bool $canDelete): array
    {
        $this->ensureHistoryEnvelopes($businessId);
        $data = $this->batchService->dataTable($request, $businessId, self::IMPORTER_KEY);
        $sharedIds = collect($data['data'])->pluck('id')->all();
        if ($sharedIds === []) {
            return $data;
        }

        $sharedBatches = UserImportBatch::where('business_id', $businessId)
            ->where('importer_key', self::IMPORTER_KEY)
            ->whereIn('id', $sharedIds)
            ->get()->keyBy('id');
        $domainBatches = DB::table('expense_import_batches')
            ->where('business_id', $businessId)
            ->whereIn('user_import_batch_id', $sharedIds)
            ->get()->keyBy('user_import_batch_id');
        $domainIds = $domainBatches->pluck('id')->all();
        $domainIdsWithRawRows = $domainIds === [] ? [] : DB::table('expense_import_preview_rows')
            ->where('business_id', $businessId)
            ->whereIn('batch_id', $domainIds)
            ->whereNotNull('raw_payload')
            ->distinct()->pluck('batch_id')->all();
        $creatorIds = $sharedBatches->pluck('created_by')->filter()->unique()->values()->all();
        $creators = $creatorIds === [] ? collect() : User::whereIn('id', $creatorIds)
            ->get(['id', 'first_name', 'surname', 'last_name'])->keyBy('id');

        $data['data'] = collect($data['data'])->map(function (array $row) use ($sharedBatches, $domainBatches, $domainIdsWithRawRows, $creators, $businessId, $canDelete) {
            $shared = $sharedBatches->get($row['id']);
            if (! $shared) {
                return $row;
            }
            $domain = $domainBatches->get($shared->id);
            $summary = json_decode((string) $shared->summary_json, true) ?: [];
            $referenceList = json_decode((string) $shared->search_text, true);
            $referenceList = is_array($referenceList) ? $referenceList : [];
            $legacyBatchNumber = (int) ($summary['legacy_import_batch_number'] ?? $this->legacyNumberFromKey((string) $shared->source_key));
            $status = $shared->status;
            $state = $status;
            $operation = $domain->operation ?? 'import';
            $domainStatus = $domain->status ?? ($status === 'imported' ? 'imported' : null);
            $progress = [
                'current' => (int) $shared->progress_current,
                'total' => (int) $shared->progress_total,
                'percent' => (int) $shared->progress_percent,
                'message' => $shared->last_message,
                'heartbeat_at' => $shared->heartbeat_at,
                'error' => $shared->error_message,
            ];

            if ($domain) {
                $runtime = app(ExpenseImportProcessService::class)->historyProgress($domain, $businessId);
                $status = $runtime['status'] ?? $status;
                $state = $runtime['state'];
                $operation = $runtime['operation'];
                $domainStatus = $runtime['domain_status'];
                $progress = array_merge($progress, $runtime);
            }

            $isImported = $status === 'imported' && $operation === 'import';
            $hasImportResult = in_array($status, ['imported', 'partial', 'reverted'], true) && $operation === 'import';
            $isReady = $domain && $domainStatus === 'preview' && in_array($state, ['completed', 'failed'], true);
            $summaryText = $hasImportResult
                ? (int) ($summary['transactions'] ?? 0).' transaksi · '.(int) ($summary['rows'] ?? 0).' baris workbook'
                : (int) ($summary['valid'] ?? 0).' valid · '.(int) ($summary['errors'] ?? 0).' error';
            $progressMeta = (int) ($progress['current'] ?? 0).' / '.(int) ($progress['total'] ?? 0);
            if (! empty($progress['heartbeat_at'])) {
                $progressMeta .= ' · '.(string) $progress['heartbeat_at'];
            }
            $creator = $creators->get($shared->created_by);
            $createdBy = $creator
                ? trim(implode(' ', array_filter([$creator->surname, $creator->first_name, $creator->last_name])))
                : '-';
            $row['history_id'] = (int) $shared->id;
            $row['source_key'] = $shared->source_key;
            $row['batch_id'] = $domain->id ?? null;
            $row['batch_number'] = $legacyBatchNumber > 0 ? $legacyBatchNumber : null;
            $row['file_name'] = $shared->file_name ?: 'Impor Expense';
            $row['created_at'] = optional($shared->created_at)->format('Y-m-d H:i:s');
            $row['created_by'] = $createdBy !== '' ? $createdBy : '-';
            $row['status'] = $status;
            $row['status_label'] = $this->statusLabel($status);
            $row['status_class'] = $this->statusClass($status);
            $row['status_message'] = $progress['error'] ?: ($progress['message'] ?: $this->statusMessage($status));
            $row['process_state'] = $state;
            $row['progress_percent'] = (int) ($progress['percent'] ?? 0);
            $row['progress_current'] = (int) ($progress['current'] ?? 0);
            $row['progress_total'] = (int) ($progress['total'] ?? 0);
            $row['progress_meta'] = $progressMeta;
            $row['summary_text'] = $summaryText;
            $row['summary_detail'] = implode(', ', array_slice($referenceList, 0, 12));
            $row['reference_count'] = (int) ($summary['reference_count'] ?? count($referenceList));
            $row['can_preview'] = (bool) $isReady;
            $row['can_cancel'] = $domain && $state === 'queued';
            $row['done'] = in_array($status, ['imported', 'partial', 'cancelled', 'reverted'], true);
            $row['can_view_references'] = $isImported && $legacyBatchNumber > 0;
            $row['references_url'] = $row['can_view_references']
                ? route('expenses.import.references', [$domain ? (string) $domain->id : 'legacy-'.$legacyBatchNumber])
                : null;
            $hasSnapshot = $shared->hasSourceSnapshot() && Storage::disk('user_imports')->exists((string) $shared->source_snapshot_path);
            $hasLegacyRows = $domain && in_array($domain->id, $domainIdsWithRawRows, true);
            $canRestore = in_array($status, ['imported', 'partial', 'reverted'], true);
            $row['restore_url'] = $canRestore && ($hasSnapshot || $hasLegacyRows)
                ? route('expenses.import.restore-workbook', [(string) ($domain->id ?? 'legacy-'.$legacyBatchNumber)])
                : null;
            $row['revert_url'] = $legacyBatchNumber > 0 && $status === 'imported' && $canDelete
                ? action([\App\Http\Controllers\ExpenseController::class, 'revertImport'], $legacyBatchNumber)
                : null;

            return $row;
        })->values();

        return $data;
    }

    public function markReverted(int $businessId, int $legacyBatchNumber): void
    {
        DB::table('expense_import_batches')->where('business_id', $businessId)->orderBy('id')->get()
            ->each(function ($domain) use ($businessId, $legacyBatchNumber): void {
                $summary = json_decode((string) $domain->summary, true) ?: [];
                if ((int) ($summary['legacy_import_batch_number'] ?? 0) !== $legacyBatchNumber) {
                    return;
                }
                DB::table('expense_import_batches')->where('id', $domain->id)->where('business_id', $businessId)->update([
                    'status' => 'reverted',
                    'rolled_back_at' => now(),
                    'updated_at' => now(),
                ]);
                $this->syncFromDomainBatch((string) $domain->id);
            });

        UserImportBatch::where('business_id', $businessId)
            ->where('importer_key', self::IMPORTER_KEY)
            ->where('source_key', 'legacy:'.$legacyBatchNumber)
            ->update([
                'status' => 'reverted',
                'stage' => 'reverted',
                'progress_percent' => 100,
                'last_message' => 'Import Expense lama berhasil di-revert.',
                'reverted_at' => now(),
                'updated_at' => now(),
            ]);
    }

    public function legacyBatch(int $businessId, int $legacyBatchNumber): ?UserImportBatch
    {
        return UserImportBatch::where('business_id', $businessId)
            ->where('importer_key', self::IMPORTER_KEY)
            ->where('source_key', 'legacy:'.$legacyBatchNumber)
            ->first();
    }

    private function backfillLegacyBatches(int $businessId, array $referencesByBatchFile): void
    {
        $groups = DB::table('transactions')
            ->where('business_id', $businessId)
            ->where('type', 'expense')
            ->whereNotNull('import_batch')
            ->select('import_batch')
            ->selectRaw('MAX(import_file) as import_file')
            ->selectRaw('MIN(import_time) as import_time')
            ->selectRaw('MAX(created_by) as created_by')
            ->selectRaw('COUNT(*) as transaction_count')
            ->groupBy('import_batch')
            ->orderBy('import_batch')
            ->get();

        $domainBatches = DB::table('expense_import_batches')->where('business_id', $businessId)->get();
        $sharedById = UserImportBatch::where('business_id', $businessId)
            ->where('importer_key', self::IMPORTER_KEY)->get()->keyBy('id');
        $claimed = [];
        foreach ($domainBatches as $domain) {
            if (! in_array($domain->status, ['imported', 'reverted'], true) || $domain->operation !== 'import') {
                continue;
            }
            $summary = json_decode((string) $domain->summary, true) ?: [];
            $number = (int) ($summary['legacy_import_batch_number'] ?? 0);
            if ($number > 0) {
                $claimed[$domain->id] = [$number];
            }
        }

        $directlyClaimedNumbers = $this->claimedNumbers($claimed);
        $candidateGroups = [];
        $candidateCounts = [];
        foreach ($domainBatches as $domain) {
            if (! in_array($domain->status, ['imported', 'reverted'], true) || $domain->operation !== 'import' || isset($claimed[$domain->id])) {
                continue;
            }
            $domainTime = strtotime((string) ($domain->confirmed_at ?: $domain->updated_at ?: $domain->created_at));
            if (! $domainTime) {
                continue;
            }
            foreach ($groups as $group) {
                $number = (int) $group->import_batch;
                if (isset($directlyClaimedNumbers[$number])
                    || (string) $group->import_file !== (string) $domain->file_name) {
                    continue;
                }
                $legacyTime = strtotime((string) $group->import_time);
                if ($legacyTime && abs($legacyTime - $domainTime) <= 300) {
                    $candidateGroups[$domain->id][] = $number;
                    $candidateCounts[$number] = ($candidateCounts[$number] ?? 0) + 1;
                }
            }
        }

        foreach ($candidateGroups as $domainId => $numbers) {
            if (count($numbers) === 1 && ($candidateCounts[$numbers[0]] ?? 0) === 1) {
                $claimed[$domainId] = $numbers;
                $domain = $domainBatches->firstWhere('id', $domainId);
                if ($domain) {
                    $summary = json_decode((string) $domain->summary, true) ?: [];
                    if ((int) ($summary['legacy_import_batch_number'] ?? 0) === 0) {
                        $summary['legacy_import_batch_number'] = (int) $numbers[0];
                        $encodedSummary = json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                        DB::table('expense_import_batches')->where('business_id', $businessId)->where('id', $domainId)->update([
                            'summary' => $encodedSummary,
                        ]);
                        $domain->summary = $encodedSummary;
                        $shared = $sharedById->get($domain->user_import_batch_id);
                        if ($shared) {
                            $referenceKey = $this->referenceGroupKey((int) $numbers[0], (string) $domain->file_name);
                            $this->syncDomainRecord($domain, $shared, $referencesByBatchFile[$referenceKey] ?? []);
                        }
                    }
                }
            }
        }
        $claimedNumbers = $this->claimedNumbers($claimed);

        foreach ($groups as $group) {
            $number = (int) $group->import_batch;
            $sourceKey = 'legacy:'.$number;
            $existing = UserImportBatch::where('business_id', $businessId)
                ->where('importer_key', self::IMPORTER_KEY)
                ->where('source_key', $sourceKey)
                ->first();
            if (isset($claimedNumbers[$number])) {
                if ($existing) {
                    $existing->delete();
                }
                continue;
            }

            $referenceKey = $this->referenceGroupKey($number, (string) $group->import_file);
            $references = $referencesByBatchFile[$referenceKey] ?? [];
            $attributes = [
                'business_id' => $businessId,
                'created_by' => $group->created_by,
                'importer_key' => self::IMPORTER_KEY,
                'source_key' => $sourceKey,
                'file_name' => $group->import_file,
                'status' => $existing && $existing->status === 'reverted' ? 'reverted' : 'imported',
                'stage' => $existing && $existing->status === 'reverted' ? 'reverted' : 'imported',
                'progress_current' => (int) $group->transaction_count,
                'progress_total' => (int) $group->transaction_count,
                'progress_percent' => 100,
                'summary_json' => json_encode([
                    'legacy_import_batch_number' => $number,
                    'transactions' => (int) $group->transaction_count,
                    'rows' => (int) $group->transaction_count,
                    'reference_count' => count($references),
                    'legacy' => true,
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'search_text' => json_encode($references, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'last_message' => 'Import Expense lama sudah selesai.',
                'completed_at' => $group->import_time ?: now(),
                'updated_at' => $group->import_time ?: now(),
            ];
            if ($existing) {
                $existing->forceFill($attributes)->save();
            } else {
                $this->batchService->create($attributes);
            }
        }
    }

    private function claimedNumbers(array $claimed): array
    {
        $numbers = [];
        foreach ($claimed as $batchNumbers) {
            foreach ($batchNumbers as $number) {
                $numbers[(int) $number] = true;
            }
        }

        return $numbers;
    }

    private function referencesForImport(int $businessId, int $importBatchNumber, string $fileName): array
    {
        $query = DB::table('transactions')
            ->where('business_id', $businessId)
            ->where('type', 'expense')
            ->where('import_batch', $importBatchNumber)
            ->whereNotNull('ref_no')
            ->where('ref_no', '<>', '');
        if ($fileName !== '') {
            $query->where('import_file', $fileName);
        }

        return $query->orderBy('ref_no')->pluck('ref_no')->map(fn ($reference) => (string) $reference)->unique()->values()->all();
    }

    private function referencesByBatchFile(int $businessId): array
    {
        $rows = DB::table('transactions')
            ->where('business_id', $businessId)
            ->where('type', 'expense')
            ->whereNotNull('import_batch')
            ->whereNotNull('ref_no')
            ->where('ref_no', '<>', '')
            ->orderBy('ref_no')
            ->get(['import_batch', 'import_file', 'ref_no']);
        $references = [];
        foreach ($rows as $row) {
            $key = $this->referenceGroupKey((int) $row->import_batch, (string) $row->import_file);
            $references[$key][(string) $row->ref_no] = true;
        }

        foreach ($references as $key => $values) {
            $references[$key] = array_keys($values);
        }

        return $references;
    }

    private function referenceGroupKey(int $batchNumber, string $fileName): string
    {
        return $batchNumber.'|'.base64_encode($fileName);
    }

    private function legacyNumberFromKey(string $sourceKey): int
    {
        return preg_match('/^legacy:([1-9][0-9]*)$/D', $sourceKey, $matches) ? (int) $matches[1] : 0;
    }

    private function sharedStatus(object $domain): string
    {
        $state = (string) ($domain->process_state ?: 'completed');
        if ($domain->status === 'reverted' || $domain->rolled_back_at) {
            return 'reverted';
        }
        if ($state === 'cancelled' || $domain->status === 'cancelled') {
            return 'cancelled';
        }
        if ($state === 'failed') {
            return 'failed';
        }
        if ($domain->operation === 'import') {
            if ($state === 'queued') {
                return 'queued_import';
            }
            if ($state === 'processing') {
                return 'importing';
            }
            if ($domain->status === 'imported') {
                return 'imported';
            }
            if ($domain->status === 'partial') {
                return 'partial';
            }
        }
        if ($state === 'queued') {
            return 'queued_preview';
        }
        if ($state === 'processing') {
            return 'analyzing';
        }
        if ($state === 'completed' && $domain->status === 'preview') {
            return 'preview_ready';
        }

        return in_array($domain->status, ['imported', 'partial'], true) ? $domain->status : 'failed';
    }

    private function statusLabel(string $status): string
    {
        return [
            'queued_preview' => 'Menunggu preview',
            'analyzing' => 'Menganalisis workbook',
            'preview_ready' => 'Preview siap',
            'queued_import' => 'Menunggu import',
            'importing' => 'Import berjalan',
            'imported' => 'Import selesai',
            'partial' => 'Import sebagian',
            'failed' => 'Gagal',
            'cancelled' => 'Dibatalkan',
            'reverted' => 'Di-revert',
        ][$status] ?? ucfirst(str_replace('_', ' ', $status));
    }

    private function statusClass(string $status): string
    {
        return [
            'queued_preview' => 'warning',
            'analyzing' => 'warning',
            'preview_ready' => 'info',
            'queued_import' => 'warning',
            'importing' => 'warning',
            'imported' => 'success',
            'partial' => 'warning',
            'failed' => 'danger',
            'cancelled' => 'default',
            'reverted' => 'default',
        ][$status] ?? 'default';
    }

    private function statusMessage(string $status): string
    {
        return [
            'queued_preview' => 'Menunggu proses preview.',
            'analyzing' => 'Preview sedang diproses di antrean.',
            'preview_ready' => 'Preview siap diperiksa.',
            'queued_import' => 'Menunggu proses import.',
            'importing' => 'Import sedang diproses di antrean.',
            'imported' => 'Import selesai.',
            'partial' => 'Import selesai sebagian.',
            'failed' => 'Proses import gagal.',
            'cancelled' => 'Proses dibatalkan.',
            'reverted' => 'Import sudah di-revert.',
        ][$status] ?? '';
    }
}
