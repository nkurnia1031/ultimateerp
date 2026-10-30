<?php

namespace Tests\Feature;

use App\AccountTransaction;
use App\Transaction;
use App\TransactionPayment;
use App\User;
use App\Utils\ModuleUtil;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\TestCase;

class JournalImportCandidateResponseTest extends TestCase
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
        DB::table('accounting_journal_imports')->insert([
            'id' => 5,
            'business_id' => 7,
            'status' => 'analyzed',
            'import_options' => '{}',
            'total_entries' => 3,
            'processed_entries' => 0,
        ]);

        DB::table('business_locations')->insert([
            'id' => 70,
            'business_id' => 7,
            'name' => 'Lokasi aktif test',
            'is_active' => 1,
        ]);
        DB::table('accounts')->insert([
            ['id' => 30, 'business_id' => 7, 'name' => 'Bank CI', 'account_number' => 'CI-30'],
        ]);
        DB::table('transactions')->insert([
            ['id' => 10, 'business_id' => 7, 'type' => 'expense', 'invoice_no' => 'EXP-CI', 'transaction_date' => '2026-10-01 00:00:00', 'total_before_tax' => 110, 'tax_amount' => 0, 'final_total' => 110],
            ['id' => 11, 'business_id' => 7, 'type' => 'expense', 'invoice_no' => 'EXP-CI', 'transaction_date' => '2026-10-01 00:00:00', 'total_before_tax' => 100, 'tax_amount' => 10, 'final_total' => 110],
            ['id' => 12, 'business_id' => 8, 'type' => 'expense', 'invoice_no' => 'EXP-CI', 'transaction_date' => '2026-10-01 00:00:00', 'total_before_tax' => 100, 'tax_amount' => 10, 'final_total' => 110],
            ['id' => 20, 'business_id' => 7, 'type' => 'sell', 'invoice_no' => 'SELL-CI', 'transaction_date' => '2026-10-01 00:00:00', 'total_before_tax' => 75, 'tax_amount' => 0, 'final_total' => 75],
        ]);
        DB::table('transaction_payments')->insert([
            'id' => 40,
            'transaction_id' => 20,
            'payment_ref_no' => 'PAY-CI',
            'paid_on' => '2026-10-01 00:00:00',
            'amount' => 75,
            'method' => 'cash',
        ]);
        DB::table('account_transactions')->insert([
            'id' => 50,
            'account_id' => 30,
            'sub_type' => 'deposit',
            'reff_no' => 'DEP-CI',
            'operation_date' => '2026-10-01 00:00:00',
            'amount' => 100,
            'type' => 'credit',
            'note' => 'CI deposit',
        ]);

        $this->insertCandidateRow(1, 'Expense', 'EXP-CI', 110, 110, [
            ['id' => 10, 'record_type' => Transaction::class],
            ['id' => 11, 'record_type' => Transaction::class],
            ['id' => 12, 'record_type' => Transaction::class],
        ], [
            ['account_name' => 'Office expense', 'type' => 'debit', 'debit' => 100],
            ['account_name' => 'VAT In', 'type' => 'debit', 'debit' => 10],
            ['account_name' => 'Cash', 'type' => 'credit', 'credit' => 110],
        ]);
        $this->insertCandidateRow(2, 'Receive Payment', 'PAY-CI', 75, 75, [
            ['id' => 40, 'record_type' => TransactionPayment::class],
        ], [
            ['account_name' => 'Cash', 'type' => 'debit', 'debit' => 75],
            ['account_name' => 'Accounts Receivable', 'type' => 'credit', 'credit' => 75],
        ]);
        $this->insertCandidateRow(3, 'Bank Deposit', 'DEP-CI', 100, 100, [
            ['id' => 50, 'record_type' => AccountTransaction::class],
        ], [
            ['account_name' => 'Bank CI', 'type' => 'debit', 'debit' => 100],
            ['account_name' => 'Other income', 'type' => 'credit', 'credit' => 100],
        ]);

        $moduleUtil = Mockery::mock(ModuleUtil::class);
        $moduleUtil->shouldReceive('hasThePermissionInSubscription')->byDefault()->andReturn(true);
        $this->app->instance(ModuleUtil::class, $moduleUtil);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_public_draft_rows_endpoint_returns_component_ranked_transaction_candidates_in_business_scope(): void
    {
        $response = $this->requestDraftRows('Expense');

        $response->assertOk()->assertJsonPath('total', 1);
        $response->assertJsonPath('rows.0.source_link_candidate_details.0.id', 11);
        $response->assertJsonPath('rows.0.source_link_candidate_details.0.base_difference', 0);
        $response->assertJsonPath('rows.0.source_link_candidate_details.0.tax_difference', 0);
        $response->assertJsonPath('rows.0.source_link_candidate_details.0.tax_direction_match', true);
        $response->assertJsonPath('rows.0.source_link_candidate_details.1.id', 10);
        $response->assertJsonPath('rows.0.source_link_candidate_details.1.base_difference', 10);
        $response->assertJsonPath('rows.0.source_link_candidate_details.1.tax_difference', 10);
        $response->assertJsonPath('rows.0.source_link_candidate_details.1.tax_direction_match', null);
        $this->assertNotContains(12, array_column($response->json('rows.0.source_link_candidate_details'), 'id'));
    }

    public function test_public_draft_rows_endpoint_returns_payment_candidate_details(): void
    {
        $response = $this->requestDraftRows('Receive Payment');

        $response->assertOk()
            ->assertJsonPath('rows.0.source_link_candidate_details.0.id', 40)
            ->assertJsonPath('rows.0.source_link_candidate_details.0.amount', 75)
            ->assertJsonPath('rows.0.source_link_candidate_details.0.record_type', TransactionPayment::class);
    }

    public function test_public_draft_rows_endpoint_returns_bank_candidate_details(): void
    {
        $response = $this->requestDraftRows('Bank Deposit');

        $response->assertOk()
            ->assertJsonPath('rows.0.source_link_candidate_details.0.id', 50)
            ->assertJsonPath('rows.0.source_link_candidate_details.0.amount', 100)
            ->assertJsonPath('rows.0.source_link_candidate_details.0.record_type', AccountTransaction::class);
    }

    private function requestDraftRows(string $type)
    {
        $user = Mockery::mock(User::class)->makePartial();
        $user->id = 55;
        $user->shouldReceive('can')->with('superadmin')->andReturn(false);
        $user->shouldReceive('can')->with('accounting.add_journal')->andReturn(true);

        return $this->withoutMiddleware([
            \App\Http\Middleware\SetSessionData::class,
            \App\Http\Middleware\Language::class,
            \App\Http\Middleware\Timezone::class,
            \App\Http\Middleware\AdminSidebarMenu::class,
        ])->actingAs($user)->withSession(['user' => ['business_id' => 7]])
            ->getJson('/accounting/journal-entry/import/5/draft-rows?'.http_build_query([
                'type' => $type,
                'per_page' => 100,
            ]));
    }

    private function insertCandidateRow(int $entryIndex, string $sourceType, string $sourceNumber, float $debit, float $credit, array $candidates, array $lines): void
    {
        DB::table('accounting_journal_import_rows')->insert([
            'journal_import_id' => 5,
            'business_id' => 7,
            'entry_index' => $entryIndex,
            'source_type' => $sourceType,
            'source_number' => $sourceNumber,
            'source_key' => $sourceType.'|'.$sourceNumber,
            'source_action' => 'review',
            'entry_data' => json_encode([
                'source_type' => $sourceType,
                'source_number' => $sourceNumber,
                'date' => '2026-10-01',
                'description' => 'Candidate response fixture',
                'debit_total' => $debit,
                'credit_total' => $credit,
                'lines' => $lines,
                'source_link_status' => 'suggested',
                'source_link_candidate_details' => $candidates,
            ]),
            'draft_data' => '{}',
            'processing_status' => 'pending',
            'reconciliation_status' => 'not_checked',
        ]);
    }

    private function createSchema(): void
    {
        Schema::create('accounting_journal_imports', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedInteger('business_id');
            $table->string('status');
            $table->longText('import_options')->nullable();
            $table->unsignedInteger('total_entries')->default(0);
            $table->unsignedInteger('processed_entries')->default(0);
        });
        Schema::create('accounting_journal_import_rows', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('journal_import_id');
            $table->unsignedInteger('business_id');
            $table->unsignedInteger('entry_index');
            $table->string('source_type');
            $table->string('source_number')->nullable();
            $table->text('source_key')->nullable();
            $table->string('source_action');
            $table->string('source_record_type')->nullable();
            $table->unsignedBigInteger('source_record_id')->nullable();
            $table->longText('entry_data');
            $table->longText('draft_data')->nullable();
            $table->boolean('is_reviewed')->default(false);
            $table->string('processing_status')->default('pending');
            $table->unsignedInteger('processing_attempts')->default(0);
            $table->text('processing_message')->nullable();
            $table->text('processing_error')->nullable();
            $table->dateTime('processing_started_at')->nullable();
            $table->dateTime('processed_at')->nullable();
            $table->unsignedBigInteger('journal_mapping_id')->nullable();
            $table->string('reconciliation_status')->default('not_checked');
            $table->decimal('journal_total', 22, 4)->nullable();
            $table->decimal('business_total', 22, 4)->nullable();
            $table->decimal('difference_amount', 22, 4)->nullable();
            $table->unsignedInteger('difference_approved_by')->nullable();
            $table->dateTime('difference_approved_at')->nullable();
            $table->text('difference_approval_reason')->nullable();
            $table->timestamps();
        });
        Schema::create('business_locations', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedInteger('business_id');
            $table->string('name')->nullable();
            $table->boolean('is_active')->default(true);
            $table->dateTime('deleted_at')->nullable();
        });
        Schema::create('contacts', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedInteger('business_id');
            $table->string('name')->nullable();
            $table->string('supplier_business_name')->nullable();
        });
        Schema::create('expense_categories', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedInteger('business_id');
            $table->string('name')->nullable();
        });
        Schema::create('transactions', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedInteger('business_id');
            $table->string('type');
            $table->string('invoice_no')->nullable();
            $table->string('ref_no')->nullable();
            $table->dateTime('transaction_date');
            $table->decimal('total_before_tax', 22, 4)->nullable();
            $table->decimal('tax_amount', 22, 4)->nullable();
            $table->decimal('final_total', 22, 4)->default(0);
            $table->unsignedInteger('contact_id')->nullable();
            $table->unsignedInteger('location_id')->nullable();
            $table->unsignedInteger('expense_category_id')->nullable();
            $table->text('additional_notes')->nullable();
        });
        Schema::create('accounts', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedInteger('business_id');
            $table->string('name')->nullable();
            $table->string('account_number')->nullable();
            $table->dateTime('deleted_at')->nullable();
        });
        Schema::create('transaction_payments', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('transaction_id');
            $table->string('payment_ref_no')->nullable();
            $table->dateTime('paid_on')->nullable();
            $table->decimal('amount', 22, 4)->default(0);
            $table->string('method')->nullable();
            $table->string('note')->nullable();
            $table->unsignedInteger('account_id')->nullable();
        });
        Schema::create('account_transactions', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedInteger('account_id');
            $table->string('sub_type')->nullable();
            $table->string('reff_no')->nullable();
            $table->dateTime('operation_date')->nullable();
            $table->decimal('amount', 22, 4)->default(0);
            $table->string('type')->nullable();
            $table->text('note')->nullable();
            $table->unsignedBigInteger('transfer_transaction_id')->nullable();
            $table->dateTime('deleted_at')->nullable();
        });
    }
}
