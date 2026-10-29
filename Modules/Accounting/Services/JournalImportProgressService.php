<?php

namespace Modules\Accounting\Services;

use Illuminate\Support\Facades\DB;

class JournalImportProgressService
{
    public function record(
        int $importId,
        int $businessId,
        string $phase,
        int $processed,
        int $total,
        string $unit,
        array $counts = [],
        ?string $message = null,
        array $additionalValues = [],
        bool $allowCancellationReset = false
    ): array {
        $batch = DB::table('accounting_journal_imports')
            ->where('id', $importId)
            ->where('business_id', $businessId)
            ->first(['progress_data']);

        if (empty($batch)) {
            return [];
        }

        $progress = $this->decode($batch->progress_data ?? null);
        $progress['phase'] = $phase;
        $progress['phases'][$phase] = [
            'processed' => max(0, $processed),
            'total' => max(0, $total),
            'unit' => $unit,
            'counts' => $counts,
        ];

        $values = array_merge([
            'progress_data' => json_encode($progress, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'updated_at' => now(),
        ], $additionalValues);
        if ($message !== null) {
            $values['last_message'] = $message;
        }

        $query = DB::table('accounting_journal_imports')
            ->where('id', $importId)
            ->where('business_id', $businessId);
        if (! $allowCancellationReset) {
            $query->whereNull('cancel_requested_at');
        }
        if (! $query->update($values)) {
            $currentBatch = DB::table('accounting_journal_imports')
                ->where('id', $importId)
                ->where('business_id', $businessId)
                ->first(['cancel_requested_at']);

            if (empty($currentBatch) || (! $allowCancellationReset && ! empty($currentBatch->cancel_requested_at))) {
                return [];
            }
        }

        return $progress;
    }

    public function decode(?string $serialized): array
    {
        $progress = $serialized ? json_decode($serialized, true) : null;

        return is_array($progress) ? $progress : [];
    }

    public function currentPhase(array $progress): ?array
    {
        $phase = $progress['phase'] ?? null;
        if (! is_string($phase) || ! isset($progress['phases'][$phase]) || ! is_array($progress['phases'][$phase])) {
            return null;
        }

        return array_merge(['phase' => $phase], $progress['phases'][$phase]);
    }
}
