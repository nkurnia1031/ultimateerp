<?php

namespace Tests\Feature;

use App\User;
use App\Utils\ModuleUtil;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Modules\Accounting\Jobs\ProcessGeneralLedgerBackfillRetryJob;
use Modules\Accounting\Services\GeneralLedgerBackfillReviewService;
use Mockery;
use Tests\TestCase;

class GeneralLedgerBackfillReviewTest extends TestCase
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
        DB::table('business_locations')->insert([
            ['id' => 70, 'business_id' => 7, 'name' => 'Lokasi Utama', 'accounting_default_map' => '{}'],
            ['id' => 80, 'business_id' => 8, 'name' => 'Business Lain', 'accounting_default_map' => '{}'],
        ]);
        DB::table('accounts')->insert([
            ['id' => 201, 'business_id' => 7, 'name' => 'Bank Operasional'],
            ['id' => 202, 'business_id' => 7, 'name' => 'Kas Kecil'],
            ['id' => 801, 'business_id' => 8, 'name' => 'Akun Tenant Lain'],
        ]);
        DB::table('accounting_accounts')->insert([
            ['id' => 101, 'business_id' => 7, 'name' => 'Piutang Usaha', 'gl_code' => '1100', 'account_primary_type' => 'asset', 'status' => 'active', 'account_id' => null],
            ['id' => 102, 'business_id' => 7, 'name' => 'Pendapatan Penjualan', 'gl_code' => '4000', 'account_primary_type' => 'income', 'status' => 'active', 'account_id' => null],
            ['id' => 103, 'business_id' => 7, 'name' => 'VAT Masukan', 'gl_code' => '1400', 'account_primary_type' => 'asset', 'status' => 'active', 'account_id' => null],
            ['id' => 104, 'business_id' => 7, 'name' => 'VAT Keluaran', 'gl_code' => '2100', 'account_primary_type' => 'liability', 'status' => 'active', 'account_id' => null],
            ['id' => 801, 'business_id' => 8, 'name' => 'CoA Tenant Lain', 'gl_code' => '1000', 'account_primary_type' => 'asset', 'status' => 'active', 'account_id' => null],
        ]);
    }

    public function test_review_list_filters_search_and_pagination_are_scoped_to_business(): void
    {
        $this->insertReview(1, 7, 'App\\Transaction', 9001, 'mapping_lokasi_tidak_lengkap', 'sale', 70, 'sell', 'INV-9001', 'Penjualan unit A');
        $this->insertReview(2, 7, 'App\\TransactionPayment', 9002, 'akun_utama_belum_dimapping', 'sell_payment', 70, 'sell', 'PAY-9002', 'Pembayaran INV-9002');
        $this->insertReview(3, 8, 'App\\Transaction', 8001, 'mapping_lokasi_tidak_lengkap', 'sale', 80, 'sell', 'INV-8001', 'Tenant lain');

        $result = app(GeneralLedgerBackfillReviewService::class)->listItems(7, [
            'search' => 'INV-9001',
            'reason' => 'mapping_lokasi_tidak_lengkap',
            'status' => 'pending',
            'page' => 1,
            'per_page' => 10,
        ]);

        $this->assertSame(1, $result['meta']['total']);
        $this->assertSame(9001, $result['data'][0]['source_record_id']);
        $this->assertSame('Transaction', $result['data'][0]['source_type']);
    }

    public function test_location_mapping_is_business_validated_and_returns_only_matching_pending_sources(): void
    {
        $this->insertReview(1, 7, 'App\\Transaction', 9001, 'mapping_lokasi_tidak_lengkap', 'sale', 70, 'sell', 'INV-9001', 'Penjualan');
        $this->insertReview(2, 7, 'App\\Transaction', 9002, 'akun_vat_tidak_ditemukan', 'sale', 70, 'sell', 'INV-9002', 'Penjualan kena pajak');
        $this->insertReview(3, 7, 'App\\Transaction', 9003, 'mapping_lokasi_tidak_lengkap', 'purchases', 70, 'purchase', 'PUR-9003', 'Pembelian');
        $this->insertReview(4, 8, 'App\\Transaction', 8001, 'mapping_lokasi_tidak_lengkap', 'sale', 80, 'sell', 'INV-8001', 'Tenant lain');

        $service = app(GeneralLedgerBackfillReviewService::class);
        $matched = $service->saveLocationMapping(7, 55, [
            'location_id' => 70,
            'mapping_key' => 'sale',
            'payment_account_id' => 102,
            'deposit_to_id' => 101,
        ]);

        $this->assertSame(2, $matched);
        $saved = json_decode(DB::table('business_locations')->where('id', 70)->value('accounting_default_map'), true);
        $this->assertSame(102, (int) $saved['sale']['payment_account']);
        $this->assertSame(101, (int) $saved['sale']['deposit_to']);

        $this->expectException(ValidationException::class);
        $service->saveLocationMapping(7, 55, [
            'location_id' => 80,
            'mapping_key' => 'sale',
            'payment_account_id' => 102,
            'deposit_to_id' => 101,
        ]);
    }

    public function test_vat_mapping_is_per_business_direction_and_counts_only_compatible_pending_items(): void
    {
        $this->insertReview(1, 7, 'App\\Transaction', 9001, 'akun_vat_tidak_ditemukan', 'purchases', 70, 'purchase', 'PUR-9001', 'Pembelian pajak');
        $this->insertReview(2, 7, 'App\\Transaction', 9002, 'akun_vat_ambigu', 'expense_5', 70, 'expense', 'EXP-9002', 'Biaya pajak');
        $this->insertReview(3, 7, 'App\\Transaction', 9003, 'akun_vat_tidak_ditemukan', 'sale', 70, 'sell', 'INV-9003', 'Penjualan pajak');

        $service = app(GeneralLedgerBackfillReviewService::class);
        $matched = $service->saveVatMapping(7, 55, 'input', 103);

        $this->assertSame(2, $matched);
        $this->assertSame(103, (int) DB::table('accounting_general_ledger_vat_accounts')->where('business_id', 7)->where('direction', 'input')->value('accounting_account_id'));
        $this->assertSame(0, DB::table('accounting_general_ledger_vat_accounts')->where('business_id', 8)->count());

        $this->expectException(ValidationException::class);
        $service->saveVatMapping(7, 55, 'output', 801);
    }

    public function test_main_account_crosswalk_and_confirmed_retry_batch_are_idempotent_and_tenant_scoped(): void
    {
        Queue::fake();
        $this->insertReview(1, 7, 'App\\TransactionPayment', 9001, 'akun_utama_belum_dimapping', 'sell_payment', 70, 'sell', 'PAY-9001', 'Pembayaran', 201);
        $this->insertReview(2, 7, 'App\\TransactionPayment', 9002, 'akun_utama_belum_dimapping', 'sell_payment', 70, 'sell', 'PAY-9002', 'Pembayaran', 201);
        $this->insertReview(3, 7, 'App\\TransactionPayment', 9003, 'akun_utama_belum_dimapping', 'sell_payment', 70, 'sell', 'PAY-9003', 'Lain', 202);
        DB::table('accounting_general_ledger_backfill_reviews')->where('id', 3)->update(['counterpart_main_account_id' => 201]);
        $this->insertReview(4, 8, 'App\\TransactionPayment', 8001, 'akun_utama_belum_dimapping', 'sell_payment', 80, 'sell', 'PAY-8001', 'Tenant lain', 201);

        $service = app(GeneralLedgerBackfillReviewService::class);
        $this->assertSame(3, $service->saveMainAccountMapping(7, 55, 201, 101));
        $this->assertSame(201, (int) DB::table('accounting_accounts')->where('id', 101)->value('account_id'));

        $first = $service->enqueueRetry(7, 55, ['type' => 'main_account', 'main_account_id' => 201]);
        $second = $service->enqueueRetry(7, 55, ['type' => 'main_account', 'main_account_id' => 201]);

        $this->assertSame(3, $first['total_items']);
        $this->assertSame(0, $second['total_items']);
        $this->assertSame('retrying', DB::table('accounting_general_ledger_backfill_reviews')->where('id', 1)->value('status'));
        $this->assertSame('retrying', DB::table('accounting_general_ledger_backfill_reviews')->where('id', 3)->value('status'));
        $this->assertSame('pending', DB::table('accounting_general_ledger_backfill_reviews')->where('id', 4)->value('status'));
        $this->assertSame(1, DB::table('accounting_general_ledger_backfill_retry_batches')->where('business_id', 7)->where('total_items', 3)->count());
        Queue::assertPushed(ProcessGeneralLedgerBackfillRetryJob::class);
        $this->assertCount(1, Queue::pushed(ProcessGeneralLedgerBackfillRetryJob::class));
        $this->assertSame(3, $service->retryBatchStatus(7, $first['batch_id'])['total_items']);
        $this->assertNull($service->retryBatchStatus(8, $first['batch_id']));
    }

    public function test_review_page_enforces_journal_view_permission(): void
    {
        $user = Mockery::mock(User::class)->makePartial();
        $user->id = 55;
        $user->shouldReceive('can')->with('superadmin')->andReturn(true);
        $user->shouldReceive('can')->with('accounting.view_journal')->andReturn(false);

        $response = $this->withoutMiddleware([
            \App\Http\Middleware\SetSessionData::class,
            \App\Http\Middleware\Language::class,
            \App\Http\Middleware\Timezone::class,
            \App\Http\Middleware\AdminSidebarMenu::class,
        ])->actingAs($user)->withSession(['user' => ['business_id' => 7]])
            ->get(route('accounting.generalLedger.backfillReview'));

        $response->assertForbidden();
    }

    public function test_review_page_enforces_accounting_subscription(): void
    {
        $moduleUtil = Mockery::mock(ModuleUtil::class);
        $moduleUtil->shouldReceive('hasThePermissionInSubscription')->once()->with(7, 'accounting_module')->andReturn(false);
        $this->app->instance(ModuleUtil::class, $moduleUtil);

        $user = Mockery::mock(User::class)->makePartial();
        $user->id = 55;
        $user->shouldReceive('can')->with('superadmin')->andReturn(false);
        $user->shouldReceive('can')->with('accounting.view_journal')->andReturn(true);

        $response = $this->withoutMiddleware([
            \App\Http\Middleware\SetSessionData::class,
            \App\Http\Middleware\Language::class,
            \App\Http\Middleware\Timezone::class,
            \App\Http\Middleware\AdminSidebarMenu::class,
        ])->actingAs($user)->withSession(['user' => ['business_id' => 7]])
            ->get(route('accounting.generalLedger.backfillReview'));

        $response->assertForbidden();
    }

    private function insertReview(
        int $id,
        int $businessId,
        string $sourceType,
        int $sourceId,
        string $reason,
        string $mappingKey,
        int $locationId,
        string $kind,
        string $reference,
        string $summary,
        ?int $mainAccountId = null
    ): void {
        DB::table('accounting_general_ledger_backfill_reviews')->insert([
            'id' => $id,
            'business_id' => $businessId,
            'source_record_type' => $sourceType,
            'source_record_id' => $sourceId,
            'operation_date' => '2023-01-01 10:00:00',
            'source_reference' => $reference,
            'source_summary' => $summary,
            'source_context' => json_encode(['transaction_type' => $kind, 'location_id' => $locationId]),
            'skip_reason' => $reason,
            'status' => 'pending',
            'location_id' => $locationId,
            'mapping_key' => $mappingKey,
            'transaction_kind' => $kind,
            'main_account_id' => $mainAccountId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createSchema(): void
    {
        Schema::create('business_locations', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('business_id');
            $table->string('name');
            $table->text('accounting_default_map')->nullable();
            $table->timestamps();
        });
        Schema::create('accounts', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('business_id');
            $table->string('name');
        });
        Schema::create('accounting_accounts', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('business_id');
            $table->string('name');
            $table->string('gl_code')->nullable();
            $table->string('account_primary_type')->nullable();
            $table->string('status')->nullable();
            $table->unsignedInteger('account_id')->nullable();
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
        Schema::create('accounting_general_ledger_vat_accounts', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedInteger('business_id');
            $table->string('direction', 10);
            $table->unsignedBigInteger('accounting_account_id');
            $table->unsignedInteger('created_by')->nullable();
            $table->unsignedInteger('updated_by')->nullable();
            $table->timestamps();
            $table->unique(['business_id', 'direction']);
        });
        Schema::create('accounting_general_ledger_backfill_retry_batches', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedInteger('business_id');
            $table->unsignedInteger('created_by');
            $table->string('status', 24)->default('queued');
            $table->unsignedInteger('total_items')->default(0);
            $table->unsignedInteger('processed_items')->default(0);
            $table->unsignedInteger('resolved_items')->default(0);
            $table->unsignedInteger('failed_items')->default(0);
            $table->text('last_message')->nullable();
            $table->dateTime('started_at')->nullable();
            $table->dateTime('finished_at')->nullable();
            $table->timestamps();
        });
        Schema::create('accounting_general_ledger_backfill_retry_items', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('batch_id');
            $table->unsignedBigInteger('review_id');
            $table->string('status', 24)->default('queued');
            $table->text('message')->nullable();
            $table->timestamps();
            $table->unique(['batch_id', 'review_id']);
        });
    }
}
