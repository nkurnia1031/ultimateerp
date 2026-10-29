<?php

namespace Tests\Feature;

use App\User;
use App\Utils\ModuleUtil;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
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
            $table->dateTime('cancel_requested_at')->nullable();
            $table->dateTime('cancelled_at')->nullable();
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
    }
}
