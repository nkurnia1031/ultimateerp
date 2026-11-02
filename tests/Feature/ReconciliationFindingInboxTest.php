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
        $this->createPermissionTables();
        $this->createFindingTable();
        Schema::create('system', function (Blueprint $table) {
            $table->string('key');
            $table->text('value')->nullable();
        });
        Schema::create('transactions', function (Blueprint $table) {
            $table->increments('id');
            $table->decimal('final_total', 22, 4);
        });
        Schema::create('accounting_accounts_transactions', function (Blueprint $table) {
            $table->increments('id');
            $table->decimal('amount', 22, 4);
        });
        DB::table('transactions')->insert(['id' => 1, 'final_total' => 123.45]);
        DB::table('accounting_accounts_transactions')->insert(['id' => 1, 'amount' => 67.89]);
    }

    public function test_persisting_the_same_annual_report_is_idempotent_and_keeps_offsets_visible(): void
    {
        $service = app(LegacyReconciliationFindingSnapshotService::class);
        $report = $this->annualReport();

        $firstCount = $service->persistReport(1, $report);
        $report['generated_at'] = '2026-11-03T10:05:00+07:00';
        $secondCount = $service->persistReport(1, $report);

        $this->assertSame(9, $firstCount);
        $this->assertSame(9, $secondCount);
        $this->assertSame(9, DB::table('accounting_legacy_reconciliation_findings')->count());
        $this->assertSame(1, DB::table('accounting_legacy_reconciliation_audits')->count());

        $summary = $service->summary(1);
        $baseSummary = collect($summary['by_year_type_component'])->firstWhere('component', 'base');
        $vatSummary = collect($summary['by_year_type_component'])->firstWhere('component', 'vat_out');
        $debitSummary = collect($summary['by_year_type_component'])->firstWhere(function (array $row): bool {
            return $row['type'] === 'control' && $row['component'] === 'debit';
        });
        $this->assertSame(935000.0, (float) $baseSummary['gross_amount']);
        $this->assertSame(935000.0, (float) $baseSummary['net_amount']);
        $this->assertSame(935000.0, (float) $vatSummary['gross_amount']);
        $this->assertSame(-935000.0, (float) $vatSummary['net_amount']);
        $this->assertSame(1870000.0, (float) $debitSummary['gross_amount']);
        $this->assertSame(0.0, (float) $debitSummary['net_amount']);
        $this->assertSame(2, DB::table('accounting_legacy_reconciliation_findings')->where('type', 'control')->where('component', 'debit')->count());
        $this->assertSame(9, $summary['open_count']);
        $this->assertSame(9, DB::table('accounting_legacy_reconciliation_findings')->where('is_current', true)->count());
        $this->assertSame(10.0, (float) DB::table('accounting_legacy_reconciliation_findings')
            ->where('type', 'unbalanced_journal')->value('variance'));
        $this->assertSame('2026-11-03 10:05:00', $summary['last_audited_at']);
        $this->assertSame(123.45, (float) DB::table('transactions')->where('id', 1)->value('final_total'));
        $this->assertSame(67.89, (float) DB::table('accounting_accounts_transactions')->where('id', 1)->value('amount'));
    }

    public function test_completed_audit_with_no_findings_still_has_a_timestamp_and_year_filter(): void
    {
        $report = $this->annualReport();
        $report['year'] = 2024;
        $report['generated_at'] = '2026-11-04T11:15:00+07:00';
        $report['transaction_reconciliation'] = [];
        $report['account_balances'] = [];
        $report['control_comparison'] = [];

        $service = app(LegacyReconciliationFindingSnapshotService::class);
        $this->assertSame(0, $service->persistReport(1, $report));

        $summary = $service->summary(1);
        $this->assertSame('2026-11-04 11:15:00', $summary['last_audited_at']);
        $this->assertSame([2024], $service->years(1));
        $this->assertSame(0, $summary['open_count']);
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
        DB::table(config('permission.table_names.permissions'))->insert([
            'id' => 1, 'name' => 'accounting.view_reports', 'guard_name' => 'web', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table(config('permission.table_names.model_has_permissions'))->insert([
            'permission_id' => 1, 'model_type' => User::class, 'model_id' => 987654,
        ]);
        $moduleUtil = Mockery::mock(\App\Utils\ModuleUtil::class);
        $moduleUtil->shouldReceive('hasThePermissionInSubscription')->andReturn(true);
        $this->app->instance(\App\Utils\ModuleUtil::class, $moduleUtil);

        $response = $this->actingAs($user)->withSession([
            'user' => ['business_id' => 1],
            'business' => ['currency_precision' => 2],
            'currency' => ['code' => 'IDR', 'symbol' => 'Rp', 'symbol_placement' => 'before'],
        ])
            ->withoutMiddleware([
                \App\Http\Middleware\SetSessionData::class,
                \App\Http\Middleware\Language::class,
                \App\Http\Middleware\Timezone::class,
                \App\Http\Middleware\AdminSidebarMenu::class,
            ])->get(route('accounting.reconciliation.findings.data', [
                'year' => 2023, 'type' => 'transaction', 'status' => 'open', 'search' => 'INV-2023',
            ]));

        $response->assertOk();
        $payload = $response->json();
        $this->assertStringContainsString('INV-2023', $payload['rows_html']);
        $this->assertStringNotContainsString('INV-MISSING-LINK', $payload['rows_html']);
        $this->assertStringNotContainsString('TENANT-TWO-SECRET', $payload['rows_html']);
        $this->assertStringContainsString('Rp', $payload['rows_html']);
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
                [
                    'date' => '2023-01-04', 'source_type' => 'Journal Entry', 'source_number' => 'JRN-UNBAL',
                    'description' => 'Jurnal tidak seimbang', 'operational_identity' => null,
                    'journal_base' => 0, 'operational_base' => null, 'base_variance' => null,
                    'journal_vat_in' => 0, 'operational_vat_in' => null, 'vat_in_variance' => null,
                    'journal_vat_out' => 0, 'operational_vat_out' => null, 'vat_out_variance' => null,
                    'journal_total' => 0, 'operational_total' => null, 'total_variance' => null,
                    'journal_debit' => 100, 'journal_credit' => 90, 'journal_balance_variance' => 10,
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
                'control_debit' => 1129000, 'journal_debit' => 194000, 'debit_variance' => 935000,
                'control_credit' => 150, 'journal_credit' => 156, 'credit_variance' => -6,
                'control_closing_signed' => 150, 'journal_closing' => 143, 'closing_variance' => 7,
                'source_rows' => [12],
            ], [
                'year' => 2023, 'control_type' => 'general_ledger_account', 'source_file' => 'buku.xlsx',
                'source_sheet' => 'BUKU BESAR', 'account_no' => '1-10002', 'account_name' => 'Kas Kedua',
                'control_opening_signed' => 0, 'journal_opening' => 0, 'opening_variance' => 0,
                'control_debit' => 100, 'journal_debit' => 935100, 'debit_variance' => -935000,
                'control_credit' => 0, 'journal_credit' => 0, 'credit_variance' => 0,
                'control_closing_signed' => 0, 'journal_closing' => 0, 'closing_variance' => 0,
                'source_rows' => [13],
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
        Schema::create('accounting_legacy_reconciliation_audits', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedInteger('business_id');
            $table->unsignedSmallInteger('year');
            $table->char('audit_key', 64);
            $table->char('source_manifest_sha256', 64)->nullable();
            $table->unsignedInteger('finding_count')->default(0);
            $table->dateTime('completed_at');
            $table->timestamps();
            $table->unique(['business_id', 'year', 'audit_key']);
            $table->index(['business_id', 'completed_at']);
        });
    }

    private function createPermissionTables(): void
    {
        $tables = config('permission.table_names');
        Schema::create($tables['permissions'], function (Blueprint $table) {
            $table->increments('id');
            $table->string('name');
            $table->string('guard_name');
            $table->timestamps();
        });
        Schema::create($tables['roles'], function (Blueprint $table) {
            $table->increments('id');
            $table->string('name');
            $table->string('guard_name');
            $table->unsignedInteger('business_id');
            $table->boolean('is_default')->default(false);
            $table->timestamps();
        });
        Schema::create($tables['model_has_permissions'], function (Blueprint $table) {
            $table->unsignedInteger('permission_id');
            $table->morphs('model');
            $table->primary(['permission_id', 'model_id', 'model_type']);
        });
        Schema::create($tables['model_has_roles'], function (Blueprint $table) {
            $table->unsignedInteger('role_id');
            $table->morphs('model');
            $table->primary(['role_id', 'model_id', 'model_type']);
        });
        Schema::create($tables['role_has_permissions'], function (Blueprint $table) {
            $table->unsignedInteger('permission_id');
            $table->unsignedInteger('role_id');
            $table->primary(['permission_id', 'role_id']);
        });
    }
}
