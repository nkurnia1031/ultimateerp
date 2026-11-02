<?php

namespace Tests\Feature;

use App\AccountTransaction;
use App\TransactionPayment;
use App\User;
use App\Utils\ModuleUtil;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Accounting\Services\GeneralLedgerAuditService;
use Mockery;
use Tests\TestCase;

class GeneralLedgerAuditTest extends TestCase
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

        $this->createSchema();
        $this->seedAuditData();
    }

    public function test_service_matches_unique_payment_links_reports_anomalies_and_reconciles_vat_and_balances(): void
    {
        $report = app(GeneralLedgerAuditService::class)->report(1, '2022-01-01', '2022-01-31');
        $rows = collect($report['rows']->items())->keyBy('source_record_id');

        $this->assertSame('matched', $rows->get(1)['status']);
        $this->assertSame(10.0, $rows->get(1)['source_vat']);
        $this->assertSame(10.0, $rows->get(1)['journal_vat']);
        $this->assertSame('missing_posting', $rows->get(2)['status']);
        $this->assertSame('invalid_amount', $rows->get(3)['status']);
        $this->assertSame(50.0, $report['summary']['opening_balance']);
        $this->assertSame(50.0, $report['summary']['source_opening_balance']);
        $this->assertSame(150.0, $report['summary']['closing_balance']);
        $this->assertSame(130.0, $report['summary']['source_closing_balance']);
        $this->assertSame(1, $report['summary']['missing_posting_count']);
        $this->assertSame(10.0, $report['summary']['journal_vat_in']);
    }

    public function test_service_preserves_imported_source_labels_and_uses_transaction_type_for_vat_totals(): void
    {
        DB::table('account_transactions')->where('id', 1)->update(['journal_import_row_id' => 1]);

        $report = app(GeneralLedgerAuditService::class)->report(1, '2022-01-01', '2022-01-31');
        $row = collect($report['rows']->items())->firstWhere('source_record_id', 1);

        $this->assertSame('Purchase Invoice', $row['source_type']);
        $this->assertSame('Pembelian', $row['category']);
        $this->assertSame('Pembelian Perlengkapan', $row['detail_category']);
        $this->assertSame(10.0, $report['summary']['source_vat_in']);
    }

    public function test_service_flags_ambiguous_fallbacks_and_postings_without_operational_sources(): void
    {
        DB::table('account_transactions')->insert([
            'id' => 5, 'account_id' => 10, 'type' => 'credit', 'sub_type' => 'deposit', 'amount' => 10,
            'reff_no' => 'AMB-10', 'operation_date' => '2022-01-07 10:00:00', 'created_by' => 1,
            'transaction_id' => null, 'transaction_payment_id' => null, 'transfer_transaction_id' => null,
            'note' => 'Fallback ambigu', 'deleted_at' => null,
        ]);
        DB::table('accounting_acc_trans_mappings')->insert([
            ['id' => 504, 'business_id' => 1, 'journal_import_id' => null, 'import_source_key' => null, 'ref_no' => 'AMB-10', 'type' => 'journal_entry', 'note' => 'Kandidat A', 'source_record_type' => null, 'source_record_id' => null],
            ['id' => 505, 'business_id' => 1, 'journal_import_id' => null, 'import_source_key' => null, 'ref_no' => 'AMB-10', 'type' => 'journal_entry', 'note' => 'Kandidat B', 'source_record_type' => null, 'source_record_id' => null],
            ['id' => 506, 'business_id' => 1, 'journal_import_id' => null, 'import_source_key' => null, 'ref_no' => 'NO-SOURCE', 'type' => 'journal_entry', 'note' => 'Tanpa sumber', 'source_record_type' => null, 'source_record_id' => null],
        ]);
        DB::table('accounting_accounts_transactions')->insert([
            ['id' => 8, 'accounting_account_id' => 1, 'acc_trans_mapping_id' => 504, 'amount' => 10, 'type' => 'debit', 'sub_type' => 'journal_entry', 'operation_date' => '2022-01-07 10:00:00', 'note' => 'Kandidat A'],
            ['id' => 9, 'accounting_account_id' => 1, 'acc_trans_mapping_id' => 505, 'amount' => 10, 'type' => 'debit', 'sub_type' => 'journal_entry', 'operation_date' => '2022-01-07 10:00:00', 'note' => 'Kandidat B'],
            ['id' => 10, 'accounting_account_id' => 1, 'acc_trans_mapping_id' => 506, 'amount' => 5, 'type' => 'debit', 'sub_type' => 'journal_entry', 'operation_date' => '2022-01-08 10:00:00', 'note' => 'Tanpa sumber'],
        ]);

        $rows = collect(app(GeneralLedgerAuditService::class)->exportRows(1, '2022-01-01', '2022-01-31'));

        $this->assertSame('ambiguous_match', $rows->firstWhere('source_record_id', 5)['status']);
        $this->assertSame(1, $rows->where('status', 'missing_source')->count());
    }

    public function test_service_distinguishes_vat_difference_from_a_matched_cash_movement(): void
    {
        DB::table('accounting_accounts_transactions')->where('id', 2)->update(['amount' => 12]);

        $rows = collect(app(GeneralLedgerAuditService::class)->exportRows(1, '2022-01-01', '2022-01-31'));
        $purchase = $rows->firstWhere('source_record_id', 1);

        $this->assertSame('vat_difference', $purchase['status']);
        $this->assertSame(-2.0, $purchase['vat_difference']);
    }

    public function test_service_flags_duplicate_and_conflicting_overlapping_journal_source_keys(): void
    {
        $this->addOverlappingPosting(502, 2, 'identical');
        $duplicate = app(GeneralLedgerAuditService::class)->exportRows(1, '2022-01-01', '2022-01-31');
        $paymentRow = collect($duplicate)->firstWhere('source_record_id', 1);
        $this->assertSame('duplicate_journal_import', $paymentRow['status']);

        DB::table('accounting_accounts_transactions')->insert([
            ['id' => 5, 'accounting_account_id' => 1, 'acc_trans_mapping_id' => 503, 'transaction_id' => 100, 'transaction_payment_id' => 50, 'amount' => 100, 'type' => 'debit', 'sub_type' => 'journal_entry', 'operation_date' => '2022-01-04 11:00:00', 'note' => 'Posting konflik'],
        ]);
        DB::table('accounting_acc_trans_mappings')->insert([
            'id' => 503, 'business_id' => 1, 'journal_import_id' => 3, 'import_source_key' => 'Purchase Invoice|PUR-50|2022-01-04',
            'ref_no' => 'PUR-50', 'type' => 'journal_entry', 'note' => 'Posting konflik',
            'source_record_type' => TransactionPayment::class, 'source_record_id' => 50,
        ]);
        DB::table('accounting_accounts_transactions')->insert([
            ['id' => 6, 'accounting_account_id' => 3, 'acc_trans_mapping_id' => 503, 'amount' => 11, 'type' => 'debit', 'sub_type' => 'journal_entry', 'operation_date' => '2022-01-04 11:00:00', 'note' => 'VAT In'],
            ['id' => 7, 'accounting_account_id' => 4, 'acc_trans_mapping_id' => 503, 'amount' => 111, 'type' => 'credit', 'sub_type' => 'journal_entry', 'operation_date' => '2022-01-04 11:00:00', 'note' => 'Trade Payable'],
        ]);
        DB::table('accounting_journal_import_rows')->insert([
            'id' => 3, 'journal_import_id' => 3, 'journal_mapping_id' => 503, 'business_id' => 1,
            'source_type' => 'Purchase Invoice', 'source_number' => 'PUR-50', 'source_key' => 'Purchase Invoice|PUR-50|2022-01-04',
            'entry_data' => json_encode(['source_type' => 'Purchase Invoice', 'source_number' => 'PUR-50', 'date' => '2022-01-04', 'lines' => [
                ['account_no' => '1200', 'account_name' => 'VAT In', 'description' => 'Pembelian Perlengkapan', 'debit' => 11, 'credit' => 0],
            ]]),
        ]);
        DB::table('accounting_journal_imports')->insert(['id' => 3, 'business_id' => 1, 'status' => 'imported']);

        $conflict = app(GeneralLedgerAuditService::class)->exportRows(1, '2022-01-01', '2022-01-31');
        $paymentRow = collect($conflict)->firstWhere('source_record_id', 1);
        $this->assertSame('conflicting_journal_import', $paymentRow['status']);
    }

    public function test_audit_endpoint_filters_by_period_and_is_read_only_and_business_scoped(): void
    {
        DB::table('accounts')->insert(['id' => 20, 'business_id' => 2, 'name' => 'Tenant Two', 'deleted_at' => null]);
        DB::table('account_transactions')->insert([
            'id' => 99, 'account_id' => 20, 'type' => 'credit', 'sub_type' => 'deposit', 'amount' => 999,
            'reff_no' => 'TENANT-SECRET', 'operation_date' => '2022-01-04 10:00:00', 'created_by' => 1,
            'transaction_id' => null, 'transaction_payment_id' => null, 'transfer_transaction_id' => null,
            'note' => 'TENANT-SECRET', 'deleted_at' => null,
        ]);
        $movementCount = DB::table('account_transactions')->count();
        $postingCount = DB::table('accounting_accounts_transactions')->count();

        DB::table(config('permission.table_names.permissions'))->insert([
            'id' => 1, 'name' => 'accounting.view_reports', 'guard_name' => 'web', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table(config('permission.table_names.model_has_permissions'))->insert([
            'permission_id' => 1, 'model_type' => User::class, 'model_id' => 1,
        ]);
        $user = new User();
        $user->forceFill(['id' => 1, 'business_id' => 1, 'username' => 'audit-test']);
        $user->setRelation('notifications', collect());
        $moduleUtil = Mockery::mock(ModuleUtil::class);
        $moduleUtil->shouldReceive('hasThePermissionInSubscription')->andReturn(true);
        $moduleUtil->shouldReceive('isModuleInstalled')->andReturn(false);
        $this->app->instance(ModuleUtil::class, $moduleUtil);
        $response = $this->withoutMiddleware([
            \App\Http\Middleware\SetSessionData::class,
            \App\Http\Middleware\Language::class,
            \App\Http\Middleware\Timezone::class,
            \App\Http\Middleware\AdminSidebarMenu::class,
        ])->actingAs($user)->withSession([
            'user' => ['business_id' => 1],
            'business' => ['name' => 'CV Demo', 'currency_precision' => 2],
            'currency' => ['code' => 'IDR', 'symbol' => 'Rp', 'symbol_placement' => 'before', 'decimal_separator' => '.', 'thousand_separator' => ','],
        ])->get(route('accounting.generalLedger.audit', [
            'start_date' => '2022-01-01', 'end_date' => '2022-01-31', 'source_type' => 'purchase', 'search' => 'PUR-50',
        ]));

        $response->assertOk();
        $response->assertSee('PUR-50');
        $response->assertDontSee('TENANT-SECRET');
        $this->assertSame($movementCount, DB::table('account_transactions')->count());
        $this->assertSame($postingCount, DB::table('accounting_accounts_transactions')->count());

        $export = $this->get(route('accounting.generalLedger.audit.export', [
            'start_date' => '2022-01-01', 'end_date' => '2022-01-31', 'source_type' => 'purchase', 'search' => 'PUR-50',
        ]));
        $export->assertOk();
        $export->assertHeader('content-disposition', 'attachment; filename=audit-buku-besar-2022-01-01-2022-01-31.csv');
        $this->assertSame($movementCount, DB::table('account_transactions')->count());
        $this->assertSame($postingCount, DB::table('accounting_accounts_transactions')->count());

        $emptyPeriod = $this->get(route('accounting.generalLedger.audit', [
            'start_date' => '2022-02-01', 'end_date' => '2022-02-28', 'search' => 'PUR-50',
        ]));
        $emptyPeriod->assertOk();
        $emptyPeriod->assertSee('Tidak ada baris audit pada filter ini.');
    }

    private function createSchema(): void
    {
        $permissionTables = config('permission.table_names');
        Schema::create($permissionTables['permissions'], function (Blueprint $table) {
            $table->increments('id'); $table->string('name'); $table->string('guard_name'); $table->timestamps();
        });
        Schema::create($permissionTables['roles'], function (Blueprint $table) {
            $table->increments('id'); $table->string('name'); $table->string('guard_name');
            $table->unsignedInteger('business_id'); $table->boolean('is_default')->default(false); $table->timestamps();
        });
        Schema::create($permissionTables['model_has_permissions'], function (Blueprint $table) {
            $table->unsignedInteger('permission_id'); $table->morphs('model');
            $table->primary(['permission_id', 'model_id', 'model_type']);
        });
        Schema::create($permissionTables['model_has_roles'], function (Blueprint $table) {
            $table->unsignedInteger('role_id'); $table->morphs('model');
            $table->primary(['role_id', 'model_id', 'model_type']);
        });
        Schema::create($permissionTables['role_has_permissions'], function (Blueprint $table) {
            $table->unsignedInteger('permission_id'); $table->unsignedInteger('role_id');
            $table->primary(['permission_id', 'role_id']);
        });
        Schema::create('system', function (Blueprint $table) {
            $table->string('key'); $table->text('value')->nullable();
        });
        Schema::create('accounts', function (Blueprint $table) {
            $table->increments('id'); $table->unsignedInteger('business_id'); $table->string('name'); $table->softDeletes();
        });
        Schema::create('account_transactions', function (Blueprint $table) {
            $table->increments('id'); $table->unsignedInteger('account_id'); $table->string('type'); $table->string('sub_type')->nullable();
            $table->decimal('amount', 22, 4); $table->string('reff_no')->nullable(); $table->dateTime('operation_date');
            $table->unsignedInteger('created_by')->nullable(); $table->unsignedInteger('transaction_id')->nullable();
            $table->unsignedInteger('transaction_payment_id')->nullable(); $table->unsignedInteger('transfer_transaction_id')->nullable();
            $table->text('note')->nullable(); $table->softDeletes(); $table->unsignedBigInteger('journal_import_id')->nullable();
            $table->unsignedBigInteger('journal_import_row_id')->nullable();
        });
        Schema::create('transactions', function (Blueprint $table) {
            $table->increments('id'); $table->unsignedInteger('business_id'); $table->string('type'); $table->string('status');
            $table->decimal('final_total', 22, 4)->default(0); $table->decimal('tax_amount', 22, 4)->default(0);
            $table->string('ref_no')->nullable(); $table->string('invoice_no')->nullable(); $table->softDeletes();
            $table->boolean('is_cancelled')->default(false); $table->unsignedInteger('expense_category_id')->nullable();
            $table->unsignedInteger('contact_id')->nullable();
        });
        Schema::create('transaction_payments', function (Blueprint $table) {
            $table->increments('id'); $table->unsignedInteger('transaction_id')->nullable(); $table->unsignedInteger('business_id')->nullable();
            $table->unsignedInteger('account_id')->nullable(); $table->decimal('amount', 22, 4)->default(0); $table->dateTime('paid_on');
            $table->string('payment_ref_no')->nullable(); $table->string('payment_type')->nullable(); $table->boolean('is_return')->default(false);
            $table->string('method')->nullable();
        });
        Schema::create('accounting_accounts', function (Blueprint $table) {
            $table->bigIncrements('id'); $table->string('name'); $table->string('gl_code')->nullable();
            $table->unsignedInteger('business_id'); $table->string('account_primary_type')->nullable();
            $table->unsignedInteger('account_id')->nullable();
        });
        Schema::create('accounting_general_ledger_cash_bank_mappings', function (Blueprint $table) {
            $table->bigIncrements('id'); $table->unsignedInteger('business_id'); $table->unsignedInteger('accounting_account_id');
            $table->string('classification', 20); $table->unsignedInteger('created_by')->nullable();
            $table->unsignedInteger('updated_by')->nullable(); $table->timestamps();
        });
        Schema::create('accounting_acc_trans_mappings', function (Blueprint $table) {
            $table->bigIncrements('id'); $table->unsignedInteger('business_id'); $table->unsignedBigInteger('journal_import_id')->nullable();
            $table->string('import_source_key')->nullable(); $table->string('ref_no')->nullable(); $table->string('type')->nullable();
            $table->text('note')->nullable(); $table->string('source_record_type')->nullable(); $table->unsignedBigInteger('source_record_id')->nullable();
        });
        Schema::create('accounting_accounts_transactions', function (Blueprint $table) {
            $table->bigIncrements('id'); $table->unsignedBigInteger('accounting_account_id'); $table->unsignedBigInteger('acc_trans_mapping_id')->nullable();
            $table->unsignedInteger('transaction_id')->nullable(); $table->unsignedInteger('transaction_payment_id')->nullable();
            $table->decimal('amount', 22, 4); $table->string('type'); $table->string('sub_type')->nullable();
            $table->dateTime('operation_date'); $table->text('note')->nullable(); $table->unsignedBigInteger('journal_import_id')->nullable();
        });
        Schema::create('accounting_legacy_reconciliation_findings', function (Blueprint $table) {
            $table->bigIncrements('id'); $table->unsignedInteger('business_id'); $table->string('status'); $table->boolean('is_current');
        });
        Schema::create('accounting_journal_imports', function (Blueprint $table) {
            $table->bigIncrements('id'); $table->unsignedInteger('business_id'); $table->string('status');
        });
        Schema::create('accounting_journal_import_rows', function (Blueprint $table) {
            $table->bigIncrements('id'); $table->unsignedBigInteger('journal_import_id'); $table->unsignedBigInteger('journal_mapping_id')->nullable();
            $table->unsignedInteger('business_id'); $table->string('source_type'); $table->string('source_number')->nullable();
            $table->string('source_key')->nullable(); $table->longText('entry_data');
        });
    }

    private function seedAuditData(): void
    {
        DB::table('accounts')->insert(['id' => 10, 'business_id' => 1, 'name' => 'Kas Utama', 'deleted_at' => null]);
        DB::table('accounting_accounts')->insert([
            ['id' => 1, 'name' => 'Kas Utama CoA', 'gl_code' => '1000', 'business_id' => 1, 'account_primary_type' => 'asset', 'account_id' => 10],
            ['id' => 2, 'name' => 'Purchases', 'gl_code' => '5000', 'business_id' => 1, 'account_primary_type' => 'expense', 'account_id' => null],
            ['id' => 3, 'name' => 'VAT In', 'gl_code' => '1200', 'business_id' => 1, 'account_primary_type' => 'asset', 'account_id' => null],
            ['id' => 4, 'name' => 'Trade Payable', 'gl_code' => '2000', 'business_id' => 1, 'account_primary_type' => 'liability', 'account_id' => null],
            ['id' => 5, 'name' => 'Kas Tenant Lain', 'gl_code' => '1001', 'business_id' => 2, 'account_primary_type' => 'asset', 'account_id' => 20],
        ]);
        DB::table('accounting_general_ledger_cash_bank_mappings')->insert([
            'id' => 1, 'business_id' => 1, 'accounting_account_id' => 1, 'classification' => 'cash', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('accounting_acc_trans_mappings')->insert([
            'id' => 501, 'business_id' => 1, 'journal_import_id' => 1, 'import_source_key' => 'Purchase Invoice|PUR-50|2022-01-04',
            'ref_no' => 'PUR-50', 'type' => 'journal_entry', 'note' => 'Pembelian',
            'source_record_type' => TransactionPayment::class, 'source_record_id' => 50,
        ]);
        DB::table('accounting_journal_imports')->insert(['id' => 1, 'business_id' => 1, 'status' => 'imported']);
        DB::table('accounting_journal_import_rows')->insert([
            'id' => 1, 'journal_import_id' => 1, 'journal_mapping_id' => 501, 'business_id' => 1,
            'source_type' => 'Purchase Invoice', 'source_number' => 'PUR-50', 'source_key' => 'Purchase Invoice|PUR-50|2022-01-04',
            'entry_data' => json_encode(['source_type' => 'Purchase Invoice', 'source_number' => 'PUR-50', 'date' => '2022-01-04', 'lines' => [
                ['account_no' => '1200', 'account_name' => 'VAT In', 'description' => 'Pembelian Perlengkapan', 'debit' => 10, 'credit' => 0],
            ]]),
        ]);
        DB::table('transactions')->insert([
            ['id' => 100, 'business_id' => 1, 'type' => 'purchase', 'status' => 'final', 'final_total' => 100, 'tax_amount' => 10, 'ref_no' => 'PUR-50', 'is_cancelled' => false],
            ['id' => 101, 'business_id' => 1, 'type' => 'expense', 'status' => 'final', 'final_total' => 20, 'tax_amount' => 0, 'ref_no' => 'EXP-51', 'is_cancelled' => false],
            ['id' => 102, 'business_id' => 1, 'type' => 'expense', 'status' => 'final', 'final_total' => 5, 'tax_amount' => 0, 'ref_no' => 'EXP-52', 'is_cancelled' => false],
        ]);
        DB::table('transaction_payments')->insert([
            'id' => 50, 'transaction_id' => 100, 'business_id' => 1, 'account_id' => 10, 'amount' => 100,
            'paid_on' => '2022-01-04 10:00:00', 'payment_ref_no' => 'PUR-50', 'payment_type' => 'credit', 'is_return' => false,
        ]);
        DB::table('account_transactions')->insert([
            ['id' => 1, 'account_id' => 10, 'type' => 'credit', 'sub_type' => 'deposit', 'amount' => 100, 'reff_no' => 'PUR-50', 'operation_date' => '2022-01-04 10:00:00', 'created_by' => 1, 'transaction_id' => 100, 'transaction_payment_id' => 50, 'note' => 'Pembayaran PUR-50', 'deleted_at' => null],
            ['id' => 2, 'account_id' => 10, 'type' => 'debit', 'sub_type' => 'deposit', 'amount' => 20, 'reff_no' => 'EXP-51', 'operation_date' => '2022-01-05 10:00:00', 'created_by' => 1, 'transaction_id' => 101, 'transaction_payment_id' => null, 'note' => 'Biaya belum diposting', 'deleted_at' => null],
            ['id' => 3, 'account_id' => 10, 'type' => 'credit', 'sub_type' => 'deposit', 'amount' => -5, 'reff_no' => 'EXP-52', 'operation_date' => '2022-01-06 10:00:00', 'created_by' => 1, 'transaction_id' => 102, 'transaction_payment_id' => null, 'note' => 'Nominal negatif', 'deleted_at' => null],
            ['id' => 4, 'account_id' => 10, 'type' => 'credit', 'sub_type' => 'opening_balance', 'amount' => 50, 'reff_no' => 'OPEN', 'operation_date' => '2021-12-31 10:00:00', 'created_by' => 1, 'transaction_id' => null, 'transaction_payment_id' => null, 'note' => 'Saldo awal', 'deleted_at' => null],
        ]);
        DB::table('accounting_accounts_transactions')->insert([
            ['id' => 1, 'accounting_account_id' => 1, 'acc_trans_mapping_id' => 501, 'transaction_id' => 100, 'transaction_payment_id' => 50, 'amount' => 100, 'type' => 'debit', 'sub_type' => 'journal_entry', 'operation_date' => '2022-01-04 10:00:00', 'note' => 'Kas PUR-50'],
            ['id' => 2, 'accounting_account_id' => 3, 'acc_trans_mapping_id' => 501, 'transaction_id' => null, 'transaction_payment_id' => null, 'amount' => 10, 'type' => 'debit', 'sub_type' => 'journal_entry', 'operation_date' => '2022-01-04 10:00:00', 'note' => 'VAT In'],
            ['id' => 3, 'accounting_account_id' => 4, 'acc_trans_mapping_id' => 501, 'transaction_id' => null, 'transaction_payment_id' => null, 'amount' => 110, 'type' => 'credit', 'sub_type' => 'journal_entry', 'operation_date' => '2022-01-04 10:00:00', 'note' => 'Trade Payable'],
            ['id' => 4, 'accounting_account_id' => 1, 'acc_trans_mapping_id' => null, 'transaction_id' => null, 'transaction_payment_id' => null, 'amount' => 50, 'type' => 'debit', 'sub_type' => 'opening_balance', 'operation_date' => '2021-12-31 10:00:00', 'note' => 'Saldo awal'],
        ]);
    }

    private function addOverlappingPosting(int $mappingId, int $importId, string $identity): void
    {
        DB::table('accounting_acc_trans_mappings')->insert([
            'id' => $mappingId, 'business_id' => 1, 'journal_import_id' => $importId,
            'import_source_key' => 'Purchase Invoice|PUR-50|2022-01-04', 'ref_no' => 'PUR-50', 'type' => 'journal_entry',
            'note' => 'Overlap', 'source_record_type' => TransactionPayment::class, 'source_record_id' => 50,
        ]);
        DB::table('accounting_journal_imports')->insert(['id' => $importId, 'business_id' => 1, 'status' => 'imported']);
        DB::table('accounting_journal_import_rows')->insert([
            'id' => $importId, 'journal_import_id' => $importId, 'journal_mapping_id' => $mappingId, 'business_id' => 1,
            'source_type' => 'Purchase Invoice', 'source_number' => 'PUR-50', 'source_key' => 'Purchase Invoice|PUR-50|2022-01-04',
            'entry_data' => json_encode(['source_type' => 'Purchase Invoice', 'source_number' => 'PUR-50', 'date' => '2022-01-04', 'lines' => [
                ['account_no' => '1200', 'account_name' => 'VAT In', 'description' => 'Pembelian Perlengkapan'.($identity === 'different' ? ' Berbeda' : ''), 'debit' => 10, 'credit' => 0],
            ]]),
        ]);
        DB::table('accounting_accounts_transactions')->insert([
            ['id' => $mappingId * 10, 'accounting_account_id' => 1, 'acc_trans_mapping_id' => $mappingId, 'transaction_id' => 100, 'transaction_payment_id' => 50, 'amount' => 100, 'type' => 'debit', 'sub_type' => 'journal_entry', 'operation_date' => '2022-01-04 11:00:00', 'note' => 'Overlap kas'],
            ['id' => $mappingId * 10 + 1, 'accounting_account_id' => 3, 'acc_trans_mapping_id' => $mappingId, 'transaction_id' => null, 'transaction_payment_id' => null, 'amount' => 10, 'type' => 'debit', 'sub_type' => 'journal_entry', 'operation_date' => '2022-01-04 11:00:00', 'note' => 'VAT In'],
            ['id' => $mappingId * 10 + 2, 'accounting_account_id' => 4, 'acc_trans_mapping_id' => $mappingId, 'transaction_id' => null, 'transaction_payment_id' => null, 'amount' => 110, 'type' => 'credit', 'sub_type' => 'journal_entry', 'operation_date' => '2022-01-04 11:00:00', 'note' => 'Trade Payable'],
        ]);
    }
}
