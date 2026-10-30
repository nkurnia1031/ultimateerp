<?php

namespace Modules\Accounting\Services;

use App\Transaction;

class JournalImportSourceMatchPolicy
{
    public function __construct(
        private JournalImportSourceMatcher $matcher,
        private JournalImportAccountComponentService $componentService,
        private JournalImportTaxResolver $taxResolver
    ) {
    }

    public function resolve(array $candidates, string $sourceType, array $entry, int $businessId): array
    {
        $date = (string) ($entry['date'] ?? '');
        $amount = (float) ($entry['debit_total'] ?? 0);
        $componentSourceTypes = ['Expense', 'Purchase Invoice', 'Sales Invoice'];
        $components = in_array($sourceType, $componentSourceTypes, true)
            ? $this->componentService->groupJournalLines($entry, $businessId)
            : [];
        $sourceComponents = in_array($sourceType, $componentSourceTypes, true)
            ? $this->componentService->totalsForSource($components, $sourceType)
            : null;
        $ranked = $this->matcher->rankCandidates($candidates, $date, $amount, $sourceComponents);
        $exact = $this->matcher->uniqueExactCandidate($ranked, $date, $amount, $sourceComponents);
        $vatExplained = $exact === null
            && count($ranked) === 1
            && $this->candidateDifferenceIsExplainedByVat($ranked[0], $sourceType, $entry, $businessId, $components);
        $selected = $exact ?? ($vatExplained ? $ranked[0] : null);

        if ($selected !== null) {
            return [
                'status' => 'matched',
                'default_action' => $vatExplained ? 'update' : 'link',
                'selected_candidate' => $selected,
                'note' => $vatExplained
                    ? 'Selisih sumber dijelaskan seluruhnya oleh VAT yang cocok dengan TaxRate business; sumber akan dilengkapi.'
                    : 'Kandidat tunggal cocok pada nomor, tanggal, total, dan komponen Journal yang tersedia.',
                'candidates' => $ranked,
            ];
        }

        if (count($ranked) > 1) {
            return [
                'status' => 'ambiguous',
                'default_action' => 'review',
                'selected_candidate' => null,
                'note' => 'Ditemukan '.count($ranked).' sumber dengan referensi ini; periksa tanggal dan selisih tiap kandidat.',
                'candidates' => $ranked,
            ];
        }

        if (count($ranked) === 1) {
            return [
                'status' => 'suggested',
                'default_action' => 'review',
                'selected_candidate' => null,
                'note' => 'Kandidat terdekat disarankan untuk ditinjau; tanggal/total belum cocok penuh dan tidak ditautkan otomatis.',
                'candidates' => $ranked,
            ];
        }

        return [
            'status' => 'unmatched',
            'default_action' => 'create',
            'selected_candidate' => null,
            'note' => '',
            'candidates' => [],
        ];
    }

    private function candidateDifferenceIsExplainedByVat(array $candidate, string $sourceType, array $entry, int $businessId, array $components): bool
    {
        if (($candidate['record_type'] ?? null) !== Transaction::class
            || ! in_array($sourceType, ['Expense', 'Purchase Invoice', 'Sales Invoice'], true)) {
            return false;
        }

        $expectedTransactionType = $sourceType === 'Sales Invoice'
            ? 'sell'
            : ($sourceType === 'Purchase Invoice' ? 'purchase' : 'expense');
        if (($candidate['transaction_type'] ?? null) !== $expectedTransactionType
            || ! is_numeric($candidate['base_amount'] ?? null)
            || ! is_numeric($candidate['amount'] ?? null)
            || (string) ($candidate['date'] ?? '') !== (string) ($entry['date'] ?? '')) {
            return false;
        }

        $baseAmount = (float) $candidate['base_amount'];
        $sourceAmount = (float) $candidate['amount'];
        $sourceTax = is_numeric($candidate['tax_amount'] ?? null) ? (float) $candidate['tax_amount'] : 0.0;
        if ($baseAmount <= 0
            || abs($sourceAmount - $baseAmount) > 0.01
            || abs($sourceTax) > 0.01) {
            return false;
        }

        $side = $sourceType === 'Sales Invoice' ? 'credit' : 'debit';
        $otherSide = $side === 'debit' ? 'credit' : 'debit';
        $baseTotal = round((float) collect($components)->reject(fn ($component) => $component['is_tax'])->sum($side), 4);
        $taxTotal = round((float) collect($components)->filter(fn ($component) => $component['is_tax'])->sum($side), 4);
        $debitTotal = round((float) collect($components)->sum('debit'), 4);
        $creditTotal = round((float) collect($components)->sum('credit'), 4);
        $journalSideTotal = round((float) collect($components)->sum($side), 4);
        $entryTotal = (float) ($entry['debit_total'] ?? 0);

        if ($taxTotal <= 0
            || abs($baseTotal - $baseAmount) > 0.01
            || abs($journalSideTotal - $entryTotal) > 0.01
            || abs($debitTotal - $creditTotal) > 0.01
            || abs($journalSideTotal - (float) collect($components)->sum($otherSide)) > 0.01
            || abs($journalSideTotal - ($sourceAmount + $taxTotal)) > 0.01) {
            return false;
        }

        try {
            $tax = $this->taxResolver->resolveForEntry($entry, $businessId, $baseAmount);
        } catch (\Throwable $exception) {
            return false;
        }

        $expectedDirection = $sourceType === 'Sales Invoice' ? 'output' : 'input';
        return $tax !== null
            && ($tax['direction'] ?? null) === $expectedDirection
            && abs((float) ($tax['tax_amount'] ?? 0) - $taxTotal) <= 0.01;
    }
}
