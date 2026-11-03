<?php

namespace Tests\Feature;

use App\User;
use App\Utils\ModuleUtil;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Modules\Accounting\Jobs\ProcessJournalImportJob;
use Tests\TestCase;

class JournalImportBulkMappingTest extends TestCase
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
            'status' => 'partially_imported',
            'import_options' => '{}',
            'total_entries' => 3,
        ]);
        DB::table('business_locations')->insert([
            'id' => 70,
            'business_id' => 7,
            'name' => 'Lokasi default',
            'is_active' => 1,
        ]);
        DB::table('expense_categories')->insert([
            ['id' => 11, 'business_id' => 7, 'name' => 'Uang Jalan', 'parent_id' => null],
            ['id' => 12, 'business_id' => 7, 'name' => 'Transportasi', 'parent_id' => null],
            ['id' => 81, 'business_id' => 8, 'name' => 'Uang Jalan', 'parent_id' => null],
        ]);
        DB::table('accounts')->insert([
            ['id' => 101, 'business_id' => 7, 'name' => 'Kas Operasional', 'account_number' => '1001', 'is_closed' => 0],
            ['id' => 102, 'business_id' => 7, 'name' => 'Bank Mandiri', 'account_number' => '1002', 'is_closed' => 0],
            ['id' => 103, 'business_id' => 7, 'name' => 'Akun Ditutup', 'account_number' => '1003', 'is_closed' => 1],
            ['id' => 801, 'business_id' => 8, 'name' => 'Kas Tenant Lain', 'account_number' => '8001', 'is_closed' => 0],
        ]);

        $moduleUtil = Mockery::mock(ModuleUtil::class);
        $moduleUtil->shouldReceive('hasThePermissionInSubscription')->byDefault()->andReturn(true);
        $this->app->instance(ModuleUtil::class, $moduleUtil);
        Bus::fake();
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_public_preview_groups_only_pending_expenses_with_same_account_and_location(): void
    {
        $this->insertExpenseRow(1, 'EXP-1', 'pending');
        $this->insertExpenseRow(2, 'EXP-2', 'failed');
        $this->insertExpenseRow(3, 'EXP-DONE', 'completed');
        $this->insertExpenseRow(4, 'EXP-OTHER-LOCATION', 'pending', ['location_id' => 71]);
        $this->insertExpenseRow(5, 'EXP-FOREIGN', 'pending', [], 8);

        $response = $this->request('get', '/accounting/journal-entry/import/5/cohort-mappings/preview');

        $response->assertOk()->assertJsonPath('success', 1);
        $groups = $response->json('groups');
        $this->assertCount(2, $groups);
        $defaultLocationGroup = collect($groups)->firstWhere('location_id', 70);
        $otherLocationGroup = collect($groups)->firstWhere('location_id', 71);
        $this->assertSame(2, $defaultLocationGroup['row_count']);
        $this->assertSame(200, $defaultLocationGroup['component_total']);
        $this->assertSame(200, $defaultLocationGroup['journal_total']);
        $this->assertSame(11, $defaultLocationGroup['suggested_category_id']);
        $this->assertSame(1, $otherLocationGroup['row_count']);
        $this->assertContains(['id' => 11, 'name' => 'Uang Jalan'], $response->json('categories'));
        $this->assertNotContains(81, array_column($response->json('categories'), 'id'));
    }

    public function test_public_apply_maps_the_selected_cohort_and_restarts_only_the_persistent_batch_pipeline(): void
    {
        $this->insertExpenseRow(1, 'EXP-1', 'pending');
        $this->insertExpenseRow(2, 'EXP-2', 'pending');
        $this->insertExpenseRow(3, 'EXP-DONE', 'completed');
        $this->insertExpenseRow(4, 'EXP-OTHER-LOCATION', 'pending', ['location_id' => 71]);

        $preview = $this->request('get', '/accounting/journal-entry/import/5/cohort-mappings/preview');
        $group = collect($preview->json('groups'))->firstWhere('location_id', 70);
        $response = $this->request('post', '/accounting/journal-entry/import/5/cohort-mappings/apply', [
            'mappings' => [[
                'cohort_key' => $group['cohort_key'],
                'fingerprint' => $group['fingerprint'],
                'field' => 'expense_category_mappings',
                'value' => '11',
            ]],
        ]);

        $response->assertOk()
            ->assertJsonPath('success', 1)
            ->assertJsonPath('mapped_rows', 2)
            ->assertJsonPath('status', 'preparing_import');
        foreach ([1, 2] as $rowId) {
            $draft = json_decode((string) DB::table('accounting_journal_import_rows')->where('id', $rowId)->value('draft_data'), true);
            $this->assertSame(11, $draft['expense_category_mappings'][$this->accountKey()]);
            $this->assertSame(70, $draft['location_id']);
        }
        $this->assertSame('{}', DB::table('accounting_journal_import_rows')->where('id', 3)->value('draft_data'));
        $otherLocationDraft = json_decode((string) DB::table('accounting_journal_import_rows')->where('id', 4)->value('draft_data'), true);
        $this->assertArrayNotHasKey($this->accountKey(), $otherLocationDraft['expense_category_mappings'] ?? []);
        $this->assertSame('preparing_import', DB::table('accounting_journal_imports')->where('id', 5)->value('status'));
        Bus::assertDispatched(ProcessJournalImportJob::class);
    }

    public function test_public_apply_rejects_foreign_business_categories_and_stale_preview(): void
    {
        $this->insertExpenseRow(1, 'EXP-1', 'pending');
        $group = $this->request('get', '/accounting/journal-entry/import/5/cohort-mappings/preview')->json('groups.0');

        $foreignCategory = $this->request('post', '/accounting/journal-entry/import/5/cohort-mappings/apply', [
            'mappings' => [[
                'cohort_key' => $group['cohort_key'],
                'fingerprint' => $group['fingerprint'],
                'field' => 'expense_category_mappings',
                'value' => '81',
            ]],
        ]);
        $foreignCategory->assertUnprocessable();
        $this->assertSame('{}', DB::table('accounting_journal_import_rows')->where('id', 1)->value('draft_data'));

        $stalePreview = $this->request('post', '/accounting/journal-entry/import/5/cohort-mappings/apply', [
            'mappings' => [[
                'cohort_key' => $group['cohort_key'],
                'fingerprint' => str_repeat('0', 64),
                'field' => 'expense_category_mappings',
                'value' => '11',
            ]],
        ]);
        $stalePreview->assertUnprocessable();
        $this->assertSame('partially_imported', DB::table('accounting_journal_imports')->where('id', 5)->value('status'));
    }

    public function test_public_preview_groups_bank_rows_by_type_and_journal_account_context(): void
    {
        $cashLines = $this->journalLines(100, '1-10001', 'Kas GL', '2-20001', 'Hutang GL');
        $this->insertTypedRow(20, 'Bank Deposit', 'DEP-1', 'pending', [], 7, $cashLines);
        $this->insertTypedRow(21, 'Bank Deposit', 'DEP-2', 'failed', [], 7, $this->journalLines(150, '1-10001', 'Kas GL', '2-20001', 'Hutang GL'));
        $this->insertTypedRow(22, 'Bank Deposit', 'DEP-OTHER', 'pending', [], 7, $this->journalLines(90, '1-10002', 'Bank GL', '2-20001', 'Hutang GL'));
        $this->insertTypedRow(23, 'Bank Deposit', 'DEP-DONE', 'completed', [], 7, $cashLines);
        $this->insertTypedRow(24, 'Bank Deposit', 'DEP-FOREIGN', 'pending', [], 8, $cashLines);
        $this->insertTypedRow(25, 'Bank Withdrawal', 'WD-1', 'pending', [], 7, $cashLines);

        $response = $this->request('get', '/accounting/journal-entry/import/5/cohort-mappings/preview?source_type=Bank%20Deposit');

        $response->assertOk()->assertJsonPath('success', 1);
        $groups = $response->json('groups');
        $cashGroup = collect($groups)->first(fn ($group) => $group['row_ids'] === [20, 21]);
        $this->assertNotNull($cashGroup);
        $this->assertSame('payment_account_id', $cashGroup['field']);
        $this->assertSame(2, $cashGroup['row_count']);
        $this->assertSame(250, $cashGroup['journal_total']);
        $this->assertSame([101, 102], array_column($cashGroup['options'], 'id'));
        $this->assertCount(2, $groups);
        $this->assertNotContains(103, array_column($cashGroup['options'], 'id'));
        $this->assertNotContains(801, array_column($cashGroup['options'], 'id'));
    }

    public function test_public_preview_exposes_both_transfer_accounts_for_the_same_cohort(): void
    {
        $this->insertTypedRow(
            30,
            'Bank Transfer',
            'TRF-1',
            'pending',
            [],
            7,
            $this->journalLines(500, '1-10001', 'Kas GL', '1-10002', 'Bank GL')
        );

        $response = $this->request('get', '/accounting/journal-entry/import/5/cohort-mappings/preview?source_type=Bank%20Transfer');

        $response->assertOk();
        $groups = collect($response->json('groups'));
        $this->assertCount(2, $groups);
        $this->assertEqualsCanonicalizing(['payment_account_id', 'transfer_account_id'], $groups->pluck('field')->all());
        $this->assertSame([[30], [30]], $groups->pluck('row_ids')->all());
        $this->assertSame([101, 102], array_column($groups->first()['options'], 'id'));
    }

    public function test_public_preview_maps_only_unique_exact_invoice_references(): void
    {
        Schema::create('contacts', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedInteger('business_id');
            $table->string('name')->nullable();
            $table->string('supplier_business_name')->nullable();
            $table->dateTime('deleted_at')->nullable();
        });
        Schema::create('transactions', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedInteger('business_id');
            $table->string('type');
            $table->string('status');
            $table->string('invoice_no')->nullable();
            $table->string('ref_no')->nullable();
            $table->dateTime('transaction_date')->nullable();
            $table->decimal('final_total', 22, 4)->default(0);
            $table->unsignedBigInteger('contact_id')->nullable();
            $table->dateTime('deleted_at')->nullable();
        });
        DB::table('transactions')->insert([
            ['id' => 301, 'business_id' => 7, 'type' => 'sell', 'status' => 'final', 'invoice_no' => 'INV-UNIQUE', 'ref_no' => null, 'transaction_date' => '2022-05-01 00:00:00', 'final_total' => 10, 'deleted_at' => null],
            ['id' => 302, 'business_id' => 7, 'type' => 'sell', 'status' => 'final', 'invoice_no' => 'INV-AMBIG', 'ref_no' => null, 'transaction_date' => '2022-05-01 00:00:00', 'final_total' => 100, 'deleted_at' => null],
            ['id' => 303, 'business_id' => 7, 'type' => 'sell', 'status' => 'final', 'invoice_no' => 'INV-AMBIG', 'ref_no' => null, 'transaction_date' => '2022-05-02 00:00:00', 'final_total' => 100, 'deleted_at' => null],
            ['id' => 304, 'business_id' => 7, 'type' => 'sell', 'status' => 'final', 'invoice_no' => 'INV-CLOSE', 'ref_no' => null, 'transaction_date' => '2022-05-02 00:00:00', 'final_total' => 100, 'deleted_at' => null],
            ['id' => 305, 'business_id' => 8, 'type' => 'sell', 'status' => 'final', 'invoice_no' => 'INV-FOREIGN', 'ref_no' => null, 'transaction_date' => '2022-05-02 00:00:00', 'final_total' => 100, 'deleted_at' => null],
        ]);
        $this->insertTypedRow(40, 'Receive Payment', 'INV-UNIQUE', 'pending');
        $this->insertTypedRow(41, 'Receive Payment', 'INV-AMBIG', 'pending');
        $this->insertTypedRow(42, 'Receive Payment', 'INV-CLOS', 'pending');
        $this->insertTypedRow(43, 'Receive Payment', 'INV-FOREIGN', 'pending');

        $response = $this->request('get', '/accounting/journal-entry/import/5/cohort-mappings/preview?source_type=Receive%20Payment');

        $response->assertOk();
        $groups = $response->json('groups');
        $this->assertCount(1, $groups);
        $this->assertSame([40], $groups[0]['row_ids']);
        $this->assertSame('invoice_reference', $groups[0]['field']);
        $this->assertSame('transaction:301', $groups[0]['suggested_value']);
        $this->assertSame(['transaction:301'], array_column($groups[0]['options'], 'id'));
    }

    public function test_public_apply_saves_bank_and_invoice_mappings_then_starts_standard_retry(): void
    {
        Schema::create('contacts', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedInteger('business_id');
            $table->string('name')->nullable();
            $table->string('supplier_business_name')->nullable();
            $table->dateTime('deleted_at')->nullable();
        });
        Schema::create('transactions', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedInteger('business_id');
            $table->string('type');
            $table->string('status');
            $table->string('invoice_no')->nullable();
            $table->string('ref_no')->nullable();
            $table->dateTime('transaction_date')->nullable();
            $table->decimal('final_total', 22, 4)->default(0);
            $table->unsignedBigInteger('contact_id')->nullable();
            $table->dateTime('deleted_at')->nullable();
        });
        DB::table('transactions')->insert([
            ['id' => 601, 'business_id' => 7, 'type' => 'sell', 'status' => 'final', 'invoice_no' => 'INV-MAP', 'ref_no' => null, 'transaction_date' => '2022-05-01 00:00:00', 'final_total' => 10, 'deleted_at' => null],
        ]);
        $this->insertTypedRow(60, 'Bank Deposit', 'DEP-MAP', 'pending');
        $this->insertTypedRow(61, 'Receive Payment', 'INV-MAP', 'pending');

        $preview = $this->request('get', '/accounting/journal-entry/import/5/cohort-mappings/preview');
        $bankGroup = collect($preview->json('groups'))->firstWhere('field', 'payment_account_id');
        $invoiceGroup = collect($preview->json('groups'))->firstWhere('field', 'invoice_reference');
        $response = $this->request('post', '/accounting/journal-entry/import/5/cohort-mappings/apply', [
            'mappings' => [
                [
                    'cohort_key' => $bankGroup['cohort_key'],
                    'fingerprint' => $bankGroup['fingerprint'],
                    'field' => $bankGroup['field'],
                    'value' => '101',
                ],
                [
                    'cohort_key' => $invoiceGroup['cohort_key'],
                    'fingerprint' => $invoiceGroup['fingerprint'],
                    'field' => $invoiceGroup['field'],
                    'value' => 'transaction:601',
                ],
            ],
        ]);

        $response->assertOk()
            ->assertJsonPath('success', 1)
            ->assertJsonPath('mapped_rows', 2)
            ->assertJsonPath('status', 'preparing_import');
        $bankDraft = json_decode((string) DB::table('accounting_journal_import_rows')->where('id', 60)->value('draft_data'), true);
        $paymentDraft = json_decode((string) DB::table('accounting_journal_import_rows')->where('id', 61)->value('draft_data'), true);
        $this->assertSame(101, $bankDraft['payment_account_id']);
        $this->assertSame('transaction:601', $paymentDraft['invoice_reference']);
        Bus::assertDispatched(ProcessJournalImportJob::class);
    }

    public function test_public_apply_rejects_foreign_business_bank_accounts(): void
    {
        $this->insertTypedRow(70, 'Bank Withdrawal', 'WD-FOREIGN-ACCOUNT', 'pending');
        $group = $this->request('get', '/accounting/journal-entry/import/5/cohort-mappings/preview?source_type=Bank%20Withdrawal')->json('groups.0');

        $response = $this->request('post', '/accounting/journal-entry/import/5/cohort-mappings/apply', [
            'mappings' => [[
                'cohort_key' => $group['cohort_key'],
                'fingerprint' => $group['fingerprint'],
                'field' => 'payment_account_id',
                'value' => '801',
            ]],
        ]);

        $response->assertUnprocessable();
        $this->assertSame('{}', DB::table('accounting_journal_import_rows')->where('id', 70)->value('draft_data'));
    }

    public function test_draft_option_search_returns_matching_categories_only_from_active_business(): void
    {
        $response = $this->request(
            'get',
            '/accounting/journal-entry/import/5/draft-options?kind=expense_category&source_type=Expense&q=Uang'
        );

        $response->assertOk()->assertExactJson([
            'results' => [
                ['id' => 11, 'text' => 'Uang Jalan'],
            ],
        ]);
    }

    public function test_draft_option_search_returns_units_for_a_variation_without_leaking_other_businesses(): void
    {
        Schema::create('units', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('business_id');
            $table->string('actual_name');
            $table->string('short_name');
            $table->dateTime('deleted_at')->nullable();
        });
        Schema::create('products', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('business_id');
            $table->boolean('is_inactive')->default(false);
            $table->boolean('enable_stock')->default(true);
            $table->unsignedInteger('unit_id');
            $table->text('sub_unit_ids')->nullable();
        });
        Schema::create('variations', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('product_id');
            $table->dateTime('deleted_at')->nullable();
        });

        DB::table('units')->insert([
            ['id' => 31, 'business_id' => 7, 'actual_name' => 'Pieces', 'short_name' => 'Pcs', 'deleted_at' => null],
            ['id' => 32, 'business_id' => 7, 'actual_name' => 'Box', 'short_name' => 'Box', 'deleted_at' => null],
            ['id' => 81, 'business_id' => 8, 'actual_name' => 'Pieces', 'short_name' => 'Pcs', 'deleted_at' => null],
        ]);
        DB::table('products')->insert([
            ['id' => 21, 'business_id' => 7, 'is_inactive' => 0, 'enable_stock' => 1, 'unit_id' => 31, 'sub_unit_ids' => '[32]'],
            ['id' => 22, 'business_id' => 8, 'is_inactive' => 0, 'enable_stock' => 1, 'unit_id' => 81, 'sub_unit_ids' => '[]'],
        ]);
        DB::table('variations')->insert([
            ['id' => 41, 'product_id' => 21, 'deleted_at' => null],
            ['id' => 42, 'product_id' => 22, 'deleted_at' => null],
        ]);

        $response = $this->request(
            'get',
            '/accounting/journal-entry/import/5/draft-options?kind=unit&source_type=Purchase%20Invoice&variation_id=41&q=Pcs'
        );

        $response->assertOk()->assertExactJson([
            'results' => [
                ['id' => 31, 'text' => 'Pieces (Pcs)'],
            ],
        ]);

        $foreignVariation = $this->request(
            'get',
            '/accounting/journal-entry/import/5/draft-options?kind=unit&source_type=Purchase%20Invoice&variation_id=42&q=Pcs'
        );

        $foreignVariation->assertOk()->assertExactJson(['results' => []]);
    }

    private function request(string $method, string $uri, array $data = [])
    {
        $user = Mockery::mock(User::class)->makePartial();
        $user->id = 55;
        $user->shouldReceive('can')->with('superadmin')->andReturn(false);
        $user->shouldReceive('can')->with('accounting.add_journal')->andReturn(true);

        $request = $this->withoutMiddleware([
            \App\Http\Middleware\SetSessionData::class,
            \App\Http\Middleware\Language::class,
            \App\Http\Middleware\Timezone::class,
            \App\Http\Middleware\AdminSidebarMenu::class,
        ])->actingAs($user)->withSession(['user' => ['business_id' => 7, 'id' => 55]]);

        return match (strtolower($method)) {
            'get' => $request->getJson($uri),
            'post' => $request->postJson($uri, $data),
            default => throw new \InvalidArgumentException('HTTP test verb tidak didukung.'),
        };
    }

    private function insertExpenseRow(int $id, string $reference, string $status, array $draftData = [], int $businessId = 7): void
    {
        $entry = [
            'source_type' => 'Expense',
            'source_number' => $reference,
            'source_key' => 'Expense|'.$reference.'|2022-05-02',
            'source_link_status' => 'unmatched',
            'date' => '2022-05-02',
            'description' => 'Uang Jalan',
            'debit_total' => 100,
            'credit_total' => 100,
            'lines' => [
                ['account_no' => '5-50204', 'account_name' => 'Uang Jalan', 'type' => 'debit', 'debit' => 100, 'credit' => 0],
                ['account_no' => '1-10001', 'account_name' => 'Cash', 'type' => 'credit', 'debit' => 0, 'credit' => 100],
            ],
            'is_unbalanced' => false,
            'is_duplicate' => false,
            'exists_in_system' => false,
        ];

        DB::table('accounting_journal_import_rows')->insert([
            'id' => $id,
            'journal_import_id' => 5,
            'business_id' => $businessId,
            'entry_index' => $id,
            'source_type' => 'Expense',
            'source_number' => $reference,
            'source_action' => 'create',
            'entry_data' => json_encode($entry),
            'draft_data' => $draftData === [] ? '{}' : json_encode($draftData),
            'processing_status' => $status,
        ]);
    }

    private function insertTypedRow(
        int $id,
        string $sourceType,
        string $reference,
        string $status,
        array $draftData = [],
        int $businessId = 7,
        ?array $lines = null,
        string $sourceAction = 'create'
    ): void {
        $lines = $lines ?? $this->journalLines(100, '5-50001', 'Expense', '1-10001', 'Kas GL');
        $debit = array_sum(array_map(fn ($line) => (float) ($line['debit'] ?? 0), $lines));
        $credit = array_sum(array_map(fn ($line) => (float) ($line['credit'] ?? 0), $lines));
        $entry = [
            'source_type' => $sourceType,
            'source_number' => $reference,
            'source_key' => $sourceType.'|'.$reference.'|2022-05-02',
            'source_link_status' => 'unmatched',
            'date' => '2022-05-02',
            'description' => $sourceType.' '.$reference,
            'debit_total' => $debit,
            'credit_total' => $credit,
            'lines' => $lines,
            'is_unbalanced' => false,
            'is_duplicate' => false,
            'exists_in_system' => false,
        ];

        DB::table('accounting_journal_import_rows')->insert([
            'id' => $id,
            'journal_import_id' => 5,
            'business_id' => $businessId,
            'entry_index' => $id,
            'source_type' => $sourceType,
            'source_number' => $reference,
            'source_action' => $sourceAction,
            'entry_data' => json_encode($entry),
            'draft_data' => $draftData === [] ? '{}' : json_encode($draftData),
            'processing_status' => $status,
        ]);
    }

    private function journalLines(float $amount, string $debitNo, string $debitName, string $creditNo, string $creditName): array
    {
        return [
            ['account_no' => $debitNo, 'account_name' => $debitName, 'type' => 'debit', 'debit' => $amount, 'credit' => 0],
            ['account_no' => $creditNo, 'account_name' => $creditName, 'type' => 'credit', 'debit' => 0, 'credit' => $amount],
        ];
    }

    private function accountKey(): string
    {
        return md5('5-50204|uang jalan');
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
            $table->unsignedInteger('imported_entries')->default(0);
            $table->unsignedInteger('skipped_entries')->default(0);
            $table->longText('progress_data')->nullable();
            $table->unsignedBigInteger('prepare_cursor')->default(0);
            $table->unsignedBigInteger('tax_reconciliation_cursor')->default(0);
            $table->dateTime('cancel_requested_at')->nullable();
            $table->unsignedInteger('cancel_requested_by')->nullable();
            $table->dateTime('cancelled_at')->nullable();
            $table->unsignedInteger('cancelled_by')->nullable();
            $table->longText('last_message')->nullable();
            $table->longText('error_message')->nullable();
            $table->timestamps();
        });
        Schema::create('accounting_journal_import_rows', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('journal_import_id');
            $table->unsignedInteger('business_id');
            $table->unsignedInteger('entry_index');
            $table->string('source_type');
            $table->string('source_number')->nullable();
            $table->string('source_action');
            $table->string('source_record_type')->nullable();
            $table->unsignedBigInteger('source_record_id')->nullable();
            $table->longText('entry_data');
            $table->longText('draft_data')->nullable();
            $table->string('processing_status')->default('pending');
            $table->timestamps();
        });
        Schema::create('business_locations', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedInteger('business_id');
            $table->string('name')->nullable();
            $table->boolean('is_active')->default(true);
            $table->dateTime('deleted_at')->nullable();
        });
        Schema::create('expense_categories', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedInteger('business_id');
            $table->string('name');
            $table->unsignedBigInteger('parent_id')->nullable();
            $table->dateTime('deleted_at')->nullable();
        });
        Schema::create('accounts', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedInteger('business_id');
            $table->string('name');
            $table->string('account_number')->nullable();
            $table->boolean('is_closed')->default(false);
            $table->dateTime('deleted_at')->nullable();
        });
    }
}
