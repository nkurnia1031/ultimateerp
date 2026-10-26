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

    public function test_date_and_amount_disambiguation_preserves_ambiguous_matches(): void
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

        $this->assertSame([21], array_column($dateMatch, 'id'));
        $this->assertSame([23], array_column($amountMatch, 'id'));
        $this->assertSame([21, 22, 23], array_column($ambiguous, 'id'));
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
