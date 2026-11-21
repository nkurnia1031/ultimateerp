<?php

namespace Tests\Feature;

use App\User;
use App\Services\UserImportBatchService;
use App\UserImportBatch;
use App\Utils\ModuleUtil;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Mockery;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Modules\Accounting\Http\Controllers\JournalEntryController;
use Modules\Accounting\Jobs\AnalyzeJournalImportJob;
use Modules\Accounting\Services\JournalImportCancellationService;
use Modules\Accounting\Services\JournalImportDraftService;
use Modules\Accounting\Services\JournalImportHistoryService;
use Modules\Accounting\Services\JournalImportProgressService;
use Tests\TestCase;

class JournalImportHistoryLifecycleTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (! in_array('sqlite', \PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('PDO SQLite diperlukan untuk tes riwayat Journal Import.');
        }

        config([
            'database.default' => 'journal_import_history_test',
            'database.connections.journal_import_history_test' => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
                'foreign_key_constraints' => false,
            ],
            'cache.default' => 'array',
        ]);
        DB::purge('journal_import_history_test');
        DB::setDefaultConnection('journal_import_history_test');
        Storage::fake('local');
        $this->withoutMiddleware([
            \App\Http\Middleware\SetSessionData::class,
            \App\Http\Middleware\Language::class,
            \App\Http\Middleware\Timezone::class,
            \App\Http\Middleware\AdminSidebarMenu::class,
        ]);
        $this->createSchemas();

        $moduleUtil = Mockery::mock(ModuleUtil::class);
        $moduleUtil->shouldReceive('hasThePermissionInSubscription')->byDefault()->andReturn(true);
        $this->app->instance(ModuleUtil::class, $moduleUtil);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_shared_history_backfills_web_batches_and_details_row_outcomes_with_business_scope(): void
    {
        $this->journalBatch(31, 7, 'journal-2026.xlsx', 'analyzed');
        foreach (['pending', 'failed', 'completed'] as $index => $status) {
            DB::table('accounting_journal_import_rows')->insert([
                'journal_import_id' => 31,
                'business_id' => 7,
                'entry_index' => $index + 1,
                'source_type' => 'Journal Entry',
                'source_action' => 'journal_entry',
                'entry_data' => '{}',
                'processing_status' => $status,
            ]);
        }

        $history = $this->asBusiness(7)->getJson('/accounting/journal-entry/import/history?'.http_build_query([
            'draw' => 2,
            'start' => 0,
            'length' => 10,
            'status' => 'preview_ready',
            'search' => ['value' => 'journal-2026.xlsx'],
        ]));

        $history->assertOk()
            ->assertJsonPath('draw', 2)
            ->assertJsonPath('recordsTotal', 1)
            ->assertJsonPath('recordsFiltered', 1)
            ->assertJsonPath('data.0.domain_batch_id', 31)
            ->assertJsonPath('data.0.status', 'preview_ready')
            ->assertJsonPath('data.0.has_snapshot', false)
            ->assertJsonPath('data.0.restore_url', null);

        $sharedId = (int) DB::table('accounting_journal_imports')->where('id', 31)->value('user_import_batch_id');
        $this->assertGreaterThan(0, $sharedId);

        $detail = $this->asBusiness(7)->getJson('/accounting/journal-entry/import/history/'.$sharedId);
        $detail->assertOk()
            ->assertJsonPath('domain_batch_id', 31)
            ->assertJsonPath('domain_status', 'analyzed')
            ->assertJsonPath('row_outcomes.pending', 1)
            ->assertJsonPath('row_outcomes.failed', 1)
            ->assertJsonPath('row_outcomes.completed', 1)
            ->assertJsonPath('has_snapshot', false);

        $this->asBusiness(8)->getJson('/accounting/journal-entry/import/history/'.$sharedId)->assertNotFound();
    }

    public function test_source_workbook_download_rebuilds_all_sheets_and_is_business_scoped(): void
    {
        $this->journalBatch(41, 7, 'journal-source.xlsx', 'imported');
        $batch = app(UserImportBatchService::class)->create([
            'business_id' => 7,
            'created_by' => 55,
            'importer_key' => 'journal',
            'source_key' => 'domain:41',
            'file_name' => 'journal-source.xlsx',
            'status' => 'imported',
        ]);
        DB::table('accounting_journal_imports')->where('id', 41)->update(['user_import_batch_id' => $batch->id]);
        app(UserImportBatchService::class)->storePayload($batch, 'source_snapshot', [
            'version' => 1,
            'sheets' => [
                ['title' => 'Journal', 'rows' => [1 => ['A' => 'Nomor Akun', 'B' => 'Akun'], 2 => ['A' => '1000', 'B' => 'Kas']]],
                ['title' => 'Catatan', 'rows' => [1 => ['A' => 'Sheet kedua']]],
            ],
        ]);

        $download = $this->asBusiness(7)->get('/accounting/journal-entry/import/history/'.$batch->id.'/download');
        $download->assertOk()
            ->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

        $restoredPath = tempnam(sys_get_temp_dir(), 'journal-restored-');
        file_put_contents($restoredPath, $download->streamedContent());
        $restored = IOFactory::load($restoredPath);
        $this->assertSame('Journal', $restored->getSheet(0)->getTitle());
        $this->assertSame('1000', $restored->getSheet(0)->getCell('A2')->getValue());
        $this->assertSame('Catatan', $restored->getSheet(1)->getTitle());
        $this->assertSame('Sheet kedua', $restored->getSheet(1)->getCell('A1')->getValue());
        $restored->disconnectWorksheets();
        @unlink($restoredPath);

        $this->asBusiness(8)->get('/accounting/journal-entry/import/history/'.$batch->id.'/download')->assertNotFound();
    }

    public function test_analysis_snapshots_all_sheets_before_cleaning_the_staged_workbook(): void
    {
        $this->journalBatch(61, 7, 'journal-analysis.xlsx', 'queued');

        $spreadsheet = new Spreadsheet();
        $spreadsheet->getActiveSheet()->setTitle('Journal');
        $spreadsheet->getActiveSheet()->setCellValue('A1', 'Nomor Akun');
        $spreadsheet->getActiveSheet()->setCellValue('A2', '1000');
        $notes = $spreadsheet->createSheet();
        $notes->setTitle('Catatan');
        $notes->setCellValue('A1', 'Sheet kedua');
        $sourcePath = tempnam(sys_get_temp_dir(), 'journal-analysis-source-');
        (new Xlsx($spreadsheet))->save($sourcePath);
        $spreadsheet->disconnectWorksheets();

        try {
            Storage::fake('journal_imports');
            Storage::disk('journal_imports')->put('journal-analysis.xlsx', file_get_contents($sourcePath));
            DB::table('accounting_journal_imports')->where('id', 61)->update([
                'file_path' => 'journal_imports/journal-analysis.xlsx',
            ]);
            $analysis = [
                'source_date_range' => '2026-01',
                'total_entries' => 1,
                'unbalanced_count' => 0,
                'duplicate_count' => 0,
                'missing_accounts' => [],
                'entries' => [['source_type' => 'Journal Entry']],
            ];
            $controller = Mockery::mock(JournalEntryController::class)->makePartial();
            $controller->shouldReceive('analyzeJournalWorkbook')->once()->andReturn($analysis);
            $controller->shouldReceive('writeTemporaryJournalAnalysis')->once()->andReturn('journal_imports/analysis.json');
            $controller->shouldReceive('compactJournalAnalysisSummary')->once()->andReturn(['total_entries' => 1]);
            $draftService = Mockery::mock(JournalImportDraftService::class);
            $draftService->shouldReceive('persistEntries')->once()->with(61, 7, $analysis['entries']);
            $cancellationService = Mockery::mock(JournalImportCancellationService::class);
            $cancellationService->shouldReceive('stopIfRequested')->zeroOrMoreTimes()->andReturn(false);

            (new AnalyzeJournalImportJob(61))->handle(
                $controller,
                $draftService,
                $cancellationService,
                app(JournalImportProgressService::class),
                app(JournalImportHistoryService::class),
                app(UserImportBatchService::class)
            );

            $domain = DB::table('accounting_journal_imports')->where('id', 61)->first();
            $this->assertSame('analyzed', $domain->status);
            $this->assertNull($domain->file_path);
            Storage::disk('journal_imports')->assertMissing('journal-analysis.xlsx');

            $shared = UserImportBatch::findOrFail((int) $domain->user_import_batch_id);
            $this->assertSame('preview_ready', $shared->status);
            $this->assertTrue($shared->hasSourceSnapshot());
            $this->assertNull($shared->staging_path);
        } finally {
            @unlink($sourcePath);
        }
    }

    public function test_history_detail_and_workbook_download_require_journal_import_permission(): void
    {
        $this->journalBatch(51, 7, 'journal-private.xlsx', 'analyzed');

        $batch = app(UserImportBatchService::class)->create([
            'business_id' => 7,
            'created_by' => 55,
            'importer_key' => 'journal',
            'source_key' => 'domain:51',
            'file_name' => 'journal-private.xlsx',
            'status' => 'preview_ready',
        ]);
        DB::table('accounting_journal_imports')->where('id', 51)->update(['user_import_batch_id' => $batch->id]);
        app(UserImportBatchService::class)->storePayload($batch, 'source_snapshot', [
            'version' => 1,
            'sheets' => [['title' => 'Journal', 'rows' => [1 => ['A' => 'Nomor Akun']]]],
        ]);

        $this->asBusiness(7, false)
            ->getJson('/accounting/journal-entry/import/history')
            ->assertForbidden();

        $this->asBusiness(7, false)
            ->getJson('/accounting/journal-entry/import/history/'.$batch->id)
            ->assertForbidden();

        $this->asBusiness(7, false)
            ->get('/accounting/journal-entry/import/history/'.$batch->id.'/download')
            ->assertForbidden();
    }

    public function test_revert_remains_permission_scoped(): void
    {
        $this->journalBatch(52, 7, 'journal-imported.xlsx', 'imported');

        $this->asBusiness(7, true, false)
            ->postJson('/accounting/journal-entry/import/52/revert')
            ->assertForbidden();

        $this->asBusiness(8, true, true)
            ->postJson('/accounting/journal-entry/import/52/revert')
            ->assertStatus(422);

        $this->assertSame('imported', DB::table('accounting_journal_imports')->where('id', 52)->value('status'));
    }

    public function test_web_upload_returns_queued_batch_and_dispatches_analysis_asynchronously(): void
    {
        Bus::fake();
        Storage::fake('journal_imports');
        $spreadsheet = new Spreadsheet();
        $spreadsheet->getActiveSheet()->setTitle('Journal');
        $spreadsheet->getActiveSheet()->setCellValue('A1', 'Nomor Akun');
        $sourcePath = tempnam(sys_get_temp_dir(), 'journal-upload-');
        (new Xlsx($spreadsheet))->save($sourcePath);
        $spreadsheet->disconnectWorksheets();

        try {
            $upload = $this->asBusiness(7)->postJson('/accounting/journal-entry/import/analyze', [
                'journal_file' => new UploadedFile(
                    $sourcePath,
                    'journal-upload.xlsx',
                    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                    UPLOAD_ERR_OK,
                    true
                ),
            ]);

            $upload->assertStatus(202)
                ->assertJsonPath('success', 1)
                ->assertJsonPath('status', 'queued')
                ->assertJsonPath('batch_id', $upload->json('import_id'));
            Bus::assertDispatched(AnalyzeJournalImportJob::class, 1);

            $domain = DB::table('accounting_journal_imports')->where('id', $upload->json('batch_id'))->first();
            $this->assertSame('web', $domain->import_origin);
            $this->assertNotNull($domain->user_import_batch_id);
            $shared = UserImportBatch::findOrFail((int) $domain->user_import_batch_id);
            $this->assertSame('journal', $shared->importer_key);
            $this->assertSame('domain:'.$domain->id, $shared->source_key);
            $this->assertSame('queued_preview', $shared->status);
            Storage::disk('journal_imports')->assertExists(basename($domain->file_path));
        } finally {
            @unlink($sourcePath);
        }
    }

    private function journalBatch(int $id, int $businessId, string $fileName, string $status): void
    {
        DB::table('accounting_journal_imports')->insert([
            'id' => $id,
            'business_id' => $businessId,
            'file_name' => $fileName,
            'source_date_range' => '01/01/2026 - 01/31/2026',
            'total_entries' => 3,
            'imported_entries' => 0,
            'skipped_entries' => 0,
            'processed_entries' => 0,
            'status' => $status,
            'created_by' => 55,
            'import_origin' => 'web',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function asBusiness(int $businessId, bool $journalPermission = true, bool $deleteJournalPermission = false): self
    {
        $user = Mockery::mock(User::class)->makePartial();
        $user->id = 55;
        $user->shouldReceive('can')->with('superadmin')->andReturn(false);
        $user->shouldReceive('can')->with('accounting.add_journal')->andReturn($journalPermission);
        $user->shouldReceive('can')->with('accounting.delete_journal')->andReturn($deleteJournalPermission);

        return $this->withHeader('Accept', 'application/json')
            ->actingAs($user)
            ->withSession(['user' => ['id' => 55, 'business_id' => $businessId]]);
    }

    private function createSchemas(): void
    {
        Schema::create('accounting_journal_imports', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedInteger('business_id');
            $table->string('file_name')->nullable();
            $table->string('source_date_range')->nullable();
            $table->unsignedInteger('total_entries')->default(0);
            $table->unsignedInteger('imported_entries')->default(0);
            $table->unsignedInteger('skipped_entries')->default(0);
            $table->unsignedInteger('processed_entries')->default(0);
            $table->unsignedInteger('unbalanced_entries')->default(0);
            $table->unsignedInteger('duplicate_entries')->default(0);
            $table->unsignedInteger('missing_accounts')->default(0);
            $table->unsignedInteger('unsupported_entries')->default(0);
            $table->string('file_path')->nullable();
            $table->string('analysis_path')->nullable();
            $table->longText('import_options')->nullable();
            $table->string('status')->default('queued');
            $table->longText('summary')->nullable();
            $table->unsignedInteger('created_by')->nullable();
            $table->string('import_origin')->default('web');
            $table->unsignedBigInteger('user_import_batch_id')->nullable()->unique();
            $table->longText('progress_data')->nullable();
            $table->longText('last_message')->nullable();
            $table->longText('error_message')->nullable();
            $table->dateTime('cancel_requested_at')->nullable();
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
            $table->string('source_action')->nullable();
            $table->longText('entry_data')->nullable();
            $table->string('processing_status')->default('pending');
            $table->text('processing_message')->nullable();
            $table->text('processing_error')->nullable();
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
        });

        Schema::create('user_import_batches', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedInteger('business_id');
            $table->unsignedInteger('created_by')->nullable();
            $table->string('importer_key', 64);
            $table->string('source_key', 64)->nullable();
            $table->string('file_name')->nullable();
            $table->string('staging_path')->nullable();
            $table->string('source_snapshot_path')->nullable();
            $table->string('preview_path')->nullable();
            $table->string('results_path')->nullable();
            $table->string('status', 32)->default('queued_preview');
            $table->string('stage', 64)->nullable();
            $table->unsignedInteger('progress_current')->default(0);
            $table->unsignedInteger('progress_total')->default(0);
            $table->unsignedTinyInteger('progress_percent')->default(0);
            $table->longText('summary_json')->nullable();
            $table->longText('options_json')->nullable();
            $table->longText('search_text')->nullable();
            $table->text('last_message')->nullable();
            $table->longText('error_message')->nullable();
            $table->dateTime('heartbeat_at')->nullable();
            $table->dateTime('started_at')->nullable();
            $table->dateTime('completed_at')->nullable();
            $table->dateTime('failed_at')->nullable();
            $table->dateTime('reverted_at')->nullable();
            $table->timestamps();
            $table->unique(['business_id', 'importer_key', 'source_key'], 'user_import_source_key_unique');
        });
    }
}
