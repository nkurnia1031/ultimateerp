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

        if (count($matches) > 1) {
            $dateMatches = array_values(array_filter(
                $matches,
                fn ($candidate) => ($candidate['date'] ?? null) === $sourceDate
            ));

            if (count($dateMatches) === 1) {
                return $dateMatches;
            }

            $amountCandidates = $dateMatches !== [] ? $dateMatches : $matches;
            $amountMatches = array_values(array_filter(
                $amountCandidates,
                fn ($candidate) => abs((float) ($candidate['amount'] ?? 0) - $sourceAmount) <= 0.01
            ));

            if (count($amountMatches) === 1) {
                return $amountMatches;
            }
        }

        return $matches;
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
