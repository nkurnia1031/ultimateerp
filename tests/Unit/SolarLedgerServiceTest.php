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

    public function test_identical_rows_receive_distinct_stable_source_keys(): void
    {
        $service = new SolarLedgerService();
        $row = [
            'tanggal' => '2026-06-04',
            'stock_in' => 100,
            'out_actual' => 10,
            'out_meteran' => 9,
            'keterangan' => 'Pengisian',
            'source_sheet' => 'SOLAR',
            'source_row' => 6,
        ];
        $parsed = ['rows' => ['stock' => [$row, $row], 'pool' => [], 'mess' => []], 'errors' => [], 'opening' => []];
        $decorated = $service->decorateSourceKeys($parsed);

        $this->assertNotSame(
            $decorated['rows']['stock'][0]['source_key'],
            $decorated['rows']['stock'][1]['source_key']
        );
        $this->assertSame(
            $decorated['rows']['stock'][0]['source_key'],
            $service->decorateSourceKeys($parsed)['rows']['stock'][0]['source_key']
        );
    }

    public function test_source_key_changes_when_transaction_content_changes(): void
    {
        $service = new SolarLedgerService();
        $base = [
            'tanggal' => '2026-06-04',
            'qty_in' => 10,
            'qty_out' => 2,
            'keterangan' => 'Pool',
            'source_sheet' => 'POOL ',
            'source_row' => 6,
        ];
        $first = $service->decorateSourceKeys(['rows' => ['stock' => [], 'pool' => [$base], 'mess' => []], 'errors' => [], 'opening' => []]);
        $second = $service->decorateSourceKeys(['rows' => ['stock' => [], 'pool' => [array_merge($base, ['qty_out' => 3])], 'mess' => []], 'errors' => [], 'opening' => []]);

        $this->assertNotSame($first['rows']['pool'][0]['source_key'], $second['rows']['pool'][0]['source_key']);
    }
}
