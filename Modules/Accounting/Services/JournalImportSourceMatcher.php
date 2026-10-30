<?php

namespace Modules\Accounting\Services;

class JournalImportSourceMatcher
{
    public function indexCandidates(iterable $candidates): array
    {
        $byId = [];
        $byReference = [];

        foreach ($candidates as $candidate) {
            $id = (string) ($candidate['id'] ?? '');
            if ($id === '') {
                continue;
            }

            $byId[$id] = $candidate;
            foreach (($candidate['references'] ?? []) as $reference) {
                $reference = $this->normalizeReference((string) $reference);
                if ($reference !== '') {
                    $byReference[$reference][$id] = true;
                }
            }
        }

        return [
            'by_id' => $byId,
            'by_reference' => $byReference,
        ];
    }

    public function match(string $sourceNumber, string $sourceDate, float $sourceAmount, array $index): array
    {
        $sourceNumber = trim($sourceNumber);
        if ($sourceNumber === '') {
            return [];
        }

        $byId = $index['by_id'] ?? $index['byId'] ?? [];
        $byReference = $index['by_reference'] ?? $index['byReference'] ?? [];
        $referenceIds = $byReference[$this->normalizeReference($sourceNumber)] ?? [];
        $matches = [];

        foreach (array_keys($referenceIds) as $id) {
            if (isset($byId[$id])) {
                $matches[] = $byId[$id];
            }
        }

        if ($matches === []) {
            if (preg_match('/^(\d+)\/(.+)$/', $sourceNumber, $idAndReference)) {
                $candidate = $byId[$idAndReference[1]] ?? null;
                if ($candidate !== null && $this->hasReference($candidate, $idAndReference[2])) {
                    $matches[] = $candidate;
                }
            } elseif (isset($byId[$sourceNumber])) {
                $matches[] = $byId[$sourceNumber];
            }
        }

        return $this->rankCandidates($matches, $sourceDate, $sourceAmount);
    }

    public function rankCandidates(iterable $candidates, string $sourceDate, float $sourceAmount, ?array $sourceComponents = null): array
    {
        $ranked = [];
        foreach ($candidates as $candidate) {
            if (! is_array($candidate) || empty($candidate['id'])) {
                continue;
            }

            $candidate['date_match'] = (string) ($candidate['date'] ?? '') === $sourceDate;
            $candidate['amount_difference'] = is_numeric($candidate['amount'] ?? null)
                ? round(abs((float) $candidate['amount'] - $sourceAmount), 4)
                : null;
            $candidate['amount_match'] = $candidate['amount_difference'] !== null && $candidate['amount_difference'] <= 0.01;
            $candidate['base_difference'] = null;
            $candidate['tax_difference'] = null;
            $candidate['component_difference'] = null;
            $candidate['component_match'] = null;
            $candidate['tax_direction_match'] = null;

            if ($sourceComponents !== null) {
                $candidateComponents = $this->candidateComponents($candidate);
                if ($candidateComponents !== null
                    && is_numeric($sourceComponents['base'] ?? null)
                    && is_numeric($sourceComponents['tax'] ?? null)) {
                    $candidate['base_difference'] = round(abs($candidateComponents['base'] - (float) $sourceComponents['base']), 4);
                    $candidate['tax_difference'] = round(abs($candidateComponents['tax'] - (float) $sourceComponents['tax']), 4);
                    $candidate['component_difference'] = round($candidate['base_difference'] + $candidate['tax_difference'], 4);
                    $candidate['tax_direction_match'] = $this->taxDirectionMatch($candidate, $sourceComponents);
                    $candidate['component_match'] = $candidate['base_difference'] <= 0.01
                        && $candidate['tax_difference'] <= 0.01
                        && $candidate['tax_direction_match'] !== false;
                }
            }
            $ranked[] = $candidate;
        }

        usort($ranked, function (array $left, array $right): int {
            if ($left['date_match'] !== $right['date_match']) {
                return $left['date_match'] ? -1 : 1;
            }

            if ($left['amount_difference'] !== $right['amount_difference']) {
                if ($left['amount_difference'] === null) {
                    return 1;
                }
                if ($right['amount_difference'] === null) {
                    return -1;
                }

                return $left['amount_difference'] <=> $right['amount_difference'];
            }

            if ($left['component_difference'] !== $right['component_difference']) {
                if ($left['component_difference'] === null) {
                    return 1;
                }
                if ($right['component_difference'] === null) {
                    return -1;
                }

                return $left['component_difference'] <=> $right['component_difference'];
            }

            if ($left['tax_direction_match'] !== $right['tax_direction_match']) {
                if ($left['tax_direction_match'] === null) {
                    return 1;
                }
                if ($right['tax_direction_match'] === null) {
                    return -1;
                }

                return $left['tax_direction_match'] ? -1 : 1;
            }

            if ($left['component_match'] !== $right['component_match']) {
                if ($left['component_match'] === null) {
                    return 1;
                }
                if ($right['component_match'] === null) {
                    return -1;
                }

                return $left['component_match'] ? -1 : 1;
            }

            return (int) $left['id'] <=> (int) $right['id'];
        });

        return $ranked;
    }

    public function uniqueExactCandidate(array $candidates, string $sourceDate, float $sourceAmount, ?array $sourceComponents = null): ?array
    {
        $exact = array_values(array_filter($candidates, function (array $candidate) use ($sourceDate, $sourceAmount, $sourceComponents): bool {
            $matchesTotal = is_numeric($candidate['amount'] ?? null)
                && (string) ($candidate['date'] ?? '') === $sourceDate
                && abs((float) ($candidate['amount'] ?? 0) - $sourceAmount) <= 0.01;

            if (! $matchesTotal || $sourceComponents === null || ($candidate['record_type'] ?? null) !== \App\Transaction::class) {
                return $matchesTotal;
            }

            $components = $this->candidateComponents($candidate);
            if ($components === null
                || ! is_numeric($sourceComponents['base'] ?? null)
                || ! is_numeric($sourceComponents['tax'] ?? null)
                || abs($components['base'] - (float) $sourceComponents['base']) > 0.01
                || abs($components['tax'] - (float) $sourceComponents['tax']) > 0.01) {
                return false;
            }

            return $this->taxDirectionMatch($candidate, $sourceComponents) !== false;
        }));

        return count($exact) === 1 ? $exact[0] : null;
    }

    private function taxDirectionMatch(array $candidate, array $sourceComponents): ?bool
    {
        if (! empty($sourceComponents['tax_direction_conflict'])) {
            return false;
        }

        if ((float) ($sourceComponents['tax'] ?? 0) <= 0.01) {
            return null;
        }

        // Older callers can compare numeric components without direction
        // metadata. Production Journal matching always provides these fields.
        if (! array_key_exists('tax_direction', $sourceComponents)
            && ! array_key_exists('tax_direction_conflict', $sourceComponents)) {
            return null;
        }

        $expectedDirection = match ((string) ($candidate['transaction_type'] ?? '')) {
            'sell' => 'output',
            'expense', 'purchase' => 'input',
            default => null,
        };

        return $expectedDirection !== null
            && in_array($sourceComponents['tax_direction'] ?? null, ['input', 'output'], true)
            && $sourceComponents['tax_direction'] === $expectedDirection;
    }

    private function candidateComponents(array $candidate): ?array
    {
        if (! is_numeric($candidate['base_amount'] ?? null)) {
            return null;
        }

        $base = (float) $candidate['base_amount'];
        if (is_numeric($candidate['tax_amount'] ?? null)) {
            return ['base' => $base, 'tax' => (float) $candidate['tax_amount']];
        }

        if (is_numeric($candidate['amount'] ?? null)
            && abs((float) $candidate['amount'] - $base) <= 0.01) {
            return ['base' => $base, 'tax' => 0.0];
        }

        return null;
    }

    private function hasReference(array $candidate, string $reference): bool
    {
        $normalizedReference = $this->normalizeReference($reference);
        foreach (($candidate['references'] ?? []) as $candidateReference) {
            if ($this->normalizeReference((string) $candidateReference) === $normalizedReference) {
                return true;
            }
        }

        return false;
    }

    private function normalizeReference(string $reference): string
    {
        return strtolower(trim($reference));
    }
}
