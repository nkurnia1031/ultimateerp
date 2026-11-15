<?php

namespace App\Services;

use App\Transaction;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class ExpenseImportConsolidationService
{
    private $journalLinkService;

    public function __construct(?ExpenseImportJournalLinkService $journalLinkService = null)
    {
        $this->journalLinkService = $journalLinkService ?: app(ExpenseImportJournalLinkService::class);
    }

    public function assessSameYearExpenseGroup(
        array $memberIds,
        int $businessId,
        string $reference,
        ?string $date,
        float $expectedTotal,
        bool $workbookAuthoritative = false
    ): array {
        try {
            $memberIds = array_values(array_unique(array_map('intval', $memberIds)));
            sort($memberIds);
            $transactions = Transaction::where('business_id', $businessId)
                ->where('type', 'expense')
                ->whereIn('id', $memberIds)
                ->orderBy('id')
                ->get();
            if (count($memberIds) < 2 || $transactions->count() !== count($memberIds)) {
                throw new RuntimeException('Salah satu Expense sumber tidak tersedia untuk penggabungan.');
            }

            $this->assertSameReferenceAndDate($transactions, $businessId, $reference, $date, $expectedTotal, $workbookAuthoritative);
            $this->preservedTransactionValues($transactions, $workbookAuthoritative);
            $this->assertNoUnsupportedReferences($transactions, $memberIds);
            $this->assertNoJournalSourceRelations($memberIds);
            $this->assertSameYearBackfillMappingsArePreservable($transactions, $businessId, $reference, (string) $date);

            $paymentTotal = Schema::hasTable('transaction_payments')
                ? (float) DB::table('transaction_payments')->whereIn('transaction_id', $memberIds)->sum('amount')
                : 0.0;
            if (! $workbookAuthoritative && $paymentTotal - $expectedTotal > 0.01) {
                throw new RuntimeException('Total pembayaran Expense sumber melebihi total grup.');
            }

            return ['safe' => true, 'reason' => null];
        } catch (RuntimeException $exception) {
            return ['safe' => false, 'reason' => $exception->getMessage()];
        }
    }

    public function assessJournalExpenseGroup(
        array $memberIds,
        int $businessId,
        string $reference,
        int $journalRowId,
        int $journalMappingId
    ): array {
        try {
            $memberIds = array_values(array_unique(array_map('intval', $memberIds)));
            sort($memberIds);
            $transactions = Transaction::where('business_id', $businessId)
                ->where('type', 'expense')
                ->whereIn('id', $memberIds)
                ->orderBy('id')
                ->get();
            if (count($memberIds) < 2 || $transactions->count() !== count($memberIds)) {
                throw new RuntimeException('Salah satu Expense sumber tidak tersedia untuk penggabungan.');
            }

            $dates = $transactions->map(fn ($transaction) => substr((string) ($transaction->transaction_date ?? ''), 0, 10))
                ->filter()->unique()->values();
            if ($dates->count() !== 1) {
                throw new RuntimeException('Grup Expense yang tertaut ke Journal harus memiliki satu tanggal sumber.');
            }
            $this->assertSameReferenceAndDate($transactions, $businessId, $reference, (string) $dates->first(), 0, true);
            $this->preservedTransactionValues($transactions, true);
            $this->assertNoUnsupportedReferences($transactions, $memberIds);
            $this->assertJournalRelationsAreExclusive($memberIds, $businessId, $journalRowId, $journalMappingId);
            $replaceableMappingIds = $this->assertGeneratedMappingsAreReplaceable(
                $transactions,
                $businessId,
                $reference,
                (string) $dates->first(),
                $journalMappingId
            );
            foreach ($transactions as $transaction) {
                $this->assertNoUnreplaceableSourceLedgerRows($transaction, $journalMappingId, $replaceableMappingIds);
            }

            return ['safe' => true, 'reason' => null];
        } catch (RuntimeException $exception) {
            return ['safe' => false, 'reason' => $exception->getMessage()];
        }
    }

    public function synchronizeExpenseBackfill(Transaction $transaction, int $businessId, float $totalAmount, string $date): void
    {
        if (! Schema::hasTable('accounting_acc_trans_mappings') || ! Schema::hasTable('accounting_accounts_transactions')) {
            return;
        }
        $mappings = DB::table('accounting_acc_trans_mappings')
            ->where('business_id', $businessId)
            ->where('source_record_type', Transaction::class)
            ->where('source_record_id', (int) $transaction->id)
            ->where('type', 'general_ledger_backfill')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
        if ($mappings->isEmpty()) {
            return;
        }
        if ($mappings->count() !== 1) {
            throw new RuntimeException('Expense memiliki beberapa posting GL backfill; nominal tidak diubah otomatis.');
        }

        $mapping = $mappings->first();
        $rows = DB::table('accounting_accounts_transactions')
            ->where('acc_trans_mapping_id', (int) $mapping->id)
            ->orderBy('id')
            ->lockForUpdate()
            ->get(['id', 'transaction_id', 'transaction_payment_id', 'map_type', 'sub_type', 'type', 'amount']);
        if ($rows->count() < 2 || $rows->contains(fn ($row) =>
            (int) ($row->transaction_id ?? 0) !== (int) $transaction->id
            || $row->transaction_payment_id !== null
            || (string) $row->map_type !== 'general_ledger_backfill'
            || (string) $row->sub_type !== 'expense'
            || ! in_array((string) $row->type, ['debit', 'credit'], true)
            || (float) $row->amount < 0
        )) {
            throw new RuntimeException('Baris GL backfill Expense tidak aman untuk disesuaikan dengan workbook.');
        }

        $debits = $rows->where('type', 'debit')->values();
        $credits = $rows->where('type', 'credit')->values();
        if ($debits->isEmpty() || $credits->isEmpty()
            || abs((float) $debits->sum('amount') - (float) $credits->sum('amount')) > 0.01) {
            throw new RuntimeException('Posting GL backfill Expense tidak seimbang; nominal tidak diubah otomatis.');
        }

        foreach ([$debits, $credits] as $side) {
            $amounts = $this->scaledAmounts($side->pluck('amount')->map(fn ($amount) => (float) $amount)->all(), $totalAmount);
            foreach ($side as $index => $row) {
                DB::table('accounting_accounts_transactions')->where('id', (int) $row->id)->update([
                    'amount' => $amounts[$index],
                ]);
            }
        }

        $mappingUpdates = ['ref_no' => (string) $transaction->ref_no];
        if (Schema::hasColumn('accounting_acc_trans_mappings', 'operation_date')) {
            $mappingUpdates['operation_date'] = $date;
        }
        DB::table('accounting_acc_trans_mappings')->where('id', (int) $mapping->id)->update($mappingUpdates);
    }

    public function consolidateSameYearExpenseGroup(
        Collection $transactions,
        int $businessId,
        string $reference,
        float $expectedTotal,
        string $date,
        bool $workbookAuthoritative = false
    ): array {
        $memberIds = $transactions->pluck('id')->map(fn ($id) => (int) $id)->unique()->sort()->values()->all();
        if (count($memberIds) < 2 || count($memberIds) !== $transactions->count()) {
            throw new RuntimeException('Konsolidasi Expense memerlukan sedikitnya dua transaksi sumber yang berbeda.');
        }

        $transactions = Transaction::where('business_id', $businessId)
            ->where('type', 'expense')
            ->whereIn('id', $memberIds)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
        if ($transactions->count() !== count($memberIds)) {
            throw new RuntimeException('Salah satu Expense sumber tidak lagi tersedia. Muat ulang preview.');
        }

        $this->assertSameReferenceAndDate($transactions, $businessId, $reference, $date, $expectedTotal, $workbookAuthoritative);
        $paymentTotal = Schema::hasTable('transaction_payments')
            ? (float) DB::table('transaction_payments')->whereIn('transaction_id', $memberIds)->sum('amount')
            : 0.0;
        $paymentCount = Schema::hasTable('transaction_payments')
            ? (int) DB::table('transaction_payments')->whereIn('transaction_id', $memberIds)->count()
            : 0;
        if (! $workbookAuthoritative && $paymentTotal - $expectedTotal > 0.01) {
            throw new RuntimeException('Total pembayaran Expense sumber melebihi total grup; riwayat pembayaran tidak diubah.');
        }

        $preserved = $this->preservedTransactionValues($transactions, $workbookAuthoritative);
        $this->assertNoUnsupportedReferences($transactions, $memberIds);
        $this->assertNoJournalSourceRelations($memberIds);
        $mappings = $this->assertSameYearBackfillMappingsArePreservable($transactions, $businessId, $reference, $date);

        $primary = $transactions->first();
        $primaryMapping = $mappings->firstWhere('source_record_id', (int) $primary->id);
        if (! $primaryMapping) {
            throw new RuntimeException('Posting GL Expense utama tidak tersedia untuk dipertahankan.');
        }
        $secondaryIds = array_values(array_diff($memberIds, [(int) $primary->id]));
        $this->moveExpenseChildren($primary, $secondaryIds, $memberIds, 0);

        foreach ($mappings as $mapping) {
            if ((int) $mapping->id === (int) $primaryMapping->id) {
                continue;
            }
            DB::table('accounting_accounts_transactions')
                ->where('acc_trans_mapping_id', (int) $mapping->id)
                ->update(['acc_trans_mapping_id' => (int) $primaryMapping->id]);
            DB::table('accounting_acc_trans_mappings')->where('id', (int) $mapping->id)->delete();
        }
        DB::table('accounting_acc_trans_mappings')->where('id', (int) $primaryMapping->id)->update([
            'source_record_id' => (int) $primary->id,
            'updated_at' => now(),
        ]);

        if ($secondaryIds !== []) {
            Transaction::where('business_id', $businessId)
                ->where('type', 'expense')
                ->whereIn('id', $secondaryIds)
                ->delete();
        }

        $primary->refresh();

        return [
            'transaction' => $primary,
            'member_ids' => $memberIds,
            'preserved' => $preserved,
            'payment_total' => $paymentTotal,
            'payment_count' => $paymentCount,
        ];
    }

    /**
     * Consolidate a uniquely matched same-reference Expense group into its
     * lowest-id member while preserving supported child records and payments.
     */
    public function consolidate(
        Collection $transactions,
        int $businessId,
        string $reference,
        float $expectedTotal,
        int $journalRowId,
        int $journalMappingId,
        bool $workbookAuthoritative = false
    ): array {
        $memberIds = $transactions->pluck('id')->map(fn ($id) => (int) $id)->unique()->sort()->values()->all();
        if (count($memberIds) < 2 || count($memberIds) !== $transactions->count()) {
            throw new RuntimeException('Konsolidasi Expense memerlukan sedikitnya dua transaksi sumber yang berbeda.');
        }

        $transactions = Transaction::where('business_id', $businessId)
            ->where('type', 'expense')
            ->whereIn('id', $memberIds)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
        if ($transactions->count() !== count($memberIds)) {
            throw new RuntimeException('Salah satu Expense sumber tidak lagi tersedia. Muat ulang preview.');
        }

        $dates = $transactions->map(fn ($transaction) => substr((string) ($transaction->transaction_date ?? ''), 0, 10))
            ->filter()
            ->unique()
            ->values();
        if ($dates->count() !== 1) {
            throw new RuntimeException('Grup Expense harus memiliki satu tanggal transaksi yang sama.');
        }
        $date = (string) $dates->first();

        foreach ($transactions as $transaction) {
            if ($this->normalizeReference((string) $transaction->ref_no) !== $this->normalizeReference($reference)) {
                throw new RuntimeException('Referensi Expense sumber berubah. Muat ulang preview sebelum menggabungkan.');
            }
        }

        $actualTotal = (float) $transactions->sum(fn ($transaction) => (float) $transaction->final_total);
        if (! $workbookAuthoritative && abs($actualTotal - $expectedTotal) > 0.01) {
            throw new RuntimeException('Total Expense sumber berubah sejak preview. Muat ulang preview sebelum menggabungkan.');
        }

        $paymentTotal = Schema::hasTable('transaction_payments')
            ? (float) DB::table('transaction_payments')->whereIn('transaction_id', $memberIds)->sum('amount')
            : 0.0;
        $paymentCount = Schema::hasTable('transaction_payments')
            ? (int) DB::table('transaction_payments')->whereIn('transaction_id', $memberIds)->count()
            : 0;
        if (! $workbookAuthoritative && $paymentTotal - $expectedTotal > 0.01) {
            throw new RuntimeException('Total pembayaran Expense sumber melebihi total Expense hasil gabungan; riwayat pembayaran tidak diubah.');
        }

        $preserved = $this->preservedTransactionValues($transactions, $workbookAuthoritative);
        $this->assertNoUnsupportedReferences($transactions, $memberIds);
        $this->assertJournalRelationsAreExclusive($memberIds, $businessId, $journalRowId, $journalMappingId);
        $replaceableMappingIds = $this->assertGeneratedMappingsAreReplaceable($transactions, $businessId, $reference, $date, $journalMappingId);

        foreach ($transactions as $transaction) {
            $this->journalLinkService->removeProvenDuplicateExpenseMap($transaction);
            $this->removeProvenGeneratedBackfill($transaction, $businessId, $reference, $date);
            $this->assertNoUnreplaceableSourceLedgerRows($transaction, $journalMappingId, $replaceableMappingIds);
        }

        $primary = $transactions->first();
        $secondaryIds = array_values(array_diff($memberIds, [(int) $primary->id]));
        if ($secondaryIds !== []) {
            $this->moveExpenseChildren($primary, $secondaryIds, $memberIds, $journalRowId);
            Transaction::where('business_id', $businessId)
                ->where('type', 'expense')
                ->whereIn('id', $secondaryIds)
                ->delete();
        }

        $primary->refresh();

        return [
            'transaction' => $primary,
            'member_ids' => $memberIds,
            'preserved' => $preserved,
            'payment_total' => $paymentTotal,
            'payment_count' => $paymentCount,
        ];
    }

    private function preservedTransactionValues(Collection $transactions, bool $workbookAuthoritative = false): array
    {
        foreach (['location_id', 'contact_id', 'expense_for', 'currency_id', 'exchange_rate'] as $column) {
            $values = $transactions->pluck($column)->filter(fn ($value) => $value !== null && $value !== '')
                ->map(fn ($value) => (string) $value)->unique()->values();
            if (! $workbookAuthoritative && $values->count() > 1) {
                throw new RuntimeException("Expense dengan REFERENCE NO sama memiliki nilai {$column} yang berbeda; transaksi tidak digabung.");
            }
        }

        $documents = $transactions->pluck('document')->map(fn ($value) => trim((string) $value))->filter()->unique()->values();
        if (! $workbookAuthoritative && $documents->count() > 1) {
            throw new RuntimeException('Expense sumber memiliki lampiran dokumen berbeda; gabungkan dokumen tersebut sebelum mengimpor.');
        }

        $notes = $transactions->pluck('additional_notes')->map(fn ($value) => trim((string) $value))->filter()->unique()->values();

        if (! $workbookAuthoritative && Schema::hasTable('expense_lines')
            && DB::table('expense_lines')->whereIn('transaction_id', $transactions->pluck('id'))->whereNotNull('document')->where('document', '<>', '')->exists()) {
            throw new RuntimeException('Baris Expense sumber memiliki lampiran. Baris tidak digabung agar lampiran tidak hilang.');
        }

        $tags = collect();
        if (Schema::hasTable('transaction_tags') && Schema::hasTable('tags')) {
            $tags = DB::table('transaction_tags as transaction_tag')
                ->join('tags', 'tags.id', '=', 'transaction_tag.tag_id')
                ->whereIn('transaction_tag.transaction_id', $transactions->pluck('id'))
                ->pluck('tags.name')
                ->map(fn ($name) => trim((string) $name))
                ->filter()
                ->unique()
                ->values();
        }

        return [
            'location_id' => $this->singleValue($transactions, 'location_id'),
            'contact_id' => $this->singleValue($transactions, 'contact_id'),
            'expense_for' => $this->singleValue($transactions, 'expense_for'),
            'document' => $documents->first(),
            'additional_notes' => $notes->implode("\n"),
            'additional_note_values' => $notes->all(),
            'tags' => $tags->all(),
        ];
    }

    private function singleValue(Collection $transactions, string $column)
    {
        return $transactions->pluck($column)->filter(fn ($value) => $value !== null && $value !== '')
            ->unique()->values()->first();
    }

    private function assertSameReferenceAndDate(
        Collection $transactions,
        int $businessId,
        string $reference,
        ?string $date,
        float $expectedTotal,
        bool $workbookAuthoritative = false
    ): void {
        if ($date === null || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            throw new RuntimeException('Penggabungan Expense memerlukan tanggal workbook yang valid.');
        }
        foreach ($transactions as $transaction) {
            if ((int) $transaction->business_id !== $businessId
                || $this->normalizeReference((string) $transaction->ref_no) !== $this->normalizeReference($reference)
                || substr((string) ($transaction->transaction_date ?? ''), 0, 10) !== $date) {
                throw new RuntimeException('Referensi, business, atau tanggal Expense sumber berbeda; transaksi tidak digabung.');
            }
        }
        $actualTotal = (float) $transactions->sum(fn ($transaction) => (float) $transaction->final_total);
        if (! $workbookAuthoritative && abs($actualTotal - $expectedTotal) > 0.01) {
            throw new RuntimeException('Total Expense sumber berubah atau berbeda dari total workbook.');
        }
    }

    private function assertNoJournalSourceRelations(array $memberIds): void
    {
        if (Schema::hasTable('accounting_acc_trans_mappings')
            && DB::table('accounting_acc_trans_mappings')
                ->where('source_record_type', Transaction::class)
                ->whereIn('source_record_id', $memberIds)
                ->where('type', '<>', 'general_ledger_backfill')
                ->exists()) {
            throw new RuntimeException('Expense sumber sudah tertaut ke Journal atau mapping akuntansi lain.');
        }

        if (Schema::hasTable('accounting_journal_import_source_links')
            && DB::table('accounting_journal_import_source_links')
                ->where('source_record_type', Transaction::class)
                ->whereIn('source_record_id', $memberIds)
                ->exists()) {
            throw new RuntimeException('Expense sumber memiliki relasi Journal; grup hanya dapat digabung lewat pencocokan Journal.');
        }

        if (Schema::hasTable('accounting_journal_import_rows')
            && DB::table('accounting_journal_import_rows')
                ->where('source_record_type', Transaction::class)
                ->whereIn('source_record_id', $memberIds)
                ->exists()) {
            throw new RuntimeException('Expense sumber dipakai oleh baris Journal dan tidak dapat digabung tanpa Journal yang cocok.');
        }
    }

    private function assertSameYearBackfillMappingsArePreservable(
        Collection $transactions,
        int $businessId,
        string $reference,
        string $date
    ): Collection {
        if (! Schema::hasTable('accounting_acc_trans_mappings') || ! Schema::hasTable('accounting_accounts_transactions')) {
            throw new RuntimeException('Mapping atau baris General Ledger belum tersedia; posting Expense tidak dapat dipertahankan dengan aman.');
        }

        $ids = $transactions->pluck('id')->map(fn ($id) => (int) $id)->all();
        $mappings = DB::table('accounting_acc_trans_mappings')
            ->where('business_id', $businessId)
            ->where('source_record_type', Transaction::class)
            ->whereIn('source_record_id', $ids)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
        if ($mappings->count() !== count($ids)) {
            throw new RuntimeException('Setiap Expense sumber harus memiliki tepat satu posting GL backfill yang akan dipertahankan.');
        }

        foreach ($transactions as $transaction) {
            $sourceMappings = $mappings->where('source_record_id', (int) $transaction->id)->values();
            if ($sourceMappings->count() !== 1
                || (string) $sourceMappings[0]->type !== 'general_ledger_backfill'
                || ! $this->generatedPostingIsExact($sourceMappings[0], $transaction, $reference, $date)) {
                throw new RuntimeException('Posting GL Expense tidak dapat dibuktikan sebagai backfill seimbang yang aman untuk digabung.');
            }
        }

        return $mappings;
    }

    private function assertNoUnsupportedReferences(Collection $transactions, array $memberIds): void
    {
        $secondaryIds = array_values(array_diff($memberIds, [(int) $transactions->first()->id]));
        foreach (['return_parent_id', 'recur_parent_id', 'transfer_parent_id'] as $column) {
            if (Schema::hasColumn('transactions', $column)
                && $transactions->contains(fn ($transaction) => ! empty($transaction->{$column}))) {
                throw new RuntimeException('Expense sumber merupakan bagian dari relasi transaksi lain dan tidak dapat digabung otomatis.');
            }
        }
        foreach (['return_parent_id', 'recur_parent_id', 'transfer_parent_id'] as $column) {
            if (Schema::hasColumn('transactions', $column)
                && Transaction::whereIn($column, $secondaryIds)->exists()) {
                throw new RuntimeException('Expense sumber terhubung ke transaksi lain dan tidak dapat digabung otomatis.');
            }
        }

        if (Schema::hasTable('heavy_equipment_driver_settlements')
            && Schema::hasColumn('heavy_equipment_driver_settlements', 'payroll_transaction_id')
            && DB::table('heavy_equipment_driver_settlements')->whereIn('payroll_transaction_id', $secondaryIds)->exists()) {
            throw new RuntimeException('Expense sumber tertaut ke penyelesaian payroll dan tidak dapat digabung otomatis.');
        }

        foreach ([
            'inventory_products' => 'transaction_id',
            'purchase_lines' => 'transaction_id',
            'transaction_sell_lines' => 'transaction_id',
            'stock_adjustment_lines' => 'transaction_id',
            'asset_consumptions' => 'transaction_id',
            'asset_depreciation' => 'transaction_id',
            'heavy_equipment_trip_billings' => 'transaction_id',
            'project_invoice_lines' => 'transaction_id',
        ] as $table => $column) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, $column)
                && DB::table($table)->whereIn($column, $secondaryIds)->exists()) {
                throw new RuntimeException('Expense sumber memiliki relasi operasional tambahan pada '.str_replace('_', ' ', $table).'; transaksi tidak digabung otomatis.');
            }
        }
    }

    private function assertJournalRelationsAreExclusive(array $memberIds, int $businessId, int $journalRowId, int $journalMappingId): void
    {
        if (Schema::hasTable('accounting_acc_trans_mappings')) {
            $mappings = DB::table('accounting_acc_trans_mappings')
                ->whereIn('source_record_id', $memberIds)
                ->where('source_record_type', Transaction::class)
                ->lockForUpdate()
                ->get(['id', 'business_id', 'type', 'source_record_id']);
            foreach ($mappings as $mapping) {
                if ((int) $mapping->business_id !== $businessId
                    || ((int) $mapping->id !== $journalMappingId && (string) $mapping->type !== 'general_ledger_backfill')) {
                    throw new RuntimeException('Expense sumber tertaut ke posting lain; relasi Journal tersebut tidak disentuh.');
                }
            }
        }

        if (Schema::hasTable('accounting_journal_import_source_links')) {
            $foreignLinkExists = DB::table('accounting_journal_import_source_links')
                ->whereIn('source_record_id', $memberIds)
                ->where('source_record_type', Transaction::class)
                ->where(function ($query) use ($businessId, $journalRowId) {
                    $query->where('business_id', '<>', $businessId)
                        ->orWhere('journal_import_row_id', '<>', $journalRowId);
                })
                ->exists();
            if ($foreignLinkExists) {
                throw new RuntimeException('Expense sumber tertaut ke baris Journal lain; transaksi tidak digabung otomatis.');
            }
        }

        if (Schema::hasTable('accounting_journal_import_rows')) {
            $otherJournalRowExists = DB::table('accounting_journal_import_rows')
                ->whereIn('source_record_id', $memberIds)
                ->where('source_record_type', Transaction::class)
                ->where('id', '<>', $journalRowId)
                ->exists();
            if ($otherJournalRowExists) {
                throw new RuntimeException('Expense sumber telah dipakai oleh baris Journal lain; transaksi tidak digabung otomatis.');
            }
        }
    }

    private function assertGeneratedMappingsAreReplaceable(Collection $transactions, int $businessId, string $reference, string $date, int $journalMappingId): array
    {
        if (! Schema::hasTable('accounting_acc_trans_mappings') || ! Schema::hasTable('accounting_accounts_transactions')) {
            return [];
        }

        $ids = $transactions->pluck('id')->map(fn ($id) => (int) $id)->all();
        $mappings = DB::table('accounting_acc_trans_mappings')
            ->where('source_record_type', Transaction::class)
            ->whereIn('source_record_id', $ids)
            ->lockForUpdate()
            ->get(['id', 'business_id', 'type', 'source_record_id']);
        $generatedCounts = $mappings->where('type', 'general_ledger_backfill')->groupBy('source_record_id');
        if ($generatedCounts->contains(fn ($rows) => $rows->count() > 1)) {
            throw new RuntimeException('Expense sumber memiliki lebih dari satu posting otomatis; transaksi tidak digabung otomatis.');
        }
        foreach ($mappings as $mapping) {
            if ((int) $mapping->id === $journalMappingId) {
                continue;
            }
            if ((int) $mapping->business_id !== $businessId || (string) $mapping->type !== 'general_ledger_backfill') {
                throw new RuntimeException('Expense sumber memiliki mapping akuntansi yang tidak boleh dipindahkan otomatis.');
            }
            $source = $transactions->firstWhere('id', (int) $mapping->source_record_id);
            if (! $source || ! $this->generatedPostingIsExact($mapping, $source, $reference, $date)) {
                throw new RuntimeException('Posting Expense lama tidak terbukti sebagai duplikat Journal; transaksi tidak digabung otomatis.');
            }
        }

        return $mappings->where('type', 'general_ledger_backfill')
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();

    }

    private function generatedPostingIsExact(object $mapping, Transaction $transaction, string $reference, string $date): bool
    {
        $fullMapping = DB::table('accounting_acc_trans_mappings')->where('id', (int) $mapping->id)->first(['ref_no', 'operation_date']);
        if (! $fullMapping
            || $this->normalizeReference((string) $fullMapping->ref_no) !== $this->normalizeReference($reference)
            || substr((string) $fullMapping->operation_date, 0, 10) !== $date) {
            return false;
        }

        $lines = DB::table('accounting_accounts_transactions')->where('acc_trans_mapping_id', (int) $mapping->id)
            ->get(['transaction_id', 'transaction_payment_id', 'amount', 'type', 'sub_type', 'map_type']);
        if ($lines->count() < 2
            || $lines->contains(fn ($line) => (int) $line->transaction_id !== (int) $transaction->id
                || $line->transaction_payment_id !== null
                || (string) $line->sub_type !== 'expense'
                || (string) $line->map_type !== 'general_ledger_backfill'
                || ! in_array((string) $line->type, ['debit', 'credit'], true)
                || (float) $line->amount < 0)) {
            return false;
        }

        $debitTotal = (float) $lines->where('type', 'debit')->sum('amount');
        $creditTotal = (float) $lines->where('type', 'credit')->sum('amount');

        return $lines->where('type', 'debit')->isNotEmpty()
            && $lines->where('type', 'credit')->isNotEmpty()
            && abs($debitTotal - (float) $transaction->final_total) <= 0.01
            && abs($creditTotal - (float) $transaction->final_total) <= 0.01;
    }

    private function removeProvenGeneratedBackfill(Transaction $transaction, int $businessId, string $reference, string $date): void
    {
        if (! Schema::hasTable('accounting_acc_trans_mappings') || ! Schema::hasTable('accounting_accounts_transactions')) {
            return;
        }

        $mappings = DB::table('accounting_acc_trans_mappings')
            ->where('business_id', $businessId)
            ->where('source_record_type', Transaction::class)
            ->where('source_record_id', (int) $transaction->id)
            ->where('type', 'general_ledger_backfill')
            ->get(['id']);
        foreach ($mappings as $mapping) {
            if (! $this->generatedPostingIsExact($mapping, $transaction, $reference, $date)) {
                throw new RuntimeException('Posting Expense lama berubah saat konsolidasi; transaksi tidak digabung.');
            }
            DB::table('accounting_accounts_transactions')->where('acc_trans_mapping_id', (int) $mapping->id)->delete();
            DB::table('accounting_acc_trans_mappings')->where('id', (int) $mapping->id)->delete();
        }
    }

    private function assertNoUnreplaceableSourceLedgerRows(Transaction $transaction, int $journalMappingId, array $replaceableMappingIds = []): void
    {
        if (! Schema::hasTable('accounting_accounts_transactions')) {
            return;
        }

        $rows = DB::table('accounting_accounts_transactions')
            ->where('transaction_id', (int) $transaction->id)
            ->whereNull('transaction_payment_id')
            ->get(['acc_trans_mapping_id', 'map_type']);
        foreach ($rows as $row) {
            if ((int) ($row->acc_trans_mapping_id ?? 0) === $journalMappingId) {
                continue;
            }
            if (in_array((int) ($row->acc_trans_mapping_id ?? 0), $replaceableMappingIds, true)) {
                continue;
            }
            throw new RuntimeException('Expense masih memiliki baris GL operasional yang tidak terbukti duplikat Journal. Transaksi tidak digabung otomatis.');
        }
    }

    private function moveExpenseChildren(Transaction $primary, array $secondaryIds, array $memberIds, int $journalRowId): void
    {
        $primaryId = (int) $primary->id;
        foreach ([
            'expense_lines' => 'transaction_id',
            'transaction_payments' => 'transaction_id',
            'account_transactions' => 'transaction_id',
            'cash_register_transactions' => 'transaction_id',
        ] as $table => $column) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, $column)) {
                DB::table($table)->whereIn($column, $secondaryIds)->update([$column => $primaryId]);
            }
        }

        if (Schema::hasTable('accounting_accounts_transactions') && Schema::hasColumn('accounting_accounts_transactions', 'transaction_id')) {
            DB::table('accounting_accounts_transactions')->whereIn('transaction_id', $secondaryIds)->update(['transaction_id' => $primaryId]);
        }

        foreach (['heavy_equipment_vendor_loadings', 'heavy_equipment_vendor_loading_lines', 'heavy_equipment_vendor_loading_documents'] as $table) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, 'expense_transaction_id')) {
                DB::table($table)->whereIn('expense_transaction_id', $secondaryIds)->update(['expense_transaction_id' => $primaryId]);
            }
        }

        if (Schema::hasTable('media') && Schema::hasColumn('media', 'model_type') && Schema::hasColumn('media', 'model_id')) {
            DB::table('media')->where('model_type', $primary->getMorphClass())
                ->whereIn('model_id', $secondaryIds)->update(['model_id' => $primaryId]);
        }

        if (Schema::hasTable('transaction_tags')) {
            $now = now();
            $tagIds = DB::table('transaction_tags')->whereIn('transaction_id', $memberIds)->pluck('tag_id')->unique()->values();
            $links = $tagIds->map(fn ($tagId) => [
                'transaction_id' => $primaryId,
                'tag_id' => (int) $tagId,
                'created_at' => $now,
                'updated_at' => $now,
            ])->all();
            if ($links !== []) {
                DB::table('transaction_tags')->insertOrIgnore($links);
            }
            DB::table('transaction_tags')->whereIn('transaction_id', $secondaryIds)->delete();
        }

        if (Schema::hasTable('accounting_journal_import_source_links')) {
            DB::table('accounting_journal_import_source_links')
                ->where('journal_import_row_id', $journalRowId)
                ->where('source_record_type', Transaction::class)
                ->whereIn('source_record_id', $memberIds)
                ->delete();
        }
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

    private function normalizeReference(string $reference): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', $reference)));
    }
}
