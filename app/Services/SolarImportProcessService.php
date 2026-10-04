<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class SolarImportProcessService
{
    public const STALE_AFTER_SECONDS = 60;

    private const CACHE_TTL_SECONDS = 14400;

    private const MAX_EVENTS_PER_BATCH = 300;

    public function queued(string $batchId, int $businessId, string $operation, int $total = 0): void
    {
        $message = $operation === 'preview' ? 'File diterima dan menunggu worker queue Solar.' : 'Import masuk antrean worker Solar.';
        DB::table('solar_import_batches')
            ->where('id', $batchId)
            ->where('business_id', $businessId)
            ->update([
                'operation' => $operation,
                'process_state' => 'queued',
                'stage' => 'queued',
                'progress_current' => 0,
                'progress_total' => $total,
                'progress_percent' => 0,
                'progress_message' => $message,
                'error_message' => null,
                'failed_at' => null,
                'completed_at' => null,
                'started_at' => null,
                'cancel_requested_at' => null,
                'heartbeat_at' => now(),
                'updated_at' => now(),
            ]);

        $this->rememberStatus($batchId, $businessId, [
            'operation' => $operation,
            'process_state' => 'queued',
            'stage' => 'queued',
            'progress_current' => 0,
            'progress_total' => $total,
            'progress_percent' => 0,
            'progress_message' => $message,
            'heartbeat_at' => now()->toDateTimeString(),
            'started_at' => null,
        ]);
        $this->appendEvent($batchId, $businessId, 'queued', $message, 0, 0, $total);
    }

    public function start(string $batchId, int $businessId, string $stage, string $message, int $percent = 1): bool
    {
        $updated = DB::table('solar_import_batches')
            ->where('id', $batchId)
            ->where('business_id', $businessId)
            ->where('process_state', 'queued')
            ->whereNull('cancel_requested_at')
            ->update([
                'process_state' => 'processing',
                'stage' => $stage,
                'progress_percent' => max(0, min(99, $percent)),
                'progress_message' => $message,
                'started_at' => now(),
                'heartbeat_at' => now(),
                'updated_at' => now(),
            ]);

        if ($updated > 0) {
            $this->update($batchId, $businessId, $stage, $message, $percent);
        }

        return $updated > 0;
    }

    public function update(
        string $batchId,
        int $businessId,
        string $stage,
        string $message,
        int $percent,
        ?int $current = null,
        ?int $total = null,
        array $context = []
    ): void {
        $values = [
            'stage' => $stage,
            'progress_percent' => max(0, min(99, $percent)),
            'progress_message' => $message,
            'heartbeat_at' => now(),
            'updated_at' => now(),
        ];
        if ($current !== null) {
            $values['progress_current'] = max(0, $current);
        }
        if ($total !== null) {
            $values['progress_total'] = max(0, $total);
        }

        $live = [
            'process_state' => 'processing',
            'stage' => $stage,
            'progress_percent' => $values['progress_percent'],
            'progress_message' => $message,
            'heartbeat_at' => $values['heartbeat_at']->toDateTimeString(),
            'context' => $context,
        ];
        if ($current !== null) {
            $live['progress_current'] = max(0, $current);
        }
        if ($total !== null) {
            $live['progress_total'] = max(0, $total);
        }
        $this->rememberStatus($batchId, $businessId, $live);
        $this->appendEvent($batchId, $businessId, $stage, $message, $percent, $current, $total, $context);

        DB::table('solar_import_batches')
            ->where('id', $batchId)
            ->where('business_id', $businessId)
            ->where('process_state', 'processing')
            ->update($values);
    }

    public function complete(string $batchId, int $businessId, string $message = 'Proses selesai.'): void
    {
        $this->finishStatus($batchId, $businessId, 'completed', 'finished', $message, null);
        $this->appendEvent($batchId, $businessId, 'finished', $message, 100);
        $this->flushPendingEvents($batchId, $businessId);
    }

    public function fail(string $batchId, int $businessId, string $message): void
    {
        $publicMessage = $this->publicErrorMessage($message);
        $this->finishStatus($batchId, $businessId, 'failed', 'failed', 'Proses gagal.', $publicMessage);
        $this->appendEvent($batchId, $businessId, 'failed', $publicMessage, null, null, null, [], 'error');
        $this->flushPendingEvents($batchId, $businessId);
    }

    public function cancel(string $batchId, int $businessId): bool
    {
        $cancelled = DB::table('solar_import_batches')
            ->where('id', $batchId)
            ->where('business_id', $businessId)
            ->where('process_state', 'queued')
            ->update([
                'process_state' => 'cancelled',
                'stage' => 'cancelled',
                'progress_message' => 'Proses dibatalkan sebelum worker mulai.',
                'cancel_requested_at' => now(),
                'heartbeat_at' => now(),
                'completed_at' => now(),
                'updated_at' => now(),
            ]) > 0;

        if ($cancelled) {
            $this->finishStatus($batchId, $businessId, 'cancelled', 'cancelled', 'Proses dibatalkan sebelum worker mulai.', null);
            $this->appendEvent($batchId, $businessId, 'cancelled', 'Proses dibatalkan sebelum worker mulai.', null);
            $this->flushPendingEvents($batchId, $businessId);
        }

        return $cancelled;
    }

    public function isCancelled(string $batchId, int $businessId): bool
    {
        return DB::table('solar_import_batches')
            ->where('id', $batchId)
            ->where('business_id', $businessId)
            ->whereNotNull('cancel_requested_at')
            ->exists();
    }

    public function status(string $batchId, int $businessId): array
    {
        $batch = DB::table('solar_import_batches')
            ->where('id', $batchId)
            ->where('business_id', $businessId)
            ->first();
        abort_if(! $batch, 404, 'Batch import tidak ditemukan.');

        $live = Cache::get($this->statusKey($batchId));
        if (is_array($live) && ($live['business_id'] ?? null) === $businessId) {
            foreach ($live as $field => $value) {
                if (in_array($field, ['business_id', 'batch_id'], true)) {
                    continue;
                }
                $batch->{$field} = $value;
            }
        }

        $heartbeat = $batch->heartbeat_at ? Carbon::parse($batch->heartbeat_at) : null;
        $state = $batch->process_state ?: 'completed';
        $stale = in_array($state, ['queued', 'processing'], true)
            && (! $heartbeat || $heartbeat->diffInSeconds(now()) > self::STALE_AFTER_SECONDS);
        $summary = $batch->operation === 'preview'
            ? app(SolarImportPreviewService::class)->summary($batchId, $businessId)
            : (json_decode((string) $batch->counts, true) ?: []);
        $lastEvent = (int) DB::table('solar_import_batch_events')->where('batch_id', $batchId)->where('business_id', $businessId)->max('sequence');
        $cachedEvents = Cache::get($this->eventsKey($batchId), []);
        if (is_array($cachedEvents) && $cachedEvents !== []) {
            $lastEvent = max($lastEvent, (int) max(array_column($cachedEvents, 'id')));
        }
        $warnings = json_decode((string) ($batch->preview_warnings ?? '[]'), true) ?: [];

        return [
            'batch_id' => $batch->id,
            'operation' => $batch->operation ?: 'preview',
            'domain_status' => $batch->status,
            'state' => $state,
            'stage' => $batch->stage ?: 'finished',
            'current' => (int) $batch->progress_current,
            'total' => (int) $batch->progress_total,
            'percent' => (int) $batch->progress_percent,
            'message' => $batch->progress_message,
            'heartbeat_at' => $batch->heartbeat_at,
            'started_at' => $batch->started_at,
            'created_at' => $batch->created_at,
            'completed_at' => $batch->completed_at,
            'error' => $batch->error_message ? $this->publicErrorMessage($batch->error_message) : null,
            'stale' => $stale,
            'activity_age_seconds' => $heartbeat ? max(0, $heartbeat->diffInSeconds(now())) : null,
            'activity_context' => $live['context'] ?? [],
            'last_event_id' => $lastEvent,
            'preview_warnings' => $warnings,
            'summary' => $summary,
            'can_confirm' => $batch->operation === 'preview'
                && $state === 'completed'
                && $batch->status === 'preview'
                && ! collect($warnings)->contains(fn ($warning) => ! empty($warning['blocking'])),
        ];
    }

    public function events(string $batchId, int $businessId, int $afterId = 0): array
    {
        abort_unless(DB::table('solar_import_batches')->where('id', $batchId)->where('business_id', $businessId)->exists(), 404, 'Batch import tidak ditemukan.');

        $stored = DB::table('solar_import_batch_events')
            ->where('batch_id', $batchId)
            ->where('business_id', $businessId)
            ->where('sequence', '>', max(0, $afterId))
            ->orderBy('sequence')
            ->limit(100)
            ->get()
            ->map(fn ($event) => [
                'id' => (int) $event->sequence,
                'stage' => $event->stage,
                'level' => $event->level,
                'message' => $event->message,
                'percent' => $event->progress_percent === null ? null : (int) $event->progress_percent,
                'current' => $event->progress_current === null ? null : (int) $event->progress_current,
                'total' => $event->progress_total === null ? null : (int) $event->progress_total,
                'context' => json_decode((string) $event->context, true) ?: [],
                'created_at' => $event->created_at,
            ])->all();

        $cached = Cache::get($this->eventsKey($batchId), []);
        $pending = collect(is_array($cached) ? $cached : [])
            ->filter(fn ($event) => ($event['business_id'] ?? null) === $businessId && (int) ($event['id'] ?? 0) > $afterId)
            ->map(function ($event) {
                unset($event['business_id'], $event['batch_id'], $event['pending']);
                return $event;
            })->all();

        return collect($stored)->keyBy('id')->merge(collect($pending)->keyBy('id'))->sortKeys()->take(100)->values()->all();
    }

    public function flushPendingEvents(string $batchId, int $businessId): void
    {
        $events = Cache::get($this->eventsKey($batchId), []);
        $events = is_array($events) ? $events : [];
        foreach ($events as $index => $event) {
            if (($event['business_id'] ?? null) !== $businessId || empty($event['pending'])) {
                continue;
            }
            DB::table('solar_import_batch_events')->insertOrIgnore($this->eventPayload($event));
            $events[$index]['pending'] = false;
        }
        Cache::put($this->eventsKey($batchId), array_slice($events, -self::MAX_EVENTS_PER_BATCH), self::CACHE_TTL_SECONDS);
        $this->pruneEvents($batchId, $businessId);
    }

    private function finishStatus(string $batchId, int $businessId, string $state, string $stage, string $message, ?string $error): void
    {
        $values = [
            'process_state' => $state,
            'stage' => $stage,
            'progress_message' => $message,
            'error_message' => $error ? mb_substr($error, 0, 500) : null,
            'completed_at' => now(),
            'heartbeat_at' => now(),
            'updated_at' => now(),
        ];
        if ($state === 'completed') {
            $values['progress_percent'] = 100;
        }
        if ($state === 'failed') {
            $values['failed_at'] = now();
        }
        DB::table('solar_import_batches')->where('id', $batchId)->where('business_id', $businessId)->update($values);
        $this->rememberStatus($batchId, $businessId, $values);
    }

    private function appendEvent(
        string $batchId,
        int $businessId,
        string $stage,
        string $message,
        ?int $percent,
        ?int $current = null,
        ?int $total = null,
        array $context = [],
        string $level = 'info'
    ): void {
        $sequenceKey = $this->sequenceKey($batchId);
        $sequence = Cache::get($sequenceKey);
        if ($sequence === null) {
            $sequence = (int) DB::table('solar_import_batch_events')->where('batch_id', $batchId)->max('sequence');
        }
        $sequence = (int) $sequence + 1;
        Cache::put($sequenceKey, $sequence, self::CACHE_TTL_SECONDS);

        $event = [
            'id' => $sequence,
            'batch_id' => $batchId,
            'business_id' => $businessId,
            'stage' => $stage,
            'level' => $level,
            'message' => mb_substr(trim($message), 0, 1000),
            'percent' => $percent === null ? null : max(0, min(100, $percent)),
            'current' => $current === null ? null : max(0, $current),
            'total' => $total === null ? null : max(0, $total),
            'context' => $context,
            'created_at' => now()->toDateTimeString(),
            'pending' => DB::transactionLevel() > 0,
        ];
        if (! $event['pending']) {
            DB::table('solar_import_batch_events')->insertOrIgnore($this->eventPayload($event));
        }
        $events = Cache::get($this->eventsKey($batchId), []);
        $events = is_array($events) ? $events : [];
        $events[] = $event;
        Cache::put($this->eventsKey($batchId), array_slice($events, -self::MAX_EVENTS_PER_BATCH), self::CACHE_TTL_SECONDS);
        if (! $event['pending']) {
            $this->pruneEvents($batchId, $businessId);
        }
    }

    private function eventPayload(array $event): array
    {
        return [
            'batch_id' => $event['batch_id'],
            'business_id' => $event['business_id'],
            'sequence' => $event['id'],
            'stage' => $event['stage'],
            'level' => $event['level'],
            'message' => $event['message'],
            'progress_percent' => $event['percent'],
            'progress_current' => $event['current'],
            'progress_total' => $event['total'],
            'context' => json_encode($event['context'], JSON_UNESCAPED_UNICODE),
            'created_at' => $event['created_at'],
        ];
    }

    private function pruneEvents(string $batchId, int $businessId): void
    {
        $oldestKept = DB::table('solar_import_batch_events')
            ->where('batch_id', $batchId)
            ->where('business_id', $businessId)
            ->orderByDesc('sequence')
            ->skip(self::MAX_EVENTS_PER_BATCH)
            ->value('sequence');
        if ($oldestKept !== null) {
            DB::table('solar_import_batch_events')->where('batch_id', $batchId)->where('business_id', $businessId)->where('sequence', '<=', $oldestKept)->delete();
        }
    }

    private function rememberStatus(string $batchId, int $businessId, array $values): void
    {
        $current = Cache::get($this->statusKey($batchId), []);
        $current = is_array($current) && ($current['business_id'] ?? null) === $businessId ? $current : [];
        Cache::put($this->statusKey($batchId), array_merge($current, $values, [
            'batch_id' => $batchId,
            'business_id' => $businessId,
            'context' => $values['context'] ?? ($current['context'] ?? []),
        ]), self::CACHE_TTL_SECONDS);
    }

    private function statusKey(string $batchId): string
    {
        return 'solar-import:'.$batchId.':live-status';
    }

    private function eventsKey(string $batchId): string
    {
        return 'solar-import:'.$batchId.':events';
    }

    private function sequenceKey(string $batchId): string
    {
        return 'solar-import:'.$batchId.':event-sequence';
    }

    private function publicErrorMessage(string $message): string
    {
        $message = trim(strip_tags($message));
        if (mb_strlen($message) > 500 || preg_match('/SQLSTATE|Stack trace|Exception:|Error:/i', $message)) {
            return 'Proses gagal karena kesalahan sistem. Coba proses ulang. Jika masih gagal, hubungi administrator dengan ID batch.';
        }

        return $message !== '' ? $message : 'Proses gagal. Coba proses ulang atau hubungi administrator dengan ID batch.';
    }
}
