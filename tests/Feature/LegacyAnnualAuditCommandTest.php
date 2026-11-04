<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Modules\Accounting\Http\Controllers\JournalEntryController;
use Modules\Accounting\Services\JournalImportDraftService;
use Modules\Accounting\Services\LegacyOperationalDraftEnricher;
use Modules\Accounting\Services\LegacyOperationalEvidenceReader;
use Modules\Accounting\Services\LegacyJournalImportSourceLinkAuditReader;
use Modules\Accounting\Services\LegacyAnnualAuditService;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class LegacyAnnualAuditCommandTest extends TestCase
{
    private string $sourceDirectory;

    private string $reportDirectory;

    private array $additionalJournalEntries = [];

    private array $additionalOperationalDocuments = [];

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
            ->zeroOrMoreTimes()
            ->andReturnUsing(function (): array {
                return [
                    'entries' => array_merge([
                        $this->saleEntry('INV-2022', '2022-12-31', 100, 11),
                        $this->saleEntry('INV-2023', '2023-01-02', 200, 22),
                        $this->expenseEntry('EXP-MISSING', '2023-01-03', 50),
                        $this->expenseWithVatEntry('EXP-VATIN', '2023-01-04', 50, 5),
                    ], $this->additionalJournalEntries),
                    'missing_accounts' => [],
                ];
            });
        $this->app->instance(JournalEntryController::class, $controller);

        $draftService = Mockery::mock(JournalImportDraftService::class);
        $draftService->shouldReceive('sourceTypes')->zeroOrMoreTimes()->andReturn([
            'Journal Entry', 'Sales Invoice', 'Purchase Invoice', 'Expense',
            'Receive Payment', 'Purchase Payment', 'Bank Deposit', 'Bank Transfer',
            'Bank Withdrawal', 'Stock Adjustment', 'Stock In/Out', 'Opening Balance', 'Reversal',
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
        $reader->shouldReceive('read')->zeroOrMoreTimes()->andReturnUsing(function () use ($evidence): array {
            $evidence['documents'] = array_merge($evidence['documents'], $this->additionalOperationalDocuments);

            return $evidence;
        });
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
            ->expectsOutputToContain('2023: 3 Journal; 1 sumber operasional cocok')
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
        $this->assertSame(1, $report['summary']['operational_matches']);
        $this->assertSame(1, $report['summary']['missing_operational_source']);
        $this->assertSame(1, $report['summary']['amount_mismatches']);
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
        $this->assertSame('amount_mismatch', $sale['match_status']);
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

    public function test_annual_audit_checks_supported_non_invoice_source_types_and_keeps_journal_entries_gl_only(): void
    {
        $typesRequiringOperationalSource = [
            'Receive Payment', 'Purchase Payment', 'Bank Deposit', 'Bank Transfer',
            'Bank Withdrawal', 'Stock Adjustment', 'Stock In/Out', 'Opening Balance', 'Reversal',
        ];
        $this->additionalJournalEntries = array_merge(
            [$this->sourceTypeEntry('Journal Entry', 'JE-ONLY')],
            array_map(
                fn (string $type): array => $this->sourceTypeEntry($type, 'REF-'.strtoupper(str_replace(' ', '-', $type))),
                $typesRequiringOperationalSource
            )
        );
        $this->additionalOperationalDocuments['Receive Payment|REF-RECEIVE-PAYMENT|2023-02-01'] = [
            'source_type' => 'Receive Payment',
            'source_number' => 'REF-RECEIVE-PAYMENT',
            'date' => '2023-02-01',
            'status' => 'matched',
            'source_count' => 1,
            'base' => 100,
            'tax' => 0,
            'total' => 100,
            'category_name' => 'Pembayaran',
            'description' => 'Pembayaran fixture',
            'lines' => [],
            'expense_lines' => [],
            'provenance' => [[
                'path' => 'PEMBAYARAN/receive-payment.xlsx',
                'row' => 2,
                'source_identity' => 'fixture-payment',
            ]],
        ];

        $this->artisan('accounting:import-legacy', [
            '--business-id' => 987654,
            '--user-id' => 987654,
            '--year' => 2023,
            '--source-dir' => $this->sourceDirectory,
        ])->assertExitCode(0);

        $reportPath = $this->reportDirectory.DIRECTORY_SEPARATOR.'business-987654-2023.json';
        $report = json_decode((string) file_get_contents($reportPath), true);
        $rows = collect($report['transaction_reconciliation'])->keyBy('source_number');

        $this->assertSame('not_applicable', $rows['JE-ONLY']['match_status']);
        $this->assertSame('matched', $rows['REF-RECEIVE-PAYMENT']['match_status']);
        $this->assertSame('Pembayaran', $rows['REF-RECEIVE-PAYMENT']['label']);
        foreach (array_slice($typesRequiringOperationalSource, 1) as $type) {
            $number = 'REF-'.strtoupper(str_replace(' ', '-', $type));
            $this->assertSame('missing_operational_source', $rows[$number]['match_status'], $type);
            $this->assertSame('journal_description', $rows[$number]['label_source'], $type);
        }
        $this->assertSame(9, $report['summary']['missing_operational_source']);
        $this->assertSame(0, $report['summary']['unsupported_source_types']);
    }

    public function test_annual_audit_uses_completed_persisted_journal_source_links_without_double_counting_workbook_sources(): void
    {
        Schema::create('accounting_journal_import_rows', function ($table) {
            $table->increments('id');
            $table->unsignedInteger('business_id');
            $table->unsignedInteger('journal_import_id');
            $table->string('source_type');
            $table->string('source_number')->nullable();
            $table->unsignedInteger('entry_index')->default(0);
            $table->longText('entry_data');
            $table->string('processing_status');
        });
        Schema::create('accounting_journal_import_source_links', function ($table) {
            $table->increments('id');
            $table->unsignedInteger('business_id');
            $table->unsignedInteger('journal_import_id');
            $table->unsignedInteger('journal_import_row_id');
            $table->string('journal_source_type');
            $table->string('source_record_type');
            $table->unsignedInteger('source_record_id');
            $table->string('source_reference')->nullable();
            $table->date('source_date')->nullable();
            $table->decimal('base_amount', 22, 4)->nullable();
            $table->decimal('tax_amount', 22, 4)->nullable();
            $table->decimal('total_amount', 22, 4)->nullable();
            $table->string('link_method')->default('single');
        });
        Schema::create('transactions', function ($table) {
            $table->increments('id');
            $table->unsignedInteger('business_id');
            $table->string('type');
            $table->timestamp('deleted_at')->nullable();
        });
        Schema::create('transaction_payments', function ($table) {
            $table->increments('id');
            $table->unsignedInteger('transaction_id');
            $table->timestamp('deleted_at')->nullable();
        });

        $entry = $this->sourceTypeEntry('Receive Payment', 'PAY-LINKED');
        DB::table('transactions')->insert([
            'id' => 91,
            'business_id' => 987654,
            'type' => 'sell',
            'deleted_at' => null,
        ]);
        DB::table('transaction_payments')->insert([
            'id' => 701,
            'transaction_id' => 91,
            'deleted_at' => null,
        ]);
        DB::table('transactions')->insert([
            'id' => 92,
            'business_id' => 987654,
            'type' => 'purchase',
            'deleted_at' => null,
        ]);
        DB::table('transaction_payments')->insert([
            'id' => 702,
            'transaction_id' => 92,
            'deleted_at' => null,
        ]);
        $rowId = DB::table('accounting_journal_import_rows')->insertGetId([
            'business_id' => 987654,
            'journal_import_id' => 51,
            'source_type' => $entry['source_type'],
            'source_number' => $entry['source_number'],
            'entry_index' => 0,
            'entry_data' => json_encode($entry),
            'processing_status' => 'completed',
        ]);
        $conflictEntry = $this->sourceTypeEntry('Purchase Payment', 'PAY-CONFLICT');
        $conflictRowId = DB::table('accounting_journal_import_rows')->insertGetId([
            'business_id' => 987654,
            'journal_import_id' => 51,
            'source_type' => $conflictEntry['source_type'],
            'source_number' => $conflictEntry['source_number'],
            'entry_index' => 1,
            'entry_data' => json_encode($conflictEntry),
            'processing_status' => 'completed',
        ]);
        DB::table('accounting_journal_import_source_links')->insert([
            [
                'business_id' => 987654,
                'journal_import_id' => 51,
                'journal_import_row_id' => $rowId,
                'journal_source_type' => 'Receive Payment',
                'source_record_type' => \App\TransactionPayment::class,
                'source_record_id' => 701,
                'source_reference' => 'PAY-LINKED',
                'source_date' => '2023-02-01',
                'base_amount' => null,
                'tax_amount' => null,
                'total_amount' => 100,
                'link_method' => 'single',
            ],
            [
                'business_id' => 987654,
                'journal_import_id' => 51,
                'journal_import_row_id' => $conflictRowId,
                'journal_source_type' => 'Purchase Payment',
                'source_record_type' => \App\TransactionPayment::class,
                'source_record_id' => 702,
                'source_reference' => 'PAY-CONFLICT',
                'source_date' => '2023-02-01',
                'base_amount' => null,
                'tax_amount' => null,
                'total_amount' => 101,
                'link_method' => 'single',
            ],
        ]);

        $workbookEvidence = [
            'documents' => [
                'Receive Payment|PAY-LINKED|2023-02-01' => [
                    'source_type' => 'Receive Payment',
                    'source_number' => 'PAY-LINKED',
                    'date' => '2023-02-01',
                    'status' => 'matched',
                    'source_count' => 1,
                    'base' => null,
                    'tax' => null,
                    'total' => 100,
                    'category_name' => '',
                    'description' => '',
                    'lines' => [],
                    'expense_lines' => [],
                    'provenance' => [[
                        'path' => 'PEMBAYARAN/receive-payment.xlsx',
                        'row' => 2,
                        'source_identity' => 'fixture-payment',
                    ]],
                ],
                'Purchase Payment|PAY-CONFLICT|2023-02-01' => [
                    'source_type' => 'Purchase Payment',
                    'source_number' => 'PAY-CONFLICT',
                    'date' => '2023-02-01',
                    'status' => 'matched',
                    'source_count' => 1,
                    'base' => null,
                    'tax' => null,
                    'total' => 100,
                    'category_name' => '',
                    'description' => '',
                    'lines' => [],
                    'expense_lines' => [],
                    'provenance' => [],
                ],
            ],
            'document_count' => 2,
            'source_record_count' => 2,
            'duplicate_source_count' => 0,
            'conflict_count' => 0,
            'file_summaries' => [],
        ];
        $evidence = app(LegacyJournalImportSourceLinkAuditReader::class)
            ->mergeIntoEvidence($workbookEvidence, 987654, 51);
        $report = app(LegacyAnnualAuditService::class)->generate(
            [$entry, $conflictEntry],
            $evidence,
            ['root' => $this->sourceDirectory, 'files' => []],
            987654,
            2023,
            $this->reportDirectory
        );
        $annualReport = json_decode((string) file_get_contents($report['reports'][2023]['json']), true);

        $row = collect($annualReport['transaction_reconciliation'])->firstWhere('source_number', 'PAY-LINKED');
        $this->assertSame('matched', $row['match_status']);
        $this->assertSame('Penerimaan Pembayaran', $row['label']);
        $this->assertSame('linked_operational_source_type', $row['label_source']);
        $this->assertSame(100.0, $row['operational_total']);
        $this->assertSame(1, $row['operational_source_count']);
        $this->assertSame(1, $row['linked_operational_source_count']);
        $this->assertSame(2, $annualReport['summary']['linked_operational_source_rows']);
        $this->assertCount(2, $row['operational_sources']);
        $conflict = collect($annualReport['transaction_reconciliation'])->firstWhere('source_number', 'PAY-CONFLICT');
        $this->assertSame('conflict', $conflict['match_status']);
        $this->assertSame(100.0, $conflict['operational_total']);
        $this->assertStringContainsString('total', (string) $conflict['match_reason']);
    }

    public function test_annual_report_includes_journal_import_errors_and_unmapped_accounts(): void
    {
        $entry = $this->expenseEntry('EXP-FAILED', '2023-03-02', 150);
        $entry['missing_account_lines'] = [[
            'account_no' => '5-80001',
            'account_name' => 'Office Expense',
            'description' => 'Biaya kantor',
        ]];
        $entry['has_missing_accounts'] = true;
        $journalImportRows = [[
            'id' => 901,
            'year' => 2023,
            'date' => '2023-03-02',
            'source_type' => 'Expense',
            'source_number' => 'EXP-FAILED',
            'source_key' => $entry['source_key'],
            'processing_status' => 'failed',
            'processing_error' => 'Akun 5-80001 belum dipetakan.',
            'processing_message' => null,
            'source_action' => 'create',
            'journal_mapping_id' => null,
            'reconciliation_status' => 'not_checked',
            'journal_total' => 150,
            'business_total' => null,
            'difference_amount' => null,
            'missing_account_lines' => $entry['missing_account_lines'],
        ]];
        $journalImportRows[] = array_merge($journalImportRows[0], [
            'id' => 902,
            'date' => '2023-03-03',
            'source_number' => 'EXP-RUNNING',
            'source_key' => 'Expense|EXP-RUNNING|2023-03-03',
            'processing_status' => 'running',
            'processing_error' => null,
            'processing_message' => 'Sedang diproses.',
            'journal_total' => 75,
            'missing_account_lines' => [],
        ]);
        $evidence = [
            'documents' => [],
            'document_count' => 0,
            'source_record_count' => 0,
            'duplicate_source_count' => 0,
            'conflict_count' => 0,
            'file_summaries' => [],
        ];

        $result = app(LegacyAnnualAuditService::class)->generate(
            [$entry],
            $evidence,
            ['root' => $this->sourceDirectory, 'files' => []],
            987654,
            2023,
            $this->reportDirectory,
            $journalImportRows
        );
        $jsonPath = $result['reports'][2023]['json'];
        $xlsxPath = $result['reports'][2023]['xlsx'];
        $report = json_decode((string) file_get_contents($jsonPath), true);
        $transaction = $report['transaction_reconciliation'][0];

        $this->assertSame(2, $report['schema_version']);
        $this->assertSame(1, $report['summary']['journal_import_failed_rows']);
        $this->assertSame(['failed' => 1, 'running' => 1], $report['summary']['journal_import_status_counts']);
        $this->assertSame(1, $report['summary']['journal_import_unfinished_rows']);
        $this->assertSame(1, $report['summary']['journal_import_unmapped_account_rows']);
        $this->assertSame('failed', $transaction['journal_import_status']);
        $this->assertSame('Akun 5-80001 belum dipetakan.', $transaction['journal_import_errors'][0]);
        $this->assertSame('5-80001', $transaction['unmapped_journal_accounts'][0]['account_no']);

        $workbook = IOFactory::load($xlsxPath);
        $importSheet = $workbook->getSheetByName('Status Import Journal');
        $this->assertNotNull($importSheet);
        $sheetRows = $importSheet->toArray();
        $this->assertSame('failed', $sheetRows[1][5]);
        $this->assertStringContainsString('belum dipetakan', $sheetRows[1][8]);
        $workbook->disconnectWorksheets();
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

    private function sourceTypeEntry(string $sourceType, string $number): array
    {
        return [
            'source_type' => $sourceType,
            'source_number' => $number,
            'date' => '2023-02-01',
            'description' => $sourceType.' '.$number,
            'source_key' => $sourceType.'|'.$number.'|2023-02-01',
            'debit_total' => 100,
            'credit_total' => 100,
            'lines' => [
                ['account_no' => '1-10001', 'account_name' => 'Cash', 'debit' => 100, 'credit' => 0],
                ['account_no' => '2-20001', 'account_name' => 'Clearing', 'debit' => 0, 'credit' => 100],
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
