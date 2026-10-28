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
use Modules\Accounting\Services\GeneralLedgerBackfillService;
use Modules\Accounting\Services\GeneralLedgerBackfillManualJournalService;
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

    public function test_selected_retry_preview_is_balanced_and_requires_explicit_confirmation(): void
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

        $previewer = Mockery::mock(GeneralLedgerBackfillService::class);
        $previewer->shouldReceive('previewReviewItems')->twice()->with(7, [1, 2, 3])->andReturn([
            $this->eligiblePreview(1, 100, [
                ['accounting_account_id' => 101, 'account_code' => '1100', 'account_name' => 'Piutang Usaha', 'type' => 'debit', 'amount' => 100],
                ['accounting_account_id' => 102, 'account_code' => '4000', 'account_name' => 'Pendapatan Penjualan', 'type' => 'credit', 'amount' => 100],
            ]),
            $this->eligiblePreview(2, 50, [
                ['accounting_account_id' => 103, 'account_code' => '1400', 'account_name' => 'VAT Masukan', 'type' => 'debit', 'amount' => 50],
                ['accounting_account_id' => 102, 'account_code' => '4000', 'account_name' => 'Pendapatan Penjualan', 'type' => 'credit', 'amount' => 50],
            ]),
            array_merge($this->eligiblePreview(3, 0, []), ['eligible' => false, 'plan_hash' => null, 'reason' => 'akun_vat_tidak_ditemukan']),
        ]);
        $this->app->instance(GeneralLedgerBackfillService::class, $previewer);
        $this->app->forgetInstance(GeneralLedgerBackfillReviewService::class);
        $service = app(GeneralLedgerBackfillReviewService::class);

        $preview = $service->createRetryPreview(7, 55, [1, 2, 3]);

        $this->assertSame('awaiting_confirmation', $preview['status']);
        $this->assertSame(3, $preview['selected_items']);
        $this->assertSame(2, $preview['total_items']);
        $this->assertSame(150.0, $preview['planned_debit']);
        $this->assertSame(150.0, $preview['planned_credit']);
        $this->assertSame('pending', DB::table('accounting_general_ledger_backfill_reviews')->where('id', 1)->value('status'));
        $this->assertSame('pending', DB::table('accounting_general_ledger_backfill_reviews')->where('id', 3)->value('status'));
        $this->assertSame(0, DB::table('accounting_general_ledger_backfill_retry_items')->where('status', 'queued')->count());
        Queue::assertNothingPushed();

        $confirmed = $service->confirmRetryPreview(7, 55, $preview['batch_id'], $preview['plan_hash']);

        $this->assertSame('queued', $confirmed['status']);
        $this->assertSame(2, $confirmed['total_items']);
        $this->assertSame(55, (int) DB::table('accounting_general_ledger_backfill_retry_batches')->where('id', $preview['batch_id'])->value('confirmed_by'));
        $this->assertSame('retrying', DB::table('accounting_general_ledger_backfill_reviews')->where('id', 1)->value('status'));
        $this->assertSame('retrying', DB::table('accounting_general_ledger_backfill_reviews')->where('id', 2)->value('status'));
        $this->assertSame('pending', DB::table('accounting_general_ledger_backfill_reviews')->where('id', 3)->value('status'));
        $this->assertSame('pending', DB::table('accounting_general_ledger_backfill_reviews')->where('id', 4)->value('status'));
        $this->assertSame(1, DB::table('accounting_general_ledger_backfill_retry_batches')->where('business_id', 7)->where('total_items', 2)->count());
        $this->assertSame(2, DB::table('accounting_general_ledger_backfill_retry_items')->where('batch_id', $preview['batch_id'])->where('status', 'queued')->count());
        Queue::assertPushed(ProcessGeneralLedgerBackfillRetryJob::class);
        $this->assertCount(1, Queue::pushed(ProcessGeneralLedgerBackfillRetryJob::class));

        $again = $service->confirmRetryPreview(7, 55, $preview['batch_id'], $preview['plan_hash']);
        $this->assertSame('queued', $again['status']);
        $this->assertCount(1, Queue::pushed(ProcessGeneralLedgerBackfillRetryJob::class));
        $this->assertSame(2, $service->retryBatchStatus(7, $preview['batch_id'])['total_items']);
        $this->assertNull($service->retryBatchStatus(8, $preview['batch_id']));
    }

    public function test_retry_preview_keeps_vat_separate_and_worker_rejects_a_changed_plan(): void
    {
        DB::table('business')->insert(['id' => 7, 'name' => 'CV Demo']);
        DB::table('business_locations')->where('id', 70)->update([
            'accounting_default_map' => json_encode(['sale' => ['payment_account' => 102, 'deposit_to' => 101]]),
        ]);
        DB::table('accounting_general_ledger_vat_accounts')->insert([
            'business_id' => 7,
            'direction' => 'output',
            'accounting_account_id' => 104,
            'created_by' => 55,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('transactions')->insert([
            'id' => 9050,
            'business_id' => 7,
            'location_id' => 70,
            'type' => 'sell',
            'status' => 'final',
            'transaction_date' => '2023-02-01 10:00:00',
            'total_before_tax' => 100,
            'tax_amount' => 10,
            'final_total' => 110,
            'created_by' => 55,
            'invoice_no' => 'INV-9050',
        ]);
        $this->insertReview(50, 7, 'App\\Transaction', 9050, 'akun_vat_tidak_ditemukan', 'sale', 70, 'sell', 'INV-9050', 'Penjualan kena pajak');

        $service = app(GeneralLedgerBackfillService::class);
        $preview = $service->previewReviewItems(7, [50])[0];

        $this->assertTrue($preview['eligible']);
        $this->assertSame(110.0, $preview['debit_total']);
        $this->assertSame(110.0, $preview['credit_total']);
        $this->assertSame(10.0, $preview['source_tax_amount']);
        $this->assertSame('Pendapatan Usaha', $preview['posting_label']);
        $this->assertSame([
            ['accounting_account_id' => 101, 'account_code' => '1100', 'account_name' => 'Piutang Usaha', 'type' => 'debit', 'amount' => 110.0],
            ['accounting_account_id' => 102, 'account_code' => '4000', 'account_name' => 'Pendapatan Penjualan', 'type' => 'credit', 'amount' => 100.0],
            ['accounting_account_id' => 104, 'account_code' => '2100', 'account_name' => 'VAT Keluaran', 'type' => 'credit', 'amount' => 10.0],
        ], $preview['lines']);
        $this->assertSame('pending', DB::table('accounting_general_ledger_backfill_reviews')->where('id', 50)->value('status'));
        $this->assertSame(0, DB::table('accounting_acc_trans_mappings')->count());
        $this->assertSame(0, DB::table('accounting_accounts_transactions')->count());

        DB::table('accounting_general_ledger_backfill_reviews')->where('id', 50)->update(['status' => 'retrying']);
        DB::table('transactions')->where('id', 9050)->update(['final_total' => 120, 'total_before_tax' => 110]);
        $result = $service->retryReviewItem(7, 50, $preview['plan_hash'], 55);

        $this->assertFalse($result['resolved']);
        $this->assertStringContainsString('berubah sejak pratinjau', $result['reason']);
        $this->assertSame(0, DB::table('accounting_acc_trans_mappings')->count());
        $this->assertSame(0, DB::table('accounting_accounts_transactions')->count());
    }

    public function test_retry_confirmation_rejects_a_plan_that_changed_after_preview(): void
    {
        Queue::fake();
        $this->insertReview(10, 7, 'App\\Transaction', 9010, 'mapping_lokasi_tidak_lengkap', 'sale', 70, 'sell', 'INV-9010', 'Penjualan');
        $line = ['accounting_account_id' => 101, 'account_code' => '1100', 'account_name' => 'Piutang Usaha', 'type' => 'debit', 'amount' => 100];
        $initial = $this->eligiblePreview(10, 100, [$line, [
            'accounting_account_id' => 102, 'account_code' => '4000', 'account_name' => 'Pendapatan Penjualan', 'type' => 'credit', 'amount' => 100,
        ]]);
        $changed = $this->eligiblePreview(10, 120, [
            ['accounting_account_id' => 101, 'account_code' => '1100', 'account_name' => 'Piutang Usaha', 'type' => 'debit', 'amount' => 120],
            ['accounting_account_id' => 102, 'account_code' => '4000', 'account_name' => 'Pendapatan Penjualan', 'type' => 'credit', 'amount' => 120],
        ]);
        $changed['plan_hash'] = hash('sha256', 'changed-plan');
        $previewer = Mockery::mock(GeneralLedgerBackfillService::class);
        $previewer->shouldReceive('previewReviewItems')->twice()->with(7, [10])->andReturn([$initial], [$changed]);
        $this->app->instance(GeneralLedgerBackfillService::class, $previewer);
        $this->app->forgetInstance(GeneralLedgerBackfillReviewService::class);
        $service = app(GeneralLedgerBackfillReviewService::class);

        $preview = $service->createRetryPreview(7, 55, [10]);

        try {
            $service->confirmRetryPreview(7, 55, $preview['batch_id'], $preview['plan_hash']);
            $this->fail('Pratinjau yang berubah sebelum konfirmasi harus ditolak.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('plan_hash', $exception->errors());
        }

        $this->assertSame('pending', DB::table('accounting_general_ledger_backfill_reviews')->where('id', 10)->value('status'));
        $this->assertSame('awaiting_confirmation', DB::table('accounting_general_ledger_backfill_retry_batches')->where('id', $preview['batch_id'])->value('status'));
        $this->assertSame(0, DB::table('accounting_general_ledger_backfill_retry_items')->where('batch_id', $preview['batch_id'])->where('status', 'queued')->count());
        Queue::assertNothingPushed();
    }

    private function eligiblePreview(int $reviewId, float $total, array $lines): array
    {
        return [
            'review_id' => $reviewId,
            'eligible' => true,
            'source_type' => 'Transaction',
            'source_record_type' => 'App\\Transaction',
            'source_record_id' => 9000 + $reviewId,
            'source_reference' => 'REF-'.$reviewId,
            'operation_date' => '2023-01-01',
            'source_summary' => 'Sumber uji #'.$reviewId,
            'source_amount' => $total,
            'skip_reason' => 'akun_utama_belum_dimapping',
            'posting_label' => 'sell',
            'mapping_note' => 'Mapping lokasi aktif.',
            'plan_hash' => hash('sha256', 'plan-'.$reviewId),
            'debit_total' => $total,
            'credit_total' => $total,
            'lines' => $lines,
            'reason' => null,
        ];
    }

    public function test_manual_journal_is_balanced_linked_audited_and_idempotent_without_mutating_source(): void
    {
        $this->insertReview(20, 7, 'App\\AccountTransaction', 9020, 'pergerakan_rekening_tanpa_akun_lawan', '', 70, 'expense', 'BANK-9020', 'Mutasi bank manual');
        DB::table('account_transactions')->insert([
            'id' => 9020,
            'account_id' => 201,
            'operation_date' => '2023-04-05 12:30:00',
            'description' => 'Mutasi operasional',
        ]);
        $sourceBefore = (array) DB::table('account_transactions')->where('id', 9020)->first();
        $lines = [
            ['accounting_account_id' => 101, 'debit' => '1250.50', 'credit' => '0'],
            ['accounting_account_id' => 102, 'debit' => '0', 'credit' => '1250.50'],
        ];
        $service = app(GeneralLedgerBackfillManualJournalService::class);

        $created = $service->create(7, 20, 55, 'Koreksi mutasi berdasarkan bukti kas', $lines);
        $duplicate = $service->create(7, 20, 55, 'Koreksi mutasi berdasarkan bukti kas', $lines);

        $this->assertTrue($created['created']);
        $this->assertFalse($duplicate['created']);
        $this->assertSame($created['journal_id'], $duplicate['journal_id']);
        $this->assertSame('GLR-7-20', $created['ref_no']);
        $this->assertSame(1, DB::table('accounting_acc_trans_mappings')->where('business_id', 7)->count());
        $this->assertSame(2, DB::table('accounting_accounts_transactions')->count());
        $this->assertSame(1250.5, (float) DB::table('accounting_accounts_transactions')->where('type', 'debit')->sum('amount'));
        $this->assertSame(1250.5, (float) DB::table('accounting_accounts_transactions')->where('type', 'credit')->sum('amount'));
        $mapping = DB::table('accounting_acc_trans_mappings')->first();
        $this->assertSame('App\\AccountTransaction', $mapping->source_record_type);
        $this->assertSame(9020, (int) $mapping->source_record_id);
        $this->assertSame('matched', $mapping->source_link_status);
        $this->assertSame('Koreksi mutasi berdasarkan bukti kas', $mapping->source_link_note);
        $this->assertSame('resolved', DB::table('accounting_general_ledger_backfill_reviews')->where('id', 20)->value('status'));
        $audit = json_decode(DB::table('accounting_general_ledger_backfill_reviews')->where('id', 20)->value('audit_log'), true);
        $this->assertSame('manual_journal_created', $audit[0]['event']);
        $this->assertSame(55, $audit[0]['by']);
        $this->assertSame('Koreksi mutasi berdasarkan bukti kas', $audit[0]['reason']);
        $this->assertSame($sourceBefore, (array) DB::table('account_transactions')->where('id', 9020)->first());
    }

    public function test_manual_journal_rejects_unbalanced_lines_and_foreign_business_accounts_without_writes(): void
    {
        $this->insertManualJournalSource();
        $service = app(GeneralLedgerBackfillManualJournalService::class);

        try {
            $service->create(7, 20, 55, 'Alasan jurnal yang cukup', [
                ['accounting_account_id' => 101, 'debit' => '100.00', 'credit' => '0'],
                ['accounting_account_id' => 102, 'debit' => '0', 'credit' => '99.99'],
            ]);
            $this->fail('Jurnal tidak seimbang harus ditolak.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('lines', $exception->errors());
        }

        try {
            $service->create(7, 20, 55, 'Alasan jurnal yang cukup', [
                ['accounting_account_id' => 101, 'debit' => '100.00', 'credit' => '0'],
                ['accounting_account_id' => 801, 'debit' => '0', 'credit' => '100.00'],
            ]);
            $this->fail('CoA business lain harus ditolak.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('lines', $exception->errors());
        }

        $this->assertSame(0, DB::table('accounting_acc_trans_mappings')->count());
        $this->assertSame(0, DB::table('accounting_accounts_transactions')->count());
        $this->assertSame('pending', DB::table('accounting_general_ledger_backfill_reviews')->where('id', 20)->value('status'));
    }

    public function test_manual_journal_rolls_back_mapping_and_queue_when_any_line_insert_fails(): void
    {
        $this->insertManualJournalSource();
        DB::unprepared("CREATE TRIGGER reject_second_manual_line BEFORE INSERT ON accounting_accounts_transactions WHEN NEW.accounting_account_id = 102 BEGIN SELECT RAISE(ABORT, 'simulated journal line failure'); END");

        try {
            app(GeneralLedgerBackfillManualJournalService::class)->create(7, 20, 55, 'Koreksi berdasarkan dokumen', [
                ['accounting_account_id' => 101, 'debit' => '100.00', 'credit' => '0'],
                ['accounting_account_id' => 102, 'debit' => '0', 'credit' => '100.00'],
            ]);
            $this->fail('Kesalahan insert baris harus menggagalkan seluruh jurnal.');
        } catch (\Throwable $exception) {
            $this->assertStringContainsString('simulated journal line failure', $exception->getMessage());
        }

        $this->assertSame(0, DB::table('accounting_acc_trans_mappings')->count());
        $this->assertSame(0, DB::table('accounting_accounts_transactions')->count());
        $this->assertSame('pending', DB::table('accounting_general_ledger_backfill_reviews')->where('id', 20)->value('status'));
    }

    public function test_manual_journal_endpoint_enforces_permission_and_returns_created_reference(): void
    {
        $this->insertManualJournalSource();
        $moduleUtil = Mockery::mock(ModuleUtil::class);
        $moduleUtil->shouldReceive('hasThePermissionInSubscription')->once()->with(7, 'accounting_module')->andReturn(true);
        $this->app->instance(ModuleUtil::class, $moduleUtil);

        $user = Mockery::mock(User::class)->makePartial();
        $user->id = 55;
        $user->shouldReceive('can')->with('superadmin')->andReturn(false);
        $user->shouldReceive('can')->with('accounting.add_journal')->andReturn(true);
        $request = $this->withoutMiddleware([
            \App\Http\Middleware\SetSessionData::class,
            \App\Http\Middleware\Language::class,
            \App\Http\Middleware\Timezone::class,
            \App\Http\Middleware\AdminSidebarMenu::class,
        ])->actingAs($user)->withSession(['user' => ['business_id' => 7]])
            ->postJson(route('accounting.generalLedger.backfillReview.manualJournal', ['reviewId' => 20]), [
                'reason' => 'Jurnal sesuai bukti transaksi',
                'lines' => [
                    ['accounting_account_id' => 101, 'debit' => '100.00', 'credit' => '0'],
                    ['accounting_account_id' => 102, 'debit' => '0', 'credit' => '100.00'],
                ],
            ]);

        $request->assertCreated()->assertJsonPath('ref_no', 'GLR-7-20');

        $unauthorized = Mockery::mock(User::class)->makePartial();
        $unauthorized->id = 56;
        $unauthorized->shouldReceive('can')->with('superadmin')->andReturn(true);
        $unauthorized->shouldReceive('can')->with('accounting.add_journal')->andReturn(false);
        $this->withoutMiddleware([
            \App\Http\Middleware\SetSessionData::class,
            \App\Http\Middleware\Language::class,
            \App\Http\Middleware\Timezone::class,
            \App\Http\Middleware\AdminSidebarMenu::class,
        ])->actingAs($unauthorized)->withSession(['user' => ['business_id' => 7]])
            ->postJson(route('accounting.generalLedger.backfillReview.manualJournal', ['reviewId' => 20]), [
                'reason' => 'Alasan uji akses ditolak',
                'lines' => [
                    ['accounting_account_id' => 101, 'debit' => '100.00', 'credit' => '0'],
                    ['accounting_account_id' => 102, 'debit' => '0', 'credit' => '100.00'],
                ],
            ])->assertForbidden();

        $this->assertSame(1, DB::table('accounting_acc_trans_mappings')->count());
        $this->assertSame(2, DB::table('accounting_accounts_transactions')->count());
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

    private function insertManualJournalSource(): void
    {
        $this->insertReview(20, 7, 'App\\AccountTransaction', 9020, 'pergerakan_rekening_tanpa_akun_lawan', '', 70, 'expense', 'BANK-9020', 'Mutasi bank manual');
        DB::table('account_transactions')->insert([
            'id' => 9020,
            'account_id' => 201,
            'operation_date' => '2023-04-05 12:30:00',
            'description' => 'Mutasi operasional',
        ]);
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
            $table->unsignedInteger('selected_items')->default(0);
            $table->string('status', 24)->default('queued');
            $table->unsignedInteger('total_items')->default(0);
            $table->decimal('planned_debit', 22, 4)->default(0);
            $table->decimal('planned_credit', 22, 4)->default(0);
            $table->string('plan_hash', 64)->nullable();
            $table->longText('preview_data')->nullable();
            $table->dateTime('previewed_at')->nullable();
            $table->unsignedInteger('confirmed_by')->nullable();
            $table->dateTime('confirmed_at')->nullable();
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
            $table->string('plan_hash', 64)->nullable();
            $table->decimal('planned_debit', 22, 4)->default(0);
            $table->decimal('planned_credit', 22, 4)->default(0);
            $table->text('preview_message')->nullable();
            $table->longText('preview_data')->nullable();
            $table->text('message')->nullable();
            $table->timestamps();
            $table->unique(['batch_id', 'review_id']);
        });
        Schema::create('account_transactions', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedInteger('account_id');
            $table->dateTime('operation_date');
            $table->string('description')->nullable();
        });
        Schema::create('accounting_acc_trans_mappings', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedInteger('business_id');
            $table->string('ref_no', 100);
            $table->string('type', 100);
            $table->unsignedInteger('created_by');
            $table->dateTime('operation_date');
            $table->text('note')->nullable();
            $table->string('source_record_type', 64)->nullable();
            $table->unsignedBigInteger('source_record_id')->nullable();
            $table->string('source_link_status', 20)->nullable();
            $table->text('source_link_note')->nullable();
            $table->timestamps();
        });
        Schema::create('accounting_accounts_transactions', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('accounting_account_id');
            $table->unsignedBigInteger('acc_trans_mapping_id')->nullable();
            $table->unsignedBigInteger('transaction_id')->nullable();
            $table->unsignedBigInteger('transaction_payment_id')->nullable();
            $table->decimal('amount', 22, 4);
            $table->string('type', 100);
            $table->string('sub_type', 100);
            $table->string('map_type', 100)->nullable();
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
            $table->dateTime('transaction_date');
            $table->decimal('total_before_tax', 22, 4)->default(0);
            $table->decimal('tax_amount', 22, 4)->default(0);
            $table->decimal('final_total', 22, 4)->default(0);
            $table->unsignedInteger('created_by')->nullable();
            $table->string('invoice_no')->nullable();
            $table->string('ref_no')->nullable();
            $table->softDeletes();
        });
    }
}
