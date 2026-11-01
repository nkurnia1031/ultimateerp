<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Modules\Accounting\Http\Controllers\JournalEntryController;
use Modules\Accounting\Services\JournalImportDraftService;
use Modules\Accounting\Services\LegacyOperationalDraftEnricher;
use Modules\Accounting\Services\LegacyOperationalEvidenceReader;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class LegacyAnnualAuditCommandTest extends TestCase
{
    private string $sourceDirectory;

    private string $reportDirectory;

    protected function setUp(): void
    {
        parent::setUp();
        if (! in_array('sqlite', \PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('PDO SQLite tidak tersedia pada PHP runtime ini.');
        }

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite' => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
                'foreign_key_constraints' => false,
            ],
        ]);
        DB::purge('sqlite');
        DB::setDefaultConnection('sqlite');
        Schema::create('business', function ($table) {
            $table->increments('id');
            $table->string('name');
        });
        Schema::create('users', function ($table) {
            $table->increments('id');
            $table->string('name');
        });
        DB::table('business')->insert(['id' => 987654, 'name' => 'Legacy Audit Fixture']);
        DB::table('users')->insert(['id' => 987654, 'name' => 'Fixture User']);

        $this->sourceDirectory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'legacy-annual-audit-'.bin2hex(random_bytes(8));
        $this->reportDirectory = storage_path('app/legacy-import-reports/business-987654');
        mkdir($this->sourceDirectory, 0777, true);
        mkdir($this->sourceDirectory.DIRECTORY_SEPARATOR.'PENJUALAN', 0777, true);
        mkdir($this->sourceDirectory.DIRECTORY_SEPARATOR.'BIAYA', 0777, true);
        file_put_contents(
            $this->sourceDirectory.DIRECTORY_SEPARATOR.'journal_01-01-2022_31-12-2023.xlsx',
            'synthetic journal workbook'
        );
        file_put_contents(
            $this->sourceDirectory.DIRECTORY_SEPARATOR.'journal_01-01-2022_31-12-2023_copy.xlsx',
            'synthetic overlapping journal workbook'
        );
        file_put_contents(
            $this->sourceDirectory.DIRECTORY_SEPARATOR.'PENJUALAN'.DIRECTORY_SEPARATOR.'sales_list_01-01-2023_31-12-2023.xlsx',
            'synthetic operational workbook'
        );
        file_put_contents(
            $this->sourceDirectory.DIRECTORY_SEPARATOR.'BIAYA'.DIRECTORY_SEPARATOR.'expense_list_01-01-2023_31-12-2023.xlsx',
            'synthetic expense workbook'
        );
        $this->createGeneralLedgerControl();

        $controller = Mockery::mock(JournalEntryController::class);
        $controller->shouldReceive('analyzeJournalWorkbook')
            ->twice()
            ->andReturn([
                'entries' => [
                    $this->saleEntry('INV-2022', '2022-12-31', 100, 11),
                    $this->saleEntry('INV-2023', '2023-01-02', 200, 22),
                    $this->expenseEntry('EXP-MISSING', '2023-01-03', 50),
                    $this->expenseWithVatEntry('EXP-VATIN', '2023-01-04', 50, 5),
                ],
                'missing_accounts' => [],
            ]);
        $this->app->instance(JournalEntryController::class, $controller);

        $draftService = Mockery::mock(JournalImportDraftService::class);
        $draftService->shouldReceive('sourceTypes')->zeroOrMoreTimes()->andReturn([
            'Journal Entry', 'Sales Invoice', 'Purchase Invoice', 'Expense',
        ]);
        $this->app->instance(JournalImportDraftService::class, $draftService);

        $enricher = Mockery::mock(LegacyOperationalDraftEnricher::class);
        $enricher->shouldReceive('enrich')->zeroOrMoreTimes()->andReturnUsing(
            fn (array $entries): array => $entries
        );
        $enricher->shouldReceive('prepareStandaloneDrafts')->zeroOrMoreTimes()->andReturn([]);
        $this->app->instance(LegacyOperationalDraftEnricher::class, $enricher);

        $evidence = [
            'documents' => [
                'Sales Invoice|INV-2023|2023-01-02' => [
                    'source_type' => 'Sales Invoice',
                    'source_number' => 'INV-2023',
                    'date' => '2023-01-02',
                    'status' => 'matched',
                    'source_count' => 1,
                    'base' => 201,
                    'tax' => 21,
                    'total' => 222,
                    'category_name' => '',
                    'description' => 'Penjualan fixture',
                    'lines' => [],
                    'expense_lines' => [],
                    'provenance' => [[
                        'path' => 'PENJUALAN/sales_list_01-01-2023_31-12-2023.xlsx',
                        'row' => 12,
                        'source_identity' => 'fixture-sale-2023',
                    ]],
                ],
                'Expense|EXP-VATIN|2023-01-04' => [
                    'source_type' => 'Expense',
                    'source_number' => 'EXP-VATIN',
                    'date' => '2023-01-04',
                    'status' => 'matched',
                    'source_count' => 1,
                    'base' => 50,
                    'tax' => 5,
                    'total' => 55,
                    'category_name' => 'Operasional',
                    'description' => 'Expense fixture with input VAT',
                    'lines' => [],
                    'expense_lines' => [],
                    'provenance' => [[
                        'path' => 'BIAYA/expense_list_01-01-2023_31-12-2023.xlsx',
                        'row' => 18,
                        'source_identity' => 'fixture-expense-2023',
                    ]],
                ],
            ],
            'document_count' => 2,
            'source_record_count' => 2,
            'duplicate_source_count' => 0,
            'conflict_count' => 0,
            'file_summaries' => [],
        ];
        $reader = Mockery::mock(LegacyOperationalEvidenceReader::class);
        $reader->shouldReceive('read')->once()->andReturn($evidence);
        $this->app->instance(LegacyOperationalEvidenceReader::class, $reader);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        $this->removeDirectory($this->sourceDirectory);
        foreach (['business-987654-2023.json', 'business-987654-2023.xlsx'] as $name) {
            $path = $this->reportDirectory.DIRECTORY_SEPARATOR.$name;
            if (is_file($path)) {
                @unlink($path);
            }
        }
        if (is_dir($this->reportDirectory) && count(scandir($this->reportDirectory) ?: []) <= 2) {
            @rmdir($this->reportDirectory);
        }
        parent::tearDown();
    }

    public function test_public_legacy_import_command_writes_annual_report_and_keeps_database_read_only(): void
    {
        $tablesBefore = $this->tableNames();
        $businessCountBefore = DB::table('business')->count();
        $userCountBefore = DB::table('users')->count();

        $this->artisan('accounting:import-legacy', [
            '--business-id' => 987654,
            '--user-id' => 987654,
            '--year' => 2023,
            '--source-dir' => $this->sourceDirectory,
        ])->expectsOutputToContain('Laporan rekonsiliasi tahunan:')
            ->expectsOutputToContain('2023: 3 Journal; 2 sumber operasional cocok')
            ->assertExitCode(0);

        $this->assertSame($tablesBefore, $this->tableNames());
        $this->assertSame($businessCountBefore, DB::table('business')->count());
        $this->assertSame($userCountBefore, DB::table('users')->count());

        $jsonPath = $this->reportDirectory.DIRECTORY_SEPARATOR.'business-987654-2023.json';
        $xlsxPath = $this->reportDirectory.DIRECTORY_SEPARATOR.'business-987654-2023.xlsx';
        $this->assertFileExists($jsonPath);
        $this->assertFileExists($xlsxPath);
        $report = json_decode((string) file_get_contents($jsonPath), true);
        $this->assertSame(2023, $report['year']);
        $this->assertSame(3, $report['summary']['journal_entries']);
        $this->assertSame(2, $report['summary']['operational_matches']);
        $this->assertSame(1, $report['summary']['missing_operational_source']);
        $this->assertSame(3, $report['summary']['journal_duplicate_sources']);

        $sale = collect($report['transaction_reconciliation'])->firstWhere('source_number', 'INV-2023');
        $this->assertSame('INV-2023', $sale['source_number']);
        $this->assertSame('Pendapatan Usaha', $sale['label']);
        $this->assertSame('matched_operational_source_type', $sale['label_source']);
        $this->assertSame(200.0, $sale['journal_base']);
        $this->assertSame(22.0, $sale['journal_vat_out']);
        $this->assertSame(222.0, $sale['journal_total']);
        $this->assertSame(-1.0, $sale['base_variance']);
        $this->assertSame(1.0, $sale['vat_out_variance']);
        $this->assertSame(0.0, $sale['total_variance']);
        $expense = collect($report['transaction_reconciliation'])->firstWhere('source_number', 'EXP-VATIN');
        $this->assertSame('Operasional', $expense['label']);
        $this->assertSame('operational_category', $expense['label_source']);
        $this->assertSame(5.0, $expense['journal_vat_in']);
        $this->assertSame(0.0, $expense['vat_in_variance']);

        $cash = collect($report['account_balances'])->firstWhere('account_no', '1-10001');
        $this->assertSame(111.0, $cash['opening_balance']);
        $this->assertSame(222.0, $cash['debit']);
        $this->assertSame(105.0, $cash['credit']);
        $this->assertSame(228.0, $cash['closing_balance']);
        $control = collect($report['control_comparison'])->firstWhere('control_type', 'general_ledger_account');
        $this->assertSame(111.0, $control['journal_opening']);
        $this->assertSame(-11.0, $control['opening_variance']);

        $workbook = \PhpOffice\PhpSpreadsheet\IOFactory::load($xlsxPath);
        try {
            $this->assertSame('Rekonsiliasi Transaksi', $workbook->getSheetByName('Rekonsiliasi Transaksi')->getTitle());
            $this->assertSame('Status Match', $workbook->getSheetByName('Rekonsiliasi Transaksi')->getCell('U1')->getValue());
        } finally {
            $workbook->disconnectWorksheets();
        }
    }

    private function saleEntry(string $number, string $date, float $base, float $tax): array
    {
        $total = $base + $tax;

        return [
            'source_type' => 'Sales Invoice',
            'source_number' => $number,
            'date' => $date,
            'description' => 'Sales '.$number,
            'source_key' => 'Sales Invoice|'.$number.'|'.$date,
            'debit_total' => $total,
            'credit_total' => $total,
            'lines' => [
                ['account_no' => '1-10001', 'account_name' => 'Cash', 'debit' => $total, 'credit' => 0],
                ['account_no' => '4-10001', 'account_name' => 'Sales Revenue', 'debit' => 0, 'credit' => $base],
                ['account_no' => '2-20001', 'account_name' => 'VAT Out', 'debit' => 0, 'credit' => $tax],
            ],
            'legacy_sources' => [],
        ];
    }

    private function expenseEntry(string $number, string $date, float $amount): array
    {
        return [
            'source_type' => 'Expense',
            'source_number' => $number,
            'date' => $date,
            'description' => 'Operational expense '.$number,
            'source_key' => 'Expense|'.$number.'|'.$date,
            'debit_total' => $amount,
            'credit_total' => $amount,
            'lines' => [
                ['account_no' => '5-80001', 'account_name' => 'Office Expense', 'debit' => $amount, 'credit' => 0],
                ['account_no' => '1-10001', 'account_name' => 'Cash', 'debit' => 0, 'credit' => $amount],
            ],
            'legacy_sources' => [],
        ];
    }

    private function expenseWithVatEntry(string $number, string $date, float $base, float $tax): array
    {
        $total = $base + $tax;

        return [
            'source_type' => 'Expense',
            'source_number' => $number,
            'date' => $date,
            'description' => 'Operational expense '.$number,
            'source_key' => 'Expense|'.$number.'|'.$date,
            'debit_total' => $total,
            'credit_total' => $total,
            'lines' => [
                ['account_no' => '5-80001', 'account_name' => 'Office Expense', 'debit' => $base, 'credit' => 0],
                ['account_no' => '1-10002', 'account_name' => 'VAT In', 'debit' => $tax, 'credit' => 0],
                ['account_no' => '1-10001', 'account_name' => 'Cash', 'debit' => 0, 'credit' => $total],
            ],
            'legacy_sources' => [],
        ];
    }

    private function createGeneralLedgerControl(): void
    {
        $workbook = new Spreadsheet();
        $sheet = $workbook->getActiveSheet();
        $sheet->setTitle('Account Transaction');
        $sheet->fromArray([
            ['Nama Akun / Tanggal', 'Transaksi', 'Nomor', 'Keterangan', 'Debit', 'Kredit', 'Saldo', 'Tags'],
            ['(1-10001) Cash', '', '', '', '', '', '', ''],
            ['01/01/2022', 'Opening Balance', '', 'Opening Balance', 111, 0, 111, ''],
            ['01/01/2023', 'Opening Balance', '', 'Opening Balance', 100, 0, 100, ''],
        ]);
        $path = $this->sourceDirectory.DIRECTORY_SEPARATOR.'general_ledger_01-01-2010_06-09-2025.xlsx';
        (new Xlsx($workbook))->save($path);
        $workbook->disconnectWorksheets();
    }

    private function tableNames(): array
    {
        return array_column(DB::select("SELECT name FROM sqlite_master WHERE type = 'table' ORDER BY name"), 'name');
    }

    private function removeDirectory(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $child = $path.DIRECTORY_SEPARATOR.$entry;
            if (is_dir($child) && ! is_link($child)) {
                $this->removeDirectory($child);
            } else {
                @unlink($child);
            }
        }
        @rmdir($path);
    }
}
