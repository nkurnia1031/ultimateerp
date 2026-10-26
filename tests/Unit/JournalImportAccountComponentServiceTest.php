<?php

namespace Tests\Unit;

use Modules\Accounting\Services\JournalImportAccountComponentService;
use PHPUnit\Framework\TestCase;

class JournalImportAccountComponentServiceTest extends TestCase
{
    public function test_it_groups_journal_sides_and_separates_tax_accounts_from_expense_accounts(): void
    {
        $service = new JournalImportAccountComponentService();
        $entry = ['lines' => [
            ['account_no' => '6101', 'account_name' => 'Office Supplies', 'type' => 'debit', 'amount' => 70],
            ['account_no' => '2101', 'account_name' => 'VAT IN', 'type' => 'debit', 'amount' => 7],
            ['account_no' => '6101', 'account_name' => 'Office Supplies', 'type' => 'debit', 'amount' => 30],
            ['account_no' => '6201', 'account_name' => 'Transportation', 'type' => 'debit', 'amount' => 20],
            ['account_no' => '1101', 'account_name' => 'Cash', 'type' => 'credit', 'amount' => 97],
        ]];

        $components = $service->groupJournalLines($entry, 3);

        $this->assertCount(4, $components);
        $this->assertSame(100.0, $components[0]['debit']);
        $this->assertFalse($components[0]['is_tax']);
        $this->assertSame('VAT IN', $components[1]['account_name']);
        $this->assertSame(7.0, $components[1]['debit']);
        $this->assertTrue($components[1]['is_tax']);
        $this->assertSame(20.0, $components[2]['debit']);
        $this->assertFalse($components[2]['is_tax']);
        $this->assertSame(97.0, $components[3]['credit']);
    }

    public function test_tax_components_are_detected_from_their_names_and_journal_sides(): void
    {
        $service = new JournalImportAccountComponentService();
        $entry = ['lines' => [
            ['account_no' => '2101', 'account_name' => 'VAT In', 'type' => 'debit', 'amount' => 10],
            ['account_no' => '2102', 'account_name' => 'VAT Out', 'type' => 'credit', 'amount' => 10],
            ['account_no' => '2103', 'account_name' => 'Prepaid Income Tax - PPh 23', 'type' => 'credit', 'amount' => 2],
            ['account_no' => '6101', 'account_name' => 'VAT support services', 'type' => 'debit', 'amount' => 25],
        ]];

        $components = $service->groupJournalLines($entry, 3);
        $byName = collect($components)->keyBy('account_name');

        $this->assertTrue($byName['VAT In']['is_tax']);
        $this->assertSame('input', $byName['VAT In']['tax_direction']);
        $this->assertTrue($byName['VAT Out']['is_tax']);
        $this->assertSame('output', $byName['VAT Out']['tax_direction']);
        $this->assertTrue($byName['Prepaid Income Tax - PPh 23']['is_tax']);
        $this->assertSame('withholding', $byName['Prepaid Income Tax - PPh 23']['tax_direction']);
        $this->assertFalse($byName['VAT support services']['is_tax']);
    }

    public function test_it_reads_the_debit_and_credit_shape_saved_by_journal_import(): void
    {
        $service = new JournalImportAccountComponentService();
        $entry = ['lines' => [
            ['account_no' => '5-50000', 'account_name' => 'Cost of Sales', 'debit' => 27272727, 'credit' => 0],
            ['account_no' => '1-10500', 'account_name' => 'VAT In', 'debit' => 2727272.7, 'credit' => 0],
            ['account_no' => '1-10004', 'account_name' => 'MANDIRI - BMT', 'debit' => 0, 'credit' => 29999999.7],
        ]];

        $components = $service->groupJournalLines($entry, 2);

        $this->assertCount(3, $components);
        $this->assertSame(27272727.0, $components[0]['debit']);
        $this->assertFalse($components[0]['is_tax']);
        $this->assertSame('VAT In', $components[1]['account_name']);
        $this->assertSame(2727272.7, $components[1]['debit']);
        $this->assertTrue($components[1]['is_tax']);
        $this->assertSame(29999999.7, $components[2]['credit']);
    }

}
