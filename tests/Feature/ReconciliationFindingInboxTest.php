<?php

namespace Tests\Feature;

use App\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Modules\Accounting\Services\LegacyReconciliationFindingSnapshotService;
use Tests\TestCase;

class ReconciliationFindingInboxTest extends TestCase
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
                'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => false,
            ],
        ]);
        DB::purge('sqlite');
        DB::setDefaultConnection('sqlite');
        $this->createFindingTable();
    }

    public function test_persisting_the_same_annual_report_is_idempotent_and_keeps_offsets_visible(): void
    {
        $service = app(LegacyReconciliationFindingSnapshotService::class);
        $report = $this->annualReport();

        $firstCount = $service->persistReport(1, $report);
        $secondCount = $service->persistReport(1, $report);

        $this->assertSame(5, $firstCount);
        $this->assertSame(5, $secondCount);
        $this->assertSame(5, DB::table('accounting_legacy_reconciliation_findings')->count());

        $summary = $service->summary(1);
        $transactionSummary = collect($summary['by_year_and_type'])->firstWhere('type', 'transaction');
        $this->assertSame(1870000.0, (float) $transactionSummary['gross_amount']);
        $this->assertSame(0.0, (float) $transactionSummary['net_amount']);
        $this->assertSame(5, $summary['open_count']);
        $this->assertSame(5, DB::table('accounting_legacy_reconciliation_findings')->where('is_current', true)->count());
    }

    public function test_findings_page_filters_searches_and_scopes_rows_to_the_active_business(): void
    {
        app(LegacyReconciliationFindingSnapshotService::class)->persistReport(1, $this->annualReport());
        DB::table('accounting_legacy_reconciliation_findings')->insert($this->findingRow([
            'business_id' => 2,
            'finding_key' => hash('sha256', 'tenant-two-secret'),
            'source_number' => 'TENANT-TWO-SECRET',
            'description' => 'Tenant lain',
        ]));

        $user = Mockery::mock(User::class)->makePartial();
        $user->forceFill(['id' => 987654, 'business_id' => 1, 'username' => 'reconciliation-test']);
        $user->shouldReceive('can')->andReturnUsing(fn (string $permission) => $permission === 'accounting.view_reports');
        $moduleUtil = Mockery::mock(\App\Utils\ModuleUtil::class);
        $moduleUtil->shouldReceive('hasThePermissionInSubscription')->andReturn(true);
        $this->app->instance(\App\Utils\ModuleUtil::class, $moduleUtil);

        $response = $this->actingAs($user)->withSession(['user' => ['business_id' => 1]])
            ->get(route('accounting.reconciliation.findings', [
                'year' => 2023, 'type' => 'transaction', 'status' => 'open', 'search' => 'INV-2023',
            ]));

        $response->assertOk();
        $response->assertSee('INV-2023');
        $response->assertDontSee('INV-MISSING-LINK');
        $response->assertDontSee('TENANT-TWO-SECRET');
        $response->assertSee('Rp');
    }

    private function annualReport(): array
    {
        return [
            'business_id' => 1,
            'year' => 2023,
            'generated_at' => '2026-11-03T10:00:00+07:00',
            'source_manifest_sha256' => str_repeat('a', 64),
            'transaction_reconciliation' => [
                [
                    'date' => '2023-01-02', 'source_type' => 'Sales Invoice', 'source_number' => 'INV-2023',
                    'description' => 'Penjualan fixture', 'operational_identity' => 'Sales Invoice|INV-2023|2023-01-02',
                    'journal_base' => 10000000, 'operational_base' => 9065000, 'base_variance' => 935000,
                    'journal_vat_in' => 0, 'operational_vat_in' => 0, 'vat_in_variance' => 0,
                    'journal_vat_out' => 935000, 'operational_vat_out' => 1870000, 'vat_out_variance' => -935000,
                    'journal_total' => 10935000, 'operational_total' => 10935000, 'total_variance' => 0,
                    'journal_debit' => 10935000, 'journal_credit' => 10935000, 'journal_balance_variance' => 0,
                    'journal_components' => [], 'journal_sources' => [], 'operational_sources' => [],
                ],
                [
                    'date' => '2023-01-03', 'source_type' => 'Expense', 'source_number' => 'INV-MISSING-LINK',
                    'description' => 'Jurnal seimbang tanpa sumber operasional', 'operational_identity' => null,
                    'journal_base' => 500000, 'operational_base' => null, 'base_variance' => null,
                    'journal_vat_in' => 0, 'operational_vat_in' => null, 'vat_in_variance' => null,
                    'journal_vat_out' => 0, 'operational_vat_out' => null, 'vat_out_variance' => null,
                    'journal_total' => 500000, 'operational_total' => null, 'total_variance' => null,
                    'journal_debit' => 500000, 'journal_credit' => 500000, 'journal_balance_variance' => 0,
                    'journal_components' => [], 'journal_sources' => [], 'operational_sources' => [],
                ],
            ],
            'account_balances' => [[
                'year' => 2023, 'account_no' => '1-10001', 'account_name' => 'Kas Utama',
                'opening_balance' => 100, 'explicit_journal_opening' => 105, 'opening_variance' => 5,
                'debit' => 200, 'credit' => 150, 'closing_balance' => 155, 'opening_entries' => [],
            ]],
            'control_comparison' => [[
                'year' => 2023, 'control_type' => 'general_ledger_account', 'source_file' => 'buku.xlsx',
                'source_sheet' => 'BUKU BESAR', 'account_no' => '1-10001', 'account_name' => 'Kas Utama',
                'control_opening_signed' => 100, 'journal_opening' => 105, 'opening_variance' => -5,
                'control_debit' => 200, 'journal_debit' => 194, 'debit_variance' => 6,
                'control_credit' => 150, 'journal_credit' => 156, 'credit_variance' => -6,
                'control_closing_signed' => 150, 'journal_closing' => 143, 'closing_variance' => 7,
                'source_rows' => [12],
            ]],
        ];
    }

    private function findingRow(array $overrides = []): array
    {
        return array_merge([
            'business_id' => 1, 'year' => 2023, 'type' => 'transaction', 'component' => 'base',
            'finding_key' => hash('sha256', 'tenant-two-secret'), 'source_identity' => 'test-source',
            'source_type' => 'Expense', 'source_number' => 'TENANT-TWO-SECRET', 'source_date' => '2023-01-01',
            'account_no' => '', 'account_name' => '', 'description' => 'Tenant lain',
            'expected_amount' => 1, 'actual_amount' => 2, 'variance' => 1,
            'evidence' => '{}', 'status' => 'open', 'is_current' => true,
            'last_audit_key' => str_repeat('b', 64), 'last_audited_at' => '2026-11-03 10:00:00',
            'resolution_history' => null, 'created_at' => now(), 'updated_at' => now(),
        ], $overrides);
    }

    private function createFindingTable(): void
    {
        Schema::create('accounting_legacy_reconciliation_findings', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedInteger('business_id');
            $table->unsignedSmallInteger('year');
            $table->string('type', 40);
            $table->string('component', 40);
            $table->char('finding_key', 64);
            $table->char('source_identity', 64);
            $table->string('source_type', 100)->nullable();
            $table->text('source_number')->nullable();
            $table->date('source_date')->nullable();
            $table->string('account_no', 100)->default('');
            $table->string('account_name')->default('');
            $table->text('description')->nullable();
            $table->decimal('expected_amount', 22, 4)->nullable();
            $table->decimal('actual_amount', 22, 4)->nullable();
            $table->decimal('variance', 22, 4);
            $table->longText('evidence');
            $table->string('status', 24)->default('open');
            $table->boolean('is_current')->default(true);
            $table->char('last_audit_key', 64);
            $table->dateTime('last_audited_at');
            $table->longText('resolution_history')->nullable();
            $table->timestamps();
            $table->unique(['business_id', 'finding_key']);
            $table->index(['business_id', 'is_current', 'status']);
            $table->index(['business_id', 'year', 'type', 'is_current']);
        });
    }
}
