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
                'expense_category_id' => 11,
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
                'expense_category_id' => 81,
            ]],
        ]);
        $foreignCategory->assertUnprocessable();
        $this->assertSame('{}', DB::table('accounting_journal_import_rows')->where('id', 1)->value('draft_data'));

        $stalePreview = $this->request('post', '/accounting/journal-entry/import/5/cohort-mappings/apply', [
            'mappings' => [[
                'cohort_key' => $group['cohort_key'],
                'fingerprint' => str_repeat('0', 64),
                'expense_category_id' => 11,
            ]],
        ]);
        $stalePreview->assertUnprocessable();
        $this->assertSame('partially_imported', DB::table('accounting_journal_imports')->where('id', 5)->value('status'));
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
    }
}
