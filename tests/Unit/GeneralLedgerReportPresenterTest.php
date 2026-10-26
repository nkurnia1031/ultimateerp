<?php

namespace Tests\Unit;

use Modules\Accounting\Services\GeneralLedgerReportPresenter;
use PHPUnit\Framework\TestCase;

class GeneralLedgerReportPresenterTest extends TestCase
{
    public function test_it_allocates_non_tax_postings_by_product_subtotal_and_advances_the_running_balance(): void
    {
        $rows = (new GeneralLedgerReportPresenter())->presentAccountEntries('asset', [
            [
                'id' => 2,
                'operation_date' => '2022-01-02 09:00:00',
                'type' => 'credit',
                'amount' => 3,
                'products' => [],
            ],
            [
                'id' => 1,
                'operation_date' => '2022-01-01 09:00:00',
                'type' => 'debit',
                'amount' => 12,
                'is_tax_account' => false,
                'products' => [
                    ['name' => 'Solar', 'quantity' => 2, 'net_amount' => 8, 'tax_amount' => 2],
                    ['name' => 'Sparepart', 'quantity' => 1, 'net_amount' => 4, 'tax_amount' => 1],
                ],
            ],
        ], 100, 2);

        $this->assertSame(['Solar', 'Sparepart', ''], array_column($rows, 'product'));
        $this->assertSame([8.0, 4.0, 0.0], array_column($rows, 'debit'));
        $this->assertSame([0.0, 0.0, 3.0], array_column($rows, 'credit'));
        $this->assertSame([108.0, 112.0, 109.0], array_column($rows, 'balance'));
    }

    public function test_it_allocates_tax_by_each_products_tax_and_assigns_rounding_remainder_to_the_last_product(): void
    {
        $rows = (new GeneralLedgerReportPresenter())->presentAccountEntries('asset', [[
            'id' => 1,
            'operation_date' => '2022-01-01 09:00:00',
            'type' => 'debit',
            'amount' => 10,
            'is_tax_account' => true,
            'products' => [
                ['name' => 'Solar', 'quantity' => 1, 'net_amount' => 30, 'tax_amount' => 1],
                ['name' => 'Sparepart', 'quantity' => 1, 'net_amount' => 30, 'tax_amount' => 1],
                ['name' => 'Service', 'quantity' => 1, 'net_amount' => 30, 'tax_amount' => 1],
            ],
        ]], 0, 2);

        $this->assertSame([3.33, 3.33, 3.34], array_column($rows, 'debit'));
        $this->assertSame(10.0, array_sum(array_column($rows, 'debit')));
        $this->assertSame([3.33, 6.66, 10.0], array_column($rows, 'balance'));
    }

    public function test_it_uses_credit_as_the_normal_side_for_liability_balances(): void
    {
        $rows = (new GeneralLedgerReportPresenter())->presentAccountEntries('liability', [
            ['id' => 2, 'operation_date' => '2022-01-02', 'type' => 'debit', 'amount' => 10, 'products' => []],
            ['id' => 1, 'operation_date' => '2022-01-01', 'type' => 'credit', 'amount' => 30, 'products' => []],
        ], 100, 2);

        $this->assertSame([130.0, 120.0], array_column($rows, 'balance'));
    }

    public function test_it_keeps_fractional_cents_between_postings_before_display_rounding(): void
    {
        $entries = [];
        for ($id = 1; $id <= 100; $id++) {
            $entries[] = [
                'id' => $id,
                'operation_date' => '2022-01-01 09:00:00',
                'type' => 'debit',
                'amount' => 0.004,
                'products' => [],
            ];
        }

        $rows = (new GeneralLedgerReportPresenter())->presentAccountEntries('asset', $entries, 0, 2);

        $this->assertSame(0.4, $rows[99]['balance']);
    }

    public function test_it_keeps_a_posting_on_one_row_when_product_amounts_cannot_be_matched(): void
    {
        $rows = (new GeneralLedgerReportPresenter())->presentAccountEntries('expense', [[
            'id' => 1,
            'operation_date' => '2022-01-01',
            'type' => 'debit',
            'amount' => 50,
            'products' => [
                ['name' => 'Solar', 'quantity' => 2, 'net_amount' => 0, 'tax_amount' => 0],
                ['name' => 'Sparepart', 'quantity' => 1, 'net_amount' => 0, 'tax_amount' => 0],
            ],
        ]], 0, 2);

        $this->assertCount(1, $rows);
        $this->assertSame('Solar; Sparepart', $rows[0]['product']);
        $this->assertSame(50.0, $rows[0]['debit']);
        $this->assertSame(50.0, $rows[0]['balance']);
    }
}
