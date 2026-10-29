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

    public function rankCandidates(iterable $candidates, string $sourceDate, float $sourceAmount): array
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

            return (int) $left['id'] <=> (int) $right['id'];
        });

        return $ranked;
    }

    public function uniqueExactCandidate(array $candidates, string $sourceDate, float $sourceAmount): ?array
    {
        $exact = array_values(array_filter($candidates, function (array $candidate) use ($sourceDate, $sourceAmount): bool {
            return is_numeric($candidate['amount'] ?? null)
                && (string) ($candidate['date'] ?? '') === $sourceDate
                && abs((float) ($candidate['amount'] ?? 0) - $sourceAmount) <= 0.01;
        }));

        return count($exact) === 1 ? $exact[0] : null;
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
