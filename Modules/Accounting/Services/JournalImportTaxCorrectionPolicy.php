<?php

namespace Modules\Accounting\Services;

class JournalImportTaxCorrectionPolicy
{
    public function shouldCorrectFinalTotal($sourceTax, $sourceBase, $sourceFinal, array $newTotals, float $tolerance = 0.01): bool
    {
        if (($sourceTax !== null && (float) $sourceTax > 0)
            || $sourceBase === null
            || $sourceFinal === null
            || (float) ($newTotals['tax'] ?? 0) <= 0
            || (float) ($newTotals['total_before_tax'] ?? 0) < 0
            || ! isset($newTotals['final_total'])) {
            return false;
        }

        return abs((float) $sourceFinal - (float) $sourceBase) <= $tolerance
            && abs((float) $newTotals['final_total'] - ((float) $newTotals['total_before_tax'] + (float) $newTotals['tax'])) <= $tolerance;
    }
}
