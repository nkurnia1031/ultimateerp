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

        $sourceTransaction = null;
        $sourceTransactions = collect();
        if (count($linkedRecords) > 1) {
            $memberIds = array_values(array_unique(array_map('intval', $sourceLinkGroup['member_ids'] ?? [])));
            sort($memberIds);
            $linkedIds = array_values(array_unique(array_map(fn ($record) => (int) $record['id'], array_values($linkedRecords))));
            sort($linkedIds);
            $memberTypes = collect($sourceLinkMembers)->map(fn ($member) => (string) ($member['record_type'] ?? ''))->unique()->values();
            $memberRecordIds = collect($sourceLinkMembers)->pluck('id')->map(fn ($id) => (int) $id)->unique()->sort()->values()->all();
            if (($sourceLinkGroup['status'] ?? null) !== 'confirmed'
                || count($memberIds) < 2
                || $memberIds !== $linkedIds
                || $memberIds !== $memberRecordIds
                || $memberTypes->count() !== 1
                || $memberTypes->first() !== Transaction::class
                || collect($linkedRecords)->contains(fn ($linked) => $linked['type'] !== Transaction::class)) {
                throw new RuntimeException('Relasi grup sumber Journal tidak memiliki konfirmasi atau anggota yang konsisten.');
            }

            $sourceTransactions = Transaction::where('business_id', $businessId)
                ->where('type', 'expense')
                ->whereIn('id', $memberIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            if ($sourceTransactions->count() !== count($memberIds)) {
                throw new RuntimeException('Salah satu Expense dalam grup sumber Journal tidak ditemukan pada business aktif.');
            }
            $journalDate = substr(trim((string) ($entry['date'] ?? '')), 0, 10);
            $sourceMembersById = collect($sourceLinkMembers)->keyBy(fn ($member) => (int) ($member['id'] ?? 0));
            foreach ($sourceTransactions as $source) {
                $sourceMember = $sourceMembersById->get((int) $source->id);
                if ($this->normalizeReference((string) $source->ref_no) !== $this->normalizeReference($reference)
                    || substr((string) $source->transaction_date, 0, 10) !== $journalDate
                    || ! $sourceMember
                    || abs((float) $source->total_before_tax - (float) ($sourceMember['base_amount'] ?? -1)) > 0.01
                    || abs((float) $source->tax_amount - (float) ($sourceMember['tax_amount'] ?? -1)) > 0.01
                    || abs((float) $source->final_total - (float) ($sourceMember['amount'] ?? -1)) > 0.01) {
                    throw new RuntimeException('Tanggal, referensi, atau nilai anggota grup Expense berubah setelah konfirmasi Journal.');
                }
            }
            $groupTotals = [
                'base_amount' => (float) $sourceTransactions->sum(fn ($source) => (float) ($source->total_before_tax ?? 0)),
                'tax_amount' => (float) $sourceTransactions->sum(fn ($source) => (float) ($source->tax_amount ?? 0)),
                'total_amount' => (float) $sourceTransactions->sum(fn ($source) => (float) ($source->final_total ?? 0)),
            ];
            foreach ($groupTotals as $key => $amount) {
                if (! is_numeric($sourceLinkGroup[$key] ?? null) || abs($amount - (float) $sourceLinkGroup[$key]) > 0.01) {
                    throw new RuntimeException('Nominal anggota grup Expense berubah setelah konfirmasi Journal.');
                }
            }
            $journalTotals = DB::table('accounting_accounts_transactions')
                ->where('acc_trans_mapping_id', (int) $mapping->id)
                ->selectRaw("SUM(CASE WHEN type = 'debit' THEN amount ELSE 0 END) as debit_total, SUM(CASE WHEN type = 'credit' THEN amount ELSE 0 END) as credit_total")
                ->first();
            if (! $journalTotals
                || abs((float) $journalTotals->debit_total - (float) $journalTotals->credit_total) > 0.01
                || abs((float) $journalTotals->debit_total - $groupTotals['total_amount']) > 0.01
                || ! is_numeric($sourceLinkGroup['journal_total_amount'] ?? null)
                || abs((float) $journalTotals->debit_total - (float) $sourceLinkGroup['journal_total_amount']) > 0.01) {
                throw new RuntimeException('Nilai posting GL berubah atau tidak seimbang dengan grup Expense.');
            }
            $sourceTransaction = $sourceTransactions->firstWhere('id', (int) ($mapping->source_record_id ?? 0))
                ?: $sourceTransactions->first();
        } elseif ($linkedRecords !== []) {
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
            $sourceTransactions = collect([$sourceTransaction]);
        }

        return [
            'row' => $row,
            'mapping' => $mapping,
            'entry' => $entry,
            'transaction' => $sourceTransaction,
            'transactions' => $sourceTransactions,
        ];
    }

    public function synchronizeAmounts(
        array $lockedJournal,
        Transaction $transaction,
        float $baseAmount,
        float $taxAmount,
        float $totalAmount,
        string $date
    ): array {
        $mapping = $lockedJournal['mapping'] ?? null;
        $row = $lockedJournal['row'] ?? null;
        if (! $mapping || ! $row) {
            throw new RuntimeException('Posting Journal yang dipilih tidak tersedia untuk sinkronisasi workbook.');
        }

        $totalAmount = round($totalAmount, 4);
        $glRows = DB::table('accounting_accounts_transactions')
            ->where('acc_trans_mapping_id', (int) $mapping->id)
            ->orderBy('id')
            ->lockForUpdate()
            ->get(['id', 'type', 'amount']);
        $debitRows = $glRows->where('type', 'debit')->values();
        $creditRows = $glRows->where('type', 'credit')->values();
        if ($debitRows->isEmpty() || $creditRows->isEmpty()) {
            throw new RuntimeException('Posting GL Journal tidak memiliki sisi debit dan kredit untuk disesuaikan dengan workbook.');
        }
        $oldDebit = (float) $debitRows->sum('amount');
        $oldCredit = (float) $creditRows->sum('amount');
        if ($oldDebit <= 0 || $oldCredit <= 0) {
            throw new RuntimeException('Posting GL Journal harus memiliki baris debit dan kredit sebelum sinkronisasi.');
        }

        foreach ([['rows' => $debitRows], ['rows' => $creditRows]] as $side) {
            $rows = $side['rows'];
            $amounts = $this->scaledAmounts($rows->pluck('amount')->map(fn ($amount) => (float) $amount)->all(), $totalAmount);
            foreach ($rows as $index => $glRow) {
                DB::table('accounting_accounts_transactions')
                    ->where('id', (int) $glRow->id)
                    ->update(['amount' => $amounts[$index]]);
            }
        }

        $entry = is_array($lockedJournal['entry'] ?? null) ? $lockedJournal['entry'] : [];
        if (is_array($entry['lines'] ?? null)) {
            $entry['lines'] = $this->scaleJournalEntryLines($entry['lines'], $totalAmount);
        }
        $entry['date'] = $date !== '' ? $date : ($entry['date'] ?? null);
        $entry['debit_total'] = $totalAmount;
        $entry['credit_total'] = $totalAmount;
        if (array_key_exists('total_amount', $entry)) {
            $entry['total_amount'] = $totalAmount;
        }
        if (array_key_exists('tax_amount', $entry)) {
            $entry['tax_amount'] = round($taxAmount, 4);
        }
        $entry['source_link_group'] = [
            'method' => 'single',
            'members' => [[
                'record_type' => Transaction::class,
                'id' => (int) $transaction->id,
                'reference' => (string) $transaction->ref_no,
                'date' => $date,
                'base_amount' => round($baseAmount, 4),
                'tax_amount' => round($taxAmount, 4),
                'amount' => $totalAmount,
            ]],
        ];

        DB::table('accounting_journal_import_rows')
            ->where('id', (int) $row->id)
            ->where('business_id', (int) $row->business_id)
            ->update([
                'entry_data' => json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'updated_at' => now(),
            ]);
        if (Schema::hasColumn('accounting_acc_trans_mappings', 'operation_date')) {
            DB::table('accounting_acc_trans_mappings')
                ->where('id', (int) $mapping->id)
                ->where('business_id', (int) $mapping->business_id)
                ->update(['operation_date' => $date]);
        }

        $row->entry_data = json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $lockedJournal['entry'] = $entry;
        $lockedJournal['row'] = $row;

        return $lockedJournal;
    }

    private function scaleJournalEntryLines(array $lines, float $totalAmount): array
    {
        $debitIndexes = [];
        $debitValues = [];
        $creditIndexes = [];
        $creditValues = [];
        foreach ($lines as $index => $line) {
            if (! is_array($line)) {
                continue;
            }
            $side = strtolower(trim((string) ($line['type'] ?? '')));
            if ($side === 'debit') {
                $debitIndexes[] = $index;
                $debitValues[] = (float) ($line['amount'] ?? $line['debit'] ?? 0);
            } elseif ($side === 'credit') {
                $creditIndexes[] = $index;
                $creditValues[] = (float) ($line['amount'] ?? $line['credit'] ?? 0);
            } else {
                if ((float) ($line['debit'] ?? 0) > 0) {
                    $debitIndexes[] = $index;
                    $debitValues[] = (float) $line['debit'];
                }
                if ((float) ($line['credit'] ?? 0) > 0) {
                    $creditIndexes[] = $index;
                    $creditValues[] = (float) $line['credit'];
                }
            }
        }

        $scaledDebits = $this->scaledAmounts($debitValues, $totalAmount);
        foreach ($debitIndexes as $position => $index) {
            if (strtolower(trim((string) ($lines[$index]['type'] ?? ''))) === 'debit'
                && array_key_exists('amount', $lines[$index])) {
                $lines[$index]['amount'] = $scaledDebits[$position];
            } else {
                $lines[$index]['debit'] = $scaledDebits[$position];
            }
        }
        $scaledCredits = $this->scaledAmounts($creditValues, $totalAmount);
        foreach ($creditIndexes as $position => $index) {
            if (strtolower(trim((string) ($lines[$index]['type'] ?? ''))) === 'credit'
                && array_key_exists('amount', $lines[$index])) {
                $lines[$index]['amount'] = $scaledCredits[$position];
            } else {
                $lines[$index]['credit'] = $scaledCredits[$position];
            }
        }

        return $lines;
    }

    private function scaledAmounts(array $sourceAmounts, float $targetAmount): array
    {
        if ($sourceAmounts === []) {
            return [];
        }
        $targetUnits = max(0, (int) round($targetAmount * 10000, 0, PHP_ROUND_HALF_UP));
        $weights = array_map(fn ($amount) => max(0, (int) round((float) $amount * 10000, 0, PHP_ROUND_HALF_UP)), $sourceAmounts);
        $weightTotal = array_sum($weights);
        $allocated = 0;
        $scaled = [];
        foreach ($weights as $index => $weight) {
            if ($index === count($weights) - 1) {
                $units = $targetUnits - $allocated;
            } elseif ($weightTotal > 0) {
                $units = (int) floor($targetUnits * $weight / $weightTotal);
            } else {
                $units = (int) floor($targetUnits / count($weights));
            }
            $allocated += $units;
            $scaled[] = $units / 10000;
        }

        return $scaled;
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
            ]);

        DB::table('accounting_journal_import_rows')
            ->where('id', (int) $row->id)
            ->where('business_id', $businessId)
            ->update([
                'source_record_type' => Transaction::class,
                'source_record_id' => $transactionId,
                'entry_data' => json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
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

    public function linkGroup(array $lockedJournal, $transactions, int $businessId, int $userId, array $matchedGroup): void
    {
        if (! Schema::hasTable('accounting_journal_import_source_links')) {
            throw new RuntimeException('Relasi grup sumber Journal belum tersedia. Jalankan migration Accounting.');
        }
        $transactions = collect($transactions)->sortBy('id')->values();
        if ($transactions->count() < 2 || $transactions->contains(function ($transaction) use ($businessId) {
            return (int) $transaction->business_id !== $businessId || $transaction->type !== 'expense';
        })) {
            throw new RuntimeException('Grup sumber harus memuat minimal dua Expense aktif pada business ini.');
        }

        $row = $lockedJournal['row'];
        $mapping = $lockedJournal['mapping'];
        $entry = $lockedJournal['entry'];
        $members = $transactions->map(function ($transaction) {
            return [
                'record_type' => Transaction::class,
                'id' => (int) $transaction->id,
                'reference' => (string) $transaction->ref_no,
                'date' => substr((string) $transaction->transaction_date, 0, 10),
                'base_amount' => (float) $transaction->total_before_tax,
                'tax_amount' => (float) $transaction->tax_amount,
                'amount' => (float) $transaction->final_total,
            ];
        })->all();
        $primary = $transactions->first();
        $baseAmount = (float) $transactions->sum(fn ($transaction) => (float) $transaction->total_before_tax);
        $taxAmount = (float) $transactions->sum(fn ($transaction) => (float) $transaction->tax_amount);
        $totalAmount = (float) $transactions->sum(fn ($transaction) => (float) $transaction->final_total);
        $fingerprint = hash('sha256', json_encode([
            'business_id' => $businessId,
            'journal_import_row_id' => (int) $row->id,
            'member_ids' => $transactions->pluck('id')->map(fn ($id) => (int) $id)->values()->all(),
            'base' => $baseAmount,
            'tax' => $taxAmount,
            'total' => $totalAmount,
        ], JSON_UNESCAPED_SLASHES));

        $entry['source_link_status'] = 'matched';
        $entry['source_link_label'] = 'Grup split ('.$transactions->count().' Expense)';
        $entry['source_link_note'] = 'Satu posting Journal ditautkan ke Expense yang tetap terpisah.';
        $entry['source_link_group'] = [
            'status' => 'confirmed',
            'method' => 'expense_import_split_group',
            'fingerprint' => $fingerprint,
            'confirmed_by' => $userId,
            'confirmed_at' => now()->toDateTimeString(),
            'member_ids' => $transactions->pluck('id')->map(fn ($id) => (int) $id)->values()->all(),
            'members' => $members,
            'base_amount' => $baseAmount,
            'tax_amount' => $taxAmount,
            'total_amount' => $totalAmount,
            'journal_base_amount' => (float) ($matchedGroup['journal_base_amount'] ?? $baseAmount),
            'journal_tax_amount' => (float) ($matchedGroup['journal_tax_amount'] ?? $taxAmount),
            'journal_total_amount' => (float) ($matchedGroup['journal_total_amount'] ?? $totalAmount),
        ];

        DB::table('accounting_acc_trans_mappings')
            ->where('id', (int) $mapping->id)
            ->where('business_id', $businessId)
            ->update([
                'source_record_type' => Transaction::class,
                'source_record_id' => (int) $primary->id,
                'source_link_status' => 'matched',
                'source_link_note' => 'Satu posting Journal ditautkan ke grup Expense terpisah melalui import Expense.',
            ]);
        DB::table('accounting_journal_import_rows')
            ->where('id', (int) $row->id)
            ->where('business_id', $businessId)
            ->update([
                'source_record_type' => Transaction::class,
                'source_record_id' => (int) $primary->id,
                'entry_data' => json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'processing_message' => 'Posting Journal ditautkan ke grup Expense terpisah melalui import Expense.',
                'updated_at' => now(),
            ]);

        $this->sourceLinkService->sync(
            (int) $row->id,
            (int) $row->journal_import_id,
            $businessId,
            'Expense',
            $entry,
            Transaction::class,
            (int) $primary->id
        );
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
