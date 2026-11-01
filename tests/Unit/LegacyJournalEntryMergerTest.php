<?php

namespace Tests\Unit;

use Modules\Accounting\Services\LegacyJournalEntryMerger;
use Modules\Accounting\Services\JournalImportDraftService;
use PHPUnit\Framework\TestCase;

class LegacyJournalEntryMergerTest extends TestCase
{
    public function test_identical_entries_from_overlapping_workbooks_are_kept_once_with_both_sources(): void
    {
        $entry = $this->entry();
        $result = (new LegacyJournalEntryMerger())->merge([
            ['path' => 'journal-2022.xlsx', 'sha256' => 'a', 'entries' => [$entry]],
            ['path' => 'journal-2021-2025.xlsx', 'sha256' => 'b', 'entries' => [$entry]],
        ]);

        $this->assertCount(1, $result['entries']);
        $this->assertSame(1, $result['duplicate_count']);
        $this->assertSame(0, $result['conflict_count']);
        $this->assertSame(
            ['journal-2022.xlsx', 'journal-2021-2025.xlsx'],
            array_column($result['entries'][0]['legacy_sources'], 'path')
        );
    }

    public function test_conflicting_entries_keep_both_versions_and_mark_them_for_review(): void
    {
        $first = $this->entry();
        $second = $this->entry();
        $second['lines'][0]['debit'] = 120;
        $second['debit_total'] = 120;

        $result = (new LegacyJournalEntryMerger())->merge([
            ['path' => 'journal-2022.xlsx', 'sha256' => 'a', 'entries' => [$first]],
            ['path' => 'journal-2021-2025.xlsx', 'sha256' => 'b', 'entries' => [$second]],
        ]);

        $this->assertCount(2, $result['entries']);
        $this->assertSame(1, $result['conflict_count']);
        $this->assertSame(0, $result['duplicate_count']);
        $this->assertTrue($result['entries'][0]['source_identity_conflict']);
        $this->assertTrue($result['entries'][1]['source_identity_conflict']);
        $this->assertSame('review', (new JournalImportDraftService())->defaultActionFor($result['entries'][0]));
        $this->assertSame('review', (new JournalImportDraftService())->defaultActionFor($result['entries'][1]));
    }

    public function test_year_filter_keeps_only_entries_whose_transaction_date_is_in_that_year(): void
    {
        $currentYear = $this->entry();
        $nextYear = $this->entry();
        $nextYear['source_number'] = '9002';
        $nextYear['source_key'] = 'Expense|9002|2023-01-01';
        $nextYear['date'] = '2023-01-01';

        $result = (new LegacyJournalEntryMerger())->merge([
            ['path' => 'journal.xlsx', 'sha256' => 'a', 'entries' => [$currentYear, $nextYear]],
        ], 2022);

        $this->assertCount(1, $result['entries']);
        $this->assertSame(1, $result['excluded_by_year']);
        $this->assertSame('2022-12-31', $result['entries'][0]['date']);
    }

    private function entry(): array
    {
        return [
            'header_row' => 6,
            'source_type' => 'Expense',
            'source_number' => '9001',
            'date' => '2022-12-31',
            'source_key' => 'Expense|9001|2022-12-31',
            'debit_total' => 100,
            'credit_total' => 100,
            'lines' => [[
                'account_no' => '5-1001',
                'account_name' => 'Repairs',
                'description' => 'Repair expense',
                'debit' => 100,
                'credit' => 0,
            ]],
        ];
    }
}
