<?php

namespace Modules\Accounting\Services;

use App\PurchaseLine;
use App\TaxRate;
use App\Transaction;
use App\TransactionSellLine;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Accounting\Entities\AccountingAccTransMapping;

class JournalTaxReconciliationService
{
    private const MATCH_TOLERANCE = 0.01;

    /**
     * Derive Journal tax from account labels, posting sides, and source detail,
     * then apply it to uniquely linked source invoices and expenses.
     * Sales and purchase line taxes are only changed when their full calculated
     * tax matches the Journal amount; otherwise the source remains unchanged.
     */
    public function reconcileImport(int $journalImportId, int $businessId): array
    {
        $result = ['reconciled' => 0, 'review' => 0, 'not_applicable' => 0];
        $afterId = 0;

        do {
            $chunk = $this->reconcileImportChunk($journalImportId, $businessId, $afterId);
            foreach ($chunk['result'] as $status => $count) {
                $result[$status] += $count;
            }
            $afterId = $chunk['last_id'];
        } while ($chunk['has_more']);

        return $result;
    }

    public function reconcileImportChunk(int $journalImportId, int $businessId, int $afterId, int $limit = 50): array
    {
        $mappings = AccountingAccTransMapping::where('journal_import_id', $journalImportId)
            ->where('business_id', $businessId)
            ->where('source_link_status', 'matched')
            ->whereNotNull('source_record_id')
            ->where('id', '>', $afterId)
            ->orderBy('id')
            ->limit($limit + 1)
            ->get();
        $hasMore = $mappings->count() > $limit;
        $mappings = $mappings->take($limit);
        $result = ['reconciled' => 0, 'review' => 0, 'not_applicable' => 0];
        $lastId = $afterId;

        foreach ($mappings as $mapping) {
            $lastId = (int) $mapping->id;

            try {
                $status = DB::transaction(function () use ($mapping, $businessId) {
                    return $this->reconcileMapping($mapping, $businessId);
                });
            } catch (\Throwable $exception) {
                Log::warning('Journal Import tax reconciliation failed for a mapping.', [
                    'journal_import_id' => $journalImportId,
                    'mapping_id' => $mapping->id,
                    'exception' => $exception->getMessage(),
                ]);
                $mapping->refresh();
                $mapping->tax_reconciliation_status = 'review';
                $mapping->tax_reconciliation_note = 'Rekonsiliasi otomatis gagal; transaksi sumber tidak diubah. Periksa log dan tinjau manual.';
                $mapping->save();
                $status = 'review';
            }

            $result[$status]++;
        }

        return [
            'result' => $result,
            'processed_rows' => $mappings->count(),
            'last_id' => $lastId,
            'has_more' => $hasMore,
        ];
    }

    public function revertImport(int $journalImportId, int $businessId): int
    {
        $mappings = AccountingAccTransMapping::where('journal_import_id', $journalImportId)
            ->where('business_id', $businessId)
            ->whereNotNull('source_tax_snapshot')
            ->get();
        $restored = 0;

        foreach ($mappings as $mapping) {
            $snapshot = json_decode((string) $mapping->source_tax_snapshot, true);
            if (! is_array($snapshot) || ! is_array($snapshot['transaction_before'] ?? null) || ! is_array($snapshot['transaction_after'] ?? null)) {
                throw new \RuntimeException('Snapshot pajak sumber tidak valid; revert dibatalkan agar data tidak tertimpa.');
            }

            $source = Transaction::where('business_id', $businessId)
                ->lockForUpdate()
                ->find($mapping->source_record_id);
            if (! $source) {
                continue;
            }

            foreach ($snapshot['transaction_after'] as $field => $expected) {
                if (! $this->sameValue($source->{$field}, $expected)) {
                    throw new \RuntimeException('Transaksi sumber berubah setelah rekonsiliasi Journal; pulihkan pajaknya secara manual sebelum revert batch.');
                }
            }

            foreach ($snapshot['lines'] ?? [] as $lineSnapshot) {
                $lineQuery = DB::table($lineSnapshot['table'])
                    ->where('transaction_id', $source->id)
                    ->where('id', $lineSnapshot['id']);
                $line = $lineQuery->first(array_keys($lineSnapshot['after'] ?? []));
                if (! $line) {
                    throw new \RuntimeException('Item invoice berubah setelah rekonsiliasi Journal; pulihkan pajaknya secara manual sebelum revert batch.');
                }

                foreach ($lineSnapshot['after'] as $field => $expected) {
                    if ($field === 'updated_at') {
                        continue;
                    }
                    if (! $this->sameValue($line->{$field}, $expected)) {
                        throw new \RuntimeException('Item invoice berubah setelah rekonsiliasi Journal; pulihkan pajaknya secara manual sebelum revert batch.');
                    }
                }

                $lineQuery->update(array_merge($lineSnapshot['before'], ['updated_at' => now()]));
            }

            DB::table('transactions')
                ->where('business_id', $businessId)
                ->where('id', $source->id)
                ->update(array_merge($snapshot['transaction_before'], ['updated_at' => now()]));
            $restored++;
        }

        return $restored;
    }

    private function reconcileMapping(AccountingAccTransMapping $mapping, int $businessId): string
    {
        if (in_array($mapping->tax_reconciliation_status, ['reconciled', 'review'], true) && ! empty($mapping->source_tax_snapshot)) {
            return $mapping->tax_reconciliation_status;
        }

        if ($mapping->source_record_type !== Transaction::class) {
            return $this->saveResult($mapping, 'not_applicable', 'Sumber bukan transaksi Sales, Purchase, atau Expense.', null, null);
        }

        $source = Transaction::where('business_id', $businessId)->find($mapping->source_record_id);
        if (! $source) {
            return $this->saveResult($mapping, 'review', 'Transaksi sumber tidak ditemukan lagi pada business ini.', null, null);
        }

        if (! in_array($source->type, ['sell', 'purchase', 'expense'], true)) {
            return $this->saveResult($mapping, 'not_applicable', 'Jenis transaksi sumber tidak memakai rekonsiliasi pajak ini.', null, null);
        }

        $sourceRow = DB::table('accounting_journal_import_rows')
            ->where('journal_import_id', $mapping->journal_import_id)
            ->where('business_id', $businessId)
            ->where('journal_mapping_id', $mapping->id)
            ->first(['source_action']);
        $linkedOnly = ($sourceRow->source_action ?? null) === 'link';

        $journalLines = DB::table('accounting_accounts_transactions as journal_line')
            ->leftJoin('accounting_accounts as account', function ($join) use ($businessId) {
                $join->on('account.id', '=', 'journal_line.accounting_account_id')
                    ->where('account.business_id', '=', $businessId);
            })
            ->where('journal_line.acc_trans_mapping_id', $mapping->id)
            ->where('journal_line.sub_type', 'journal_entry')
            ->get([
                'journal_line.accounting_account_id',
                'journal_line.amount',
                'journal_line.type',
                'account.gl_code as account_no',
                'account.name as account_name',
            ]);

        if ($journalLines->isEmpty()) {
            return $this->saveResult($mapping, 'review', 'Baris GL Journal untuk sumber ini tidak ditemukan.', null, null);
        }
        $journalTotal = $this->journalTransactionTotal($journalLines);

        $entry = ['lines' => $journalLines->map(fn ($line) => [
            'account_no' => (string) ($line->account_no ?? ''),
            'account_name' => (string) ($line->account_name ?? ''),
            'type' => (string) $line->type,
            'amount' => (float) $line->amount,
        ])->all()];
        $taxableBase = $this->sourceTaxableBase($source);
        try {
            $resolvedTax = (new JournalImportTaxResolver())->resolveForEntry($entry, $businessId, $taxableBase);
        } catch (\Throwable $exception) {
            return $this->saveResult($mapping, 'review', $exception->getMessage(), null, null, null, $journalTotal);
        }

        if ($resolvedTax === null) {
            $status = $this->sourceHasTax($source) ? 'review' : 'not_applicable';
            $note = $status === 'review'
                ? 'Transaksi sumber memiliki pajak, tetapi komponen pajak Journal tidak dapat dikenali dari nama akun dan sisi debit/kredit.'
                : 'Tidak ada komponen pajak Journal yang terdeteksi dan transaksi sumber tidak memiliki pajak.';

            return $this->saveResult($mapping, $status, $note, null, null, null, $journalTotal);
        }

        $journalTax = (float) $resolvedTax['tax_amount'];
        $taxRate = TaxRate::where('business_id', $businessId)
            ->whereNull('deleted_at')
            ->find((int) $resolvedTax['id']);
        if (! $taxRate) {
            return $this->saveResult($mapping, 'review', 'TaxRate hasil hitung tidak aktif pada business ini.', $journalTax, null, null, $journalTotal);
        }

        $rate = (float) $resolvedTax['rate'];

        if ($journalTax < -self::MATCH_TOLERANCE) {
            return $this->saveResult($mapping, 'review', 'Arah debit/kredit akun pajak berlawanan dengan jenis transaksi; sumber tidak diubah.', $journalTax, null, null, $journalTotal);
        }
        $journalTax = max(0, $journalTax);

        if ($source->type === 'expense') {
            if ($taxableBase === null || $taxableBase <= 0) {
                return $this->saveResult($mapping, 'review', 'Basis total Expense kosong; sumber tidak diubah.', $journalTax, null);
            }

            $finalTotal = $taxableBase + $journalTax;
            if ($linkedOnly) {
                $conflict = $this->linkedHeaderTaxConflict($source, (int) $taxRate->id, $journalTax, $taxableBase, $finalTotal);
                if ($conflict !== null) {
                    return $this->saveResult($mapping, 'review', $conflict, $journalTax, null, null, $journalTotal, $finalTotal);
                }
            }
            $totalMismatch = abs(round($finalTotal - $journalTotal, 4)) > self::MATCH_TOLERANCE;
            $note = $linkedOnly
                ? 'Pajak Expense yang belum terisi dilengkapi dari akun pajak Journal; basis transaksi dipertahankan.'
                : 'Tax Expense diperbarui dari akun pajak Journal; basis transaksi dipertahankan.';
            if ($totalMismatch) {
                $note .= ' Hasil total transaksi ('.number_format($finalTotal, 4, '.', '').') berbeda dari total debit Journal ('.number_format($journalTotal, 4, '.', '').'); perlu ditinjau.';
            }

            $snapshot = [
                'transaction_before' => $this->transactionTaxValues($source),
                'transaction_after' => [
                    'tax_id' => (int) $taxRate->id,
                    'tax_amount' => $journalTax,
                    'total_before_tax' => $taxableBase,
                    'final_total' => $finalTotal,
                ],
                'lines' => [],
            ];

            $source->tax_id = $taxRate->id;
            $source->tax_amount = $journalTax;
            $source->total_before_tax = $taxableBase;
            $source->final_total = $finalTotal;
            $source->save();

            return $this->saveResult($mapping, $totalMismatch ? 'review' : 'reconciled', $note, $journalTax, $journalTax, $snapshot, $journalTotal, $finalTotal);
        }

        $lineUpdates = $this->calculateSourceLineTaxes($source, $rate, $taxRate->id, $linkedOnly);
        if ($lineUpdates['error'] !== null) {
            return $this->saveResult($mapping, 'review', $lineUpdates['error'], $journalTax, $lineUpdates['tax_amount'], null, $journalTotal);
        }

        $calculatedTax = (float) $lineUpdates['tax_amount'];
        if (abs(round($calculatedTax - $journalTax, 4)) > self::MATCH_TOLERANCE) {
            return $this->saveResult(
                $mapping,
                'review',
                'Pajak item hasil perhitungan ('.number_format($calculatedTax, 4, '.', '').') berbeda dari Journal ('.number_format($journalTax, 4, '.', '').'); item dan header tidak diubah.',
                $journalTax,
                $calculatedTax,
                null,
                $journalTotal,
                $source->total_before_tax === null ? null : (float) $source->total_before_tax + $journalTax
            );
        }

        if ($taxableBase === null || $taxableBase <= 0) {
            return $this->saveResult($mapping, 'review', 'Basis total invoice kosong; sumber tidak diubah.', $journalTax, $calculatedTax, null, $journalTotal);
        }

        $finalTotal = $taxableBase + $journalTax;
        if ($linkedOnly) {
            $conflict = $this->linkedHeaderTaxConflict($source, (int) $taxRate->id, $journalTax, $taxableBase, $finalTotal);
            if ($conflict !== null) {
                return $this->saveResult($mapping, 'review', $conflict, $journalTax, $calculatedTax, null, $journalTotal, $finalTotal);
            }
        }
        $totalMismatch = abs(round($finalTotal - $journalTotal, 4)) > self::MATCH_TOLERANCE;
        $note = $linkedOnly
            ? 'Pajak item invoice yang belum terisi dilengkapi dari Journal; basis total invoice dipertahankan.'
            : 'Tax item dan header diperbarui dari Journal; basis total invoice dipertahankan.';
        if ($totalMismatch) {
            $note .= ' Hasil total transaksi ('.number_format($finalTotal, 4, '.', '').') berbeda dari total debit Journal ('.number_format($journalTotal, 4, '.', '').'); perlu ditinjau.';
        }

        $snapshot = [
            'transaction_before' => $this->transactionTaxValues($source),
            'transaction_after' => [
                'tax_id' => (int) $taxRate->id,
                'tax_amount' => $journalTax,
                'total_before_tax' => $taxableBase,
                'final_total' => $finalTotal,
            ],
            'lines' => [],
        ];

        foreach ($lineUpdates['lines'] as $lineUpdate) {
            $snapshot['lines'][] = [
                'table' => $lineUpdate['table'],
                'id' => $lineUpdate['id'],
                'before' => $lineUpdate['before'],
                'after' => $lineUpdate['values'],
            ];
            $lineQuery = DB::table($lineUpdate['table'])->where('id', $lineUpdate['id'])->where('transaction_id', $source->id);
            if (! $lineQuery->exists()) {
                throw new \RuntimeException('Item invoice berubah saat rekonsiliasi; import dibatalkan.');
            }
            $lineQuery->update($lineUpdate['values']);
        }

        $source->tax_id = $taxRate->id;
        $source->tax_amount = $journalTax;
        $source->total_before_tax = $taxableBase;
        $source->final_total = $finalTotal;
        $source->save();

        return $this->saveResult($mapping, $totalMismatch ? 'review' : 'reconciled', $note, $journalTax, $calculatedTax, $snapshot, $journalTotal, $finalTotal);
    }

    private function calculateSourceLineTaxes(Transaction $source, float $rate, int $taxRateId, bool $preserveExisting = false): array
    {
        $lines = [];
        $taxAmount = 0.0;

        if ($source->type === 'sell' && $source->sub_type === 'project_invoice') {
            if (! DB::getSchemaBuilder()->hasTable('pjt_invoice_lines')) {
                return ['lines' => [], 'tax_amount' => null, 'error' => 'Item Project Invoice tidak tersedia untuk rekonsiliasi; sumber tidak diubah.'];
            }

            $sourceLines = DB::table('pjt_invoice_lines')
                ->where('transaction_id', $source->id)
                ->get(['id', 'quantity', 'rate', 'tax_rate_id', 'total']);

            foreach ($sourceLines as $line) {
                $quantity = (float) $line->quantity;
                $base = (float) $line->rate;
                $unitTax = round(($base * $rate) / 100, 4);
                $expectedTotal = round($quantity * ($base + $unitTax), 4);
                if ($preserveExisting
                    && ((! empty($line->tax_rate_id) && (int) $line->tax_rate_id !== $taxRateId)
                        || ($line->total !== null
                            && abs((float) $line->total) > self::MATCH_TOLERANCE
                            && abs((float) $line->total - ($quantity * $base)) > self::MATCH_TOLERANCE
                            && abs((float) $line->total - $expectedTotal) > self::MATCH_TOLERANCE))) {
                    return ['lines' => [], 'tax_amount' => null, 'error' => 'Pajak atau total item Project Invoice sudah terisi berbeda dari Journal; item sumber tidak diubah.'];
                }
                $taxAmount += $quantity * $unitTax;
                $lines[] = [
                    'table' => 'pjt_invoice_lines',
                    'id' => $line->id,
                    'before' => ['tax_rate_id' => $line->tax_rate_id, 'total' => $line->total],
                    'values' => ['tax_rate_id' => $taxRateId, 'total' => $expectedTotal, 'updated_at' => now()],
                ];
            }
        } elseif ($source->type === 'sell') {
            $sourceLinesQuery = TransactionSellLine::where('transaction_id', $source->id);
            if (DB::getSchemaBuilder()->hasColumn('transaction_sell_lines', 'parent_sell_line_id')) {
                $sourceLinesQuery->whereNull('parent_sell_line_id');
            }
            $sourceLines = $sourceLinesQuery
                ->get(['id', 'quantity', 'unit_price', 'tax_id', 'item_tax', 'unit_price_inc_tax']);

            foreach ($sourceLines as $line) {
                if ($line->unit_price === null) {
                    return ['lines' => [], 'tax_amount' => null, 'error' => 'Ada item Sales tanpa harga dasar; sumber tidak diubah.'];
                }
                $quantity = (float) $line->quantity;
                $base = (float) $line->unit_price;
                $unitTax = round(($base * $rate) / 100, 4);
                if ($preserveExisting && $this->linkedLineTaxConflicts($line, $taxRateId, $unitTax, $base)) {
                    return ['lines' => [], 'tax_amount' => null, 'error' => 'Pajak atau total item Sales sudah terisi berbeda dari Journal; item sumber tidak diubah.'];
                }
                $taxAmount += $quantity * $unitTax;
                $lines[] = [
                    'table' => 'transaction_sell_lines',
                    'id' => $line->id,
                    'before' => ['tax_id' => $line->tax_id, 'item_tax' => $line->item_tax, 'unit_price_inc_tax' => $line->unit_price_inc_tax],
                    'values' => ['tax_id' => $taxRateId, 'item_tax' => $unitTax, 'unit_price_inc_tax' => $base + $unitTax, 'updated_at' => now()],
                ];
            }
        } else {
            $sourceLines = PurchaseLine::where('transaction_id', $source->id)
                ->get(['id', 'quantity', 'purchase_price', 'tax_id', 'item_tax', 'purchase_price_inc_tax']);

            foreach ($sourceLines as $line) {
                if ($line->purchase_price === null) {
                    return ['lines' => [], 'tax_amount' => null, 'error' => 'Ada item Purchase tanpa harga dasar; sumber tidak diubah.'];
                }
                $quantity = (float) $line->quantity;
                $base = (float) $line->purchase_price;
                $unitTax = round(($base * $rate) / 100, 4);
                if ($preserveExisting && $this->linkedLineTaxConflicts($line, $taxRateId, $unitTax, $base, 'purchase_price_inc_tax')) {
                    return ['lines' => [], 'tax_amount' => null, 'error' => 'Pajak atau total item Purchase sudah terisi berbeda dari Journal; item sumber tidak diubah.'];
                }
                $taxAmount += $quantity * $unitTax;
                $lines[] = [
                    'table' => 'purchase_lines',
                    'id' => $line->id,
                    'before' => ['tax_id' => $line->tax_id, 'item_tax' => $line->item_tax, 'purchase_price_inc_tax' => $line->purchase_price_inc_tax],
                    'values' => ['tax_id' => $taxRateId, 'item_tax' => $unitTax, 'purchase_price_inc_tax' => $base + $unitTax, 'updated_at' => now()],
                ];
            }
        }

        return ['lines' => $lines, 'tax_amount' => round($taxAmount, 4), 'error' => null];
    }

    private function sourceTaxableBase(Transaction $source): ?float
    {
        if ($source->type === 'expense' && DB::getSchemaBuilder()->hasTable('expense_lines')) {
            $base = (float) DB::table('expense_lines')->where('transaction_id', $source->id)->sum('amount');

            if ($base > 0) {
                return round($base, 4);
            }
        }

        if ($source->type === 'sell' && $source->sub_type === 'project_invoice' && DB::getSchemaBuilder()->hasTable('pjt_invoice_lines')) {
            $base = (float) DB::table('pjt_invoice_lines')
                ->where('transaction_id', $source->id)
                ->get(['quantity', 'rate'])
                ->sum(fn ($line) => (float) $line->quantity * (float) $line->rate);

            if ($base > 0) {
                return round($base, 4);
            }
        }

        if ($source->type === 'sell') {
            $lines = DB::table('transaction_sell_lines')->where('transaction_id', $source->id);
            if (DB::getSchemaBuilder()->hasColumn('transaction_sell_lines', 'parent_sell_line_id')) {
                $lines->whereNull('parent_sell_line_id');
            }
            $base = (float) $lines->get(['quantity', 'unit_price'])
                ->sum(fn ($line) => (float) $line->quantity * (float) $line->unit_price);

            if ($base > 0) {
                return round($base, 4);
            }
        }

        if ($source->type === 'purchase') {
            $base = (float) DB::table('purchase_lines')
                ->where('transaction_id', $source->id)
                ->get(['quantity', 'purchase_price'])
                ->sum(fn ($line) => (float) $line->quantity * (float) $line->purchase_price);

            if ($base > 0) {
                return round($base, 4);
            }
        }

        if ($source->total_before_tax !== null && (float) $source->total_before_tax > 0) {
            return round((float) $source->total_before_tax, 4);
        }

        return null;
    }

    private function linkedHeaderTaxConflict(Transaction $source, int $taxRateId, float $journalTax, float $taxableBase, float $expectedFinalTotal): ?string
    {
        if (! empty($source->tax_id) && (int) $source->tax_id !== $taxRateId) {
            return 'TaxRate sumber sudah terisi dan berbeda dari komponen Journal; transaksi sumber tidak diubah dan perlu ditinjau.';
        }

        if ($source->tax_amount !== null
            && abs((float) $source->tax_amount) > self::MATCH_TOLERANCE
            && abs((float) $source->tax_amount - $journalTax) > self::MATCH_TOLERANCE) {
            return 'Nominal pajak sumber sudah terisi dan berbeda dari komponen Journal; transaksi sumber tidak diubah dan perlu ditinjau.';
        }

        if ($source->final_total !== null) {
            $finalTotalIsBlank = abs((float) $source->final_total) <= self::MATCH_TOLERANCE;
            $matchesExpected = abs((float) $source->final_total - $expectedFinalTotal) <= self::MATCH_TOLERANCE;
            $taxIsMissing = $source->tax_amount === null || (float) $source->tax_amount <= self::MATCH_TOLERANCE;
            $matchesBaseWithoutTax = $taxIsMissing
                && abs((float) $source->final_total - $taxableBase) <= self::MATCH_TOLERANCE;
            if (! $finalTotalIsBlank && ! $matchesExpected && ! $matchesBaseWithoutTax) {
                return 'Total akhir sumber sudah terisi dan tidak cocok dengan basis serta pajak Journal; transaksi sumber tidak diubah dan perlu ditinjau.';
            }
        }

        return null;
    }

    private function linkedLineTaxConflicts(object $line, int $taxRateId, float $unitTax, float $base, string $inclusivePriceColumn = 'unit_price_inc_tax'): bool
    {
        if (! empty($line->tax_id) && (int) $line->tax_id !== $taxRateId) {
            return true;
        }
        if ($line->item_tax !== null
            && abs((float) $line->item_tax) > self::MATCH_TOLERANCE
            && abs((float) $line->item_tax - $unitTax) > self::MATCH_TOLERANCE) {
            return true;
        }
        $inclusivePrice = $line->{$inclusivePriceColumn};
        return $inclusivePrice !== null
            && abs((float) $inclusivePrice) > self::MATCH_TOLERANCE
            && abs((float) $inclusivePrice - $base) > self::MATCH_TOLERANCE
            && abs((float) $inclusivePrice - ($base + $unitTax)) > self::MATCH_TOLERANCE;
    }

    private function journalTransactionTotal($journalLines): float
    {
        return round((float) $journalLines
            ->filter(fn ($line) => strtolower((string) $line->type) === 'debit')
            ->sum(fn ($line) => (float) $line->amount), 4);
    }

    private function sourceHasTax(Transaction $source): bool
    {
        if (! empty($source->tax_id) || abs((float) $source->tax_amount) > 0) {
            return true;
        }

        if ($source->type === 'sell' && $source->sub_type === 'project_invoice' && DB::getSchemaBuilder()->hasTable('pjt_invoice_lines')) {
            return DB::table('pjt_invoice_lines')->where('transaction_id', $source->id)->whereNotNull('tax_rate_id')->exists();
        }

        $table = $source->type === 'sell' ? 'transaction_sell_lines' : ($source->type === 'purchase' ? 'purchase_lines' : 'expense_lines');
        $taxColumn = $source->type === 'expense' ? null : 'tax_id';

        if ($taxColumn !== null) {
            return DB::table($table)->where('transaction_id', $source->id)->whereNotNull($taxColumn)->exists();
        }

        return false;
    }

    private function saveResult(AccountingAccTransMapping $mapping, string $status, string $note, ?float $journalTax, ?float $calculatedTax, ?array $snapshot = null, ?float $journalTotal = null, ?float $recalculatedFinalTotal = null): string
    {
        $mapping->tax_reconciliation_status = $status;
        $mapping->tax_reconciliation_note = $note;
        $mapping->journal_tax_amount = $journalTax;
        $mapping->calculated_tax_amount = $calculatedTax;
        $mapping->journal_total_amount = $journalTotal;
        $mapping->recalculated_final_total = $recalculatedFinalTotal;
        if ($snapshot !== null) {
            $mapping->source_tax_snapshot = json_encode($snapshot, JSON_UNESCAPED_UNICODE);
        }
        $mapping->save();

        return $status;
    }

    private function transactionTaxValues(Transaction $source): array
    {
        return [
            'tax_id' => $source->tax_id,
            'tax_amount' => $source->tax_amount,
            'total_before_tax' => $source->total_before_tax,
            'final_total' => $source->final_total,
        ];
    }

    private function sameValue($actual, $expected): bool
    {
        if ($actual === null || $expected === null) {
            return $actual === null && $expected === null;
        }

        if (is_numeric($actual) && is_numeric($expected)) {
            return abs((float) $actual - (float) $expected) <= 0.0001;
        }

        return (string) $actual === (string) $expected;
    }
}
