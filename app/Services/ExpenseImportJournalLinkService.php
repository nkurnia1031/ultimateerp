<?php

namespace App\Services;

use App\Transaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Accounting\Services\JournalImportSourceLinkService;
use RuntimeException;

class ExpenseImportJournalLinkService
{
    private $sourceLinkService;

    public function __construct(?JournalImportSourceLinkService $sourceLinkService = null)
    {
        $this->sourceLinkService = $sourceLinkService ?: new JournalImportSourceLinkService();
    }

    public function lockSelectedJournal(array $match, int $businessId, string $reference): array
    {
        $candidate = $match['selected'] ?? null;
        if (! is_array($candidate) || empty($candidate['mapping_id']) || empty($candidate['journal_row_id'])) {
            throw new RuntimeException('Pilihan Journal Expense tidak lagi tersedia. Muat ulang preview sebelum mengimpor.');
        }

        $row = DB::table('accounting_journal_import_rows')
            ->where('id', (int) $candidate['journal_row_id'])
            ->where('journal_import_id', (int) ($candidate['journal_import_id'] ?? 0))
            ->where('business_id', $businessId)
            ->lockForUpdate()
            ->first();
        $mapping = DB::table('accounting_acc_trans_mappings')
            ->where('id', (int) $candidate['mapping_id'])
            ->where('business_id', $businessId)
            ->where('type', 'journal_entry')
            ->lockForUpdate()
            ->first();
        $importStatus = DB::table('accounting_journal_imports')
            ->where('id', (int) ($candidate['journal_import_id'] ?? 0))
            ->where('business_id', $businessId)
            ->value('status');

        if (! $row || ! $mapping || $row->processing_status !== 'completed'
            || (int) $row->journal_mapping_id !== (int) $mapping->id
            || $importStatus === 'reverted') {
            throw new RuntimeException('Posting Journal berubah atau tidak lagi aktif. Muat ulang preview sebelum mengimpor.');
        }

        $entry = json_decode((string) $row->entry_data, true);
        $entry = is_array($entry) ? $entry : [];
        $journalReference = trim((string) ($entry['source_number'] ?? $row->source_number ?? ''));
        if ($this->normalizeReference($journalReference) !== $this->normalizeReference($reference)) {
            throw new RuntimeException('Nomor referensi Journal tidak lagi sama dengan kelompok Expense.');
        }

        $linkedRecords = [];
        foreach ([
            [$mapping->source_record_type ?? null, $mapping->source_record_id ?? null],
            [$row->source_record_type ?? null, $row->source_record_id ?? null],
        ] as [$recordType, $recordId]) {
            if (! empty($recordType) && ! empty($recordId)) {
                $linkedRecords[(string) $recordType.':'.(int) $recordId] = [
                    'type' => (string) $recordType,
                    'id' => (int) $recordId,
                ];
            }
        }
        $sourceLinkGroup = is_array($entry['source_link_group'] ?? null) ? $entry['source_link_group'] : [];
        $sourceLinkMembers = $sourceLinkGroup['members'] ?? [];
        foreach (is_array($sourceLinkMembers) ? $sourceLinkMembers : [] as $member) {
            if (is_array($member) && ! empty($member['record_type']) && ! empty($member['id'])) {
                $linkedRecords[(string) $member['record_type'].':'.(int) $member['id']] = [
                    'type' => (string) $member['record_type'],
                    'id' => (int) $member['id'],
                ];
            }
        }

        if (Schema::hasTable('accounting_journal_import_source_links')) {
            DB::table('accounting_journal_import_source_links')
                ->where('business_id', $businessId)
                ->where('journal_import_row_id', (int) $row->id)
                ->get(['source_record_type', 'source_record_id'])
                ->each(function ($link) use (&$linkedRecords) {
                    if (! empty($link->source_record_type) && ! empty($link->source_record_id)) {
                        $linkedRecords[(string) $link->source_record_type.':'.(int) $link->source_record_id] = [
                            'type' => (string) $link->source_record_type,
                            'id' => (int) $link->source_record_id,
                        ];
                    }
                });
        }

        if (count($linkedRecords) > 1) {
            throw new RuntimeException('Journal memiliki lebih dari satu relasi sumber. Selesaikan relasi yang bentrok sebelum import.');
        }

        $sourceTransaction = null;
        if ($linkedRecords !== []) {
            $linked = reset($linkedRecords);
            if ($linked['type'] !== Transaction::class) {
                throw new RuntimeException('Journal sudah tertaut ke jenis sumber lain; relasi tersebut tidak akan ditimpa.');
            }
            $sourceTransaction = Transaction::where('business_id', $businessId)
                ->where('type', 'expense')
                ->lockForUpdate()
                ->find($linked['id']);
            if (! $sourceTransaction) {
                throw new RuntimeException('Expense yang tertaut ke Journal tidak ditemukan pada business aktif.');
            }
            if ($this->normalizeReference((string) $sourceTransaction->ref_no) !== $this->normalizeReference($reference)) {
                throw new RuntimeException('Referensi Expense yang sudah tertaut berbeda dari workbook; relasi tidak akan ditimpa.');
            }
        }

        return [
            'row' => $row,
            'mapping' => $mapping,
            'entry' => $entry,
            'transaction' => $sourceTransaction,
        ];
    }

    public function link(array $lockedJournal, Transaction $transaction, int $businessId): void
    {
        $row = $lockedJournal['row'];
        $mapping = $lockedJournal['mapping'];
        $entry = $lockedJournal['entry'];
        $transactionId = (int) $transaction->id;

        DB::table('accounting_acc_trans_mappings')
            ->where('id', (int) $mapping->id)
            ->where('business_id', $businessId)
            ->update([
                'source_record_type' => Transaction::class,
                'source_record_id' => $transactionId,
                'source_link_status' => 'matched',
                'source_link_note' => 'Expense ditautkan dari import Expense melalui referensi Journal yang unik.',
                'updated_at' => now(),
            ]);

        DB::table('accounting_journal_import_rows')
            ->where('id', (int) $row->id)
            ->where('business_id', $businessId)
            ->update([
                'source_record_type' => Transaction::class,
                'source_record_id' => $transactionId,
                'processing_message' => 'Expense ditautkan dari import Expense melalui referensi Journal yang unik.',
                'updated_at' => now(),
            ]);

        if (Schema::hasTable('accounting_journal_import_source_links')) {
            $entry['source_link_group'] = [
                'method' => 'single',
                'members' => [[
                    'record_type' => Transaction::class,
                    'id' => $transactionId,
                    'reference' => $transaction->ref_no,
                    'date' => substr((string) $transaction->transaction_date, 0, 10),
                    'base_amount' => (float) $transaction->total_before_tax,
                    'tax_amount' => (float) $transaction->tax_amount,
                    'amount' => (float) $transaction->final_total,
                ]],
            ];
            $this->sourceLinkService->sync(
                (int) $row->id,
                (int) $row->journal_import_id,
                $businessId,
                'Expense',
                $entry,
                Transaction::class,
                $transactionId
            );
        }
    }

    /**
     * Remove only the exact two-line legacy Expense map known to duplicate the
     * selected Journal posting. Manual or partial mappings are left untouched.
     */
    public function removeProvenDuplicateExpenseMap(Transaction $transaction): int
    {
        if (! Schema::hasTable('accounting_accounts_transactions')) {
            return 0;
        }

        $maps = DB::table('accounting_accounts_transactions')
            ->where('transaction_id', (int) $transaction->id)
            ->whereNull('transaction_payment_id')
            ->whereIn('map_type', ['payment_account', 'deposit_to'])
            ->get(['id', 'map_type', 'sub_type', 'type', 'amount']);

        if ($maps->count() !== 2
            || $maps->pluck('map_type')->unique()->count() !== 2
            || $maps->contains(fn ($map) => (string) $map->sub_type !== 'expense')
            || $maps->contains(fn ($map) => ! is_numeric($map->amount)
                || abs((float) $map->amount - (float) $transaction->final_total) > 0.01)) {
            return 0;
        }

        $debit = $maps->firstWhere('type', 'debit');
        $credit = $maps->firstWhere('type', 'credit');
        if (! $debit || ! $credit || $debit->map_type !== 'deposit_to' || $credit->map_type !== 'payment_account') {
            return 0;
        }

        return DB::table('accounting_accounts_transactions')->whereIn('id', $maps->pluck('id'))->delete();
    }

    private function normalizeReference(string $reference): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', $reference)));
    }
}
