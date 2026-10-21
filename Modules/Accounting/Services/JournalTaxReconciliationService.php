<?php

namespace Modules\Accounting\Services;

use App\PurchaseLine;
use App\TaxRate;
use App\Transaction;
use App\TransactionSellLine;
use Illuminate\Support\Facades\DB;
use Modules\Accounting\Entities\AccountingAccTransMapping;

class JournalTaxReconciliationService
{
    private const MATCH_TOLERANCE = 0.01;

    /**
     * Apply mapped Journal tax to uniquely linked source invoices and expenses.
     * Sales and purchase line taxes are only changed when their full calculated
     * tax matches the Journal amount; otherwise the source remains unchanged.
     */
    public function reconcileImport(int $journalImportId, int $businessId): array
    {
        $result = ['reconciled' => 0, 'review' => 0, 'not_applicable' => 0];
        $mappings = AccountingAccTransMapping::where('journal_import_id', $journalImportId)
            ->where('business_id', $businessId)
            ->where('source_link_status', 'matched')
            ->whereNotNull('source_record_id')
            ->get();

        foreach ($mappings as $mapping) {
            $status = $this->reconcileMapping($mapping, $businessId);
            $result[$status]++;
        }

        return $result;
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

        $journalLines = DB::table('accounting_accounts_transactions')
            ->where('acc_trans_mapping_id', $mapping->id)
            ->where('sub_type', 'journal_entry')
            ->get(['accounting_account_id', 'amount', 'type']);

        if ($journalLines->isEmpty()) {
            return $this->saveResult($mapping, 'review', 'Baris GL Journal untuk sumber ini tidak ditemukan.', null, null);
        }
        $journalTotal = $this->journalTransactionTotal($journalLines);

        $accountIds = $journalLines->pluck('accounting_account_id')->unique()->values();
        $taxMappings = DB::table('accounting_journal_tax_account_mappings as tax_mapping')
            ->leftJoin('tax_rates as tax_rate', function ($join) {
                $join->on('tax_rate.id', '=', 'tax_mapping.tax_rate_id')
                    ->on('tax_rate.business_id', '=', 'tax_mapping.business_id')
                    ->whereNull('tax_rate.deleted_at');
            })
            ->where('tax_mapping.business_id', $businessId)
            ->whereIn('tax_mapping.accounting_account_id', $accountIds)
            ->select(
                'tax_mapping.accounting_account_id',
                'tax_mapping.tax_rate_id',
                'tax_rate.id as active_tax_rate_id'
            )
            ->get()
            ->keyBy('accounting_account_id');

        $mappedLines = $journalLines->filter(fn ($line) => $taxMappings->has($line->accounting_account_id))->values();
        if ($mappedLines->isEmpty()) {
            $status = $this->sourceHasTax($source) ? 'review' : 'not_applicable';
            $note = $status === 'review'
                ? 'Transaksi sumber memiliki pajak, tetapi entry Journal tidak memiliki akun pajak yang dipetakan.'
                : 'Tidak ada akun pajak GL yang dipetakan dan transaksi sumber tidak memiliki pajak.';

            return $this->saveResult($mapping, $status, $note, null, null, null, $journalTotal);
        }

        $journalTax = $this->journalTaxAmount($mappedLines, $source->type);

        $taxRateIds = [];
        foreach ($mappedLines as $line) {
            $taxMapping = $taxMappings->get($line->accounting_account_id);
            if (empty($taxMapping->active_tax_rate_id)) {
                return $this->saveResult($mapping, 'review', 'Mapping akun pajak mengarah ke TaxRate yang tidak aktif.', $journalTax, null, null, $journalTotal);
            }
            $taxRateIds[(int) $taxMapping->tax_rate_id] = true;
        }

        if (count($taxRateIds) !== 1) {
            return $this->saveResult($mapping, 'review', 'Akun pajak Journal dipetakan ke lebih dari satu TaxRate/grup; transaksi tidak diubah.', $journalTax, null, null, $journalTotal);
        }

        $taxRate = TaxRate::where('business_id', $businessId)->with('sub_taxes')->find(array_key_first($taxRateIds));
        if (! $taxRate) {
            return $this->saveResult($mapping, 'review', 'TaxRate hasil mapping tidak aktif pada business ini.', $journalTax, null, null, $journalTotal);
        }

        $rate = $this->taxRateAmount($taxRate, $businessId);
        if ($rate === null) {
            return $this->saveResult($mapping, 'review', 'Grup pajak tidak memiliki komponen aktif yang valid.', $journalTax, null, null, $journalTotal);
        }

        if ($journalTax < -self::MATCH_TOLERANCE) {
            return $this->saveResult($mapping, 'review', 'Arah debit/kredit akun pajak berlawanan dengan jenis transaksi; sumber tidak diubah.', $journalTax, null, null, $journalTotal);
        }
        $journalTax = max(0, $journalTax);

        if ($source->type === 'expense') {
            if ($source->total_before_tax === null) {
                return $this->saveResult($mapping, 'review', 'Basis total Expense kosong; sumber tidak diubah.', $journalTax, null);
            }

            $finalTotal = (float) $source->total_before_tax + $journalTax;
            $totalMismatch = abs(round($finalTotal - $journalTotal, 4)) > self::MATCH_TOLERANCE;
            $note = 'Tax Expense diperbarui dari akun pajak Journal; basis transaksi dipertahankan.';
            if ($totalMismatch) {
                $note .= ' Hasil total transaksi ('.number_format($finalTotal, 4, '.', '').') berbeda dari total debit Journal ('.number_format($journalTotal, 4, '.', '').'); perlu ditinjau.';
            }

            $snapshot = [
                'transaction_before' => $this->transactionTaxValues($source),
                'transaction_after' => [
                    'tax_id' => (int) $taxRate->id,
                    'tax_amount' => $journalTax,
                    'total_before_tax' => (float) $source->total_before_tax,
                    'final_total' => $finalTotal,
                ],
                'lines' => [],
            ];

            $source->tax_id = $taxRate->id;
            $source->tax_amount = $journalTax;
            $source->final_total = $finalTotal;
            $source->save();

            return $this->saveResult($mapping, $totalMismatch ? 'review' : 'reconciled', $note, $journalTax, $journalTax, $snapshot, $journalTotal, $finalTotal);
        }

        $lineUpdates = $this->calculateSourceLineTaxes($source, $rate, $taxRate->id);
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

        if ($source->total_before_tax === null) {
            return $this->saveResult($mapping, 'review', 'Basis total invoice kosong; sumber tidak diubah.', $journalTax, $calculatedTax, null, $journalTotal);
        }

        $finalTotal = (float) $source->total_before_tax + $journalTax;
        $totalMismatch = abs(round($finalTotal - $journalTotal, 4)) > self::MATCH_TOLERANCE;
        $note = 'Tax item dan header diperbarui dari Journal; basis total invoice dipertahankan.';
        if ($totalMismatch) {
            $note .= ' Hasil total transaksi ('.number_format($finalTotal, 4, '.', '').') berbeda dari total debit Journal ('.number_format($journalTotal, 4, '.', '').'); perlu ditinjau.';
        }

        $snapshot = [
            'transaction_before' => $this->transactionTaxValues($source),
            'transaction_after' => [
                'tax_id' => (int) $taxRate->id,
                'tax_amount' => $journalTax,
                'total_before_tax' => (float) $source->total_before_tax,
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
        $source->final_total = $finalTotal;
        $source->save();

        return $this->saveResult($mapping, $totalMismatch ? 'review' : 'reconciled', $note, $journalTax, $calculatedTax, $snapshot, $journalTotal, $finalTotal);
    }

    private function calculateSourceLineTaxes(Transaction $source, float $rate, int $taxRateId): array
    {
        $lines = [];
        $taxAmount = 0.0;

        if ($source->type === 'sell' && $source->sub_type === 'project_invoice') {
            if (! DB::getSchemaBuilder()->hasTable('pjt_invoice_lines')) {
                return ['lines' => [], 'tax_amount' => null, 'error' => 'Item Project Invoice tidak tersedia untuk rekonsiliasi; sumber tidak diubah.'];
            }

            $sourceLines = DB::table('pjt_invoice_lines')
                ->where('transaction_id', $source->id)
                ->whereNotNull('tax_rate_id')
                ->get(['id', 'quantity', 'rate', 'tax_rate_id', 'total']);

            foreach ($sourceLines as $line) {
                $quantity = (float) $line->quantity;
                $unitTax = round(((float) $line->rate * $rate) / 100, 4);
                $taxAmount += $quantity * $unitTax;
                $lines[] = [
                    'table' => 'pjt_invoice_lines',
                    'id' => $line->id,
                    'before' => ['tax_rate_id' => $line->tax_rate_id, 'total' => $line->total],
                    'values' => ['tax_rate_id' => $taxRateId, 'total' => round($quantity * ((float) $line->rate + $unitTax), 4), 'updated_at' => now()],
                ];
            }
        } elseif ($source->type === 'sell') {
            $sourceLines = TransactionSellLine::where('transaction_id', $source->id)
                ->whereNotNull('tax_id')
                ->get(['id', 'quantity', 'unit_price', 'tax_id', 'item_tax', 'unit_price_inc_tax']);

            foreach ($sourceLines as $line) {
                if ($line->unit_price === null) {
                    return ['lines' => [], 'tax_amount' => null, 'error' => 'Ada item Sales tanpa harga dasar; sumber tidak diubah.'];
                }
                $quantity = (float) $line->quantity;
                $base = (float) $line->unit_price;
                $unitTax = round(($base * $rate) / 100, 4);
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
                ->whereNotNull('tax_id')
                ->get(['id', 'quantity', 'purchase_price', 'tax_id', 'item_tax', 'purchase_price_inc_tax']);

            foreach ($sourceLines as $line) {
                if ($line->purchase_price === null) {
                    return ['lines' => [], 'tax_amount' => null, 'error' => 'Ada item Purchase tanpa harga dasar; sumber tidak diubah.'];
                }
                $quantity = (float) $line->quantity;
                $base = (float) $line->purchase_price;
                $unitTax = round(($base * $rate) / 100, 4);
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

    private function taxRateAmount(TaxRate $taxRate, int $businessId): ?float
    {
        if (! $taxRate->is_tax_group) {
            return (float) $taxRate->amount;
        }

        $subTaxes = $taxRate->sub_taxes->where('business_id', $businessId);
        if ($subTaxes->isEmpty()) {
            return null;
        }

        return (float) $subTaxes->sum('amount');
    }

    private function journalTaxAmount($mappedLines, string $sourceType): float
    {
        $amount = 0.0;
        foreach ($mappedLines as $line) {
            $lineAmount = (float) $line->amount;
            $isDebit = strtolower((string) $line->type) === 'debit';
            $amount += $sourceType === 'sell'
                ? ($isDebit ? -$lineAmount : $lineAmount)
                : ($isDebit ? $lineAmount : -$lineAmount);
        }

        return round($amount, 4);
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
