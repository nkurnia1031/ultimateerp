<?php

namespace Tests\Feature;

use App\User;
use App\Utils\ModuleUtil;
use App\Utils\TransactionUtil;
use App\Utils\Util;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Modules\Accounting\Utils\AccountingUtil;
use Tests\TestCase;

class AccountingCanonicalLedgerCutoverTest extends TestCase
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

        Schema::create('accounting_accounts', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name');
            $table->string('gl_code')->nullable();
            $table->unsignedInteger('business_id');
            $table->string('account_primary_type')->nullable();
            $table->unsignedInteger('account_sub_type_id')->nullable();
            $table->string('status')->nullable();
            $table->text('description')->nullable();
        });
        Schema::create('accounting_accounts_transactions', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('accounting_account_id');
            $table->unsignedInteger('acc_trans_mapping_id')->nullable();
            $table->unsignedInteger('transaction_id')->nullable();
            $table->unsignedInteger('transaction_payment_id')->nullable();
            $table->decimal('amount', 22, 4);
            $table->string('type');
            $table->string('sub_type')->nullable();
            $table->string('map_type')->nullable();
            $table->dateTime('operation_date');
            $table->unsignedInteger('created_by')->nullable();
            $table->text('note')->nullable();
            $table->timestamps();
        });
        Schema::create('accounting_acc_trans_mappings', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('business_id');
            $table->string('ref_no');
            $table->text('note')->nullable();
            $table->string('type');
            $table->unsignedInteger('created_by');
            $table->dateTime('operation_date');
            $table->timestamps();
        });
        Schema::create('accounting_account_types', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name');
            $table->unsignedInteger('business_id')->nullable();
            $table->string('account_primary_type')->nullable();
            $table->string('account_type')->nullable();
        });
    }

    public function test_dashboard_balances_use_canonical_postings_and_plural_expense_type(): void
    {
        DB::table('accounting_accounts')->insert([
            ['id' => 1, 'name' => 'Kas', 'business_id' => 1, 'account_primary_type' => 'asset', 'account_sub_type_id' => null, 'status' => 'active'],
            ['id' => 2, 'name' => 'Biaya Operasional', 'business_id' => 1, 'account_primary_type' => 'expenses', 'account_sub_type_id' => null, 'status' => 'active'],
            ['id' => 3, 'name' => 'Penjualan', 'business_id' => 1, 'account_primary_type' => 'income', 'account_sub_type_id' => null, 'status' => 'active'],
            ['id' => 4, 'name' => 'Utang', 'business_id' => 1, 'account_primary_type' => 'liability', 'account_sub_type_id' => null, 'status' => 'active'],
        ]);
        DB::table('accounting_accounts_transactions')->insert([
            ['id' => 1, 'accounting_account_id' => 1, 'amount' => 100, 'type' => 'debit', 'sub_type' => 'opening_balance', 'operation_date' => '2022-01-01 00:00:00'],
            ['id' => 2, 'accounting_account_id' => 2, 'amount' => 30, 'type' => 'debit', 'sub_type' => 'journal_entry', 'operation_date' => '2022-02-01 00:00:00'],
            ['id' => 3, 'accounting_account_id' => 3, 'amount' => 50, 'type' => 'credit', 'sub_type' => 'journal_entry', 'operation_date' => '2022-03-01 00:00:00'],
            ['id' => 4, 'accounting_account_id' => 4, 'amount' => 20, 'type' => 'credit', 'sub_type' => 'journal_entry', 'operation_date' => '2022-04-01 00:00:00'],
        ]);

        $user = $this->superadminUser();

        $response = $this->withoutMiddleware()
            ->actingAs($user)
            ->withSession([
                'user' => ['business_id' => 1],
                'business' => ['name' => 'CV Demo', 'currency_precision' => 2, 'quantity_precision' => 2],
                'currency' => ['symbol' => 'Rp', 'code' => 'IDR', 'decimal_separator' => '.', 'thousand_separator' => ','],
            ])
            ->get('/accounting/dashboard?start_date=2022-01-01&end_date=2022-12-31');

        $response->assertOk();
        $response->assertViewHas('coa_overview', function ($overview) {
            $this->assertSame(100.0, (float) $overview->firstWhere('account_primary_type', 'asset')->balance);
            $this->assertSame(30.0, (float) $overview->firstWhere('account_primary_type', 'expenses')->balance);
            $this->assertSame(50.0, (float) $overview->firstWhere('account_primary_type', 'income')->balance);
            $this->assertSame(20.0, (float) $overview->firstWhere('account_primary_type', 'liability')->balance);

            return true;
        });

        $this->assertFalse(Schema::hasTable('journal_entries'));
        $this->assertFalse(Schema::hasTable('chart_of_accounts'));
    }

    public function test_chart_of_accounts_export_is_business_scoped_and_uses_the_canonical_accounts_table(): void
    {
        DB::table('accounting_accounts')->insert([
            ['id' => 1, 'name' => 'Akun Business 1', 'gl_code' => '1001', 'business_id' => 1, 'account_primary_type' => 'asset', 'status' => 'active', 'description' => 'Harus diekspor'],
            ['id' => 2, 'name' => 'Akun Business 2', 'gl_code' => '1002', 'business_id' => 2, 'account_primary_type' => 'asset', 'status' => 'active', 'description' => 'Tidak boleh bocor'],
        ]);

        $response = $this->withoutMiddleware()
            ->actingAs($this->superadminUser())
            ->withSession(['user' => ['business_id' => 1]])
            ->get(route('accounting.chartOfAccounts.export'));

        $response->assertOk();
        $file_path = $response->baseResponse->getFile()->getPathname();
        $spreadsheet = IOFactory::load($file_path);
        $exported_rows = $spreadsheet->getActiveSheet()->toArray();
        $spreadsheet->disconnectWorksheets();
        unset($spreadsheet);
        unlink($file_path);

        $exported_text = implode(' ', array_map(
            fn ($row) => implode(' ', array_map('strval', $row)),
            $exported_rows
        ));
        $this->assertStringContainsString('Akun Business 1', $exported_text);
        $this->assertStringContainsString('1001', $exported_text);
        $this->assertStringNotContainsString('Akun Business 2', $exported_text);
        $this->assertStringNotContainsString('Tidak boleh bocor', $exported_text);
        $this->assertFalse(Schema::hasTable('chart_of_accounts'));
    }

    public function test_manual_journal_route_posts_once_to_the_canonical_ledger_without_a_legacy_journal_table(): void
    {
        DB::table('accounting_accounts')->insert([
            ['id' => 11, 'name' => 'Kas', 'gl_code' => '1001', 'business_id' => 1, 'account_primary_type' => 'asset', 'status' => 'active'],
            ['id' => 12, 'name' => 'Modal', 'gl_code' => '3001', 'business_id' => 1, 'account_primary_type' => 'equity', 'status' => 'active'],
        ]);

        $util = Mockery::mock(Util::class);
        $util->shouldReceive('setAndGetReferenceCount')->once()->with('journal_entry')->andReturn(1);
        $util->shouldReceive('uf_date')->times(3)->andReturn('2022-01-10 00:00:00');
        $this->app->instance(Util::class, $util);

        $accountingUtil = Mockery::mock(AccountingUtil::class);
        $accountingUtil->shouldReceive('getAccountingSettings')->once()->with(1)->andReturn([]);
        $this->app->instance(AccountingUtil::class, $accountingUtil);

        $response = $this->withoutMiddleware()
            ->actingAs($this->superadminUser())
            ->withSession(['user' => ['business_id' => 1, 'id' => 5]])
            ->post(route('journal-entry.store'), [
                'ref_no' => 'MAN-2022-001',
                'note' => 'Jurnal manual pengujian',
                'journal_date' => '2022-01-10',
                'account_id' => [11, 12],
                'debit' => ['100.00', ''],
                'credit' => ['', '100.00'],
            ]);

        $response->assertRedirect(route('journal-entry.index'));
        $this->assertSame(1, DB::table('accounting_acc_trans_mappings')->where('business_id', 1)->where('type', 'journal_entry')->count());
        $this->assertSame(2, DB::table('accounting_accounts_transactions')->where('sub_type', 'journal_entry')->count());
        $this->assertSame(100.0, (float) DB::table('accounting_accounts_transactions')->where('type', 'debit')->sum('amount'));
        $this->assertSame(100.0, (float) DB::table('accounting_accounts_transactions')->where('type', 'credit')->sum('amount'));
        $this->assertFalse(Schema::hasTable('journal_entries'));
        $this->assertFalse(Schema::hasTable('chart_of_accounts'));
    }

    public function test_operational_mapping_is_business_scoped_idempotent_and_uses_canonical_posting_rows(): void
    {
        Schema::create('transactions', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('business_id');
            $table->string('type');
            $table->decimal('final_total', 22, 4);
        });
        DB::table('accounting_accounts')->insert([
            ['id' => 11, 'name' => 'Kas Utama', 'gl_code' => '1001', 'business_id' => 1, 'account_primary_type' => 'asset', 'status' => 'active'],
            ['id' => 12, 'name' => 'Biaya', 'gl_code' => '5001', 'business_id' => 1, 'account_primary_type' => 'expenses', 'status' => 'active'],
            ['id' => 81, 'name' => 'Kas Tenant Lain', 'gl_code' => '1001', 'business_id' => 2, 'account_primary_type' => 'asset', 'status' => 'active'],
        ]);
        DB::table('transactions')->insert([
            'id' => 91,
            'business_id' => 1,
            'type' => 'expense',
            'final_total' => 250,
        ]);

        $this->app->instance(ModuleUtil::class, Mockery::mock(ModuleUtil::class));
        $this->app->instance(TransactionUtil::class, Mockery::mock(TransactionUtil::class));
        $user = $this->superadminUser();
        $payload = [
            'type' => 'expense',
            'id' => 91,
            'deposit_to' => 12,
            'payment_account' => 11,
            'description' => 'Biaya legacy',
        ];

        $response = $this->withoutMiddleware()
            ->actingAs($user)
            ->withSession(['user' => ['business_id' => 1, 'id' => 5]])
            ->withHeaders(['X-Requested-With' => 'XMLHttpRequest'])
            ->postJson(route('accounting.transactions.saveMap'), $payload);

        $response->assertOk()->assertJsonPath('success', true);
        $this->withHeaders(['X-Requested-With' => 'XMLHttpRequest'])
            ->postJson(route('accounting.transactions.saveMap'), $payload)
            ->assertOk()
            ->assertJsonPath('success', true);

        $rows = DB::table('accounting_accounts_transactions')->where('transaction_id', 91)->get();
        $this->assertCount(2, $rows);
        $this->assertSame(250.0, (float) $rows->where('type', 'debit')->sum('amount'));
        $this->assertSame(250.0, (float) $rows->where('type', 'credit')->sum('amount'));
        $this->assertSame([11, 12], $rows->pluck('accounting_account_id')->sort()->values()->all());

        $this->withHeaders(['X-Requested-With' => 'XMLHttpRequest'])
            ->postJson(route('accounting.transactions.saveMap'), array_merge($payload, ['payment_account' => 81]))
            ->assertUnprocessable();
        $this->assertSame(2, DB::table('accounting_accounts_transactions')->where('transaction_id', 91)->count());
        $this->assertFalse(Schema::hasTable('journal_entries'));
        $this->assertFalse(Schema::hasTable('chart_of_accounts'));
    }

    public function test_retired_accounting_urls_redirect_or_reject_writes_without_legacy_tables(): void
    {
        $this->withoutMiddleware();

        $this->get('/accounting/journal_entry/123/show')
            ->assertRedirect(route('journal-entry.index'));
        $this->get('/accounting/journal-entry/123')
            ->assertRedirect(route('journal-entry.index'));
        $this->get('/accounting/journal_entry/create')
            ->assertRedirect(route('journal-entry.create'));
        $this->get('/accounting/chart_of_account')
            ->assertRedirect(action([\Modules\Accounting\Http\Controllers\CoaController::class, 'index']));
        $this->get('/accounting/chart_of_account/123/show')
            ->assertRedirect(route('accounting.ledger', ['id' => 123]));
        $this->get('/accounting/chart-of-accounts/123')
            ->assertRedirect(route('accounting.ledger', ['id' => 123]));
        $this->get('/accounting/reconcile')
            ->assertRedirect(route('accounting.reconciliation.findings'));
        $this->get('/accounting/transfers')
            ->assertRedirect(action([\Modules\Accounting\Http\Controllers\TransferController::class, 'index']));

        $this->get('/accounting/journal_entry/123/reverse')->assertStatus(410);
        $this->get('/accounting/journal_entry/123/destroy')->assertStatus(410);
        $this->get('/accounting/dashboard/get_totals')->assertStatus(410);

        $this->post('/accounting/journal_entry/123/update')->assertStatus(410);
        $this->post('/accounting/chart_of_account/store')->assertStatus(410);
        $this->post('/accounting/reconcile/store')->assertStatus(410);

        $this->assertFalse(Schema::hasTable('journal_entries'));
        $this->assertFalse(Schema::hasTable('chart_of_accounts'));
    }

    private function superadminUser(): User
    {
        $user = Mockery::mock(User::class)->makePartial();
        $user->shouldReceive('can')->andReturnUsing(function ($ability) {
            return in_array($ability, ['superadmin', 'accounting.manage_accounts', 'accounting.add_journal', 'accounting.map_transactions'], true);
        });

        return $user;
    }
}
