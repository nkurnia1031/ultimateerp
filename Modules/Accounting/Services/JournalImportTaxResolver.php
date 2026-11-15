<?php

namespace Modules\Accounting\Services;

use App\TaxRate;

class JournalImportTaxResolver
{
    private const RATE_TOLERANCE = 0.01;

    private array $taxRatesByBusiness = [];

    public function resolveForEntry(array $entry, int $businessId, ?float $taxableBase = null): ?array
    {
        $components = (new JournalImportAccountComponentService())->groupJournalLines($entry, $businessId);
        $taxComponents = array_values(array_filter($components, fn ($component) => $component['is_tax']));
        if ($taxComponents === []) {
            return null;
        }

        foreach ($taxComponents as $component) {
            if ($component['tax_direction_conflict'] || $component['tax_direction'] === null) {
                throw new \RuntimeException('Arah akun pajak Journal tidak dapat ditentukan dari nama akun dan sisi debit/kredit.');
            }
        }

        $vatDirections = array_values(array_unique(array_filter(array_column($taxComponents, 'tax_direction'), fn ($direction) => in_array($direction, ['input', 'output'], true))));
        if (count($vatDirections) > 1) {
            throw new \RuntimeException('Satu transaksi memiliki VAT In dan VAT Out sekaligus; tarif pajaknya perlu ditinjau.');
        }

        $taxableBase = $this->resolveTaxableBase($taxableBase, $components, $taxComponents, $vatDirections);
        if ($taxableBase === null || $taxableBase <= 0) {
            throw new \RuntimeException('Basis kena pajak tidak tersedia dari detail transaksi atau komponen Journal.');
        }

        if (! array_key_exists($businessId, $this->taxRatesByBusiness)) {
            $this->taxRatesByBusiness[$businessId] = TaxRate::where('business_id', $businessId)
                ->whereNull('deleted_at')
                ->with('sub_taxes')
                ->get();
        }
        $taxRates = $this->taxRatesByBusiness[$businessId];
        $simpleRates = $taxRates->filter(fn ($taxRate) => ! $taxRate->is_tax_group);
        $componentRateIds = [];
        $taxAmount = 0.0;

        foreach ($taxComponents as $component) {
            if ($component['debit'] > 0 && $component['credit'] > 0) {
                throw new \RuntimeException('Akun pajak Journal memiliki nominal debit dan kredit sekaligus; komponennya perlu ditinjau.');
            }

            $amount = round((float) $component['debit'] + (float) $component['credit'], 4);
            if ($amount <= 0) {
                continue;
            }
            $signedAmount = $component['tax_direction'] === 'withholding' ? -$amount : $amount;
            $componentRate = round($signedAmount / $taxableBase * 100, 4);
            $componentMatches = $simpleRates->filter(function ($rate) use ($componentRate, $taxableBase, $signedAmount) {
                return abs(round((float) $rate->amount - $componentRate, 4)) <= self::RATE_TOLERANCE
                    && abs(round($taxableBase * (float) $rate->amount / 100 - $signedAmount, 2)) <= 0.01;
            })->values();

            if ($componentMatches->count() !== 1) {
                $label = $component['account_name'] !== '' ? $component['account_name'] : $component['account_no'];
                $percentage = number_format($componentRate, 4, '.', '');
                throw new \RuntimeException($componentMatches->isEmpty()
                    ? 'Tarif hasil hitung akun pajak Journal "'.$label.'" sebesar '.$percentage.'% tidak cocok dengan TaxRate aktif pada business ini.'
                    : 'Tarif hasil hitung akun pajak Journal "'.$label.'" cocok dengan lebih dari satu TaxRate; transaksi perlu ditinjau.');
            }

            $componentRateIds[] = (int) $componentMatches[0]->id;
            $taxAmount += $signedAmount;
        }
        $taxAmount = round($taxAmount, 4);
        if ($taxAmount === 0.0 || $componentRateIds === []) {
            throw new \RuntimeException('Nominal akun pajak Journal tidak menghasilkan tarif yang dapat digunakan.');
        }

        $componentRateIds = array_values(array_unique($componentRateIds));
        if (count($componentRateIds) === 1) {
            $taxRate = $simpleRates->firstWhere('id', $componentRateIds[0]);
            $rate = $taxRate ? (float) $taxRate->amount : null;
            if ($rate === null || abs(round($taxableBase * $rate / 100 - $taxAmount, 2)) > 0.01) {
                throw new \RuntimeException('Total komponen pajak Journal tidak sesuai dengan TaxRate hasil hitung.');
            }
        } else {
            $sortedComponentIds = $componentRateIds;
            sort($sortedComponentIds);
            $matchingGroups = $taxRates->filter(function ($taxRate) use ($businessId, $sortedComponentIds) {
                if (! $taxRate->is_tax_group) {
                    return false;
                }
                $memberIds = $taxRate->sub_taxes
                    ->where('business_id', $businessId)
                    ->pluck('id')
                    ->map(fn ($id) => (int) $id)
                    ->sort()
                    ->values()
                    ->all();

                return $memberIds === $sortedComponentIds;
            })->values();

            if ($matchingGroups->count() !== 1) {
                throw new \RuntimeException('Komponen pajak Journal tidak cocok dengan satu grup pajak aktif yang unik.');
            }

            $taxRate = $matchingGroups[0];
            $rate = (float) $taxRate->sub_taxes->where('business_id', $businessId)->sum('amount');
            if (abs(round($taxableBase * $rate / 100 - $taxAmount, 2)) > 0.01) {
                throw new \RuntimeException('Total komponen pajak Journal berbeda dari grup pajak hasil hitung.');
            }
        }

        return [
            'id' => (int) $taxRate->id,
            'rate' => round($rate, 4),
            'taxable_base' => round($taxableBase, 4),
            'tax_amount' => $taxAmount,
            'effective_rate' => round($taxAmount / $taxableBase * 100, 4),
            'direction' => $vatDirections[0] ?? 'withholding',
        ];
    }

    private function resolveTaxableBase(?float $taxableBase, array $components, array $taxComponents, array $vatDirections): ?float
    {
        if ($taxableBase !== null && is_finite($taxableBase) && $taxableBase > 0) {
            return round($taxableBase, 4);
        }

        $baseSide = $vatDirections[0] ?? null;
        if ($baseSide === 'input') {
            $side = 'debit';
        } elseif ($baseSide === 'output') {
            $side = 'credit';
        } else {
            $taxSides = [];
            foreach ($taxComponents as $component) {
                if ($component['debit'] > 0) {
                    $taxSides['debit'] = true;
                }
                if ($component['credit'] > 0) {
                    $taxSides['credit'] = true;
                }
            }
            if (count($taxSides) !== 1) {
                return null;
            }
            $side = array_key_first($taxSides);
        }

        $base = 0.0;
        foreach ($components as $component) {
            if ($component['is_tax']) {
                continue;
            }
            $base += (float) $component[$side];
        }

        return $base > 0 ? round($base, 4) : null;
    }
}
