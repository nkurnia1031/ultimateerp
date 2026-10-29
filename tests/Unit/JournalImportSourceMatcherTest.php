<?php

namespace Tests\Unit;

use Modules\Accounting\Services\JournalImportSourceMatcher;
use PHPUnit\Framework\TestCase;

class JournalImportSourceMatcherTest extends TestCase
{
    public function test_matches_case_insensitive_references_and_source_id_fallbacks(): void
    {
        $matcher = new JournalImportSourceMatcher();
        $index = $matcher->indexCandidates([
            $this->candidate(11, ['INV-2026-001'], '2026-10-01', 125.50),
            $this->candidate(12, ['PAY-2026-002'], '2026-10-02', 75.00),
        ]);

        $this->assertSame([11], array_column($matcher->match('inv-2026-001', '2026-10-01', 125.50, $index), 'id'));
        $this->assertSame([12], array_column($matcher->match('12', '2026-10-02', 75.00, $index), 'id'));
        $this->assertSame([11], array_column($matcher->match('11/INV-2026-001', '2026-10-01', 125.50, $index), 'id'));
    }

    public function test_candidates_are_ranked_by_date_then_closest_amount_without_hiding_alternatives(): void
    {
        $matcher = new JournalImportSourceMatcher();
        $index = $matcher->indexCandidates([
            $this->candidate(21, ['INV-SHARED'], '2026-10-01', 100.00),
            $this->candidate(22, ['INV-SHARED'], '2026-10-02', 200.00),
            $this->candidate(23, ['INV-SHARED'], '2026-10-02', 250.00),
        ]);

        $dateMatch = $matcher->match('INV-SHARED', '2026-10-01', 100.00, $index);
        $amountMatch = $matcher->match('INV-SHARED', '2026-10-03', 250.00, $index);
        $ambiguous = $matcher->match('INV-SHARED', '2026-10-03', 300.00, $index);

        $this->assertSame([21, 22, 23], array_column($dateMatch, 'id'));
        $this->assertSame([23, 22, 21], array_column($amountMatch, 'id'));
        $this->assertSame([23, 22, 21], array_column($ambiguous, 'id'));
        $this->assertTrue($dateMatch[0]['date_match']);
        $this->assertSame(0.0, $dateMatch[0]['amount_difference']);
        $this->assertSame(21, $matcher->uniqueExactCandidate($dateMatch, '2026-10-01', 100.00)['id']);
    }

    public function test_exact_match_is_auto_selectable_only_when_date_and_amount_match_uniquely(): void
    {
        $matcher = new JournalImportSourceMatcher();
        $candidates = [
            $this->candidate(31, ['INV-EXACT'], '2026-10-01', 100.00),
            $this->candidate(32, ['INV-EXACT'], '2026-10-02', 100.00),
        ];

        $ranked = $matcher->rankCandidates($candidates, '2026-10-01', 100.00);

        $this->assertSame(31, $matcher->uniqueExactCandidate($ranked, '2026-10-01', 100.00)['id']);
        $this->assertNull($matcher->uniqueExactCandidate($ranked, '2026-10-03', 100.00));
    }

    public function test_equal_ranked_candidates_use_source_id_as_a_stable_tiebreaker(): void
    {
        $matcher = new JournalImportSourceMatcher();
        $ranked = $matcher->rankCandidates([
            $this->candidate(42, ['INV-TIE'], '2026-10-01', 120.00),
            $this->candidate(41, ['INV-TIE'], '2026-10-01', 120.00),
        ], '2026-10-01', 100.00);

        $this->assertSame([41, 42], array_column($ranked, 'id'));
        $this->assertNull($matcher->uniqueExactCandidate($ranked, '2026-10-01', 100.00));
    }

    public function test_missing_candidate_amount_is_ranked_last_and_never_counts_as_an_exact_match(): void
    {
        $matcher = new JournalImportSourceMatcher();
        $ranked = $matcher->rankCandidates([
            ['id' => 52, 'date' => '2026-10-01', 'amount' => null, 'references' => ['INV-MISSING']],
            $this->candidate(51, ['INV-MISSING'], '2026-10-01', 110.00),
        ], '2026-10-01', 100.00);

        $this->assertSame([51, 52], array_column($ranked, 'id'));
        $this->assertNull($ranked[1]['amount_difference']);
        $this->assertNull($matcher->uniqueExactCandidate($ranked, '2026-10-01', 100.00));
    }

    public function test_large_candidate_set_is_indexed_for_direct_reference_lookup(): void
    {
        $matcher = new JournalImportSourceMatcher();
        $candidates = [];
        $lastId = 12000;

        for ($id = 1; $id <= $lastId; $id++) {
            $candidates[] = $this->candidate($id, ['DOC-'.$id], '2026-10-01', (float) $id);
        }

        $index = $matcher->indexCandidates($candidates);

        $this->assertCount($lastId, $index['by_id']);
        $this->assertCount($lastId, $index['by_reference']);
        $this->assertSame([1, 6000, 12000], array_map(function ($number) use ($matcher, $index) {
            return $matcher->match('DOC-'.$number, '2026-10-01', (float) $number, $index)[0]['id'];
        }, [1, 6000, 12000]));
    }

    private function candidate(int $id, array $references, string $date, float $amount): array
    {
        return [
            'id' => $id,
            'record_type' => 'Transaction',
            'date' => $date,
            'amount' => $amount,
            'references' => $references,
            'label' => 'Transaction #'.$id,
        ];
    }
}
