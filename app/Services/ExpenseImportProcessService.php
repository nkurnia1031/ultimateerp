<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class ExpenseImportProcessService
{
    public const STALE_AFTER_SECONDS = 60;

    public function queued(string $batchId, int $businessId, string $operation, int $total = 0): void
    {
        $heartbeat = now();
        $values = [
            'operation' => $operation,
            'process_state' => 'queued',
            'stage' => 'uploading',
            'progress_current' => 0,
            'progress_total' => $total,
            'progress_percent' => 0,
            'progress_message' => $operation === 'preview' ? 'Menunggu proses preview.' : 'Menunggu proses import.',
            'error_message' => null,
            'failed_at' => null,
            'completed_at' => null,
            'started_at' => null,
            'cancel_requested_at' => null,
            'heartbeat_at' => $heartbeat,
            'updated_at' => $heartbeat,
        ];
        DB::table('expense_import_batches')->where('id', $batchId)->where('business_id', $businessId)->update($values);
        $this->cacheProgress($batchId, $businessId, $values);
        $this->syncHistory($batchId);
    }

    public function start(string $batchId, int $businessId, string $stage, string $message, int $percent = 1): bool
    {
        $heartbeat = now();
        $values = [
            'process_state' => 'processing', 'stage' => $stage,
            'progress_percent' => max(0, min(99, $percent)), 'progress_message' => $message,
            'started_at' => $heartbeat, 'heartbeat_at' => $heartbeat, 'updated_at' => $heartbeat,
        ];
        $started = DB::table('expense_import_batches')->where('id', $batchId)->where('business_id', $businessId)
            ->where('process_state', 'queued')->whereNull('cancel_requested_at')->update($values) > 0;
        if ($started) {
            $this->cacheProgress($batchId, $businessId, array_merge($values, [
                'progress_current' => 0,
            ]));
            $this->syncHistory($batchId);
        }
        return $started;
    }

    public function update(string $batchId, int $businessId, string $stage, string $message, int $percent, ?int $current = null, ?int $total = null): void
    {
        $heartbeat = now();
        $values = [
            'stage' => $stage, 'progress_percent' => max(0, min(99, $percent)),
            'progress_message' => $message, 'heartbeat_at' => $heartbeat, 'updated_at' => $heartbeat,
        ];
        if ($current !== null) {
            $values['progress_current'] = max(0, $current);
        }
        if ($total !== null) {
            $values['progress_total'] = max(0, $total);
        }
        // Preview and import run in a long database transaction. Updating the
        // batch row from that transaction would lock the row and block the
        // polling request until the entire workbook commits. Cache the
        // heartbeat/progress so it is visible immediately, and only write the
        // row directly when no transaction is active.
        $this->cacheProgress($batchId, $businessId, $values);

        if (DB::transactionLevel() === 0) {
            DB::table('expense_import_batches')->where('id', $batchId)->where('business_id', $businessId)
                ->where('process_state', 'processing')->update($values);
            $this->syncHistory($batchId);
        }
    }

    public function complete(string $batchId, int $businessId, string $message): void
    {
        Cache::forget($this->progressCacheKey($batchId, $businessId));
        DB::table('expense_import_batches')->where('id', $batchId)->where('business_id', $businessId)->update([
            'process_state' => 'completed', 'stage' => 'finished', 'progress_percent' => 100,
            'progress_message' => $message, 'completed_at' => now(), 'heartbeat_at' => now(), 'updated_at' => now(),
        ]);
        $this->syncHistory($batchId);
    }

    public function fail(string $batchId, int $businessId, string $message): void
    {
        Cache::forget($this->progressCacheKey($batchId, $businessId));
        DB::table('expense_import_batches')->where('id', $batchId)->where('business_id', $businessId)->update([
            'process_state' => 'failed', 'stage' => 'failed', 'progress_message' => 'Proses gagal.',
            'error_message' => mb_substr($message, 0, 65000), 'failed_at' => now(), 'heartbeat_at' => now(), 'updated_at' => now(),
        ]);
        $this->syncHistory($batchId);
    }

    public function cancel(string $batchId, int $businessId): bool
    {
        $cancelled = DB::table('expense_import_batches')->where('id', $batchId)->where('business_id', $businessId)
            ->where('process_state', 'queued')->update([
                'process_state' => 'cancelled', 'status' => 'cancelled', 'stage' => 'cancelled',
                'progress_message' => 'Proses dibatalkan.', 'cancel_requested_at' => now(), 'heartbeat_at' => now(), 'updated_at' => now(),
            ]) > 0;
        if ($cancelled) {
            Cache::forget($this->progressCacheKey($batchId, $businessId));
            $this->syncHistory($batchId);
        }
        return $cancelled;
    }

    public function status(string $batchId, int $businessId): array
    {
        $batch = DB::table('expense_import_batches')->where('id', $batchId)->where('business_id', $businessId)->first();
        abort_if(! $batch, 404, 'Batch import Expense tidak ditemukan.');
        $cachedProgress = Cache::get($this->progressCacheKey($batchId, $businessId), []);
        $cachedProgress = is_array($cachedProgress) ? $cachedProgress : [];
        $heartbeatValue = $cachedProgress['heartbeat_at'] ?? $batch->heartbeat_at;
        $heartbeat = $heartbeatValue ? Carbon::parse($heartbeatValue) : null;
        $state = $batch->process_state ?: 'completed';
        $stale = in_array($state, ['queued', 'processing'], true) && (! $heartbeat || $heartbeat->diffInSeconds(now()) > self::STALE_AFTER_SECONDS);
        $startedAt = $batch->started_at ? Carbon::parse($batch->started_at) : null;
        $finishedAt = $batch->completed_at ? Carbon::parse($batch->completed_at) : now();
        $elapsedSeconds = $startedAt ? max(0, $startedAt->diffInSeconds($finishedAt)) : 0;
        $summary = ($batch->operation ?: 'preview') === 'import'
            ? (json_decode((string) $batch->summary, true) ?: [])
            : app(ExpenseImportService::class)->summary($batchId, $businessId);

        return [
            'batch_id' => $batch->id,
            'domain_status' => $batch->status,
            'operation' => $batch->operation ?: 'preview',
            'state' => $state,
            'stage' => $cachedProgress['stage'] ?? ($batch->stage ?: 'finished'),
            'current' => (int) ($cachedProgress['progress_current'] ?? $batch->progress_current),
            'total' => (int) ($cachedProgress['progress_total'] ?? $batch->progress_total),
            'percent' => (int) ($cachedProgress['progress_percent'] ?? $batch->progress_percent),
            'message' => $cachedProgress['progress_message'] ?? $batch->progress_message,
            'heartbeat_at' => $heartbeatValue,
            'started_at' => $batch->started_at,
            'completed_at' => $batch->completed_at,
            'elapsed_seconds' => $elapsedSeconds,
            'error' => $batch->error_message,
            'stale' => $stale,
            'summary' => $summary,
            'can_confirm' => $state === 'completed' && $batch->status === 'preview' && (int) ($summary['importable'] ?? 0) > 0,
        ];
    }

    public function historyProgress(string|object $batch, int $businessId): array
    {
        if (is_string($batch)) {
            $batch = DB::table('expense_import_batches')->where('id', $batch)->where('business_id', $businessId)->first();
        }
        if (! $batch) {
            return [];
        }

        $cached = Cache::get($this->progressCacheKey((string) $batch->id, $businessId), []);
        $cached = is_array($cached) ? $cached : [];
        $state = (string) ($batch->process_state ?: 'completed');
        $operation = (string) ($batch->operation ?: 'preview');
        $domainStatus = (string) $batch->status;
        $status = match (true) {
            $domainStatus === 'reverted' || $batch->rolled_back_at => 'reverted',
            $state === 'cancelled' || $domainStatus === 'cancelled' => 'cancelled',
            $state === 'failed' => 'failed',
            $operation === 'import' && $state === 'queued' => 'queued_import',
            $operation === 'import' && $state === 'processing' => 'importing',
            $operation === 'import' && $domainStatus === 'imported' => 'imported',
            $operation === 'import' && $domainStatus === 'partial' => 'partial',
            $state === 'queued' => 'queued_preview',
            $state === 'processing' => 'analyzing',
            $state === 'completed' && $domainStatus === 'preview' => 'preview_ready',
            default => $domainStatus,
        };

        return [
            'status' => $status,
            'state' => $state,
            'operation' => $operation,
            'domain_status' => $domainStatus,
            'current' => (int) ($cached['progress_current'] ?? $batch->progress_current),
            'total' => (int) ($cached['progress_total'] ?? $batch->progress_total),
            'percent' => (int) ($cached['progress_percent'] ?? $batch->progress_percent),
            'message' => $cached['progress_message'] ?? $batch->progress_message,
            'heartbeat_at' => $cached['heartbeat_at'] ?? $batch->heartbeat_at,
            'error' => $batch->error_message,
        ];
    }

    private function syncHistory(string $batchId): void
    {
        try {
            app(ExpenseImportHistoryService::class)->syncFromDomainBatch($batchId);
        } catch (\Throwable $exception) {
            \Log::warning('Expense shared import history sync failed.', [
                'batch_id' => $batchId,
                'message' => $exception->getMessage(),
            ]);
        }
    }

    private function progressCacheKey(string $batchId, int $businessId): string
    {
        return "expense_import_progress:{$businessId}:{$batchId}";
    }

    private function cacheProgress(string $batchId, int $businessId, array $values): void
    {
        $progress = [];
        foreach (['stage', 'progress_percent', 'progress_message', 'progress_current', 'progress_total', 'heartbeat_at'] as $field) {
            if (! array_key_exists($field, $values)) {
                continue;
            }
            $value = $values[$field];
            $progress[$field] = $value instanceof \DateTimeInterface ? $value->toDateTimeString() : $value;
        }
        if ($progress !== []) {
            Cache::put($this->progressCacheKey($batchId, $businessId), $progress, now()->addHours(2));
        }
    }
}
