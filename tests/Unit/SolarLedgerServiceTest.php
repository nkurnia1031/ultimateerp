<?php

namespace Tests\Unit;

use App\Services\SolarLedgerService;
use PHPUnit\Framework\TestCase;

class SolarLedgerServiceTest extends TestCase
{
    public function test_source_hash_is_stable_for_equivalent_numeric_and_text_values(): void
    {
        $service = new SolarLedgerService();
        $first = [
            'tanggal' => '2026-06-04',
            'stock_in' => 100,
            'out_actual' => 10,
            'out_meteran' => 9,
            'keterangan' => '  Pengisian   Utama ',
        ];
        $second = [
            'tanggal' => '2026-06-04',
            'stock_in' => '100.0000',
            'out_actual' => '10.0',
            'out_meteran' => 9.00,
            'keterangan' => 'pengisian utama',
        ];

        $this->assertSame($service->rowHash('stock', $first), $service->rowHash('stock', $second));
    }

    public function test_source_hash_does_not_depend_on_calculated_balance(): void
    {
        $service = new SolarLedgerService();
        $row = [
            'tanggal' => '2026-06-04',
            'qty_in' => 10,
            'qty_out' => 2,
            'keterangan' => 'Pool',
            'unit' => 'gln',
        ];

        $first = $service->rowHash('pool', $row + ['excel_current_stock' => 8]);
        $second = $service->rowHash('pool', $row + ['excel_current_stock' => 999]);

        $this->assertSame($first, $second);
    }
}
