<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class SolarImportProcessService
{
    public const STALE_AFTER_SECONDS = 60;

    public function queued(string $batchId, int $businessId, string $operation, int $total = 0): void
    {
        DB::table('solar_import_batches')
            ->where('id', $batchId)
            ->where('business_id', $businessId)
            ->update([
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
                'heartbeat_at' => now(),
                'updated_at' => now(),
            ]);
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

        return $updated > 0;
    }

    public function update(string $batchId, int $businessId, string $stage, string $message, int $percent, ?int $current = null, ?int $total = null): void
    {
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

        DB::table('solar_import_batches')
            ->where('id', $batchId)
            ->where('business_id', $businessId)
            ->where('process_state', 'processing')
            ->update($values);
    }

    public function complete(string $batchId, int $businessId, string $message = 'Proses selesai.'): void
    {
        DB::table('solar_import_batches')
            ->where('id', $batchId)
            ->where('business_id', $businessId)
            ->update([
                'process_state' => 'completed',
                'stage' => 'finished',
                'progress_percent' => 100,
                'progress_message' => $message,
                'completed_at' => now(),
                'heartbeat_at' => now(),
                'updated_at' => now(),
            ]);
    }

    public function fail(string $batchId, int $businessId, string $message): void
    {
        DB::table('solar_import_batches')
            ->where('id', $batchId)
            ->where('business_id', $businessId)
            ->update([
                'process_state' => 'failed',
                'stage' => 'failed',
                'progress_message' => 'Proses gagal.',
                'error_message' => mb_substr($this->publicErrorMessage($message), 0, 500),
                'failed_at' => now(),
                'heartbeat_at' => now(),
                'updated_at' => now(),
            ]);
    }

    public function cancel(string $batchId, int $businessId): bool
    {
        return DB::table('solar_import_batches')
            ->where('id', $batchId)
            ->where('business_id', $businessId)
            ->where('process_state', 'queued')
            ->update([
                'process_state' => 'cancelled',
                'stage' => 'cancelled',
                'progress_message' => 'Proses dibatalkan.',
                'cancel_requested_at' => now(),
                'heartbeat_at' => now(),
                'updated_at' => now(),
            ]) > 0;
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

        $heartbeat = $batch->heartbeat_at ? Carbon::parse($batch->heartbeat_at) : null;
        $stale = in_array($batch->process_state, ['queued', 'processing'], true)
            && (! $heartbeat || $heartbeat->diffInSeconds(now()) > self::STALE_AFTER_SECONDS);

        $summary = $batch->operation === 'preview'
            ? app(SolarImportPreviewService::class)->summary($batchId, $businessId)
            : (json_decode((string) $batch->counts, true) ?: []);

        return [
            'batch_id' => $batch->id,
            'operation' => $batch->operation ?: 'preview',
            'domain_status' => $batch->status,
            'state' => $batch->process_state ?: 'completed',
            'stage' => $batch->stage ?: 'finished',
            'current' => (int) $batch->progress_current,
            'total' => (int) $batch->progress_total,
            'percent' => (int) $batch->progress_percent,
            'message' => $batch->progress_message,
            'heartbeat_at' => $batch->heartbeat_at,
            'started_at' => $batch->started_at,
            'completed_at' => $batch->completed_at,
            'error' => $batch->error_message ? $this->publicErrorMessage($batch->error_message) : null,
            'stale' => $stale,
            'summary' => $summary,
            'can_confirm' => $batch->operation === 'preview'
                && $batch->process_state === 'completed'
                && $batch->status === 'preview',
        ];
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
