<?php

namespace Modules\Accounting\Services;

use Illuminate\Support\Facades\DB;
use Modules\Accounting\Entities\JournalImportBatchStatus;

class JournalImportCancellationService
{
    public function requestStop(int $importId, int $businessId, int $userId): array
    {
        return DB::transaction(function () use ($importId, $businessId, $userId) {
            $batch = DB::table('accounting_journal_imports')
                ->where('id', $importId)
                ->where('business_id', $businessId)
                ->lockForUpdate()
                ->first();

            if (empty($batch)) {
                return ['result' => 'not_found'];
            }

            if ($batch->status === 'cancelled') {
                return ['result' => 'cancelled', 'status' => 'cancelled'];
            }

            if (! JournalImportBatchStatus::isActive((string) $batch->status)) {
                return ['result' => 'not_active', 'status' => $batch->status];
            }

            if (! empty($batch->cancel_requested_at)) {
                return ['result' => 'already_requested', 'status' => $batch->status];
            }

            $requestedAt = now();
            DB::table('accounting_journal_imports')
                ->where('id', $importId)
                ->where('business_id', $businessId)
                ->update([
                    'cancel_requested_at' => $requestedAt,
                    'cancel_requested_by' => $userId,
                    'last_message' => 'Permintaan terminate diterima; proses akan berhenti pada checkpoint aman berikutnya.',
                    'updated_at' => $requestedAt,
                ]);

            if (JournalImportBatchStatus::isQueued((string) $batch->status)) {
                $batch->cancel_requested_at = $requestedAt;
                $batch->cancel_requested_by = $userId;
                $this->finishStop($batch);

                return ['result' => 'cancelled', 'status' => 'cancelled'];
            }

            return ['result' => 'requested', 'status' => $batch->status];
        });
    }

    public function isStopRequested(int $importId, int $businessId): bool
    {
        return DB::table('accounting_journal_imports')
            ->where('id', $importId)
            ->where('business_id', $businessId)
            ->whereNotNull('cancel_requested_at')
            ->exists();
    }

    public function stopIfRequested(int $importId, int $businessId): bool
    {
        return DB::transaction(function () use ($importId, $businessId) {
            $batch = DB::table('accounting_journal_imports')
                ->where('id', $importId)
                ->where('business_id', $businessId)
                ->lockForUpdate()
                ->first();

            if (empty($batch) || empty($batch->cancel_requested_at)) {
                return false;
            }

            if ($batch->status === 'cancelled') {
                return true;
            }

            if (! JournalImportBatchStatus::isActive((string) $batch->status)) {
                return false;
            }

            $this->finishStop($batch);

            return true;
        });
    }

    private function finishStop(object $batch): void
    {
        $now = now();
        DB::table('accounting_journal_import_rows')
            ->where('journal_import_id', $batch->id)
            ->where('business_id', $batch->business_id)
            ->whereIn('processing_status', ['queued', 'running'])
            ->update([
                'processing_status' => 'pending',
                'processing_message' => 'Belum selesai karena import dihentikan; baris dapat dilanjutkan dari web.',
                'processing_started_at' => null,
                'updated_at' => $now,
            ]);

        $counts = array_fill_keys(['pending', 'queued', 'running', 'completed', 'failed'], 0);
        DB::table('accounting_journal_import_rows')
            ->where('journal_import_id', $batch->id)
            ->where('business_id', $batch->business_id)
            ->select('processing_status', DB::raw('COUNT(*) as row_count'))
            ->groupBy('processing_status')
            ->get()
            ->each(function ($row) use (&$counts) {
                if (array_key_exists($row->processing_status, $counts)) {
                    $counts[$row->processing_status] = (int) $row->row_count;
                }
            });

        DB::table('accounting_journal_imports')
            ->where('id', $batch->id)
            ->where('business_id', $batch->business_id)
            ->update([
                'status' => 'cancelled',
                'cancelled_at' => $now,
                'cancelled_by' => $batch->cancel_requested_by,
                'processed_entries' => $counts['completed'] + $counts['failed'],
                'imported_entries' => $counts['completed'],
                'skipped_entries' => $counts['failed'],
                'last_message' => 'Import dihentikan dengan aman. Hasil yang sudah tersimpan dipertahankan; baris yang belum mulai dapat dilanjutkan.',
                'updated_at' => $now,
            ]);
    }
}
