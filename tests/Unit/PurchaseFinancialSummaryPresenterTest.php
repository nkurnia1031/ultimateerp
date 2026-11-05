<?php

namespace Tests\Unit;

use App\Presenters\PurchaseFinancialSummaryPresenter;
use PHPUnit\Framework\TestCase;

class PurchaseFinancialSummaryPresenterTest extends TestCase
{
    public function test_it_sums_purchase_line_tax_by_quantity_and_keeps_it_separate_from_the_base(): void
    {
        $purchaseLine = (object) [
            'quantity' => 8,
            'purchase_price' => 180180,
            'item_tax' => 19819.80,
            'tax_id' => 11,
        ];

        $summary = (new PurchaseFinancialSummaryPresenter())->summarize(
            [$purchaseLine],
            [],
            [11 => 'PPN 11']
        );

        $this->assertSame(1441440.0, $summary['total_before_tax']);
        $this->assertSame(158558.4, $summary['item_tax_total']);
        $this->assertSame(158558.4, $summary['total_tax']);
        $this->assertSame(['PPN 11' => 158558.4], $summary['taxes']);
    }

    public function test_it_adds_transaction_tax_to_the_product_line_tax(): void
    {
        $purchaseLine = (object) [
            'quantity' => 2,
            'purchase_price' => 100,
            'item_tax' => 10,
            'tax_id' => 11,
        ];

        $summary = (new PurchaseFinancialSummaryPresenter())->summarize(
            [$purchaseLine],
            ['PPN 11' => 5],
            [11 => 'PPN 11']
        );

        $this->assertSame(200.0, $summary['total_before_tax']);
        $this->assertSame(20.0, $summary['item_tax_total']);
        $this->assertSame(5.0, $summary['transaction_tax_total']);
        $this->assertSame(25.0, $summary['total_tax']);
        $this->assertSame(['PPN 11' => 25.0], $summary['taxes']);
    }
}
