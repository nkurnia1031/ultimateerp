<?php

namespace App\Presenters;

class PurchaseFinancialSummaryPresenter
{
    public function summarize(iterable $purchaseLines, array $transactionTaxes = [], array $taxNames = []): array
    {
        $totalBeforeTax = 0.0;
        $itemTaxTotal = 0.0;
        $taxBreakdown = [];

        foreach ($transactionTaxes as $name => $amount) {
            $name = trim((string) $name);
            if ($name === '') {
                $name = 'Pajak transaksi';
            }

            $taxBreakdown[$name] = ($taxBreakdown[$name] ?? 0.0) + (float) $amount;
        }

        foreach ($purchaseLines as $line) {
            $quantity = (float) ($line->quantity ?? 0);
            $totalBeforeTax += $quantity * (float) ($line->purchase_price ?? 0);

            $lineTax = $quantity * (float) ($line->item_tax ?? 0);
            if ($lineTax == 0.0) {
                continue;
            }

            $itemTaxTotal += $lineTax;
            $taxName = trim((string) ($taxNames[$line->tax_id ?? null] ?? ''));
            if ($taxName === '') {
                $taxName = 'Pajak produk';
            }

            $taxBreakdown[$taxName] = ($taxBreakdown[$taxName] ?? 0.0) + $lineTax;
        }

        return [
            'total_before_tax' => $totalBeforeTax,
            'item_tax_total' => $itemTaxTotal,
            'transaction_tax_total' => array_sum(array_map('floatval', $transactionTaxes)),
            'total_tax' => array_sum($taxBreakdown),
            'taxes' => $taxBreakdown,
        ];
    }
}
