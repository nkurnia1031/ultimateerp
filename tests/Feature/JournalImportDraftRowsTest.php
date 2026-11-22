<?php

namespace Tests\Feature;

use App\User;
use App\Utils\ModuleUtil;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Bus;
use Mockery;
use Modules\Accounting\Jobs\ProcessJournalImportJob;
use Modules\Accounting\Services\JournalImportHistoryService;
use Tests\TestCase;

class JournalImportDraftRowsTest extends TestCase
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
        Bus::fake();
        DB::table('accounting_journal_imports')->insert([
            'id' => 5,
            'business_id' => 7,
            'status' => 'partially_imported',
            'import_options' => '{}',
            'total_entries' => 3,
            'processed_entries' => 0,
        ]);
        $this->insertDraftRow(1, 1, 'pending');
        $this->insertDraftRow(2, 2, 'failed');
        $this->insertDraftRow(3, 3, 'completed');

        $moduleUtil = Mockery::mock(ModuleUtil::class);
        $moduleUtil->shouldReceive('hasThePermissionInSubscription')->byDefault()->andReturn(true);
        $this->app->instance(ModuleUtil::class, $moduleUtil);
        $historyService = Mockery::mock(JournalImportHistoryService::class);
        $historyService->shouldReceive('linkBatch')->zeroOrMoreTimes()->andReturn(null);
        $this->app->instance(JournalImportHistoryService::class, $historyService);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_review_list_defaults_to_pending_and_failed_and_keeps_completed_rows_available(): void
    {
        $defaultFirstPage = $this->requestDraftRows(['type' => 'Journal Entry', 'per_page' => 1]);
        $defaultFirstPage->assertOk()->assertJsonPath('total', 2)->assertJsonPath('last_page', 2);
        $this->assertSame([1], array_column($defaultFirstPage->json('rows'), 'id'));

        $defaultSecondPage = $this->requestDraftRows(['type' => 'Journal Entry', 'per_page' => 1, 'page' => 2]);
        $defaultSecondPage->assertOk()->assertJsonPath('total', 2)->assertJsonPath('last_page', 2);
        $this->assertSame([2], array_column($defaultSecondPage->json('rows'), 'id'));

        $completed = $this->requestDraftRows([
            'type' => 'Journal Entry',
            'processing_filter' => 'completed',
        ]);
        $completed->assertOk()->assertJsonPath('total', 1);
        $this->assertSame([3], array_column($completed->json('rows'), 'id'));

        $all = $this->requestDraftRows([
            'type' => 'Journal Entry',
            'processing_filter' => 'all',
            'per_page' => 2,
        ]);
        $all->assertOk()->assertJsonPath('total', 3)->assertJsonPath('last_page', 2);
        $this->assertSame([1, 2], array_column($all->json('rows'), 'id'));
    }

    public function test_status_endpoint_returns_phase_counts_without_marking_analysis_as_imported(): void
    {
        DB::table('accounting_journal_imports')->where('id', 5)->update([
            'status' => 'preparing_import',
            'total_entries' => 21924,
            'processed_entries' => 0,
            'progress_data' => json_encode([
                'phase' => 'preparing_import',
                'phases' => [
                    'workbook_analysis' => ['processed' => 98445, 'total' => 98445, 'unit' => 'baris workbook'],
                    'preparing_import' => [
                        'processed' => 500,
                        'total' => 21924,
                        'unit' => 'draft baris',
                        'counts' => ['queued' => 300, 'pending' => 21424],
                    ],
                ],
            ]),
        ]);

        $response = $this->requestImportStatus();

        $response->assertOk()
            ->assertJsonPath('processed_entries', 0)
            ->assertJsonPath('progress_phase', 'preparing_import')
            ->assertJsonPath('phase_progress.processed', 500)
            ->assertJsonPath('phase_progress.total', 21924)
            ->assertJsonPath('phase_progress.counts.queued', 300)
            ->assertJsonPath('phase_history.workbook_analysis.processed', 98445);
    }

    public function test_public_review_groups_filter_rows_and_bulk_apply_only_the_active_business_default_location(): void
    {
        $this->insertIncompleteExpenseRow(10, 'EXP-10', 'pending', 'Draft Expense belum siap: Pilih lokasi/gudang aktif dari business ini.');
        $this->insertIncompleteExpenseRow(11, 'EXP-11', 'failed', 'Draft Expense belum siap: Pilih lokasi/gudang aktif dari business ini.');
        $this->insertIncompleteExpenseRow(12, 'EXP-12', 'pending', 'Draft Expense belum siap: Lengkapi produk, kuantitas, satuan, dan harga.');
        $this->insertIncompleteExpenseRow(13, 'EXP-DONE', 'completed', 'Draft Expense belum siap: Pilih lokasi/gudang aktif dari business ini.');
        $this->insertIncompleteExpenseRow(14, 'EXP-FOREIGN', 'pending', 'Draft Expense belum siap: Pilih lokasi/gudang aktif dari business ini.', 8);
        DB::table('business_locations')->insert([
            'id' => 71,
            'business_id' => 8,
            'name' => 'Lokasi tenant lain',
            'is_active' => 1,
        ]);

        $summary = $this->requestReviewGroups('Expense');
        $summary->assertOk()
            ->assertJsonPath('total_rows', 3)
            ->assertJsonPath('total_groups', 2);
        $locationGroup = collect($summary->json('groups'))->firstWhere('missing_field', 'location_id');
        $this->assertSame(2, $locationGroup['row_count']);
        $this->assertSame('Isi lokasi: Lokasi aktif test', $locationGroup['bulk_actions'][0]['label']);

        $filtered = $this->requestDraftRows([
            'type' => 'Expense',
            'group_context' => $locationGroup['group_context'],
            'group_key' => $locationGroup['group_key'],
        ]);
        $filtered->assertOk()->assertJsonPath('total', 2);
        $this->assertSame([10, 11], array_column($filtered->json('rows'), 'id'));

        $foreignLocation = $this->requestReviewGroupResolution([
            'group_key' => $locationGroup['group_key'],
            'group_context' => $locationGroup['group_context'],
            'expected_count' => 2,
            'field' => 'location_id',
            'value' => '71',
        ]);
        $foreignLocation->assertUnprocessable();
        $this->assertSame('{}', DB::table('accounting_journal_import_rows')->where('id', 10)->value('draft_data'));

        $resolved = $this->requestReviewGroupResolution([
            'group_key' => $locationGroup['group_key'],
            'group_context' => $locationGroup['group_context'],
            'expected_count' => 2,
            'field' => 'location_id',
            'value' => '70',
        ]);
        $resolved->assertOk()->assertJsonPath('success', 1)->assertJsonPath('updated_rows', 2)->assertJsonPath('status', 'preparing_import');
        foreach ([10, 11] as $rowId) {
            $draft = json_decode((string) DB::table('accounting_journal_import_rows')->where('id', $rowId)->value('draft_data'), true);
            $this->assertSame(70, $draft['location_id']);
            $this->assertSame(1, (int) DB::table('accounting_journal_import_rows')->where('id', $rowId)->value('is_reviewed'));
        }
        $detailsDraft = json_decode((string) DB::table('accounting_journal_import_rows')->where('id', 12)->value('draft_data'), true);
        $this->assertArrayNotHasKey('details', $detailsDraft);
        Bus::assertDispatched(ProcessJournalImportJob::class);
    }

    public function test_stock_direction_is_suggested_only_when_all_inventory_components_prove_the_same_side(): void
    {
        $this->insertIncompleteStockRow(20, 'STOCK-IN', [
            ['account_no' => '1-10001', 'account_name' => 'Persediaan Barang', 'type' => 'debit', 'debit' => 100, 'credit' => 0],
            ['account_no' => '2-20001', 'account_name' => 'Hutang Usaha', 'type' => 'credit', 'debit' => 0, 'credit' => 100],
        ]);
        $this->insertIncompleteStockRow(21, 'STOCK-AMBIGUOUS', [
            ['account_no' => '1-10001', 'account_name' => 'Persediaan Barang', 'type' => 'debit', 'debit' => 100, 'credit' => 0],
            ['account_no' => '1-10001', 'account_name' => 'Persediaan Barang', 'type' => 'credit', 'debit' => 0, 'credit' => 100],
        ]);

        $summary = $this->requestReviewGroups('Stock In/Out');
        $directionGroup = collect($summary->json('groups'))->first(function ($group) {
            return $group['suggested_direction'] === 'in';
        });
        $this->assertNotNull($directionGroup);
        $this->assertSame('in', $directionGroup['suggested_direction']);
        $this->assertContains('movement_direction', array_column($directionGroup['bulk_actions'], 'field'));

        $ambiguousGroup = collect($summary->json('groups'))->first(function ($group) {
            return $group['suggested_direction'] === null;
        });
        $this->assertNotNull($ambiguousGroup);
        $this->assertNotContains('movement_direction', array_column($ambiguousGroup['bulk_actions'], 'field'));

        $resolved = $this->requestReviewGroupResolution([
            'group_key' => $directionGroup['group_key'],
            'group_context' => $directionGroup['group_context'],
            'expected_count' => 1,
            'field' => 'movement_direction',
            'value' => 'in',
        ]);
        $resolved->assertOk()->assertJsonPath('updated_rows', 1);
        $draft = json_decode((string) DB::table('accounting_journal_import_rows')->where('id', 20)->value('draft_data'), true);
        $this->assertSame('in', $draft['movement_direction']);
        $this->assertArrayNotHasKey('details', $draft, 'Direction inference must not fabricate product lines or quantities.');
    }

    public function test_bulk_review_marks_explained_differences_reviewed_without_a_reason(): void
    {
        $this->insertDifferenceRow(30, 'auto_approved');

        $response = $this->requestBulkDifferenceReview(['row_ids' => [30]]);

        $response->assertOk()
            ->assertJsonPath('reviewed_rows', 1)
            ->assertJsonPath('approved_rows', 0)
            ->assertJsonPath('rows.0.reconciliation_status', 'auto_approved');
        $this->assertSame(1, (int) DB::table('accounting_journal_import_rows')->where('id', 30)->value('is_reviewed'));
        $this->assertSame(55, (int) DB::table('accounting_journal_import_rows')->where('id', 30)->value('reviewed_by'));
        $this->assertNull(DB::table('accounting_journal_import_rows')->where('id', 30)->value('difference_approval_reason'));
    }

    public function test_draft_search_and_problem_filters_combine_without_losing_pagination(): void
    {
        $this->insertDifferenceRow(30, 'auto_approved');
        $this->insertDifferenceRow(31, 'mismatch', 'failed');
        $this->insertDifferenceRow(32, 'auto_approved');
        $this->insertDifferenceRow(33, 'mismatch');
        foreach ([30, 31, 32, 33] as $id) {
            DB::table('accounting_journal_import_rows')->where('id', $id)->update([
                'source_number' => 'FILTER-SEARCH-'.$id,
                'entry_data' => json_encode([
                    'source_type' => 'Expense',
                    'source_number' => 'FILTER-SEARCH-'.$id,
                    'description' => 'FILTER-SEARCH fixture',
                    'debit_total' => 100,
                    'credit_total' => 100,
                    'lines' => [],
                ]),
                'is_reviewed' => in_array($id, [32, 33], true) ? 1 : 0,
                'processing_error' => $id === 31 ? 'ERR-SEARCH-MISSING-ACCOUNT' : null,
            ]);
        }

        $errors = $this->requestDraftRows([
            'type' => 'Expense',
            'processing_filter' => 'all',
            'status_filter' => 'errors',
            'review_filter' => 'unreviewed',
            'search' => 'ERR-SEARCH-MISSING-ACCOUNT',
        ]);
        $errors->assertOk()->assertJsonPath('total', 1);
        $this->assertSame([31], array_column($errors->json('rows'), 'id'));

        $firstDifferencePage = $this->requestDraftRows([
            'type' => 'Expense',
            'processing_filter' => 'all',
            'status_filter' => 'differences',
            'review_filter' => 'reviewed',
            'search' => 'FILTER-SEARCH',
            'per_page' => 1,
        ]);
        $firstDifferencePage->assertOk()->assertJsonPath('total', 2)->assertJsonPath('last_page', 2);
        $this->assertSame([32], array_column($firstDifferencePage->json('rows'), 'id'));

        $secondDifferencePage = $this->requestDraftRows([
            'type' => 'Expense',
            'processing_filter' => 'all',
            'status_filter' => 'differences',
            'review_filter' => 'reviewed',
            'search' => 'FILTER-SEARCH',
            'per_page' => 1,
            'page' => 2,
        ]);
        $secondDifferencePage->assertOk()->assertJsonPath('total', 2)->assertJsonPath('last_page', 2);
        $this->assertSame([33], array_column($secondDifferencePage->json('rows'), 'id'));
    }

    public function test_mixed_bulk_review_requires_reason_only_for_unexplained_differences_and_updates_atomically(): void
    {
        $this->insertDifferenceRow(30, 'auto_approved');
        $this->insertDifferenceRow(31, 'mismatch');

        $missingReason = $this->requestBulkDifferenceReview(['row_ids' => [30, 31]]);
        $missingReason->assertUnprocessable();
        $this->assertSame('auto_approved', DB::table('accounting_journal_import_rows')->where('id', 30)->value('reconciliation_status'));
        $this->assertSame(0, (int) DB::table('accounting_journal_import_rows')->where('id', 30)->value('is_reviewed'));
        $this->assertSame('mismatch', DB::table('accounting_journal_import_rows')->where('id', 31)->value('reconciliation_status'));
        $this->assertSame(0, (int) DB::table('accounting_journal_import_rows')->where('id', 31)->value('is_reviewed'));

        $response = $this->requestBulkDifferenceReview([
            'row_ids' => [30, 31],
            'reason' => 'Selisih sudah diperiksa bersama sumber jurnal.',
        ]);

        $response->assertOk()
            ->assertJsonPath('reviewed_rows', 2)
            ->assertJsonPath('approved_rows', 1);
        $this->assertSame('auto_approved', DB::table('accounting_journal_import_rows')->where('id', 30)->value('reconciliation_status'));
        $this->assertNull(DB::table('accounting_journal_import_rows')->where('id', 30)->value('difference_approval_reason'));
        $this->assertSame('approved', DB::table('accounting_journal_import_rows')->where('id', 31)->value('reconciliation_status'));
        $this->assertSame('Selisih sudah diperiksa bersama sumber jurnal.', DB::table('accounting_journal_import_rows')->where('id', 31)->value('difference_approval_reason'));
        $this->assertSame(55, (int) DB::table('accounting_journal_import_rows')->where('id', 31)->value('difference_approved_by'));
    }

    public function test_bulk_difference_review_rejects_ineligible_rows_without_partial_updates(): void
    {
        $this->insertDifferenceRow(30, 'mismatch');
        $this->insertDifferenceRow(31, 'mismatch', 'queued');

        $this->requestBulkDifferenceReview([
            'row_ids' => [30, 31],
            'reason' => 'Selisih diperiksa pada daftar.',
        ])->assertUnprocessable();

        $this->assertSame('mismatch', DB::table('accounting_journal_import_rows')->where('id', 30)->value('reconciliation_status'));
        $this->assertSame(0, (int) DB::table('accounting_journal_import_rows')->where('id', 30)->value('is_reviewed'));

        DB::table('accounting_journal_import_rows')->where('id', 31)->delete();
        $this->insertDifferenceRow(31, 'mismatch', 'pending', 8);
        $this->requestBulkDifferenceReview([
            'row_ids' => [30, 31],
            'reason' => 'Selisih diperiksa pada daftar.',
        ])->assertUnprocessable();
        $this->assertSame('mismatch', DB::table('accounting_journal_import_rows')->where('id', 30)->value('reconciliation_status'));
        $this->assertSame(0, (int) DB::table('accounting_journal_import_rows')->where('id', 30)->value('is_reviewed'));
    }

    public function test_bulk_difference_review_requires_journal_import_permission(): void
    {
        $this->insertDifferenceRow(30, 'auto_approved');

        $this->requestBulkDifferenceReview(['row_ids' => [30]], false)->assertForbidden();

        $this->assertSame(0, (int) DB::table('accounting_journal_import_rows')->where('id', 30)->value('is_reviewed'));
    }

    private function requestDraftRows(array $query)
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
            ->getJson('/accounting/journal-entry/import/5/draft-rows?'.http_build_query($query));
    }

    private function requestImportStatus()
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
            ->getJson('/accounting/journal-entry/import/5/status');
    }

    private function requestReviewGroups(string $sourceType)
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
            ->getJson('/accounting/journal-entry/import/5/review-groups?'.http_build_query(['type' => $sourceType]));
    }

    private function requestReviewGroupResolution(array $payload)
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
            ->postJson('/accounting/journal-entry/import/5/review-groups/resolve', $payload);
    }

    private function requestBulkDifferenceReview(array $payload, bool $canAddJournal = true)
    {
        $user = Mockery::mock(User::class)->makePartial();
        $user->id = 55;
        $user->shouldReceive('can')->with('superadmin')->andReturn(false);
        $user->shouldReceive('can')->with('accounting.add_journal')->andReturn($canAddJournal);

        return $this->withoutMiddleware([
            \App\Http\Middleware\SetSessionData::class,
            \App\Http\Middleware\Language::class,
            \App\Http\Middleware\Timezone::class,
            \App\Http\Middleware\AdminSidebarMenu::class,
        ])->actingAs($user)->withSession(['user' => ['id' => 55, 'business_id' => 7]])
            ->patchJson('/accounting/journal-entry/import/5/draft-rows/approve-differences', $payload);
    }

    private function insertDifferenceRow(int $id, string $reconciliationStatus, string $processingStatus = 'pending', int $businessId = 7): void
    {
        DB::table('accounting_journal_import_rows')->insert([
            'id' => $id,
            'journal_import_id' => 5,
            'business_id' => $businessId,
            'entry_index' => $id,
            'source_type' => 'Expense',
            'source_number' => 'EXP-'.$id,
            'source_action' => 'link',
            'entry_data' => json_encode([
                'source_type' => 'Expense',
                'source_number' => 'EXP-'.$id,
                'date' => '2025-01-01',
                'description' => 'Biaya uji selisih',
                'debit_total' => 100,
                'credit_total' => 100,
                'lines' => [],
            ]),
            'draft_data' => '{}',
            'is_reviewed' => 0,
            'processing_status' => $processingStatus,
            'reconciliation_status' => $reconciliationStatus,
            'journal_total' => 100,
            'business_total' => 99,
            'difference_amount' => 1,
        ]);
    }

    private function insertIncompleteExpenseRow(int $id, string $number, string $status, string $message, int $businessId = 7): void
    {
        DB::table('accounting_journal_import_rows')->insert([
            'id' => $id,
            'journal_import_id' => 5,
            'business_id' => $businessId,
            'entry_index' => $id,
            'source_type' => 'Expense',
            'source_number' => $number,
            'source_action' => 'create',
            'entry_data' => json_encode([
                'source_type' => 'Expense',
                'source_number' => $number,
                'source_link_status' => 'unmatched',
                'date' => '2025-01-01',
                'description' => 'Biaya test',
                'debit_total' => 100,
                'credit_total' => 100,
                'lines' => [],
            ]),
            'draft_data' => '{}',
            'processing_status' => $status,
            'processing_message' => $message,
            'processing_error' => null,
            'reconciliation_status' => 'not_checked',
        ]);
    }

    private function insertIncompleteStockRow(int $id, string $number, array $lines): void
    {
        DB::table('accounting_journal_import_rows')->insert([
            'id' => $id,
            'journal_import_id' => 5,
            'business_id' => 7,
            'entry_index' => $id,
            'source_type' => 'Stock In/Out',
            'source_number' => $number,
            'source_action' => 'create',
            'entry_data' => json_encode([
                'source_type' => 'Stock In/Out',
                'source_number' => $number,
                'source_link_status' => 'unmatched',
                'debit_total' => 100,
                'credit_total' => 100,
                'lines' => $lines,
            ]),
            'draft_data' => '{}',
            'processing_status' => 'pending',
            'processing_message' => 'Draft stok belum siap: Pilih arah pergerakan stok Masuk atau Keluar.',
            'processing_error' => null,
            'reconciliation_status' => 'not_checked',
        ]);
    }

    private function insertDraftRow(int $id, int $entryIndex, string $status): void
    {
        DB::table('accounting_journal_import_rows')->insert([
            'id' => $id,
            'journal_import_id' => 5,
            'business_id' => 7,
            'entry_index' => $entryIndex,
            'source_type' => 'Journal Entry',
            'source_number' => 'JE-'.$id,
            'source_action' => 'journal',
            'entry_data' => json_encode([
                'source_type' => 'Journal Entry',
                'source_number' => 'JE-'.$id,
                'date' => '2025-01-01',
                'description' => 'Fixture '.$id,
                'debit_total' => 100,
                'credit_total' => 100,
                'lines' => [],
            ]),
            'draft_data' => '{}',
            'processing_status' => $status,
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
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
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
            $table->unsignedInteger('reviewed_by')->nullable();
            $table->dateTime('reviewed_at')->nullable();
            $table->unsignedInteger('processing_attempts')->default(0);
            $table->string('processing_status')->default('pending');
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

        DB::table('business_locations')->insert([
            'id' => 70,
            'business_id' => 7,
            'name' => 'Lokasi aktif test',
            'is_active' => 1,
        ]);
    }
}
