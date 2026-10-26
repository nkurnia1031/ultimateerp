<?php

namespace Modules\Accounting\Services;

use App\Utils\TransactionUtil;

class JournalImportExpenseTotalsService
{
    private $transactionUtil;

    public function __construct(TransactionUtil $transactionUtil)
    {
        $this->transactionUtil = $transactionUtil;
    }

    public function forSource(?array $source, ?float $journalTotal, ?array $tax, array $expenseComponents = [], array $taxComponents = []): array
    {
        $componentBase = round((float) collect($expenseComponents)->sum('debit'), 4);
        $componentTax = round((float) collect($taxComponents)->sum('debit'), 4);
        if ($source === null) {
            $finalTotal = $expenseComponents !== [] || $taxComponents !== []
                ? round($componentBase + $componentTax, 4)
                : (float) $journalTotal;
            $base = $expenseComponents !== [] ? $componentBase : round($finalTotal - $componentTax, 4);
            $taxAmount = $taxComponents !== [] ? $componentTax : round($finalTotal - $base, 4);

            return [
                'total_before_tax' => $base,
                'tax' => $taxAmount,
                'calculated_tax' => $tax === null ? 0.0 : round($base * (float) $tax['rate'] / 100, 4),
                'final_total' => round($finalTotal, 4),
            ];
        }

        $sourceFinal = $source['final_total'] === null ? null : (float) $source['final_total'];
        $sourceBase = $source['total_before_tax'] === null ? null : (float) $source['total_before_tax'];
        $sourceTax = $source['tax_amount'] === null ? null : (float) $source['tax_amount'];

        if ($tax !== null) {
            $gross = $sourceFinal ?? $journalTotal;
            if ($gross === null) {
                throw new \RuntimeException('Total Journal tidak tersedia untuk menghitung pajak Expense.');
            }
            $base = $sourceBase ?? ($expenseComponents !== []
                ? $componentBase
                : round((float) $this->transactionUtil->calc_percentage_base($gross, $tax['rate']), 4));
            $calculatedTax = round($base * (float) $tax['rate'] / 100, 4);
            $taxAmount = ($sourceTax !== null && $sourceTax > 0)
                ? $sourceTax
                : ($taxComponents !== [] ? $componentTax : round($gross - $base, 4));
            $sourceFinalNeedsTaxCorrection = (new JournalImportTaxCorrectionPolicy())->shouldCorrectFinalTotal(
                $sourceTax,
                $sourceBase,
                $sourceFinal,
                [
                    'total_before_tax' => $base,
                    'tax' => $taxAmount,
                    'final_total' => round($base + $taxAmount, 4),
                ]
            );
            $finalTotal = $sourceFinal === null || $sourceFinalNeedsTaxCorrection
                ? round($base + $taxAmount, 4)
                : $sourceFinal;

            return [
                'total_before_tax' => round($base, 4),
                'tax' => round($taxAmount, 4),
                'calculated_tax' => $calculatedTax,
                'final_total' => round($finalTotal, 4),
            ];
        }

        $base = $sourceBase ?? ($expenseComponents !== [] ? $componentBase : ($sourceFinal ?? $journalTotal));
        $taxAmount = ($sourceTax !== null && $sourceTax > 0)
            ? $sourceTax
            : ($taxComponents !== [] ? $componentTax : 0.0);
        if ($base === null) {
            throw new \RuntimeException('Total Expense atau total debit Journal belum tersedia.');
        }
        $computedFinal = round((float) $base + $taxAmount, 4);
        $sourceFinalNeedsTaxCorrection = (new JournalImportTaxCorrectionPolicy())->shouldCorrectFinalTotal(
            $sourceTax,
            $sourceBase,
            $sourceFinal,
            [
                'total_before_tax' => $base,
                'tax' => $taxAmount,
                'final_total' => $computedFinal,
            ]
        );
        $finalTotal = $sourceFinal === null || $sourceFinalNeedsTaxCorrection
            ? $computedFinal
            : $sourceFinal;

        return [
            'total_before_tax' => round((float) $base, 4),
            'tax' => round((float) $taxAmount, 4),
            'calculated_tax' => 0.0,
            'final_total' => round((float) $finalTotal, 4),
        ];
    }

    public function explainsTaxDifference(?array $source, float $journalTotal, array $expenseComponents, array $taxComponents, float $tolerance = 0.01): bool
    {
        if ($source === null || $taxComponents === [] || ! is_finite($journalTotal) || ! is_finite($tolerance) || $tolerance < 0) {
            return false;
        }

        $sourceBase = $source['total_before_tax'] === null ? null : (float) $source['total_before_tax'];
        $sourceTax = $source['tax_amount'] === null ? null : (float) $source['tax_amount'];
        $sourceFinal = $source['final_total'] === null ? null : (float) $source['final_total'];
        if ($sourceBase === null || $sourceFinal === null || ($sourceTax !== null && abs($sourceTax) > $tolerance)) {
            return false;
        }

        $journalBase = round((float) collect($expenseComponents)->sum('debit'), 4);
        $journalTax = round((float) collect($taxComponents)->sum('debit'), 4);
        if ($journalTax <= $tolerance
            || abs(round($sourceBase - $journalBase, 4)) > $tolerance
            || abs(round($sourceFinal - $sourceBase, 4)) > $tolerance) {
            return false;
        }

        $componentsTotal = round($journalBase + $journalTax, 4);
        $correctedSourceTotal = round($sourceBase + $journalTax, 4);

        return abs(round($journalTotal - $componentsTotal, 4)) <= $tolerance
            && abs(round($journalTotal - $correctedSourceTotal, 4)) <= $tolerance;
    }
}
