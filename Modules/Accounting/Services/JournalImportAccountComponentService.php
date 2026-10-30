<?php

namespace Modules\Accounting\Services;

class JournalImportAccountComponentService
{
    /**
     * Group Journal lines by workbook GL account and infer tax treatment from
     * the account label plus its debit/credit side.
     */
    public function groupJournalLines(array $entry, int $businessId, array $options = []): array
    {
        $lines = [];
        foreach (($entry['lines'] ?? []) as $line) {
            if (! is_array($line)) {
                continue;
            }

            $side = strtolower(trim((string) ($line['type'] ?? '')));
            if (in_array($side, ['debit', 'credit'], true)) {
                $amount = (float) ($line[$side] ?? $line['amount'] ?? 0);
                $debit = $side === 'debit' ? $amount : 0.0;
                $credit = $side === 'credit' ? $amount : 0.0;
            } else {
                $debit = (float) ($line['debit'] ?? 0);
                $credit = (float) ($line['credit'] ?? 0);
            }
            if ((! is_finite($debit) || $debit <= 0) && (! is_finite($credit) || $credit <= 0)) {
                continue;
            }

            $key = $this->accountKey($line);
            if (! isset($lines[$key])) {
                $lines[$key] = [
                    'account_key' => $key,
                    'account_no' => trim((string) ($line['account_no'] ?? '')),
                    'account_name' => trim((string) ($line['account_name'] ?? '')),
                    'debit' => 0.0,
                    'credit' => 0.0,
                    'is_tax' => false,
                    'tax_direction' => null,
                    'tax_direction_conflict' => false,
                ];
            }
            if (is_finite($debit) && $debit > 0) {
                $lines[$key]['debit'] += $debit;
            }
            if (is_finite($credit) && $credit > 0) {
                $lines[$key]['credit'] += $credit;
            }
        }

        foreach ($lines as &$component) {
            $component['debit'] = round($component['debit'], 4);
            $component['credit'] = round($component['credit'], 4);
            $component['is_tax'] = $this->looksLikeTaxAccount($component);
            if (! $component['is_tax']) {
                continue;
            }

            $namedDirection = $this->namedTaxDirection($component);
            $sideDirection = $this->sideTaxDirection($component);
            $component['tax_direction'] = $namedDirection ?? $sideDirection;
            $component['tax_direction_conflict'] = $namedDirection !== null
                && $namedDirection !== 'withholding'
                && $sideDirection !== null
                && $namedDirection !== $sideDirection;
            if ($sideDirection === null) {
                $component['tax_direction_conflict'] = true;
            }
        }
        unset($component);

        return array_values($lines);
    }

    public function totalsForSource(array $components, string $sourceType): array
    {
        $side = $sourceType === 'Sales Invoice' ? 'credit' : 'debit';
        $base = 0.0;
        $tax = 0.0;
        $taxDirections = [];
        $taxDirectionConflict = false;

        foreach ($components as $component) {
            if (! is_array($component)) {
                continue;
            }

            $amount = (float) ($component[$side] ?? 0);
            if (! is_finite($amount)) {
                continue;
            }

            if (! empty($component['is_tax'])) {
                $tax += $amount;
                if ((float) ($component['debit'] ?? 0) > 0 || (float) ($component['credit'] ?? 0) > 0) {
                    $direction = $component['tax_direction'] ?? null;
                    if (! empty($component['tax_direction_conflict']) || ! in_array($direction, ['input', 'output'], true)) {
                        $taxDirectionConflict = true;
                    } else {
                        $taxDirections[$direction] = true;
                    }
                }
            } else {
                $base += $amount;
            }
        }

        if (count($taxDirections) > 1) {
            $taxDirectionConflict = true;
        }

        return [
            'base' => round($base, 4),
            'tax' => round($tax, 4),
            'tax_direction' => count($taxDirections) === 1 ? array_key_first($taxDirections) : null,
            'tax_direction_conflict' => $taxDirectionConflict,
        ];
    }

    public function hasSourceAmounts(array $components, string $sourceType): bool
    {
        if ($components === []) {
            return false;
        }

        $totals = $this->totalsForSource($components, $sourceType);

        return abs($totals['base']) > 0.01 || abs($totals['tax']) > 0.01;
    }

    public function accountKey(array $line): string
    {
        return md5(strtolower(trim((string) ($line['account_no'] ?? '')).'|'.trim((string) ($line['account_name'] ?? ''))));
    }

    private function looksLikeTaxAccount(array $line): bool
    {
        $label = mb_strtolower(trim((string) ($line['account_no'] ?? '').' '.(string) ($line['account_name'] ?? '')));
        if (preg_match('/(?:^|[^a-z0-9])(?:support|service|services|supplies)(?:[^a-z0-9]|$)/u', $label) === 1) {
            return false;
        }

        return preg_match('/(?:^|[^a-z0-9])(ppn|pph|pajak|vat|gst|tax)(?:[^a-z0-9]|$)/u', $label) === 1;
    }

    private function namedTaxDirection(array $component): ?string
    {
        $label = mb_strtolower(trim((string) ($component['account_no'] ?? '').' '.(string) ($component['account_name'] ?? '')));
        if (preg_match('/(?:^|[^a-z0-9])(?:pph|withholding|pemotongan)(?:[^a-z0-9]|$)/u', $label) === 1) {
            return 'withholding';
        }
        if (preg_match('/(?:^|[^a-z0-9])(?:vat\s*(?:in|input)|ppn\s*(?:masukan|input)|pajak\s+masukan|input\s+tax|tax\s+input)(?:[^a-z0-9]|$)/u', $label) === 1) {
            return 'input';
        }
        if (preg_match('/(?:^|[^a-z0-9])(?:vat\s*(?:out|output)|ppn\s*(?:keluaran|output)|pajak\s+keluaran|output\s+tax|tax\s+output)(?:[^a-z0-9]|$)/u', $label) === 1) {
            return 'output';
        }

        return null;
    }

    private function sideTaxDirection(array $component): ?string
    {
        if ($component['debit'] > 0 && $component['credit'] === 0.0) {
            return 'input';
        }
        if ($component['credit'] > 0 && $component['debit'] === 0.0) {
            return 'output';
        }

        return null;
    }
}
