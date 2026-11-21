<?php

namespace Tests\Feature;

use App\Jobs\ProcessExpensePreview;
use App\Services\ExpenseImportService;
use App\Services\UserImportBatchService;
use App\UserImportBatch;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class ExpenseImportHistoryLifecycleTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (! in_array('sqlite', \PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('PDO SQLite is required for isolated Expense import history tests.');
        }

        config([
            'database.default' => 'expense_import_history_test',
            'database.connections.expense_import_history_test' => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
                'foreign_key_constraints' => false,
            ],
            'cache.default' => 'array',
        ]);
        DB::purge('expense_import_history_test');
        DB::setDefaultConnection('expense_import_history_test');
        Storage::fake('local');
        $this->withoutMiddleware([
            \App\Http\Middleware\SetSessionData::class,
            \App\Http\Middleware\AdminSidebarMenu::class,
        ]);
        $this->createSchemas();
    }

    public function test_ajax_upload_creates_a_shared_expense_batch_and_server_side_history_row(): void
    {
        Bus::fake();
        $firstWorkbook = $this->workbook('expense-first.xlsx', [
            ['LOCATION', 'TOTAL AMOUNT', 'PAID AMOUNT'],
            ['Lokasi A', 100000, 100000],
        ]);
        $secondWorkbook = $this->workbook('expense-second.xlsx', [
            ['LOCATION', 'TOTAL AMOUNT', 'PAID AMOUNT'],
            ['Lokasi B', 200000, 200000],
        ]);

        try {
            $first = $this->upload($firstWorkbook, 'expense-first.xlsx');
            $second = $this->upload($secondWorkbook, 'expense-second.xlsx');
            UserImportBatch::create([
                'business_id' => 8,
                'created_by' => 999,
                'importer_key' => 'expense',
                'source_key' => 'legacy:999',
                'file_name' => 'foreign-business.xlsx',
                'status' => 'imported',
                'stage' => 'imported',
                'progress_percent' => 100,
            ]);

            $first->assertStatus(202)->assertJsonPath('success', true);
            $second->assertStatus(202)->assertJsonPath('success', true);
            Bus::assertDispatchedTimes(ProcessExpensePreview::class, 2);

            $firstBatchId = (string) $first->json('batch_id');
            $domainBatch = DB::table('expense_import_batches')->where('id', $firstBatchId)->first();
            $sharedBatch = UserImportBatch::findOrFail((int) $domainBatch->user_import_batch_id);
            $this->assertSame('expense', $sharedBatch->importer_key);
            $this->assertSame('domain:'.$firstBatchId, $sharedBatch->source_key);
            $this->assertSame('queued_preview', $sharedBatch->status);
            $this->assertTrue(Storage::disk('local')->exists($sharedBatch->staging_path));
            app(UserImportBatchService::class)->captureWorkbook(
                $sharedBatch,
                Storage::disk('local')->path($sharedBatch->staging_path)
            );
            $reconstructedPath = app(UserImportBatchService::class)->restoredWorkbookPath($sharedBatch->fresh());
            $reconstructed = IOFactory::load($reconstructedPath);
            $this->assertSame('Lokasi A', $reconstructed->getSheet(0)->getCell('A2')->getValue());
            $reconstructed->disconnectWorksheets();
            @unlink($reconstructedPath);
            @rmdir(dirname($reconstructedPath));

            $history = $this->withHeader('Accept', 'application/json')
                ->actingAs($this->admin())
                ->withSession($this->businessSession(7))
                ->get(route('expenses.import.history', [
                    'draw' => 3,
                    'start' => 0,
                    'length' => 1,
                    'status' => 'queued_preview',
                    'search' => ['value' => 'expense-second.xlsx'],
                ]));

            $history->assertOk()
                ->assertJsonPath('draw', 3)
                ->assertJsonPath('recordsTotal', 2)
                ->assertJsonPath('recordsFiltered', 1)
                ->assertJsonPath('data.0.status', 'queued_preview')
                ->assertJsonPath('data.0.file_name', 'expense-second.xlsx')
                ->assertJsonPath('data.0.can_cancel', true);
            $this->assertCount(1, $history->json('data'));

            $pending = $this->withHeader('Accept', 'application/json')
                ->actingAs($this->admin())
                ->withSession($this->businessSession(7))
                ->get(route('expenses.import.history', [
                    'draw' => 4,
                    'start' => 0,
                    'length' => 10,
                    'status' => 'queued_preview',
                    'search' => ['value' => 'expense-first.xlsx'],
                ]));
            $pending->assertOk()->assertJsonPath('data.0.restore_url', null);
        } finally {
            @unlink($firstWorkbook);
            @unlink($secondWorkbook);
        }
    }

    public function test_legacy_expense_revert_is_post_only_and_scoped_to_the_active_business(): void
    {
        $this->actingAs($this->admin())
            ->withSession($this->businessSession(7))
            ->get('/revert-expense-import/41')
            ->assertMethodNotAllowed();

        DB::table('transactions')->insert([
            'id' => 410,
            'business_id' => 7,
            'type' => 'expense',
            'import_batch' => 41,
        ]);
        DB::table('transactions')->insert([
            'id' => 411,
            'business_id' => 8,
            'type' => 'expense',
            'import_batch' => 41,
        ]);

        $response = $this->withHeader('Accept', 'application/json')
            ->actingAs($this->admin())
            ->withSession($this->businessSession(7))
            ->post('/revert-expense-import/41');

        $response->assertOk()->assertJsonPath('success', 1);
        $this->assertDatabaseMissing('transactions', ['id' => 410]);
        $this->assertDatabaseHas('transactions', ['id' => 411]);
    }

    public function test_history_backfills_legacy_rows_without_duplicating_linked_batches_and_restores_all_sheets(): void
    {
        $shared = UserImportBatch::create([
            'business_id' => 7,
            'created_by' => 999,
            'importer_key' => 'expense',
            'source_key' => 'domain:expense-domain-1',
            'file_name' => 'linked-expense.xlsx',
            'status' => 'imported',
            'stage' => 'imported',
            'progress_current' => 1,
            'progress_total' => 1,
            'progress_percent' => 100,
            'summary_json' => json_encode(['legacy_import_batch_number' => 41, 'transactions' => 1, 'rows' => 1]),
            'last_message' => 'Import selesai.',
        ]);
        DB::table('expense_import_batches')->insert([
            'id' => 'expense-domain-1',
            'business_id' => 7,
            'created_by' => 999,
            'user_import_batch_id' => $shared->id,
            'file_name' => 'linked-expense.xlsx',
            'status' => 'imported',
            'operation' => 'import',
            'process_state' => 'completed',
            'summary' => json_encode(['legacy_import_batch_number' => 41, 'transactions' => 1, 'rows' => 1]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $fallbackShared = UserImportBatch::create([
            'business_id' => 7,
            'created_by' => 999,
            'importer_key' => 'expense',
            'source_key' => 'domain:8d45ca10-4530-4f8f-b101-7877dcf80d91',
            'file_name' => 'matched-import.xlsx',
            'status' => 'imported',
            'stage' => 'imported',
            'progress_percent' => 100,
            'summary_json' => json_encode(['transactions' => 1, 'rows' => 1]),
            'last_message' => 'Import selesai.',
        ]);
        DB::table('expense_import_batches')->insert([
            'id' => '8d45ca10-4530-4f8f-b101-7877dcf80d91',
            'business_id' => 7,
            'created_by' => 999,
            'user_import_batch_id' => $fallbackShared->id,
            'file_name' => 'matched-import.xlsx',
            'status' => 'imported',
            'operation' => 'import',
            'process_state' => 'completed',
            'summary' => json_encode(['transactions' => 1, 'rows' => 1]),
            'created_at' => now(),
            'updated_at' => now(),
            'confirmed_at' => now(),
        ]);
        DB::table('expense_import_preview_rows')->insert([
            'batch_id' => '8d45ca10-4530-4f8f-b101-7877dcf80d91',
            'business_id' => 7,
            'source_row' => 2,
            'status' => 'valid',
            'raw_payload' => json_encode(['REFERENCE NO' => 'MATCHED-43', 'TOTAL AMOUNT' => 125000]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('transactions')->insert([
            ['business_id' => 7, 'type' => 'expense', 'import_batch' => 41, 'import_time' => now(), 'import_file' => 'linked-expense.xlsx', 'ref_no' => 'LINKED-41', 'created_by' => 999],
            ['business_id' => 7, 'type' => 'expense', 'import_batch' => 42, 'import_time' => now(), 'import_file' => 'legacy-expense.xlsx', 'ref_no' => 'LEGACY-42', 'created_by' => 999],
            ['business_id' => 7, 'type' => 'expense', 'import_batch' => 43, 'import_time' => now(), 'import_file' => 'matched-import.xlsx', 'ref_no' => 'MATCHED-43', 'created_by' => 999],
        ]);
        $this->assertNotEmpty(app(UserImportBatchService::class)->storePayload($shared, 'source_snapshot', [
            'version' => 1,
            'sheets' => [
                ['title' => 'Expense 2024', 'rows' => [1 => ['A' => 'REFERENCE NO', 'B' => 'TOTAL AMOUNT'], 2 => ['A' => 'LINKED-41', 'B' => 100000]]],
                ['title' => 'Catatan', 'rows' => [1 => ['A' => 'Sumber'], 2 => ['A' => 'Workbook asli']]],
            ],
        ]));

        $history = $this->withHeader('Accept', 'application/json')
            ->actingAs($this->admin())
            ->withSession($this->businessSession(7))
            ->get(route('expenses.import.history', ['draw' => 1, 'start' => 0, 'length' => 1]));

        $history->assertOk()->assertJsonPath('recordsTotal', 3)->assertJsonPath('recordsFiltered', 3);
        $this->assertDatabaseMissing('user_import_batches', [
            'business_id' => 7,
            'importer_key' => 'expense',
            'source_key' => 'legacy:41',
        ]);
        $this->assertDatabaseMissing('user_import_batches', [
            'business_id' => 7,
            'importer_key' => 'expense',
            'source_key' => 'legacy:43',
        ]);
        $matchedHistory = $this->withHeader('Accept', 'application/json')
            ->actingAs($this->admin())
            ->withSession($this->businessSession(7))
            ->get(route('expenses.import.history', [
                'draw' => 2,
                'start' => 0,
                'length' => 10,
                'search' => ['value' => 'MATCHED-43'],
            ]));
        $matchedHistory->assertOk()
            ->assertJsonPath('recordsFiltered', 1)
            ->assertJsonPath('data.0.batch_number', 43)
            ->assertJsonPath('data.0.reference_count', 1)
            ->assertJsonPath('data.0.can_view_references', true);
        $this->assertNotEmpty($matchedHistory->json('data.0.revert_url'));
        $linkedSummary = json_decode((string) DB::table('expense_import_batches')->where('id', '8d45ca10-4530-4f8f-b101-7877dcf80d91')->value('summary'), true);
        $this->assertSame(43, $linkedSummary['legacy_import_batch_number']);

        $legacy = UserImportBatch::where('business_id', 7)->where('importer_key', 'expense')->where('source_key', 'legacy:42')->firstOrFail();
        $this->assertSame(['LEGACY-42'], json_decode((string) $legacy->search_text, true));

        $download = $this->withHeader('Accept', 'application/json')
            ->actingAs($this->admin())
            ->withSession($this->businessSession(7))
            ->get(route('expenses.import.restore-workbook', 'expense-domain-1'));
        $download->assertOk()->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $restoredPath = tempnam(sys_get_temp_dir(), 'expense-restored-');
        file_put_contents($restoredPath, $download->streamedContent());
        $restored = IOFactory::load($restoredPath);
        $this->assertSame('Expense 2024', $restored->getSheet(0)->getTitle());
        $this->assertSame('LINKED-41', $restored->getSheet(0)->getCell('A2')->getValue());
        $this->assertSame('Catatan', $restored->getSheet(1)->getTitle());
        $this->assertSame('Workbook asli', $restored->getSheet(1)->getCell('A2')->getValue());
        $restored->disconnectWorksheets();
        @unlink($restoredPath);

        app(\App\Services\ExpenseImportHistoryService::class)->markReverted(7, 43);
        Cache::forget(\App\Services\ExpenseImportHistoryService::seedCacheKey(7));
        $afterRevert = $this->withHeader('Accept', 'application/json')
            ->actingAs($this->admin())
            ->withSession($this->businessSession(7))
            ->get(route('expenses.import.history', ['draw' => 3, 'start' => 0, 'length' => 10]));
        $afterRevert->assertOk()->assertJsonPath('recordsTotal', 3);
        $this->assertDatabaseMissing('user_import_batches', [
            'business_id' => 7,
            'importer_key' => 'expense',
            'source_key' => 'legacy:43',
        ]);

        $fallbackDownload = $this->withHeader('Accept', 'application/json')
            ->actingAs($this->admin())
            ->withSession($this->businessSession(7))
            ->get(route('expenses.import.restore-workbook', '8d45ca10-4530-4f8f-b101-7877dcf80d91'));
        $fallbackDownload->assertOk()->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    }

    private function upload(string $path, string $fileName)
    {
        return $this->withHeader('Accept', 'application/json')
            ->actingAs($this->admin())
            ->withSession($this->businessSession(7))
            ->post(route('expenses.import.upload'), [
                'expense_csv' => new UploadedFile($path, $fileName, null, UPLOAD_ERR_OK, true),
            ]);
    }

    private function createSchemas(): void
    {
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
        });
        Schema::create('expense_import_batches', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('business_id');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('user_import_batch_id')->nullable();
            $table->string('file_name')->nullable();
            $table->string('file_path')->nullable();
            $table->string('file_hash', 64)->nullable();
            $table->string('status', 20)->default('preview');
            $table->string('operation', 20)->default('preview');
            $table->string('process_state', 20)->default('queued');
            $table->string('stage', 30)->nullable();
            $table->unsignedInteger('progress_current')->default(0);
            $table->unsignedInteger('progress_total')->default(0);
            $table->unsignedTinyInteger('progress_percent')->default(0);
            $table->string('progress_message')->nullable();
            $table->json('summary')->nullable();
            $table->json('date_overrides')->nullable();
            $table->json('row_overrides')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('heartbeat_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamp('cancel_requested_at')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('rolled_back_at')->nullable();
            $table->timestamps();
        });
        Schema::create('expense_import_preview_rows', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->uuid('batch_id');
            $table->unsignedBigInteger('business_id');
            $table->unsignedInteger('source_row')->nullable();
            $table->string('group_key')->nullable();
            $table->string('status', 30);
            $table->json('errors')->nullable();
            $table->json('raw_payload')->nullable();
            $table->json('normalized_payload')->nullable();
            $table->boolean('is_importable')->default(false);
            $table->string('skip_reason')->nullable();
            $table->timestamps();
        });
        Schema::create('transactions', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedInteger('business_id');
            $table->string('type');
            $table->unsignedInteger('import_batch')->nullable();
            $table->dateTime('import_time')->nullable();
            $table->string('import_file')->nullable();
            $table->string('invoice_no')->nullable();
            $table->string('ref_no')->nullable();
            $table->unsignedInteger('created_by')->nullable();
        });
        Schema::create('users', function (Blueprint $table) {
            $table->increments('id');
            $table->string('surname')->nullable();
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->softDeletes();
        });
    }

    private function workbook(string $name, array $rows): string
    {
        $path = tempnam(sys_get_temp_dir(), 'expense-upload-');
        @unlink($path);
        $path .= '.xlsx';
        $spreadsheet = new Spreadsheet();
        $spreadsheet->getActiveSheet()->fromArray($rows, null, 'A1');
        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();

        return $path;
    }

    private function admin(): \Illuminate\Foundation\Auth\User
    {
        $admin = new class extends \Illuminate\Foundation\Auth\User
        {
            public function can($ability, $arguments = [])
            {
                return true;
            }
        };
        $admin->forceFill([
            'id' => 999,
            'business_id' => 7,
            'first_name' => 'Admin',
            'user_type' => 'user',
            'allow_login' => 1,
        ]);

        return $admin;
    }

    private function businessSession(int $businessId): array
    {
        return [
            'user' => ['business_id' => $businessId, 'id' => 999],
            'business' => ['currency_symbol_placement' => 'before', 'currency_precision' => 2],
            'currency' => ['symbol' => 'Rp', 'decimal_separator' => ',', 'thousand_separator' => '.'],
        ];
    }
}
