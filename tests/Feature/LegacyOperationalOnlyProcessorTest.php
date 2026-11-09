<?php

namespace Tests\Feature;

use App\Transaction;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Modules\Accounting\Services\JournalImportExpenseService;
use Modules\Accounting\Services\JournalImportInvoiceService;
use Modules\Accounting\Services\LegacyImportRunService;
use Modules\Accounting\Services\LegacyOperationalOnlyProcessor;
use Modules\Accounting\Services\LegacySourceReconciliationFindingService;
use Tests\TestCase;

class LegacyOperationalOnlyProcessorTest extends TestCase
{
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

        $this->createSchema();
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_only_complete_operational_documents_are_created_and_retry_reuses_the_crosswalk(): void
    {
        $runService = new LegacyImportRunService();
        $runId = $runService->createRun(2, 5, 2022, sys_get_temp_dir(), hash('sha256', 'manifest'), [[
            'path' => 'sales/sales_list_2022.xlsx',
            'sha256' => hash('sha256', 'sales workbook'),
            'classification' => 'sales_operational_source',
            'period_start' => '2022-01-01',
            'period_end' => '2022-12-31',
            'size_bytes' => 1,
        ]]);

        $readyIdentity = hash('sha256', 'ready-document');
        $reviewIdentity = hash('sha256', 'incomplete-document');
        $evidence = ['documents' => [
            $readyIdentity => $this->document('SALE-001', $readyIdentity),
            $reviewIdentity => $this->document('SALE-002', $reviewIdentity),
        ]];
        $standaloneDrafts = [
            $readyIdentity => [
                'ready' => true,
                'draft_data' => ['location_id' => 20, 'details' => [['variation_id' => 30, 'quantity' => 1, 'unit_price' => 100]]],
                'expense_lines' => [],
            ],
            $reviewIdentity => ['ready' => false, 'reason' => 'Produk belum terpetakan secara unik.'],
        ];

        $invoiceService = Mockery::mock(JournalImportInvoiceService::class);
        $invoiceService->shouldReceive('createLegacyOperational')
            ->once()
            ->andReturnUsing(function () {
                $id = DB::table('transactions')->insertGetId([
                    'business_id' => 2,
                    'type' => 'sell',
                    'status' => 'draft',
                    'transaction_date' => '2022-03-12 00:00:00',
                    'ref_no' => 'SALE-001',
                    'final_total' => 110,
                    'total_before_tax' => 100,
                    'tax_amount' => 10,
                ]);

                return Transaction::find($id);
            });
        $expenseService = Mockery::mock(JournalImportExpenseService::class);
        $sourceFindings = new LegacySourceReconciliationFindingService();

        $processor = new LegacyOperationalOnlyProcessor();
        $first = $processor->process(
            $runId,
            2,
            5,
            $evidence,
            [],
            $standaloneDrafts,
            $runService,
            $invoiceService,
            $expenseService,
            $sourceFindings
        );

        $this->assertSame(['created' => 1, 'linked_existing' => 0, 'review' => 1, 'with_journal' => 0], $first);
        $this->assertSame(1, DB::table('transactions')->where('business_id', 2)->count());
        $this->assertSame(1, DB::table('accounting_legacy_import_crosswalks')
            ->where('legacy_import_run_id', $runId)
            ->whereNotNull('target_record_id')
            ->count());
        $this->assertSame(1, DB::table('accounting_legacy_import_crosswalks')
            ->where('legacy_import_run_id', $runId)
            ->where('status', 'review')
            ->whereNull('target_record_id')
            ->count());
        $this->assertSame(2, DB::table('accounting_legacy_source_findings')->where('legacy_import_run_id', $runId)->count());
        $this->assertSame(1, DB::table('accounting_legacy_source_findings')
            ->where('legacy_import_run_id', $runId)->where('reason_code', 'journal_source_missing')->count());
        $this->assertSame(1, DB::table('accounting_legacy_source_findings')
            ->where('legacy_import_run_id', $runId)->where('reason_code', 'operational_source_incomplete')->count());

        $second = $processor->process(
            $runId,
            2,
            5,
            $evidence,
            [],
            $standaloneDrafts,
            $runService,
            $invoiceService,
            $expenseService,
            $sourceFindings
        );

        $this->assertSame(['created' => 0, 'linked_existing' => 1, 'review' => 1, 'with_journal' => 0], $second);
        $this->assertSame(1, DB::table('transactions')->where('business_id', 2)->count());
        $this->assertSame(2, DB::table('accounting_legacy_import_crosswalks')->where('legacy_import_run_id', $runId)->count());
        $this->assertSame(2, DB::table('accounting_legacy_source_findings')->where('legacy_import_run_id', $runId)->count());
    }

    private function document(string $number, string $identity): array
    {
        return [
            'status' => 'matched',
            'source_type' => 'Sales Invoice',
            'source_number' => $number,
            'date' => '2022-03-12',
            'base' => 100,
            'tax' => 10,
            'total' => 110,
            'description' => 'Invoice legacy',
            'provenance' => [[
                'path' => 'sales/sales_list_2022.xlsx',
                'source_identity' => $identity,
            ]],
        ];
    }

    private function createSchema(): void
    {
        Schema::create('accounting_legacy_import_runs', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedInteger('business_id');
            $table->unsignedInteger('created_by');
            $table->unsignedSmallInteger('year_filter')->nullable();
            $table->string('source_directory', 500);
            $table->char('manifest_sha256', 64);
            $table->string('status', 32)->default('preparing');
            $table->unsignedBigInteger('journal_import_id')->nullable();
            $table->longText('summary')->nullable();
            $table->text('last_message')->nullable();
            $table->dateTime('started_at')->nullable();
            $table->dateTime('finished_at')->nullable();
            $table->timestamps();
            $table->unique(['business_id', 'manifest_sha256']);
        });

        Schema::create('accounting_legacy_import_sources', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('legacy_import_run_id');
            $table->unsignedInteger('business_id');
            $table->string('source_path', 500);
            $table->char('sha256', 64);
            $table->string('classification', 64);
            $table->date('period_start')->nullable();
            $table->date('period_end')->nullable();
            $table->string('status', 32)->default('inventoried');
            $table->unsignedBigInteger('journal_import_id')->nullable();
            $table->unsignedInteger('records_read')->default(0);
            $table->unsignedInteger('records_linked')->default(0);
            $table->unsignedInteger('records_for_review')->default(0);
            $table->longText('summary')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();
            $table->unique(['legacy_import_run_id', 'source_path']);
        });

        Schema::create('accounting_legacy_import_crosswalks', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('legacy_import_run_id');
            $table->unsignedBigInteger('legacy_import_source_id');
            $table->unsignedInteger('business_id');
            $table->char('source_identity', 64);
            $table->string('legacy_record_id', 100)->nullable();
            $table->string('source_type', 100)->nullable();
            $table->text('source_number')->nullable();
            $table->date('source_date')->nullable();
            $table->unsignedBigInteger('journal_import_row_id')->nullable();
            $table->string('target_record_type', 64)->nullable();
            $table->unsignedBigInteger('target_record_id')->nullable();
            $table->string('status', 32)->default('review');
            $table->string('match_method', 32)->nullable();
            $table->text('reason')->nullable();
            $table->timestamps();
            $table->unique(['legacy_import_run_id', 'legacy_import_source_id', 'source_identity']);
        });

        Schema::create('accounting_legacy_source_findings', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedInteger('business_id');
            $table->unsignedBigInteger('legacy_import_run_id');
            $table->char('source_identity', 64);
            $table->string('source_type', 100);
            $table->string('source_number', 191)->nullable();
            $table->date('source_date')->nullable();
            $table->decimal('source_amount', 22, 4)->nullable();
            $table->string('reason_code', 100);
            $table->text('reason');
            $table->longText('evidence')->nullable();
            $table->string('status', 24)->default('open');
            $table->string('target_record_type', 64)->nullable();
            $table->unsignedBigInteger('target_record_id')->nullable();
            $table->unsignedInteger('resolved_by')->nullable();
            $table->dateTime('first_seen_at')->nullable();
            $table->dateTime('last_seen_at')->nullable();
            $table->dateTime('resolved_at')->nullable();
            $table->text('resolution_reason')->nullable();
            $table->longText('resolution_history')->nullable();
            $table->timestamps();
            $table->unique(['business_id', 'legacy_import_run_id', 'source_identity']);
        });

        Schema::create('transactions', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedInteger('business_id');
            $table->string('type', 32);
            $table->string('status', 32);
            $table->dateTime('transaction_date');
            $table->string('ref_no')->nullable();
            $table->string('invoice_no')->nullable();
            $table->decimal('final_total', 22, 4)->default(0);
            $table->decimal('total_before_tax', 22, 4)->nullable();
            $table->decimal('tax_amount', 22, 4)->nullable();
            $table->softDeletes();
        });
    }
}
