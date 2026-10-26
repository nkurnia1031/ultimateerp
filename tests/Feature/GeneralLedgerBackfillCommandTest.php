<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class GeneralLedgerBackfillCommandTest extends TestCase
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
        $this->seedBusinessAndMappings();
    }

    public function test_dry_run_then_apply_backfills_payment_without_duplicate_account_transaction_row(): void
    {
        DB::table('transactions')->insert([
            'id' => 900,
            'business_id' => 7,
            'location_id' => 70,
            'type' => 'sell',
            'status' => 'final',
            'transaction_date' => '2023-01-03 10:00:00',
            'final_total' => 110,
            'total_before_tax' => 100,
            'tax_amount' => 10,
            'created_by' => 1,
            'invoice_no' => 'INV-900',
            'ref_no' => 'SALE-900',
        ]);
        DB::table('transaction_payments')->insert([
            'id' => 500,
            'transaction_id' => 900,
            'business_id' => 7,
            'account_id' => 201,
            'amount' => 50,
            'paid_on' => '2023-01-10 11:00:00',
            'created_by' => 1,
            'is_return' => 0,
            'is_advance' => 0,
        ]);
        DB::table('accounts')->insert(['id' => 202, 'business_id' => 7, 'name' => 'Cash Demo']);
        DB::table('account_transactions')->insert([
            [
                'id' => 700,
                'account_id' => 201,
                'type' => 'debit',
                'sub_type' => 'fund_transfer',
                'amount' => 20,
                'operation_date' => '2023-01-12 09:00:00',
                'created_by' => 1,
                'transfer_transaction_id' => 701,
                'note' => 'Transfer keluar',
            ],
            [
                'id' => 701,
                'account_id' => 202,
                'type' => 'credit',
                'sub_type' => 'fund_transfer',
                'amount' => 20,
                'operation_date' => '2023-01-12 09:00:00',
                'created_by' => 1,
                'transfer_transaction_id' => 700,
                'note' => 'Transfer masuk',
            ],
        ]);

        DB::table('business')->insert(['id' => 8, 'name' => 'Business Lain']);
        DB::table('business_locations')->insert([
            'id' => 80,
            'business_id' => 8,
            'name' => 'Lokasi Lain',
            'accounting_default_map' => json_encode(['sale' => ['payment_account' => 802, 'deposit_to' => 801]]),
        ]);
        DB::table('accounting_accounts')->insert([
            ['id' => 801, 'business_id' => 8, 'name' => 'Piutang Business Lain', 'account_primary_type' => 'asset', 'status' => 'active', 'created_by' => 1],
            ['id' => 802, 'business_id' => 8, 'name' => 'Pendapatan Business Lain', 'account_primary_type' => 'income', 'status' => 'active', 'created_by' => 1],
        ]);
        DB::table('transactions')->insert([
            'id' => 902,
            'business_id' => 8,
            'location_id' => 80,
            'type' => 'sell',
            'status' => 'final',
            'transaction_date' => '2023-01-04 10:00:00',
            'final_total' => 25,
            'total_before_tax' => 25,
            'tax_amount' => 0,
            'created_by' => 1,
            'invoice_no' => 'INV-902',
            'ref_no' => 'SALE-902',
        ]);
        DB::table('transactions')->insert([
            'id' => 903,
            'business_id' => 7,
            'location_id' => 70,
            'type' => 'sell',
            'status' => 'draft',
            'transaction_date' => '2023-01-06 10:00:00',
            'final_total' => 99,
            'total_before_tax' => 99,
            'tax_amount' => 0,
            'created_by' => 1,
            'invoice_no' => 'INV-903',
            'ref_no' => 'SALE-903',
        ]);

        $this->artisan('accounting:backfill-general-ledger', ['business_id' => 7])
            ->expectsOutputToContain('Mode: DRY-RUN')
            ->expectsOutputToContain('Transaction date range: 2023-01-03')
            ->expectsOutputToContain('Planned groups: 3')
            ->expectsOutputToContain('Groups using current location maps: 2')
            ->expectsOutputToContain('location #70 [sale]')
            ->expectsOutputToContain('Unique account_id matches: 1')
            ->expectsOutputToContain('Exact unique-name matches: 1')
            ->expectsOutputToContain('account #201 Bank Demo -> accounting #104 Bank Demo [exact_name]')
            ->expectsOutputToContain('Debit total: 180.00')
            ->expectsOutputToContain('Credit total: 180.00')
            ->assertExitCode(0);

        $this->assertSame(0, DB::table('accounting_accounts_transactions')->count());
        $this->assertSame(0, DB::table('accounting_acc_trans_mappings')->count());
        $this->assertNull(DB::table('accounting_accounts')->where('id', 104)->value('account_id'));

        $this->artisan('accounting:backfill-general-ledger', ['business_id' => 7, '--apply' => true])
            ->expectsOutputToContain('Mode: APPLY')
            ->expectsOutputToContain('Applied groups: 3')
            ->assertExitCode(0);

        $rows = DB::table('accounting_accounts_transactions')->orderBy('id')->get();
        $this->assertCount(7, $rows);
        $this->assertSame(180.0, (float) $rows->where('type', 'debit')->sum('amount'));
        $this->assertSame(180.0, (float) $rows->where('type', 'credit')->sum('amount'));
        $this->assertCount(1, $rows->where('accounting_account_id', 109));
        $this->assertCount(0, $rows->where('accounting_account_id', 110));
        $this->assertSame(201, (int) DB::table('accounting_accounts')->where('id', 104)->value('account_id'));
        $this->assertSame('2023-01-03 10:00:00', DB::table('accounting_accounts_transactions')
            ->where('transaction_id', 900)
            ->value('operation_date'));
        $this->assertSame(0, DB::table('accounting_accounts_transactions')->where('transaction_id', 902)->count());

        $this->artisan('accounting:backfill-general-ledger', ['business_id' => 7, '--apply' => true])
            ->expectsOutputToContain('Applied groups: 0')
            ->expectsOutputToContain('Already linked: 3')
            ->assertExitCode(0);

        $this->assertSame(7, DB::table('accounting_accounts_transactions')->count());
        $this->assertSame(3, DB::table('accounting_acc_trans_mappings')->count());
    }

    public function test_backfills_sales_and_purchase_groups_when_location_defaults_are_missing(): void
    {
        DB::table('business_locations')->where('id', 70)->update(['accounting_default_map' => null]);
        DB::table('accounts')->insert([
            ['id' => 204, 'business_id' => 7, 'name' => 'Account Receivable'],
            ['id' => 203, 'business_id' => 7, 'name' => 'Account Payable'],
        ]);
        DB::table('accounting_accounts')->where('id', 107)->update(['name' => 'Trade Payable']);
        DB::table('accounting_accounts')->insert([
            ['id' => 113, 'business_id' => 7, 'name' => 'Sales', 'account_primary_type' => 'income', 'status' => 'active', 'created_by' => 1],
            ['id' => 114, 'business_id' => 7, 'name' => 'Purchases', 'account_primary_type' => 'expenses', 'status' => 'active', 'created_by' => 1],
        ]);
        DB::table('transactions')->insert([
            [
                'id' => 930,
                'business_id' => 7,
                'location_id' => 70,
                'type' => 'sell',
                'status' => 'final',
                'transaction_date' => '2023-04-01 10:00:00',
                'final_total' => 110,
                'total_before_tax' => 100,
                'tax_amount' => 10,
                'created_by' => 1,
                'invoice_no' => 'INV-930',
                'ref_no' => 'SALE-930',
            ],
            [
                'id' => 931,
                'business_id' => 7,
                'location_id' => 70,
                'type' => 'purchase',
                'status' => 'received',
                'transaction_date' => '2023-04-02 10:00:00',
                'final_total' => 220,
                'total_before_tax' => 200,
                'tax_amount' => 20,
                'created_by' => 1,
                'invoice_no' => 'PUR-931',
                'ref_no' => 'PURCHASE-931',
            ],
        ]);
        DB::table('transaction_payments')->insert([
            [
                'id' => 530,
                'transaction_id' => 930,
                'business_id' => 7,
                'account_id' => 201,
                'amount' => 50,
                'paid_on' => '2023-04-03 10:00:00',
                'created_by' => 1,
                'is_return' => 0,
                'is_advance' => 0,
            ],
            [
                'id' => 531,
                'transaction_id' => 931,
                'business_id' => 7,
                'account_id' => 201,
                'amount' => 100,
                'paid_on' => '2023-04-04 10:00:00',
                'created_by' => 1,
                'is_return' => 0,
                'is_advance' => 0,
            ],
        ]);

        $this->artisan('accounting:backfill-general-ledger', ['business_id' => 7])
            ->expectsOutputToContain('Planned groups: 4')
            ->expectsOutputToContain('Auto-mapped groups: 4')
            ->expectsOutputToContain('Nearest CoA matches: 2')
            ->expectsOutputToContain('deposit_to #101 Accounts Receivable')
            ->expectsOutputToContain('payment_account #107 Trade Payable')
            ->expectsOutputToContain('Debit total: 480.00')
            ->expectsOutputToContain('Credit total: 480.00')
            ->assertExitCode(0);

        $this->assertSame(0, DB::table('accounting_accounts_transactions')->count());

        $this->artisan('accounting:backfill-general-ledger', ['business_id' => 7, '--apply' => true])
            ->expectsOutputToContain('Applied groups: 4')
            ->assertExitCode(0);

        $rows = DB::table('accounting_accounts_transactions')->get();
        $this->assertCount(10, $rows);
        $this->assertSame(480.0, (float) $rows->where('type', 'debit')->sum('amount'));
        $this->assertSame(480.0, (float) $rows->where('type', 'credit')->sum('amount'));
        $this->assertSame(201, (int) DB::table('accounting_accounts')->where('id', 104)->value('account_id'));
        $this->assertNull(DB::table('accounting_accounts')->where('id', 101)->value('account_id'));
        $this->assertNull(DB::table('accounting_accounts')->where('id', 107)->value('account_id'));
        $this->assertSame(1, DB::table('accounting_accounts_transactions')->where('accounting_account_id', 103)->count());
        $this->assertSame(1, DB::table('accounting_accounts_transactions')->where('accounting_account_id', 105)->count());
    }

    public function test_expense_category_nearest_mapping_takes_precedence_over_generic_location_default(): void
    {
        DB::table('business_locations')->where('id', 70)->update([
            'accounting_default_map' => json_encode([
                'expense' => ['payment_account' => 107, 'deposit_to' => 116],
            ]),
        ]);
        DB::table('accounts')->insert([
            ['id' => 203, 'business_id' => 7, 'name' => 'Trade Payable'],
            ['id' => 205, 'business_id' => 7, 'name' => 'Cash'],
        ]);
        DB::table('expense_categories')->insert(['id' => 5, 'business_id' => 7, 'name' => 'Meals']);
        DB::table('accounting_accounts')->where('business_id', 7)->delete();
        DB::table('accounting_accounts')->insert([
            ['id' => 107, 'business_id' => 7, 'name' => 'Trade Payable', 'account_primary_type' => 'liability', 'account_id' => 203, 'status' => 'active', 'created_by' => 1],
            ['id' => 103, 'business_id' => 7, 'name' => 'VAT Out', 'account_primary_type' => 'liability', 'account_id' => null, 'status' => 'active', 'created_by' => 1],
            ['id' => 105, 'business_id' => 7, 'name' => 'VAT In', 'account_primary_type' => 'asset', 'account_id' => null, 'status' => 'active', 'created_by' => 1],
            ['id' => 115, 'business_id' => 7, 'name' => 'Cash and cash equivalents', 'account_primary_type' => 'asset', 'account_id' => null, 'status' => 'active', 'created_by' => 1],
            ['id' => 116, 'business_id' => 7, 'name' => 'Meals and entertainment', 'account_primary_type' => 'expenses', 'account_id' => null, 'status' => 'active', 'created_by' => 1],
        ]);
        DB::table('transactions')->insert([
            [
                'id' => 940,
                'business_id' => 7,
                'location_id' => 70,
                'type' => 'expense',
                'status' => 'final',
                'expense_category_id' => 5,
                'transaction_date' => '2023-05-01 10:00:00',
                'final_total' => 55,
                'total_before_tax' => 50,
                'tax_amount' => 5,
                'created_by' => 1,
                'invoice_no' => 'EXP-940',
                'ref_no' => 'EXPENSE-940',
            ],
            [
                'id' => 941,
                'business_id' => 7,
                'location_id' => 70,
                'type' => 'expense',
                'status' => 'final',
                'expense_category_id' => 5,
                'transaction_date' => '2023-05-02 10:00:00',
                'final_total' => 22,
                'total_before_tax' => 22,
                'tax_amount' => 0,
                'created_by' => 1,
                'invoice_no' => 'EXP-941',
                'ref_no' => 'EXPENSE-941',
            ],
        ]);
        DB::table('transaction_payments')->insert([
            'id' => 540,
            'transaction_id' => 940,
            'business_id' => 7,
            'account_id' => 205,
            'amount' => 55,
            'paid_on' => '2023-05-01 10:00:00',
            'created_by' => 1,
            'is_return' => 0,
            'is_advance' => 0,
        ]);

        $this->artisan('accounting:backfill-general-ledger', ['business_id' => 7])
            ->expectsOutputToContain('Planned groups: 2')
            ->expectsOutputToContain('Auto-mapped groups: 2')
            ->expectsOutputToContain('Automatic nearest CoA mappings used:')
            ->expectsOutputToContain('[expense_5]')
            ->expectsOutputToContain('Trade Payable')
            ->expectsOutputToContain('Debit total: 77.00')
            ->expectsOutputToContain('Credit total: 77.00')
            ->assertExitCode(0);

        $this->artisan('accounting:backfill-general-ledger', ['business_id' => 7, '--apply' => true])
            ->expectsOutputToContain('Applied groups: 2')
            ->assertExitCode(0);

        $rows = DB::table('accounting_accounts_transactions')->orderBy('id')->get();
        $this->assertCount(5, $rows);
        $this->assertSame(72.0, (float) $rows->where('accounting_account_id', 116)->sum('amount'));
        $this->assertSame(5.0, (float) $rows->where('accounting_account_id', 105)->sum('amount'));
        $this->assertSame(55.0, (float) $rows->where('accounting_account_id', 115)->sum('amount'));
        $this->assertSame(22.0, (float) $rows->where('accounting_account_id', 107)->sum('amount'));
        $this->assertNull(DB::table('accounting_accounts')->where('id', 115)->value('account_id'));
    }

    public function test_purchase_and_expense_split_input_vat_using_location_mappings(): void
    {
        DB::table('transactions')->insert([
            [
                'id' => 910,
                'business_id' => 7,
                'location_id' => 70,
                'type' => 'purchase',
                'status' => 'received',
                'expense_category_id' => null,
                'transaction_date' => '2023-02-01 10:00:00',
                'final_total' => 220,
                'total_before_tax' => 200,
                'tax_amount' => 20,
                'created_by' => 1,
                'invoice_no' => 'PUR-910',
                'ref_no' => 'PURCHASE-910',
            ],
            [
                'id' => 911,
                'business_id' => 7,
                'location_id' => 70,
                'type' => 'expense',
                'status' => 'final',
                'expense_category_id' => 5,
                'transaction_date' => '2023-02-02 10:00:00',
                'final_total' => 55,
                'total_before_tax' => 50,
                'tax_amount' => 5,
                'created_by' => 1,
                'invoice_no' => 'EXP-911',
                'ref_no' => 'EXPENSE-911',
            ],
        ]);

        $this->artisan('accounting:backfill-general-ledger', ['business_id' => 7])
            ->expectsOutputToContain('Planned groups: 2')
            ->expectsOutputToContain('Groups with separated VAT: 2')
            ->expectsOutputToContain('Debit total: 275.00')
            ->expectsOutputToContain('Credit total: 275.00')
            ->assertExitCode(0);

        $this->artisan('accounting:backfill-general-ledger', ['business_id' => 7, '--apply' => true])
            ->expectsOutputToContain('Applied groups: 2')
            ->assertExitCode(0);

        $vatLines = DB::table('accounting_accounts_transactions')
            ->where('accounting_account_id', 105)
            ->orderBy('amount')
            ->get();
        $this->assertCount(2, $vatLines);
        $this->assertSame([5.0, 20.0], $vatLines->pluck('amount')->map(fn ($amount) => (float) $amount)->all());
        $this->assertSame(['debit', 'debit'], $vatLines->pluck('type')->all());
    }

    public function test_tax_bearing_transaction_with_ambiguous_vat_account_is_reported_and_skipped(): void
    {
        DB::table('accounting_accounts')->insert([
            'id' => 111,
            'business_id' => 7,
            'name' => 'VAT Out - Pajak Keluaran',
            'account_primary_type' => 'liability',
            'status' => 'active',
            'created_by' => 1,
        ]);
        DB::table('transactions')->insert([
            'id' => 901,
            'business_id' => 7,
            'location_id' => 70,
            'type' => 'sell',
            'status' => 'final',
            'transaction_date' => '2023-01-05 10:00:00',
            'final_total' => 110,
            'total_before_tax' => 100,
            'tax_amount' => 10,
            'created_by' => 1,
            'invoice_no' => 'INV-901',
            'ref_no' => 'SALE-901',
        ]);

        $this->artisan('accounting:backfill-general-ledger', ['business_id' => 7])
            ->expectsOutputToContain('Ambiguous VAT accounts: 1')
            ->assertExitCode(0);

        $this->artisan('accounting:backfill-general-ledger', ['business_id' => 7, '--apply' => true])
            ->expectsOutputToContain('Applied groups: 0')
            ->assertExitCode(0);

        $this->assertSame(0, DB::table('accounting_accounts_transactions')->count());
        $this->assertSame(0, DB::table('accounting_acc_trans_mappings')->count());
    }

    public function test_exact_name_mapping_requires_a_unique_account(): void
    {
        DB::table('accounting_accounts')->insert([
            'id' => 112,
            'business_id' => 7,
            'name' => 'Bank Demo',
            'account_primary_type' => 'asset',
            'status' => 'active',
            'created_by' => 1,
        ]);
        DB::table('transactions')->insert([
            'id' => 920,
            'business_id' => 7,
            'location_id' => 70,
            'type' => 'sell',
            'status' => 'final',
            'transaction_date' => '2023-03-01 10:00:00',
            'final_total' => 100,
            'total_before_tax' => 100,
            'tax_amount' => 0,
            'created_by' => 1,
            'invoice_no' => 'INV-920',
            'ref_no' => 'SALE-920',
        ]);
        DB::table('transaction_payments')->insert([
            'id' => 520,
            'transaction_id' => 920,
            'business_id' => 7,
            'account_id' => 201,
            'amount' => 50,
            'paid_on' => '2023-03-02 10:00:00',
            'created_by' => 1,
            'is_return' => 0,
            'is_advance' => 0,
        ]);
        DB::table('account_transactions')->insert([
            'id' => 620,
            'account_id' => 201,
            'type' => 'credit',
            'amount' => 50,
            'operation_date' => '2023-03-02 10:00:00',
            'created_by' => 1,
            'transaction_id' => 920,
            'transaction_payment_id' => 520,
        ]);

        $this->artisan('accounting:backfill-general-ledger', ['business_id' => 7])
            ->expectsOutputToContain('Planned groups: 1')
            ->expectsOutputToContain('nama_akun_utama_ambigu: 1')
            ->expectsOutputToContain('Ambiguous exact names: 1')
            ->assertExitCode(0);

        $this->artisan('accounting:backfill-general-ledger', ['business_id' => 7, '--apply' => true])
            ->expectsOutputToContain('Applied groups: 1')
            ->assertExitCode(0);

        $this->assertSame(0, DB::table('accounting_accounts_transactions')->where('transaction_payment_id', 520)->count());
        $this->assertNull(DB::table('accounting_accounts')->where('id', 104)->value('account_id'));
    }

    private function createSchema(): void
    {
        Schema::create('business', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name');
        });
        Schema::create('business_locations', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('business_id');
            $table->string('name');
            $table->text('accounting_default_map')->nullable();
        });
        Schema::create('accounts', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('business_id');
            $table->string('name');
            $table->softDeletes();
        });
        Schema::create('expense_categories', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('business_id');
            $table->string('name');
            $table->softDeletes();
        });
        Schema::create('account_transactions', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('account_id');
            $table->string('type');
            $table->string('sub_type')->nullable();
            $table->decimal('amount', 22, 4);
            $table->dateTime('operation_date');
            $table->unsignedInteger('created_by');
            $table->unsignedInteger('transaction_id')->nullable();
            $table->unsignedInteger('transaction_payment_id')->nullable();
            $table->unsignedInteger('transfer_transaction_id')->nullable();
            $table->text('note')->nullable();
            $table->softDeletes();
        });
        Schema::create('accounting_accounts', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('business_id');
            $table->string('name');
            $table->string('gl_code')->nullable();
            $table->string('account_primary_type')->nullable();
            $table->string('status')->nullable();
            $table->unsignedInteger('account_id')->nullable();
            $table->unsignedInteger('created_by')->nullable();
            $table->timestamps();
        });
        Schema::create('accounting_acc_trans_mappings', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('business_id');
            $table->string('ref_no')->nullable();
            $table->string('type');
            $table->unsignedInteger('created_by');
            $table->dateTime('operation_date');
            $table->text('note')->nullable();
            $table->string('source_record_type', 64)->nullable();
            $table->unsignedBigInteger('source_record_id')->nullable();
            $table->string('source_link_status', 20)->nullable();
            $table->text('source_link_note')->nullable();
            $table->string('import_source_key')->nullable();
            $table->timestamps();
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
            $table->unsignedInteger('created_by');
            $table->dateTime('operation_date');
            $table->text('note')->nullable();
            $table->timestamps();
        });
        Schema::create('transactions', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('business_id');
            $table->unsignedInteger('location_id')->nullable();
            $table->string('type');
            $table->string('status')->nullable();
            $table->unsignedInteger('expense_category_id')->nullable();
            $table->dateTime('transaction_date');
            $table->decimal('total_before_tax', 22, 4)->default(0);
            $table->decimal('tax_amount', 22, 4)->default(0);
            $table->decimal('final_total', 22, 4)->default(0);
            $table->unsignedInteger('created_by')->nullable();
            $table->string('invoice_no')->nullable();
            $table->string('ref_no')->nullable();
            $table->softDeletes();
        });
        Schema::create('transaction_payments', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('transaction_id');
            $table->unsignedInteger('business_id');
            $table->unsignedInteger('account_id')->nullable();
            $table->decimal('amount', 22, 4);
            $table->dateTime('paid_on');
            $table->unsignedInteger('created_by')->nullable();
            $table->boolean('is_return')->default(false);
            $table->boolean('is_advance')->default(false);
            $table->softDeletes();
        });
    }

    private function seedBusinessAndMappings(): void
    {
        DB::table('business')->insert(['id' => 7, 'name' => 'CV Demo']);
        DB::table('business_locations')->insert([
            'id' => 70,
            'business_id' => 7,
            'name' => 'Kantor Utama',
            'accounting_default_map' => json_encode([
                'sale' => ['payment_account' => 102, 'deposit_to' => 101],
                'sell_payment' => ['payment_account' => 101, 'deposit_to' => 104],
                'purchases' => ['payment_account' => 107, 'deposit_to' => 106],
                'expense_5' => ['payment_account' => 104, 'deposit_to' => 108],
            ]),
        ]);
        DB::table('accounts')->insert(['id' => 201, 'business_id' => 7, 'name' => 'Bank Demo']);
        DB::table('accounting_accounts')->insert([
            ['id' => 101, 'business_id' => 7, 'name' => 'Accounts Receivable', 'account_primary_type' => 'asset', 'status' => 'active', 'account_id' => null, 'created_by' => 1],
            ['id' => 102, 'business_id' => 7, 'name' => 'Pendapatan Penjualan', 'account_primary_type' => 'income', 'status' => 'active', 'account_id' => null, 'created_by' => 1],
            ['id' => 103, 'business_id' => 7, 'name' => 'VAT Out', 'account_primary_type' => 'liability', 'status' => 'active', 'account_id' => null, 'created_by' => 1],
            ['id' => 104, 'business_id' => 7, 'name' => 'Bank Demo', 'account_primary_type' => 'asset', 'status' => 'active', 'account_id' => null, 'created_by' => 1],
            ['id' => 105, 'business_id' => 7, 'name' => 'VAT In', 'account_primary_type' => 'asset', 'status' => 'active', 'account_id' => null, 'created_by' => 1],
            ['id' => 106, 'business_id' => 7, 'name' => 'Persediaan', 'account_primary_type' => 'asset', 'status' => 'active', 'account_id' => null, 'created_by' => 1],
            ['id' => 107, 'business_id' => 7, 'name' => 'Utang Usaha', 'account_primary_type' => 'liability', 'status' => 'active', 'account_id' => null, 'created_by' => 1],
            ['id' => 108, 'business_id' => 7, 'name' => 'Beban Umum', 'account_primary_type' => 'expense', 'status' => 'active', 'account_id' => null, 'created_by' => 1],
            ['id' => 109, 'business_id' => 7, 'name' => 'Cash Mapping by ID', 'account_primary_type' => 'asset', 'status' => 'active', 'account_id' => 202, 'created_by' => 1],
            ['id' => 110, 'business_id' => 7, 'name' => 'Cash Demo', 'account_primary_type' => 'asset', 'status' => 'active', 'account_id' => null, 'created_by' => 1],
        ]);
    }
}
