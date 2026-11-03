<?php

namespace Modules\Accounting\Services;

use App\AccountTransaction;
use App\Transaction;
use App\TransactionPayment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class GeneralLedgerBackfillManualJournalService
{
    private const REVIEW_TABLE = 'accounting_general_ledger_backfill_reviews';

    private $journalPosting;

    public function __construct(AccountingManualJournalPostingService $journalPosting)
    {
        $this->journalPosting = $journalPosting;
    }

    /**
     * Create a balanced Journal linked to one review source, atomically.
     * Repeating the call for a linked source returns the existing Journal.
     */
    public function create(int $businessId, int $reviewId, int $userId, string $reason, array $lines): array
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw ValidationException::withMessages(['reason' => 'Alasan jurnal manual wajib diisi.']);
        }
        if (count($lines) < 2 || count($lines) > 100) {
            throw ValidationException::withMessages(['lines' => 'Jurnal harus berisi 2 sampai 100 baris.']);
        }

        return DB::transaction(function () use ($businessId, $reviewId, $userId, $reason, $lines) {
            $review = DB::table(self::REVIEW_TABLE)
                ->where('business_id', $businessId)
                ->where('id', $reviewId)
                ->lockForUpdate()
                ->first();
            if (! $review) {
                throw ValidationException::withMessages(['source' => 'Item antrean tidak ditemukan pada business aktif.']);
            }
            if (! Schema::hasColumn('accounting_acc_trans_mappings', 'source_record_type')
                || ! Schema::hasColumn('accounting_acc_trans_mappings', 'source_record_id')) {
                throw ValidationException::withMessages(['source' => 'Source link Accounting belum tersedia; jalankan migrasi terlebih dahulu.']);
            }
            $existing = DB::table('accounting_acc_trans_mappings')
                ->where('business_id', $businessId)
                ->where('source_record_type', (string) $review->source_record_type)
                ->where('source_record_id', (int) $review->source_record_id)
                ->lockForUpdate()
                ->first(['id', 'ref_no']);
            if ($existing) {
                if ((string) $review->status !== 'resolved') {
                    $this->resolveQueueItem($review, $existing, $userId, $reason, true);
                }

                return [
                    'created' => false,
                    'journal_id' => (int) $existing->id,
                    'ref_no' => (string) $existing->ref_no,
                    'source_record_id' => (int) $review->source_record_id,
                ];
            }

            if (! in_array((string) $review->status, ['pending', 'retrying'], true)) {
                throw ValidationException::withMessages(['source' => 'Status antrean ini tidak dapat ditutup dengan jurnal manual.']);
            }

            $operationDate = $this->lockAndValidateSource($businessId, $review);
            $reference = 'GLR-'.$businessId.'-'.$reviewId;
            $note = 'Jurnal manual untuk sumber '.$this->sourceLabel((string) $review->source_record_type).' #'.(int) $review->source_record_id.'. Alasan: '.$reason;
            $posted = $this->journalPosting->create(
                $businessId,
                $userId,
                (string) $review->source_record_type,
                (int) $review->source_record_id,
                $reference,
                $operationDate,
                $reason,
                $lines,
                'general_ledger_backfill_manual',
                $note
            );
            $this->resolveQueueItem($review, (object) ['id' => $posted['journal_id'], 'ref_no' => $posted['ref_no']], $userId, $reason, ! $posted['created']);

            return [
                'created' => $posted['created'],
                'journal_id' => $posted['journal_id'],
                'ref_no' => $posted['ref_no'],
                'source_record_id' => (int) $review->source_record_id,
                'total' => $posted['total'],
            ];
        });
    }

    private function lockAndValidateSource(int $businessId, object $review): string
    {
        $sourceId = (int) $review->source_record_id;
        $sourceType = (string) $review->source_record_type;
        if ($sourceType === Transaction::class) {
            $query = DB::table('transactions')->where('id', $sourceId)->where('business_id', $businessId);
            if (Schema::hasColumn('transactions', 'deleted_at')) {
                $query->whereNull('deleted_at');
            }
            $source = $query->lockForUpdate()->first(['id', 'type', 'status', 'transaction_date']);
            if (! $source || ! $this->isPostedTransaction($source->type, $source->status)) {
                throw ValidationException::withMessages(['source' => 'Transaksi sumber tidak aktif atau bukan milik business ini.']);
            }

            return $this->validOperationDate($source->transaction_date ?? null, $review->operation_date ?? null);
        }

        if ($sourceType === TransactionPayment::class) {
            $query = DB::table('transaction_payments')
                ->where('id', $sourceId)
                ->where('business_id', $businessId);
            if (Schema::hasColumn('transaction_payments', 'deleted_at')) {
                $query->whereNull('deleted_at');
            }
            $payment = $query->lockForUpdate()->first(['id', 'transaction_id', 'paid_on']);
            $transaction = $payment ? DB::table('transactions')
                ->where('id', $payment->transaction_id)
                ->where('business_id', $businessId)
                ->when(Schema::hasColumn('transactions', 'deleted_at'), fn ($query) => $query->whereNull('deleted_at'))
                ->lockForUpdate()
                ->first(['id', 'type', 'status']) : null;
            if (! $payment || ! $transaction || ! $this->isPostedTransaction($transaction->type, $transaction->status)
                || ! in_array((string) $transaction->type, ['sell', 'purchase'], true)) {
                throw ValidationException::withMessages(['source' => 'Pembayaran sumber tidak aktif atau bukan milik business ini.']);
            }

            return $this->validOperationDate($payment->paid_on ?? null, $review->operation_date ?? null);
        }

        if ($sourceType === AccountTransaction::class) {
            $query = DB::table('account_transactions')->where('id', $sourceId);
            if (Schema::hasColumn('account_transactions', 'deleted_at')) {
                $query->whereNull('deleted_at');
            }
            $source = $query->lockForUpdate()->first(['id', 'account_id', 'operation_date']);
            $belongsToBusiness = $source && DB::table('accounts')
                ->where('business_id', $businessId)
                ->where('id', $source->account_id)
                ->when(Schema::hasColumn('accounts', 'deleted_at'), fn ($q) => $q->whereNull('deleted_at'))
                ->exists();
            if (! $source || ! $belongsToBusiness) {
                throw ValidationException::withMessages(['source' => 'Mutasi rekening sumber tidak aktif atau bukan milik business ini.']);
            }

            return $this->validOperationDate($source->operation_date ?? null, $review->operation_date ?? null);
        }

        throw ValidationException::withMessages(['source' => 'Jenis sumber ini belum didukung untuk jurnal manual.']);
    }

    private function validOperationDate($sourceDate, $reviewDate): string
    {
        $date = $sourceDate ?: $reviewDate;
        if (is_string($date) && trim($date) !== '') {
            return substr(trim($date), 0, 19);
        }

        return now()->toDateTimeString();
    }

    private function isPostedTransaction($type, $status): bool
    {
        $type = (string) $type;
        $status = strtolower(trim((string) $status));

        if ($type === 'sell' || $type === 'expense') {
            return $status === 'final';
        }

        return $type === 'purchase' && $status === 'received';
    }

    private function resolveQueueItem(object $review, object $mapping, int $userId, string $reason, bool $alreadyLinked): void
    {
        $audit = json_decode((string) ($review->audit_log ?? '[]'), true);
        $audit = is_array($audit) ? $audit : [];
        $now = now();
        $audit[] = [
            'event' => $alreadyLinked ? 'manual_journal_duplicate_link_resolved' : 'manual_journal_created',
            'at' => $now->toIso8601String(),
            'by' => $userId,
            'journal_id' => (int) $mapping->id,
            'ref_no' => (string) $mapping->ref_no,
            'reason' => $reason,
        ];
        DB::table(self::REVIEW_TABLE)
            ->where('business_id', (int) $review->business_id)
            ->where('id', (int) $review->id)
            ->update([
                'status' => 'resolved',
                'resolved_at' => $now,
                'last_seen_at' => $now,
                'audit_log' => json_encode($audit, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'updated_at' => $now,
            ]);
    }

    private function sourceLabel(string $sourceType): string
    {
        $parts = explode('\\', $sourceType);

        return (string) end($parts);
    }
}
