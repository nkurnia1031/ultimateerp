<?php

namespace Tests\Unit;

use App\Services\SolarWorkbookService;
use App\Services\SolarImportPreviewService;
use App\Services\SolarLedgerService;
use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\Writer\Xls;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
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

    public function test_usage_template_round_trips_daily_gallons_and_ignores_formula_totals(): void
    {
        $service = new SolarWorkbookService();
        foreach (['xlsx', 'xls'] as $format) {
            $workbook = $service->buildUsageTemplate('2026-06', ['Excavator A']);
            $workbook->getSheet(0)->setCellValue('C6', 2.5);
            $this->assertSame('=SUM(C6:AG6)', $workbook->getSheet(0)->getCell('AH6')->getValue());
            $this->assertSame('=AH6*30', $workbook->getSheet(0)->getCell('AI6')->getValue());

            $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'solar-usage-'.uniqid('', true).'.'.$format;
            $writer = $format === 'xls' ? new Xls($workbook) : new Xlsx($workbook);
            $writer->save($path);
            $workbook->disconnectWorksheets();

            try {
                $parsed = $service->parse($path);
            } finally {
                @unlink($path);
            }

            $this->assertSame([], $parsed['errors'], $format);
            $this->assertCount(1, $parsed['sheets'], $format);
            $this->assertCount(1, $parsed['rows']['usage'], $format);
            $this->assertSame('2026-06-01', $parsed['rows']['usage'][0]['tanggal'], $format);
            $this->assertSame('Excavator A', $parsed['rows']['usage'][0]['equipment_name'], $format);
            $this->assertSame(2.5, $parsed['rows']['usage'][0]['quantity_gln'], $format);
        }
    }

    public function test_monthly_usage_workbook_still_reads_all_month_sheets_and_ignores_unrecognized_tabs(): void
    {
        $service = new SolarWorkbookService();
        $workbook = $service->buildUsageTemplate('2026-06', ['Excavator A']);
        $first = $workbook->getSheet(0);
        $first->setCellValue('C6', 2.5);
        $second = clone $first;
        $second->setTitle('July 2026');
        $second->setCellValue('B6', 'Loader B');
        $second->setCellValue('C6', 4);
        $workbook->addSheet($second);
        $notes = new Worksheet($workbook, 'Catatan');
        $notes->setCellValue('A1', 'Sheet ini bukan matriks pemakaian.');
        $workbook->addSheet($notes);

        $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'solar-usage-months-'.uniqid('', true).'.xlsx';
        (new Xlsx($workbook))->save($path);
        $workbook->disconnectWorksheets();

        try {
            $parsed = $service->parse($path);
        } finally {
            @unlink($path);
        }

        $this->assertCount(2, $parsed['sheets']);
        $this->assertCount(2, $parsed['rows']['usage']);
        $this->assertSame(['Juni 2026', 'July 2026'], array_column($parsed['sheets'], 'title'));
        $this->assertContains('Catatan', $parsed['ignored_sheets']);
        $this->assertSame(2.5, $parsed['rows']['usage'][0]['quantity_gln']);
        $this->assertSame(4.0, $parsed['rows']['usage'][1]['quantity_gln']);
    }

    public function test_import_preview_extracts_asset_code_and_driver_without_changing_note(): void
    {
        $service = $this->previewService();
        $assets = new Collection([(object) ['id' => 14, 'name' => 'Excavator A', 'asset_code' => 'B 1234 XY']]);
        $drivers = new Collection([(object) ['id' => 28, 'full_name' => 'Budi Santoso']]);
        $row = ['keterangan' => 'Pengisian unit B-1234 XY oleh Budi Santoso', 'unit' => null, 'operator' => null];

        $this->invokePreviewMethod($service, 'extractReferenceNames', [&$row, $assets, $drivers]);

        $this->assertSame('B 1234 XY', $row['unit']);
        $this->assertSame('Budi Santoso', $row['operator']);
        $this->assertSame('Pengisian unit B-1234 XY oleh Budi Santoso', $row['keterangan']);
    }

    public function test_fuzzy_asset_reference_is_suggested_but_does_not_match_automatically(): void
    {
        $service = $this->previewService();
        $assets = new Collection([(object) ['id' => 14, 'name' => 'Excavator Alpha', 'asset_code' => 'EX-014']]);

        $suggestions = $this->invokePreviewMethod($service, 'referenceSuggestions', [$assets, 'Excavator Alpa', '', 'asset']);
        $exactMatches = $this->invokePreviewMethod($service, 'matchAsset', [$assets, 'Excavator Alpa']);

        $this->assertSame(14, $suggestions[0]['id']);
        $this->assertCount(0, $exactMatches);
    }

    public function test_asset_code_matching_requires_whole_tokens_and_duplicate_codes_stay_ambiguous(): void
    {
        $service = $this->previewService();
        $assets = new Collection([
            (object) ['id' => 14, 'name' => 'CAT 12', 'asset_code' => 'EX-014'],
            (object) ['id' => 15, 'name' => 'Loader Beta', 'asset_code' => 'EX-014'],
        ]);
        $row = ['keterangan' => 'LOCATER 120', 'unit' => null, 'operator' => null];

        $this->invokePreviewMethod($service, 'extractReferenceNames', [&$row, $assets, new Collection()]);

        $this->assertNull($row['unit']);
        $this->assertCount(2, $this->invokePreviewMethod($service, 'matchAsset', [$assets, 'EX 014']));
    }

    public function test_transaction_workbook_reads_only_first_normalized_ledger_tabs_and_reports_ignored_tabs(): void
    {
        $service = new SolarWorkbookService();
        $workbook = $service->buildTemplate();
        $workbook->getSheetByName('SOLAR')->setCellValue('A5', '01/11/2023');
        $workbook->getSheetByName('SOLAR')->setCellValue('B5', 10);
        $duplicate = new Worksheet($workbook, 'SO LAR');
        $duplicate->setCellValue('A4', 'TANGGAL');
        $duplicate->setCellValue('A5', '01/11/2023');
        $duplicate->setCellValue('B5', 999);
        $workbook->addSheet($duplicate);
        $unrelated = new Worksheet($workbook, 'Catatan');
        $unrelated->setCellValue('A1', 'Data yang tidak boleh ikut dibaca');
        $workbook->addSheet($unrelated);

        $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'solar-sheet-scope-'.uniqid('', true).'.xlsx';
        (new Xlsx($workbook))->save($path);
        $workbook->disconnectWorksheets();

        try {
            $parsed = $service->parse($path);
        } finally {
            @unlink($path);
        }

        $this->assertCount(1, $parsed['rows']['stock']);
        $this->assertSame(10.0, $parsed['rows']['stock'][0]['stock_in']);
        $this->assertSame(['SOLAR', 'POOL', 'MESS'], array_column($parsed['sheets'], 'title'));
        $this->assertContains('SO LAR', $parsed['ignored_sheets']);
        $this->assertContains('Catatan', $parsed['ignored_sheets']);
        $this->assertContains('ignored_sheets', array_column($parsed['warnings'], 'type'));
    }

    public function test_near_miss_ledger_sheet_warns_and_blocks_while_missing_sheet_without_typo_is_only_a_warning(): void
    {
        $service = new SolarWorkbookService();
        $workbook = $service->buildTemplate();
        $workbook->removeSheetByIndex($workbook->getIndex($workbook->getSheetByName('MESS')));
        $typo = new Worksheet($workbook, 'MES');
        $typo->setCellValue('A4', 'TANGGAL');
        $workbook->addSheet($typo);
        $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'solar-sheet-typo-'.uniqid('', true).'.xlsx';
        (new Xlsx($workbook))->save($path);
        $workbook->disconnectWorksheets();

        try {
            $typoResult = $service->parse($path);
        } finally {
            @unlink($path);
        }
        $warning = collect($typoResult['warnings'])->firstWhere('type', 'near_sheet_name');
        $this->assertNotNull($warning);
        $this->assertTrue($warning['blocking']);
        $this->assertSame('MESS', $warning['expected']);
        $this->assertContains('MES', $warning['found']);

        $workbook = $service->buildTemplate();
        $workbook->removeSheetByIndex($workbook->getIndex($workbook->getSheetByName('MESS')));
        $workbook->getSheetByName('SOLAR')->setCellValue('A5', '01/11/2023');
        $workbook->getSheetByName('SOLAR')->setCellValue('B5', 10);
        $workbook->getSheetByName('POOL')->setCellValue('A1', 'Notes');
        $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'solar-sheet-missing-'.uniqid('', true).'.xlsx';
        (new Xlsx($workbook))->save($path);
        $workbook->disconnectWorksheets();

        try {
            $missingResult = $service->parse($path);
        } finally {
            @unlink($path);
        }
        $missing = collect($missingResult['warnings'])->firstWhere('type', 'missing_sheet');
        $this->assertNotNull($missing);
        $this->assertFalse($missing['blocking']);
        $this->assertCount(1, $missingResult['rows']['stock']);
    }

    public function test_parser_emits_sheet_and_row_checkpoints(): void
    {
        $service = new SolarWorkbookService();
        $workbook = $service->buildTemplate();
        $sheet = $workbook->getSheetByName('SOLAR');
        for ($row = 5; $row <= 205; $row++) {
            $sheet->setCellValue("A{$row}", '01/11/2023');
            $sheet->setCellValue("B{$row}", 1);
        }
        $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'solar-progress-'.uniqid('', true).'.xlsx';
        (new Xlsx($workbook))->save($path);
        $workbook->disconnectWorksheets();
        $checkpoints = [];

        try {
            $service->parse($path, [], function (...$checkpoint) use (&$checkpoints): void {
                $checkpoints[] = $checkpoint;
            });
        } finally {
            @unlink($path);
        }

        $rowCheckpoints = array_filter($checkpoints, fn ($checkpoint) => ($checkpoint[0] ?? null) === 'reading' && isset($checkpoint[5]['row']));
        $this->assertNotEmpty($rowCheckpoints);
        $this->assertNotEmpty(array_filter($rowCheckpoints, fn ($checkpoint) => isset($checkpoint[5]['sheet']) && isset($checkpoint[5]['row_total'])));
    }

    private function previewService(): SolarImportPreviewService
    {
        return new SolarImportPreviewService(
            $this->createMock(SolarWorkbookService::class),
            $this->createMock(SolarLedgerService::class)
        );
    }

    private function invokePreviewMethod(SolarImportPreviewService $service, string $method, array $arguments)
    {
        return (new \ReflectionMethod($service, $method))->invokeArgs($service, $arguments);
    }
}
