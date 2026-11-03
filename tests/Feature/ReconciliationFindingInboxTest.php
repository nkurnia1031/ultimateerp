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
            $table->unsignedInteger('business_id')->default(0);
            $table->string('type')->default('');
            $table->string('status')->default('final');
            $table->string('ref_no')->nullable();
            $table->string('invoice_no')->nullable();
            $table->dateTime('transaction_date')->nullable();
            $table->decimal('total_before_tax', 22, 4)->default(0);
            $table->unsignedInteger('tax_id')->nullable();
            $table->decimal('tax_amount', 22, 4)->default(0);
            $table->decimal('final_total', 22, 4)->default(0);
            $table->dateTime('deleted_at')->nullable();
            $table->dateTime('updated_at')->nullable();
        });
        Schema::create('tax_rates', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('business_id');
            $table->string('name');
            $table->decimal('amount', 8, 4);
            $table->boolean('is_tax_group')->default(false);
            $table->dateTime('deleted_at')->nullable();
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

    public function test_comparable_annual_audit_resolves_cleared_findings_and_reopens_returning_variances(): void
    {
        $service = app(LegacyReconciliationFindingSnapshotService::class);
        $report = $this->annualReport();
        $service->persistReport(1, $report);
        $findingKey = DB::table('accounting_legacy_reconciliation_findings')
            ->where('type', 'transaction')->where('component', 'base')->value('finding_key');

        $report['transaction_reconciliation'][0]['journal_base'] = 9065000;
        $report['transaction_reconciliation'][0]['base_variance'] = 0;
        $report['generated_at'] = '2026-11-04T10:00:00+07:00';
        $service->persistReport(1, $report);

        $cleared = DB::table('accounting_legacy_reconciliation_findings')->where('finding_key', $findingKey)->first();
        $this->assertFalse((bool) $cleared->is_current);
        $this->assertSame('resolved', $cleared->status);
        $this->assertSame('audit_cleared', json_decode($cleared->resolution_history, true)[0]['action']);
        $this->assertSame(1, $service->summary(1, ['status' => 'resolved'])['filtered_count']);

        $report['transaction_reconciliation'][0]['journal_base'] = 10000000;
        $report['transaction_reconciliation'][0]['base_variance'] = 935000;
        $report['generated_at'] = '2026-11-05T10:00:00+07:00';
        $service->persistReport(1, $report);

        $reopened = DB::table('accounting_legacy_reconciliation_findings')->where('finding_key', $findingKey)->first();
        $history = json_decode($reopened->resolution_history, true);
        $this->assertTrue((bool) $reopened->is_current);
        $this->assertSame('open', $reopened->status);
        $this->assertSame(['audit_cleared', 'reopened'], array_column($history, 'action'));
    }

    public function test_cash_bank_snapshot_is_idempotent_and_only_clears_comparable_periods(): void
    {
        $service = app(LegacyReconciliationFindingSnapshotService::class);
        $summary = [
            'opening_balance' => 101, 'source_opening_balance' => 100, 'opening_balance_difference' => 1,
            'closing_balance' => 301, 'source_closing_balance' => 300, 'closing_balance_difference' => 1,
        ];
        $row = [
            'date' => '2022-01-04', 'source_type' => 'Purchase Invoice', 'source_number' => 'PUR-50',
            'description' => 'Pembayaran pembelian', 'account_id' => 10, 'payment_account' => 'Kas Utama',
            'operational_amount' => 100, 'accounting_amount' => 98, 'difference' => 2,
            'source_vat' => 10, 'journal_vat' => 8, 'vat_difference' => 2, 'tax_direction' => 'input',
            'source_record_type' => 'App\\TransactionPayment', 'source_record_id' => 50,
        ];

        $this->assertSame(4, $service->persistCashBankAudit(1, '2022-01-01', '2022-01-31', null, $summary, [$row]));
        $this->assertSame(4, $service->persistCashBankAudit(1, '2022-01-01', '2022-01-31', null, $summary, [$row]));
        $this->assertSame(4, DB::table('accounting_legacy_reconciliation_findings')->where('type', 'cash_bank')->count());

        $service->persistCashBankAudit(1, '2022-02-01', '2022-02-28', null, $summary, [array_merge($row, [
            'date' => '2022-02-04', 'source_number' => 'PUR-51', 'source_record_id' => 51,
        ])]);
        $clearedSummary = [
            'opening_balance' => 100, 'source_opening_balance' => 100, 'opening_balance_difference' => 0,
            'closing_balance' => 300, 'source_closing_balance' => 300, 'closing_balance_difference' => 0,
        ];
        $clearedRow = array_merge($row, ['difference' => 0, 'accounting_amount' => 100, 'vat_difference' => 0, 'journal_vat' => 10]);
        $this->assertSame(0, $service->persistCashBankAudit(1, '2022-01-01', '2022-01-31', null, $clearedSummary, [$clearedRow]));

        $this->assertSame(4, DB::table('accounting_legacy_reconciliation_findings')->where('type', 'cash_bank')->where('is_current', true)->count());
        $this->assertSame(4, DB::table('accounting_legacy_reconciliation_findings')->where('type', 'cash_bank')->where('status', 'resolved')->count());
        $this->assertSame(8, DB::table('accounting_legacy_reconciliation_findings')->where('type', 'cash_bank')->count());
    }

    public function test_incomplete_cash_bank_mapping_does_not_clear_prior_balance_findings(): void
    {
        $service = app(LegacyReconciliationFindingSnapshotService::class);
        $summary = [
            'opening_balance' => 101, 'source_opening_balance' => 100, 'opening_balance_difference' => 1,
            'closing_balance' => 301, 'source_closing_balance' => 300, 'closing_balance_difference' => 1,
            'cash_bank_mapping_scope' => 'mapping-a',
        ];
        $service->persistCashBankAudit(1, '2022-03-01', '2022-03-31', null, $summary, []);

        $summary['opening_balance'] = 100;
        $summary['closing_balance'] = 300;
        $summary['opening_balance_difference'] = 0;
        $summary['closing_balance_difference'] = 0;
        $summary['cash_bank_balance_comparable'] = false;
        $service->persistCashBankAudit(1, '2022-03-01', '2022-03-31', null, $summary, []);

        $this->assertSame(2, DB::table('accounting_legacy_reconciliation_findings')
            ->where('type', 'cash_bank')->where('is_current', true)->where('scope_key', 'like', 'cash-bank-balance:2022-03-01:2022-03-31:all:%')->count());
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
            'business' => ['currency_precision' => 2, 'currency_symbol_placement' => 'before'],
            'currency' => [
                'code' => 'IDR', 'symbol' => 'Rp', 'symbol_placement' => 'before',
                'decimal_separator' => '.', 'thousand_separator' => ',',
            ],
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
        $this->assertSame(2, $payload['filtered_count']);

        $summaryRows = $this->renderedTableRows($payload['summary_html']);
        $findingRows = $this->renderedTableRows($payload['rows_html']);
        $summaryByComponent = [];
        foreach ($summaryRows as $row) {
            $summaryByComponent[$row[2]] = ['gross' => $row[4], 'net' => $row[5]];
        }
        $detailsByComponent = [];
        foreach ($findingRows as $row) {
            $component = null;
            foreach (['Basis', 'VAT Out'] as $candidate) {
                if (strpos($row[1], $candidate) !== false) {
                    $component = $candidate;
                    break;
                }
            }
            $this->assertNotNull($component);
            $this->assertArrayNotHasKey($component, $detailsByComponent);
            $detailsByComponent[$component] = array_slice($row, 4, 3);
        }

        $this->assertSame([
            'Basis' => ['gross' => 'Rp 935,000.00', 'net' => 'Rp 935,000.00'],
            'VAT Out' => ['gross' => 'Rp 935,000.00', 'net' => 'Rp -935,000.00'],
        ], $summaryByComponent);
        $this->assertSame([
            'Basis' => ['Rp 9,065,000.00', 'Rp 10,000,000.00', 'Rp 935,000.00'],
            'VAT Out' => ['Rp 1,870,000.00', 'Rp 935,000.00', 'Rp -935,000.00'],
        ], $detailsByComponent);
        $this->assertSame($detailsByComponent['Basis'][2], $summaryByComponent['Basis']['net']);
        $this->assertSame($detailsByComponent['VAT Out'][2], $summaryByComponent['VAT Out']['net']);
    }

    public function test_expense_vat_correction_requires_preview_confirms_idempotently_and_resolves_only_the_cleared_finding(): void
    {
        $this->withoutReconciliationPageBootstrapMiddleware();
        DB::table('tax_rates')->insert([
            'id' => 7, 'business_id' => 1, 'name' => 'PPN 10%', 'amount' => 10, 'is_tax_group' => false,
        ]);
        DB::table('transactions')->insert([
            'id' => 101, 'business_id' => 1, 'type' => 'expense', 'status' => 'final',
            'ref_no' => 'EXP-2023-1', 'transaction_date' => '2023-01-02 09:00:00',
            'total_before_tax' => 1000, 'tax_id' => null, 'tax_amount' => 0, 'final_total' => 1000,
        ]);
        $findingId = $this->insertActionableExpenseVatFinding();
        $user = $this->accountingManagerUser();

        $preview = $this->actingAs($user)->withSession(['user' => ['business_id' => 1]])
            ->withHeaders(['Accept' => 'application/json'])
            ->post(route('accounting.reconciliation.findings.correctionPreview', ['findingId' => $findingId]));

        $preview->assertOk()->assertJsonPath('supported', true)
            ->assertJsonPath('proposal.before.tax_amount', 0)
            ->assertJsonPath('proposal.after.tax_amount', 100)
            ->assertJsonPath('proposal.after.final_total', 1100);
        $fingerprint = $preview->json('proposal.fingerprint');
        $this->assertSame(0.0, (float) DB::table('transactions')->where('id', 101)->value('tax_amount'));

        $confirm = $this->withHeaders(['Accept' => 'application/json'])->post(
            route('accounting.reconciliation.findings.correctionConfirm', ['findingId' => $findingId]),
            ['fingerprint' => $fingerprint]
        );

        $confirm->assertOk()->assertJsonPath('status', 'resolved');
        $this->assertSame(7, (int) DB::table('transactions')->where('id', 101)->value('tax_id'));
        $this->assertSame(100.0, (float) DB::table('transactions')->where('id', 101)->value('tax_amount'));
        $this->assertSame(1100.0, (float) DB::table('transactions')->where('id', 101)->value('final_total'));
        $this->assertSame('resolved', DB::table('accounting_legacy_reconciliation_findings')->where('id', $findingId)->value('status'));
        $this->assertFalse((bool) DB::table('accounting_legacy_reconciliation_findings')->where('id', $findingId)->value('is_current'));
        $this->assertSame(67.89, (float) DB::table('accounting_accounts_transactions')->where('id', 1)->value('amount'));

        $retry = $this->withHeaders(['Accept' => 'application/json'])->post(
            route('accounting.reconciliation.findings.correctionConfirm', ['findingId' => $findingId]),
            ['fingerprint' => $fingerprint]
        );
        $retry->assertOk()->assertJsonPath('status', 'resolved')->assertJsonPath('idempotent', true);
        $history = json_decode(DB::table('accounting_legacy_reconciliation_findings')->where('id', $findingId)->value('resolution_history'), true);
        $this->assertCount(1, $history);
        $this->assertSame('operational_tax_corrected', $history[0]['action']);
        $this->assertSame(987654, (int) $history[0]['actor_id']);
        $this->assertSame(0.0, (float) $history[0]['before']['tax_amount']);
        $this->assertSame(100.0, (float) $history[0]['after']['tax_amount']);
    }

    public function test_expense_vat_correction_rejects_ambiguous_sources_and_accept_requires_a_reason(): void
    {
        $this->withoutReconciliationPageBootstrapMiddleware();
        DB::table('tax_rates')->insert([
            'id' => 7, 'business_id' => 1, 'name' => 'PPN 10%', 'amount' => 10, 'is_tax_group' => false,
        ]);
        DB::table('transactions')->insert([
            ['id' => 101, 'business_id' => 1, 'type' => 'expense', 'status' => 'final', 'ref_no' => 'EXP-2023-1', 'transaction_date' => '2023-01-02 09:00:00', 'total_before_tax' => 1000, 'tax_id' => null, 'tax_amount' => 0, 'final_total' => 1000],
            ['id' => 102, 'business_id' => 1, 'type' => 'expense', 'status' => 'final', 'ref_no' => 'EXP-2023-1', 'transaction_date' => '2023-01-02 10:00:00', 'total_before_tax' => 1000, 'tax_id' => null, 'tax_amount' => 0, 'final_total' => 1000],
        ]);
        $findingId = $this->insertActionableExpenseVatFinding();
        $user = $this->accountingManagerUser();

        $preview = $this->actingAs($user)->withSession(['user' => ['business_id' => 1]])
            ->withHeaders(['Accept' => 'application/json'])
            ->post(route('accounting.reconciliation.findings.correctionPreview', ['findingId' => $findingId]));
        $preview->assertOk()->assertJsonPath('supported', false);
        $this->assertStringContainsString('unik', strtolower($preview->json('reason')));

        $missingReason = $this->withHeaders(['Accept' => 'application/json'])->post(
            route('accounting.reconciliation.findings.accept', ['findingId' => $findingId]),
            ['reason' => '   ']
        );
        $missingReason->assertStatus(422)->assertJsonValidationErrors('reason');

        $accepted = $this->withHeaders(['Accept' => 'application/json'])->post(
            route('accounting.reconciliation.findings.accept', ['findingId' => $findingId]),
            ['reason' => 'Diperiksa dan selisih sesuai dokumen pendukung.']
        );
        $accepted->assertOk()->assertJsonPath('status', 'accepted');
        $this->assertSame('accepted', DB::table('accounting_legacy_reconciliation_findings')->where('id', $findingId)->value('status'));
        $this->assertSame(0, app(LegacyReconciliationFindingSnapshotService::class)->summary(1)['open_count']);
        $history = json_decode(DB::table('accounting_legacy_reconciliation_findings')->where('id', $findingId)->value('resolution_history'), true);
        $this->assertSame('accepted', $history[0]['action']);
        $this->assertSame(987654, (int) $history[0]['actor_id']);
        $this->assertSame('Diperiksa dan selisih sesuai dokumen pendukung.', $history[0]['reason']);
        $this->assertNotEmpty($history[0]['at']);
    }

    public function test_finding_actions_enforce_active_business_and_accounting_management_permission(): void
    {
        $this->withoutReconciliationPageBootstrapMiddleware();
        $findingId = (int) DB::table('accounting_legacy_reconciliation_findings')->insertGetId($this->findingRow([
            'business_id' => 2, 'finding_key' => hash('sha256', 'other-business-finding'),
        ]));
        $manager = $this->accountingManagerUser();

        $crossBusiness = $this->actingAs($manager)->withSession(['user' => ['business_id' => 1]])
            ->withHeaders(['Accept' => 'application/json'])
            ->post(route('accounting.reconciliation.findings.accept', ['findingId' => $findingId]), [
                'reason' => 'Sumber sah.',
            ]);
        $crossBusiness->assertNotFound();

        $reader = Mockery::mock(User::class)->makePartial();
        $reader->forceFill(['id' => 987655, 'business_id' => 1, 'username' => 'reports-only']);
        $reader->shouldReceive('can')->andReturnUsing(fn (string $permission) => $permission === 'accounting.view_reports');
        $forbidden = $this->actingAs($reader)->withSession(['user' => ['business_id' => 1]])
            ->withHeaders(['Accept' => 'application/json'])
            ->post(route('accounting.reconciliation.findings.accept', ['findingId' => $findingId]), [
                'reason' => 'Sumber sah.',
            ]);
        $forbidden->assertForbidden();
        $this->assertSame('open', DB::table('accounting_legacy_reconciliation_findings')->where('id', $findingId)->value('status'));
    }

    private function insertActionableExpenseVatFinding(): int
    {
        $evidence = [
            'date' => '2023-01-02', 'source_type' => 'Expense', 'source_number' => 'EXP-2023-1',
            'match_status' => 'matched', 'operational_source_count' => 1, 'has_operational_source' => true,
            'operational_identity' => 'Expense|EXP-2023-1|2023-01-02',
            'operational_base' => 1000, 'operational_vat_in' => 0, 'operational_vat_out' => 0,
            'operational_total' => 1000, 'journal_base' => 1000, 'journal_vat_in' => 100,
            'journal_vat_out' => 0, 'journal_other_tax' => 0, 'journal_total' => 1100,
        ];
        DB::table('accounting_legacy_reconciliation_findings')->insert($this->findingRow([
            'type' => 'transaction', 'component' => 'vat_in', 'finding_key' => hash('sha256', 'actionable-vat'),
            'source_type' => 'Expense', 'source_number' => 'EXP-2023-1', 'source_date' => '2023-01-02',
            'expected_amount' => 0, 'actual_amount' => 100, 'variance' => 100,
            'evidence' => json_encode($evidence),
        ]));

        return (int) DB::table('accounting_legacy_reconciliation_findings')->where('finding_key', hash('sha256', 'actionable-vat'))->value('id');
    }

    private function accountingManagerUser(): User
    {
        $user = Mockery::mock(User::class)->makePartial();
        $user->forceFill(['id' => 987654, 'business_id' => 1, 'username' => 'reconciliation-test']);
        $user->shouldReceive('can')->andReturnUsing(fn (string $permission) => in_array($permission, [
            'accounting.view_reports', 'accounting.manage_accounts', 'expense.edit',
        ], true));
        $this->app->instance(\App\Utils\ModuleUtil::class, Mockery::mock(\App\Utils\ModuleUtil::class, function ($mock): void {
            $mock->shouldReceive('hasThePermissionInSubscription')->andReturn(true);
        }));

        return $user;
    }

    private function withoutReconciliationPageBootstrapMiddleware(): void
    {
        $this->withoutMiddleware([
            \App\Http\Middleware\SetSessionData::class,
            \App\Http\Middleware\Language::class,
            \App\Http\Middleware\Timezone::class,
            \App\Http\Middleware\AdminSidebarMenu::class,
        ]);
    }

    private function renderedTableRows(string $html): array
    {
        preg_match_all('/<tr\\b[^>]*>(.*?)<\\/tr>/si', $html, $rowMatches);

        return array_map(function (string $rowHtml): array {
            preg_match_all('/<td\\b[^>]*>(.*?)<\\/td>/si', $rowHtml, $cellMatches);

            return array_map(function (string $cellHtml): string {
                $text = html_entity_decode(strip_tags($cellHtml), ENT_QUOTES | ENT_HTML5, 'UTF-8');

                return trim(preg_replace('/\\s+/u', ' ', $text));
            }, $cellMatches[1]);
        }, $rowMatches[1]);
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
            'business_id' => 1, 'year' => 2023, 'scope_key' => 'legacy-annual', 'type' => 'transaction', 'component' => 'base',
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
            $table->string('scope_key', 120)->default('legacy-annual');
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
            $table->string('scope_key', 120)->default('legacy-annual');
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
