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

    public function test_dry_run_does_not_stage_actionable_skips_and_apply_stages_them_idempotently_per_business(): void
    {
        DB::table('transactions')->insert([
            [
                'id' => 959,
                'business_id' => 7,
                'location_id' => 70,
                'type' => 'sell',
                'status' => 'final',
                'transaction_date' => '2023-05-31 10:00:00',
                'final_total' => 100,
                'total_before_tax' => 100,
                'tax_amount' => 0,
                'created_by' => 1,
                'invoice_no' => 'INV-959',
                'ref_no' => 'SALE-959',
            ],
            [
                'id' => 960,
                'business_id' => 7,
                'location_id' => 70,
                'type' => 'sell',
                'status' => 'final',
                'transaction_date' => '2023-06-01 10:00:00',
                'final_total' => 0,
                'total_before_tax' => 0,
                'tax_amount' => 0,
                'created_by' => 1,
                'invoice_no' => 'INV-960',
                'ref_no' => 'SALE-960',
            ],
            [
                'id' => 961,
                'business_id' => 7,
                'location_id' => 70,
                'type' => 'sell',
                'status' => 'draft',
                'transaction_date' => '2023-06-02 10:00:00',
                'final_total' => 0,
                'total_before_tax' => 0,
                'tax_amount' => 0,
                'created_by' => 1,
                'invoice_no' => 'INV-961',
                'ref_no' => 'SALE-961',
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
            'id' => 962,
            'business_id' => 8,
            'location_id' => 80,
            'type' => 'sell',
            'status' => 'final',
            'transaction_date' => '2023-06-03 10:00:00',
            'final_total' => 0,
            'total_before_tax' => 0,
            'tax_amount' => 0,
            'created_by' => 1,
            'invoice_no' => 'INV-962',
            'ref_no' => 'SALE-962',
        ]);

        $this->artisan('accounting:backfill-general-ledger', ['business_id' => 7])
            ->expectsOutputToContain('Planned groups: 1')
            ->expectsOutputToContain('nominal_transaksi_tidak_valid: 1')
            ->assertExitCode(0);
        $this->assertSame(0, DB::table('accounting_general_ledger_backfill_reviews')->count());

        $this->artisan('accounting:backfill-general-ledger', ['business_id' => 7, '--apply' => true])
            ->expectsOutputToContain('Applied groups: 1')
            ->assertExitCode(0);
        $review = DB::table('accounting_general_ledger_backfill_reviews')->first();
        $this->assertSame(7, (int) $review->business_id);
        $this->assertSame(960, (int) $review->source_record_id);
        $this->assertSame('nominal_transaksi_tidak_valid', $review->skip_reason);
        $this->assertSame('pending', $review->status);
        $this->assertSame(1, count(json_decode($review->audit_log, true)));
        $this->assertSame('INV-960', $review->source_reference);

        $this->artisan('accounting:backfill-general-ledger', ['business_id' => 7, '--apply' => true])
            ->assertExitCode(0);
        $this->assertSame(1, DB::table('accounting_general_ledger_backfill_reviews')->where('business_id', 7)->count());
        $this->assertSame(1, count(json_decode(DB::table('accounting_general_ledger_backfill_reviews')->where('business_id', 7)->value('audit_log'), true)));

        $this->artisan('accounting:backfill-general-ledger', ['business_id' => 8, '--apply' => true])
            ->assertExitCode(0);
        $this->assertSame(2, DB::table('accounting_general_ledger_backfill_reviews')->count());
        $this->assertSame(1, DB::table('accounting_general_ledger_backfill_reviews')->where('business_id', 8)->count());
    }

    public function test_apply_stages_opening_balances_and_unpaired_account_movements_as_separate_sources(): void
    {
        DB::table('accounts')->insert(['id' => 203, 'business_id' => 7, 'name' => 'Rekening Tanpa Mapping']);
        DB::table('account_transactions')->insert([
            [
                'id' => 800,
                'account_id' => 203,
                'type' => 'debit',
                'sub_type' => 'opening_balance',
                'amount' => 100,
                'operation_date' => '2023-01-01 00:00:00',
                'created_by' => 1,
                'note' => 'Saldo awal rekening',
            ],
            [
                'id' => 801,
                'account_id' => 203,
                'type' => 'credit',
                'sub_type' => 'expense_payment',
                'amount' => 15,
                'operation_date' => '2023-02-01 00:00:00',
                'created_by' => 1,
                'note' => 'Mutasi tanpa pasangan',
            ],
        ]);

        $this->artisan('accounting:backfill-general-ledger', ['business_id' => 7, '--apply' => true])
            ->expectsOutputToContain('saldo_awal_rekening_tidak_disalin: 1')
            ->expectsOutputToContain('pergerakan_rekening_tanpa_akun_lawan: 1')
            ->expectsOutputToContain('Actionable review sources queued: 2')
            ->assertExitCode(0);

        $reviews = DB::table('accounting_general_ledger_backfill_reviews')->orderBy('source_record_id')->get();
        $this->assertCount(2, $reviews);
        $this->assertSame('saldo_awal_rekening_tidak_disalin', $reviews[0]->skip_reason);
        $this->assertSame('pergerakan_rekening_tanpa_akun_lawan', $reviews[1]->skip_reason);
        $this->assertSame(800, (int) $reviews[0]->source_record_id);
        $this->assertSame(801, (int) $reviews[1]->source_record_id);
    }

    public function test_retry_updates_the_existing_reason_and_linked_source_closes_the_item_with_audit_history(): void
    {
        DB::table('transactions')->insert([
            'id' => 963,
            'business_id' => 7,
            'location_id' => 70,
            'type' => 'sell',
            'status' => 'final',
            'transaction_date' => '2023-06-04 10:00:00',
            'final_total' => 0,
            'total_before_tax' => 0,
            'tax_amount' => 0,
            'created_by' => 1,
            'invoice_no' => 'INV-963',
            'ref_no' => 'SALE-963',
        ]);

        $this->artisan('accounting:backfill-general-ledger', ['business_id' => 7, '--apply' => true])
            ->assertExitCode(0);

        DB::table('accounting_accounts')->where('id', 105)->delete();
        DB::table('transactions')->where('id', 963)->update([
            'type' => 'purchase',
            'status' => 'received',
            'final_total' => 100,
            'total_before_tax' => 90,
            'tax_amount' => 10,
        ]);
        $this->artisan('accounting:backfill-general-ledger', ['business_id' => 7, '--apply' => true])
            ->expectsOutputToContain('akun_vat_tidak_ditemukan: 1')
            ->assertExitCode(0);

        $review = DB::table('accounting_general_ledger_backfill_reviews')->where('source_record_id', 963)->first();
        $this->assertSame('akun_vat_tidak_ditemukan', $review->skip_reason);
        $auditLog = json_decode($review->audit_log, true);
        $this->assertCount(2, $auditLog);
        $this->assertSame('updated', $auditLog[1]['event']);
        $this->assertSame('nominal_transaksi_tidak_valid', $auditLog[1]['changes']['reason']['from']);

        DB::table('transactions')->where('id', 963)->update([
            'type' => 'expense',
            'status' => 'final',
            'expense_category_id' => 5,
            'tax_amount' => 0,
        ]);
        $this->artisan('accounting:backfill-general-ledger', ['business_id' => 7, '--apply' => true])
            ->expectsOutputToContain('Applied groups: 1')
            ->assertExitCode(0);
        $this->artisan('accounting:backfill-general-ledger', ['business_id' => 7, '--apply' => true])
            ->expectsOutputToContain('Already linked: 1')
            ->assertExitCode(0);

        $review = DB::table('accounting_general_ledger_backfill_reviews')->where('source_record_id', 963)->first();
        $this->assertSame('resolved', $review->status);
        $this->assertNotNull($review->resolved_at);
        $this->assertSame(1, DB::table('accounting_general_ledger_backfill_reviews')->where('source_record_id', 963)->count());
        $this->assertSame('resolved', json_decode($review->audit_log, true)[2]['event']);
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

    public function test_expense_with_same_receivable_account_on_both_sides_is_skipped(): void
    {
        DB::table('accounts')->insert([
            'id' => 204,
            'business_id' => 7,
            'name' => 'Account Receivable',
        ]);
        DB::table('accounting_accounts')->where('id', 101)->update(['account_id' => 204]);
        DB::table('expense_categories')->insert([
            'id' => 42,
            'business_id' => 7,
            'name' => 'Employee Receivables (Expenses)',
        ]);
        DB::table('transactions')->insert([
            'id' => 950,
            'business_id' => 7,
            'location_id' => 70,
            'type' => 'expense',
            'status' => 'final',
            'expense_category_id' => 42,
            'transaction_date' => '2023-05-04 10:00:00',
            'final_total' => 12,
            'total_before_tax' => 12,
            'tax_amount' => 0,
            'created_by' => 1,
            'invoice_no' => 'EXP-950',
            'ref_no' => 'EXPENSE-950',
        ]);
        DB::table('transaction_payments')->insert([
            'id' => 550,
            'transaction_id' => 950,
            'business_id' => 7,
            'account_id' => 204,
            'amount' => 12,
            'paid_on' => '2023-05-04 10:00:00',
            'created_by' => 1,
            'is_return' => 0,
            'is_advance' => 0,
        ]);

        $this->artisan('accounting:backfill-general-ledger', ['business_id' => 7])
            ->expectsOutputToContain('Planned groups: 0')
            ->expectsOutputToContain('akun_debit_kredit_sama: 1')
            ->assertExitCode(0);

        $this->artisan('accounting:backfill-general-ledger', ['business_id' => 7, '--apply' => true])
            ->expectsOutputToContain('Applied groups: 0')
            ->expectsOutputToContain('akun_debit_kredit_sama: 1')
            ->assertExitCode(0);

        $this->assertSame(0, DB::table('accounting_accounts_transactions')->count());
        $this->assertSame(0, DB::table('accounting_acc_trans_mappings')->count());
        $this->assertSame(0, DB::table('accounting_general_ledger_backfill_reviews')->count());
    }

    public function test_expense_categories_with_clear_meaning_map_to_matching_coa_accounts(): void
    {
        $categories = [
            9 => 'Depreciation - Vehicle',
            10 => 'Depreciation - Machinery & Equipment',
            24 => 'Maintenance - General',
            39 => 'Training & Developments',
            45 => 'Taxes and Licenses',
            49 => 'Insurance',
        ];

        foreach ($categories as $id => $name) {
            DB::table('expense_categories')->insert([
                'id' => $id,
                'business_id' => 7,
                'name' => $name,
            ]);
        }

        DB::table('accounting_accounts')->insert([
            ['id' => 120, 'business_id' => 7, 'name' => 'Amortisation expense', 'account_primary_type' => 'expenses', 'status' => 'active', 'created_by' => 1],
            ['id' => 121, 'business_id' => 7, 'name' => 'Repair and maintenance', 'account_primary_type' => 'expenses', 'status' => 'active', 'created_by' => 1],
            ['id' => 122, 'business_id' => 7, 'name' => 'Dues and Subscriptions', 'account_primary_type' => 'expenses', 'status' => 'active', 'created_by' => 1],
            ['id' => 123, 'business_id' => 7, 'name' => 'Other general and administrative expenses', 'account_primary_type' => 'expenses', 'status' => 'active', 'created_by' => 1],
            ['id' => 124, 'business_id' => 7, 'name' => 'Insurance - General', 'account_primary_type' => 'expenses', 'status' => 'active', 'created_by' => 1],
        ]);

        $transactions = [];
        $payments = [];
        $index = 0;
        foreach ($categories as $categoryId => $name) {
            $transactionId = 960 + $index;
            $paymentId = 560 + $index;
            $transactions[] = [
                'id' => $transactionId,
                'business_id' => 7,
                'location_id' => 70,
                'type' => 'expense',
                'status' => 'final',
                'expense_category_id' => $categoryId,
                'transaction_date' => '2023-06-'.str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT).' 10:00:00',
                'final_total' => 100,
                'total_before_tax' => 100,
                'tax_amount' => 0,
                'created_by' => 1,
                'invoice_no' => 'EXP-'.$transactionId,
                'ref_no' => 'EXPENSE-'.$transactionId,
            ];
            $payments[] = [
                'id' => $paymentId,
                'transaction_id' => $transactionId,
                'business_id' => 7,
                'account_id' => 201,
                'amount' => 100,
                'paid_on' => '2023-06-'.str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT).' 10:00:00',
                'created_by' => 1,
                'is_return' => 0,
                'is_advance' => 0,
            ];
            $index++;
        }
        DB::table('transactions')->insert($transactions);
        DB::table('transaction_payments')->insert($payments);

        $this->artisan('accounting:backfill-general-ledger', ['business_id' => 7])
            ->expectsOutputToContain('Planned groups: 6')
            ->expectsOutputToContain('deposit_to #120 Amortisation expense (semantic_hint; score 1.0000)')
            ->expectsOutputToContain('deposit_to #121 Repair and maintenance (semantic_hint; score 1.0000)')
            ->expectsOutputToContain('deposit_to #122 Dues and Subscriptions (semantic_hint; score 1.0000)')
            ->expectsOutputToContain('deposit_to #123 Other general and administrative expenses (semantic_hint; score 1.0000)')
            ->expectsOutputToContain('deposit_to #124 Insurance - General (semantic_hint; score 1.0000)')
            ->assertExitCode(0);

        $this->assertSame(0, DB::table('accounting_accounts_transactions')->count());
        $this->assertSame(0, DB::table('accounting_acc_trans_mappings')->count());
        $this->assertSame(0, DB::table('accounting_general_ledger_backfill_reviews')->count());
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
            ['id' => 206, 'business_id' => 7, 'name' => 'Giro'],
            ['id' => 207, 'business_id' => 7, 'name' => 'Employee Receivables (Expenses)'],
        ]);
        DB::table('expense_categories')->insert(['id' => 5, 'business_id' => 7, 'name' => 'Meals']);
        DB::table('expense_categories')->insert([
            ['id' => 6, 'business_id' => 7, 'name' => 'Transportation'],
            ['id' => 42, 'business_id' => 7, 'name' => 'Employee Receivables (Expenses)'],
            ['id' => 45, 'business_id' => 7, 'name' => 'Taxes and Licenses'],
        ]);
        DB::table('accounting_accounts')->where('business_id', 7)->delete();
        DB::table('accounting_accounts')->insert([
            ['id' => 107, 'business_id' => 7, 'name' => 'Trade Payable', 'account_primary_type' => 'liability', 'account_id' => 203, 'status' => 'active', 'created_by' => 1],
            ['id' => 103, 'business_id' => 7, 'name' => 'VAT Out', 'account_primary_type' => 'liability', 'account_id' => null, 'status' => 'active', 'created_by' => 1],
            ['id' => 105, 'business_id' => 7, 'name' => 'VAT In', 'account_primary_type' => 'asset', 'account_id' => null, 'status' => 'active', 'created_by' => 1],
            ['id' => 115, 'business_id' => 7, 'name' => 'Cash and cash equivalents', 'account_primary_type' => 'asset', 'account_id' => null, 'status' => 'active', 'created_by' => 1],
            ['id' => 116, 'business_id' => 7, 'name' => 'Meals and entertainment', 'account_primary_type' => 'expenses', 'account_id' => null, 'status' => 'active', 'created_by' => 1],
            ['id' => 117, 'business_id' => 7, 'name' => 'Accounts Receivable (A/R)', 'account_primary_type' => 'asset', 'account_id' => null, 'status' => 'active', 'created_by' => 1],
            ['id' => 118, 'business_id' => 7, 'name' => 'Travel expenses - general and admin expenses', 'account_primary_type' => 'expenses', 'account_id' => null, 'status' => 'active', 'created_by' => 1],
            ['id' => 119, 'business_id' => 7, 'name' => 'Income tax expense', 'account_primary_type' => 'expenses', 'account_id' => null, 'status' => 'active', 'created_by' => 1],
            ['id' => 123, 'business_id' => 7, 'name' => 'Other general and administrative expenses', 'account_primary_type' => 'expenses', 'account_id' => null, 'status' => 'active', 'created_by' => 1],
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
            [
                'id' => 942,
                'business_id' => 7,
                'location_id' => 70,
                'type' => 'expense',
                'status' => 'final',
                'expense_category_id' => 6,
                'transaction_date' => '2023-05-03 10:00:00',
                'final_total' => 10,
                'total_before_tax' => 10,
                'tax_amount' => 0,
                'created_by' => 1,
                'invoice_no' => 'EXP-942',
                'ref_no' => 'EXPENSE-942',
            ],
            [
                'id' => 943,
                'business_id' => 7,
                'location_id' => 70,
                'type' => 'expense',
                'status' => 'final',
                'expense_category_id' => 42,
                'transaction_date' => '2023-05-04 10:00:00',
                'final_total' => 12,
                'total_before_tax' => 12,
                'tax_amount' => 0,
                'created_by' => 1,
                'invoice_no' => 'EXP-943',
                'ref_no' => 'EXPENSE-943',
            ],
            [
                'id' => 944,
                'business_id' => 7,
                'location_id' => 70,
                'type' => 'expense',
                'status' => 'final',
                'expense_category_id' => 45,
                'transaction_date' => '2023-05-05 10:00:00',
                'final_total' => 8,
                'total_before_tax' => 8,
                'tax_amount' => 0,
                'created_by' => 1,
                'invoice_no' => 'EXP-944',
                'ref_no' => 'EXPENSE-944',
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
            ->expectsOutputToContain('Planned groups: 5')
            ->expectsOutputToContain('Auto-mapped groups: 5')
            ->expectsOutputToContain('Automatic nearest CoA mappings used:')
            ->expectsOutputToContain('[expense_5]')
            ->expectsOutputToContain('Trade Payable')
            ->expectsOutputToContain('account #206 Giro -> accounting #115 Cash and cash equivalents [semantic_hint; score 1.0000]')
            ->expectsOutputToContain('account #207 Employee Receivables (Expenses) -> accounting #117 Accounts Receivable (A/R) [semantic_hint; score 1.0000]')
            ->expectsOutputToContain('Debit total: 107.00')
            ->expectsOutputToContain('Credit total: 107.00')
            ->assertExitCode(0);

        $this->artisan('accounting:backfill-general-ledger', ['business_id' => 7, '--apply' => true])
            ->expectsOutputToContain('Applied groups: 5')
            ->assertExitCode(0);

        $rows = DB::table('accounting_accounts_transactions')->orderBy('id')->get();
        $this->assertCount(11, $rows);
        $this->assertSame(72.0, (float) $rows->where('accounting_account_id', 116)->sum('amount'));
        $this->assertSame(5.0, (float) $rows->where('accounting_account_id', 105)->sum('amount'));
        $this->assertSame(55.0, (float) $rows->where('accounting_account_id', 115)->sum('amount'));
        $this->assertSame(52.0, (float) $rows->where('accounting_account_id', 107)->sum('amount'));
        $this->assertSame(10.0, (float) $rows->where('accounting_account_id', 118)->sum('amount'));
        $this->assertSame(12.0, (float) $rows->where('accounting_account_id', 117)->sum('amount'));
        $this->assertSame(8.0, (float) $rows->where('accounting_account_id', 123)->sum('amount'));
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

    public function test_income_tax_accounts_are_not_used_as_vat_accounts(): void
    {
        DB::table('accounting_accounts')->whereIn('id', [103, 105])->delete();
        DB::table('accounting_accounts')->insert([
            ['id' => 113, 'business_id' => 7, 'name' => 'Income tax expense', 'account_primary_type' => 'expenses', 'account_id' => null, 'status' => 'active', 'created_by' => 1],
            ['id' => 114, 'business_id' => 7, 'name' => 'Income tax payable', 'account_primary_type' => 'liability', 'account_id' => null, 'status' => 'active', 'created_by' => 1],
            ['id' => 115, 'business_id' => 7, 'name' => 'Deferred tax assets', 'account_primary_type' => 'asset', 'account_id' => null, 'status' => 'active', 'created_by' => 1],
        ]);
        DB::table('transactions')->insert([
            'id' => 904,
            'business_id' => 7,
            'location_id' => 70,
            'type' => 'sell',
            'status' => 'final',
            'transaction_date' => '2023-01-07 10:00:00',
            'final_total' => 110,
            'total_before_tax' => 100,
            'tax_amount' => 10,
            'created_by' => 1,
            'invoice_no' => 'INV-904',
            'ref_no' => 'SALE-904',
        ]);

        $this->artisan('accounting:backfill-general-ledger', ['business_id' => 7])
            ->expectsOutputToContain('Planned groups: 0')
            ->expectsOutputToContain('Ambiguous VAT accounts: 0')
            ->expectsOutputToContain('Missing VAT accounts: 1')
            ->expectsOutputToContain('akun_vat_tidak_ditemukan: 1')
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

    public function test_retry_review_item_rechecks_one_source_and_is_idempotent_after_journal_link(): void
    {
        DB::table('transactions')->insert([
            'id' => 990,
            'business_id' => 7,
            'location_id' => 70,
            'type' => 'sell',
            'status' => 'final',
            'transaction_date' => '2023-08-01 10:00:00',
            'final_total' => 125,
            'total_before_tax' => 125,
            'tax_amount' => 0,
            'created_by' => 1,
            'invoice_no' => 'INV-990',
            'ref_no' => 'SALE-990',
        ]);
        DB::table('accounting_general_ledger_backfill_reviews')->insert([
            'id' => 990,
            'business_id' => 7,
            'source_record_type' => \App\Transaction::class,
            'source_record_id' => 990,
            'operation_date' => '2023-08-01 10:00:00',
            'source_reference' => 'INV-990',
            'source_summary' => 'Transaksi sell #990',
            'source_context' => json_encode(['transaction_type' => 'sell', 'location_id' => 70, 'final_total' => 125, 'tax_amount' => 0]),
            'skip_reason' => 'mapping_lokasi_tidak_lengkap',
            'status' => 'retrying',
            'location_id' => 70,
            'mapping_key' => 'sale',
            'transaction_kind' => 'sell',
            'counterpart_main_account_id' => null,
            'first_seen_at' => now(),
            'last_seen_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $service = app(\Modules\Accounting\Services\GeneralLedgerBackfillService::class);
        $firstResult = $service->retryReviewItem(7, 990);
        $secondResult = $service->retryReviewItem(7, 990);

        $this->assertTrue($firstResult['resolved']);
        $this->assertTrue($secondResult['resolved']);
        $this->assertSame('resolved', DB::table('accounting_general_ledger_backfill_reviews')->where('id', 990)->value('status'));
        $this->assertSame(1, DB::table('accounting_acc_trans_mappings')->where('business_id', 7)->where('source_record_type', \App\Transaction::class)->where('source_record_id', 990)->count());
        $this->assertSame(2, DB::table('accounting_accounts_transactions')->where('transaction_id', 990)->count());
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
        Schema::create('accounting_general_ledger_backfill_reviews', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedInteger('business_id');
            $table->string('source_record_type', 100);
            $table->unsignedBigInteger('source_record_id');
            $table->dateTime('operation_date')->nullable();
            $table->string('source_reference', 191)->nullable();
            $table->text('source_summary');
            $table->json('source_context')->nullable();
            $table->string('skip_reason', 100);
            $table->string('status', 24)->default('pending');
            $table->unsignedInteger('location_id')->nullable();
            $table->string('mapping_key', 64)->nullable();
            $table->string('transaction_kind', 24)->nullable();
            $table->unsignedInteger('main_account_id')->nullable();
            $table->unsignedInteger('counterpart_main_account_id')->nullable();
            $table->unsignedBigInteger('retry_batch_id')->nullable();
            $table->dateTime('first_seen_at')->nullable();
            $table->dateTime('last_seen_at')->nullable();
            $table->dateTime('resolved_at')->nullable();
            $table->json('audit_log')->nullable();
            $table->timestamps();
            $table->unique(['business_id', 'source_record_type', 'source_record_id']);
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
