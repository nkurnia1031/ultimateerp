<?php

namespace Tests\Unit;

use App\Services\SolarWorkbookService;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PHPUnit\Framework\TestCase;

class SolarWorkbookServiceTest extends TestCase
{
    public function test_export_uses_reference_layout_and_can_be_parsed_again(): void
    {
        $service = new SolarWorkbookService();
        $rows = [
            'stock' => [
                (object) [
                    'tanggal' => '2026-06-04',
                    'stock_in' => 100,
                    'out_actual' => 25,
                    'out_meteran' => 24,
                    'keterangan' => 'Test stock',
                ],
            ],
            'pool' => [
                (object) [
                    'tanggal' => '2026-06-04',
                    'qty_in' => 10,
                    'qty_out' => 2,
                    'keterangan' => 'Test pool',
                    'unit' => 'gln',
                    'operator' => 'Operator A',
                    'customer_id' => 7,
                    'customer_name' => 'Customer A',
                ],
            ],
            'mess' => [
                (object) [
                    'tanggal' => '2026-06-04',
                    'qty_in' => 8,
                    'qty_out' => 1,
                    'keterangan' => 'Test mess',
                    'nama_kapal' => 'Kapal A',
                    'unit' => 'gln',
                    'operator' => 'Operator B',
                    'customer_id' => null,
                    'customer_name' => null,
                    'lokasi' => 'Dermaga',
                ],
            ],
        ];
        $opening = [
            'stock' => ['actual' => 1000, 'meter' => 1005],
            'pool' => ['quantity' => 20],
            'mess' => ['quantity' => 15],
        ];

        $workbook = $service->buildExport($rows, $opening, ['C7 | Customer A']);
        $this->assertSame('TANGGAL', $workbook->getSheetByName('SOLAR')->getCell('A4')->getValue());
        $this->assertSame('NAMA KAPAL', $workbook->getSheetByName('MESS')->getCell('F4')->getValue());
        $this->assertNotSame('', $workbook->getSheetByName('SOLAR')->getComment('A4')->getText()->getPlainText());

        $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'solar-workbook-'.uniqid('', true).'.xlsx';
        (new Xlsx($workbook))->save($path);
        $workbook->disconnectWorksheets();

        try {
            $parsed = $service->parse($path);
        } finally {
            @unlink($path);
        }

        $this->assertSame([], $parsed['errors']);
        $this->assertSame('2026-06-04', $parsed['opening']['stock']['date']);
        $this->assertSame(1000.0, $parsed['opening']['stock']['actual']);
        $this->assertCount(1, $parsed['rows']['stock']);
        $this->assertSame(100.0, $parsed['rows']['stock'][0]['stock_in']);
        $this->assertSame('Kapal A', $parsed['rows']['mess'][0]['nama_kapal']);
        $this->assertSame('C7 | Customer A', $parsed['rows']['pool'][0]['customer_ref']);
    }

    public function test_invalid_dates_are_reported_and_never_replaced_with_today(): void
    {
        $service = new SolarWorkbookService();
        $workbook = $service->buildTemplate();
        $sheet = $workbook->getSheetByName('SOLAR');
        $sheet->setCellValue('A5', '20/12/202');
        $sheet->setCellValue('B5', 10);

        $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'solar-invalid-'.uniqid('', true).'.xlsx';
        (new Xlsx($workbook))->save($path);
        $workbook->disconnectWorksheets();

        try {
            $parsed = $service->parse($path);
            $corrected = $service->parse($path, ['stock:5' => '2024-12-20']);
        } finally {
            @unlink($path);
        }

        $this->assertCount(1, $parsed['errors']);
        $this->assertSame('stock', $parsed['errors'][0]['ledger']);
        $this->assertSame(5, $parsed['errors'][0]['row']);
        $this->assertSame('20/12/202', $parsed['errors'][0]['raw_date']);
        $this->assertStringContainsString('tidak valid', $parsed['errors'][0]['message']);
        $this->assertSame([], $parsed['rows']['stock']);

        $this->assertSame([], $corrected['errors']);
        $this->assertSame('2024-12-20', $corrected['rows']['stock'][0]['tanggal']);
    }
}
