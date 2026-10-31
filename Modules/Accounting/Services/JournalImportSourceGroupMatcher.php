<?php

namespace Modules\Accounting\Services;

use App\Transaction;

/**
 * Finds exact multi-transaction matches for one Journal Import row.
 *
 * Search is deliberately bounded. If the candidate set is too large or the
 * search budget is exhausted, the result stays manual instead of guessing.
 */
class JournalImportSourceGroupMatcher
{
    private const MAX_CANDIDATES = 36;

    private const MAX_SEARCH_STEPS = 50000;

    private const MAX_RETURNED_GROUPS = 5;

    private const TOLERANCE_UNITS = 100;

    public function __construct(private JournalImportAccountComponentService $componentService)
    {
    }

    public function findGroups(string $sourceType, array $entry, iterable $candidates, int $businessId, array $options = []): array
    {
        $targets = $this->journalTargets($sourceType, $entry, $businessId, $options);
        if ($targets === null) {
            return $this->result('none');
        }

        $eligible = $this->eligibleCandidates($sourceType, $entry, $candidates);
        if (count($eligible) < 2) {
            return $this->result('none', [], count($eligible));
        }
        if (count($eligible) > self::MAX_CANDIDATES) {
            return $this->result('limited', [], count($eligible));
        }

        usort($eligible, fn (array $left, array $right): int => $right['_total_units'] <=> $left['_total_units']);
        $suffix = array_fill(0, count($eligible) + 1, ['total' => 0, 'base' => 0, 'tax' => 0]);
        for ($index = count($eligible) - 1; $index >= 0; $index--) {
            $suffix[$index] = [
                'total' => $suffix[$index + 1]['total'] + $eligible[$index]['_total_units'],
                'base' => $suffix[$index + 1]['base'] + $eligible[$index]['_base_units'],
                'tax' => $suffix[$index + 1]['tax'] + $eligible[$index]['_tax_units'],
            ];
        }

        $groups = [];
        $steps = 0;
        $exhausted = false;
        $search = function (int $start, array $members, int $total, int $base, int $tax) use (
            &$search,
            &$groups,
            &$steps,
            &$exhausted,
            $eligible,
            $suffix,
            $targets
        ): void {
            if ($exhausted || count($groups) >= self::MAX_RETURNED_GROUPS) {
                return;
            }
            $steps++;
            if ($steps > self::MAX_SEARCH_STEPS) {
                $exhausted = true;

                return;
            }

            if (count($members) >= 2 && $this->totalsMatch($total, $base, $tax, $targets)) {
                $groups[] = $this->makeGroup($members, $total, $base, $tax, $targets);

                return;
            }
            if ($start >= count($eligible)) {
                return;
            }

            $remaining = $suffix[$start];
            if ($total + $remaining['total'] < $targets['total'] - self::TOLERANCE_UNITS
                || $base + $remaining['base'] < $targets['base'] - self::TOLERANCE_UNITS
                || $tax + $remaining['tax'] < $targets['tax'] - self::TOLERANCE_UNITS) {
                return;
            }

            for ($index = $start; $index < count($eligible); $index++) {
                $candidate = $eligible[$index];
                $nextTotal = $total + $candidate['_total_units'];
                $nextBase = $base + $candidate['_base_units'];
                $nextTax = $tax + $candidate['_tax_units'];
                if ($nextTotal > $targets['total'] + self::TOLERANCE_UNITS
                    || $nextBase > $targets['base'] + self::TOLERANCE_UNITS
                    || $nextTax > $targets['tax'] + self::TOLERANCE_UNITS) {
                    continue;
                }

                $search($index + 1, array_merge($members, [$candidate]), $nextTotal, $nextBase, $nextTax);
                if ($exhausted || count($groups) >= self::MAX_RETURNED_GROUPS) {
                    return;
                }
            }
        };

        $search(0, [], 0, 0, 0);
        if ($exhausted) {
            return $this->result('limited', [], count($eligible));
        }

        return $this->result(count($groups) === 1 ? 'unique' : (count($groups) > 1 ? 'ambiguous' : 'none'), $groups, count($eligible));
    }

    /**
     * Validate a group explicitly chosen by a user. It must still match the
     * current business candidates, date, reference, total, base and VAT.
     */
    public function validateSelectedGroup(string $sourceType, array $entry, iterable $candidates, array $memberIds, int $businessId, array $options = []): ?array
    {
        $targets = $this->journalTargets($sourceType, $entry, $businessId, $options);
        $providedIds = array_values(array_map('intval', $memberIds));
        $memberIds = array_values(array_unique(array_filter($providedIds, fn (int $id): bool => $id > 0)));
        if ($targets === null || count($memberIds) < 2 || count($memberIds) !== count($providedIds)) {
            return null;
        }

        $eligible = $this->eligibleCandidates($sourceType, $entry, $candidates);
        $byId = [];
        foreach ($eligible as $candidate) {
            $byId[(int) $candidate['id']] = $candidate;
        }
        if (count(array_intersect($memberIds, array_keys($byId))) !== count($memberIds)) {
            return null;
        }

        $members = array_map(fn (int $id): array => $byId[$id], $memberIds);
        $total = array_sum(array_column($members, '_total_units'));
        $base = array_sum(array_column($members, '_base_units'));
        $tax = array_sum(array_column($members, '_tax_units'));
        if (! $this->totalsMatch($total, $base, $tax, $targets)) {
            return null;
        }

        usort($members, fn (array $left, array $right): int => (int) $left['id'] <=> (int) $right['id']);

        return $this->makeGroup($members, $total, $base, $tax, $targets);
    }

    public function groupFingerprint(int $rowId, string $sourceType, array $group): string
    {
        return hash('sha256', json_encode([
            'row_id' => $rowId,
            'source_type' => $sourceType,
            'members' => array_values(array_map('intval', $group['member_ids'] ?? [])),
            'base' => $group['base_amount'] ?? null,
            'tax' => $group['tax_amount'] ?? null,
            'total' => $group['total_amount'] ?? null,
        ], JSON_UNESCAPED_SLASHES));
    }

    private function journalTargets(string $sourceType, array $entry, int $businessId, array $options): ?array
    {
        if (! in_array($sourceType, ['Expense', 'Purchase Invoice'], true)) {
            return null;
        }

        $components = $this->componentService->groupJournalLines($entry, $businessId, $options);
        if (! $this->componentService->hasSourceAmounts($components, $sourceType)) {
            return null;
        }

        $totals = $this->componentService->totalsForSource($components, $sourceType);
        $expectedDirection = 'input';
        if (($totals['tax'] ?? 0) > 0.01
            && (! empty($totals['tax_direction_conflict']) || ($totals['tax_direction'] ?? null) !== $expectedDirection)) {
            return null;
        }

        $sideTotal = (float) collect($components)->sum('debit');
        if ($sideTotal <= 0 || abs($sideTotal - (float) ($entry['debit_total'] ?? 0)) > 0.01) {
            return null;
        }

        return [
            'total' => $this->units($sideTotal),
            'base' => $this->units((float) $totals['base']),
            'tax' => $this->units((float) $totals['tax']),
            'journal_total' => $sideTotal,
            'journal_base' => (float) $totals['base'],
            'journal_tax' => (float) $totals['tax'],
        ];
    }

    private function eligibleCandidates(string $sourceType, array $entry, iterable $candidates): array
    {
        $expectedType = $sourceType === 'Expense' ? 'expense' : 'purchase';
        $reference = $this->normalizeReference((string) ($entry['source_number'] ?? ''));
        $date = substr(trim((string) ($entry['date'] ?? '')), 0, 10);
        if ($reference === '' || $date === '') {
            return [];
        }

        $eligible = [];
        $seen = [];
        foreach ($candidates as $candidate) {
            if (! is_array($candidate)
                || ($candidate['record_type'] ?? null) !== Transaction::class
                || ($candidate['transaction_type'] ?? null) !== $expectedType) {
                continue;
            }
            $id = (int) ($candidate['id'] ?? 0);
            if ($id < 1 || isset($seen[$id]) || (string) ($candidate['date'] ?? '') !== $date) {
                continue;
            }
            $hasReference = false;
            foreach (($candidate['references'] ?? []) as $candidateReference) {
                if ($this->normalizeReference((string) $candidateReference) === $reference) {
                    $hasReference = true;
                    break;
                }
            }
            if (! $hasReference || ! is_numeric($candidate['amount'] ?? null) || ! is_numeric($candidate['base_amount'] ?? null)) {
                continue;
            }

            $total = (float) $candidate['amount'];
            $base = (float) $candidate['base_amount'];
            $tax = is_numeric($candidate['tax_amount'] ?? null) ? (float) $candidate['tax_amount'] : 0.0;
            if ($total <= 0 || $base < 0 || $tax < 0) {
                continue;
            }

            $seen[$id] = true;
            $candidate['_total_units'] = $this->units($total);
            $candidate['_base_units'] = $this->units($base);
            $candidate['_tax_units'] = $this->units($tax);
            $eligible[] = $candidate;
        }

        return $eligible;
    }

    private function totalsMatch(int $total, int $base, int $tax, array $targets): bool
    {
        return abs($total - $targets['total']) <= self::TOLERANCE_UNITS
            && abs($base - $targets['base']) <= self::TOLERANCE_UNITS
            && abs($tax - $targets['tax']) <= self::TOLERANCE_UNITS;
    }

    private function makeGroup(array $members, int $total, int $base, int $tax, array $targets): array
    {
        usort($members, fn (array $left, array $right): int => (int) $left['id'] <=> (int) $right['id']);
        $publicMembers = array_map(function (array $candidate): array {
            unset($candidate['_total_units'], $candidate['_base_units'], $candidate['_tax_units']);

            return [
                'id' => (int) $candidate['id'],
                'record_type' => Transaction::class,
                'label' => (string) ($candidate['label'] ?? ('Transaksi #'.$candidate['id'])),
                'date' => (string) ($candidate['date'] ?? ''),
                'reference' => (string) (($candidate['references'][0] ?? '')),
                'references' => array_values($candidate['references'] ?? []),
                'base_amount' => (float) $candidate['base_amount'],
                'tax_amount' => is_numeric($candidate['tax_amount'] ?? null) ? (float) $candidate['tax_amount'] : 0.0,
                'amount' => (float) $candidate['amount'],
            ];
        }, $members);

        return [
            'member_ids' => array_column($publicMembers, 'id'),
            'members' => $publicMembers,
            'base_amount' => $base / 10000,
            'tax_amount' => $tax / 10000,
            'total_amount' => $total / 10000,
            'journal_base_amount' => $targets['journal_base'],
            'journal_tax_amount' => $targets['journal_tax'],
            'journal_total_amount' => $targets['journal_total'],
        ];
    }

    private function units(float $amount): int
    {
        return (int) round($amount * 10000, 0, PHP_ROUND_HALF_UP);
    }

    private function normalizeReference(string $reference): string
    {
        return mb_strtolower(trim($reference));
    }

    private function result(string $status, array $groups = [], int $candidateCount = 0): array
    {
        return [
            'status' => $status,
            'groups' => $groups,
            'candidate_count' => $candidateCount,
        ];
    }
}
