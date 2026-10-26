<?php

namespace Tests\Unit;

use App\Utils\TransactionUtil;
use Mockery;
use Modules\Accounting\Services\JournalImportExpenseTotalsService;
use PHPUnit\Framework\TestCase;

class JournalImportExpenseTotalsServiceTest extends TestCase
{
    public function test_a_clear_journal_tax_component_fills_a_zero_tax_source_without_merging_into_expense_base(): void
    {
        $transactionUtil = Mockery::mock(TransactionUtil::class);
        $transactionUtil->shouldNotReceive('calc_percentage_base');
        $service = new JournalImportExpenseTotalsService($transactionUtil);

        $totals = $service->forSource(
            [
                'total_before_tax' => 27272727,
                'tax_amount' => 0,
                'final_total' => 27272727,
            ],
            29999999.7,
            null,
            [['debit' => 27272727]],
            [['debit' => 2727272.7]]
        );

        $this->assertSame(27272727.0, $totals['total_before_tax']);
        $this->assertSame(2727272.7, $totals['tax']);
        $this->assertSame(29999999.7, $totals['final_total']);
    }

    public function test_a_difference_is_explained_only_when_the_tax_component_accounts_for_the_entire_gap(): void
    {
        $service = new JournalImportExpenseTotalsService(Mockery::mock(TransactionUtil::class));
        $source = [
            'total_before_tax' => 27272727,
            'tax_amount' => 0,
            'final_total' => 27272727,
        ];
        $expenseComponents = [['debit' => 27272727]];
        $taxComponents = [['debit' => 2727272.7]];

        $this->assertTrue($service->explainsTaxDifference($source, 29999999.7, $expenseComponents, $taxComponents));
        $this->assertFalse($service->explainsTaxDifference($source, 30000001, $expenseComponents, $taxComponents));
        $this->assertFalse($service->explainsTaxDifference($source, 29999999.7, [['debit' => 27272726]], $taxComponents));
        $this->assertFalse($service->explainsTaxDifference($source, 29999999.7, $expenseComponents, []));
    }
}
