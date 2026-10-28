<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Modules\Accounting\Http\Controllers\JournalEntryController;
use Modules\Accounting\Services\JournalTaxReconciliationService;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class JournalImportCliCommandTest extends TestCase
{
    private array $temporaryPaths = [];

    private bool $requestCancellationAfterJournalPost = false;

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
            'filesystems.disks.journal_imports.root' => storage_path('framework/testing/journal-imports-cli'),
        ]);
        Storage::forgetDisk('journal_imports');
        DB::purge('sqlite');
        DB::setDefaultConnection('sqlite');

        $this->createSchema();
        DB::table('business')->insert(['id' => 2, 'name' => 'CLI Test Business']);
        DB::table('users')->insert(['id' => 7, 'name' => 'CLI Test User']);
        DB::table('accounting_accounts')->insert([
            ['id' => 11, 'business_id' => 2, 'name' => 'Kas', 'gl_code' => '100', 'account_id' => null],
            ['id' => 12, 'business_id' => 2, 'name' => 'Pendapatan Jasa', 'gl_code' => '300', 'account_id' => null],
        ]);

        $controller = Mockery::mock(JournalEntryController::class)->makePartial();
        $controller->shouldReceive('processJournalImportEntriesForBatch')
            ->zeroOrMoreTimes()
            ->andReturnUsing(function ($import, array $entries) {
                $entry = $entries[0];
                DB::table('accounting_acc_trans_mappings')->insert([
                    'business_id' => $import->business_id,
                    'journal_import_id' => $import->id,
                    'type' => 'journal_entry',
                    'import_source_key' => $entry['source_key'],
                ]);
                if ($this->requestCancellationAfterJournalPost) {
                    DB::table('accounting_journal_imports')->where('id', $import->id)->update([
                        'cancel_requested_at' => now(),
                        'cancel_requested_by' => 7,
                    ]);
                }

                return ['imported' => 1, 'skipped' => 0];
            });
        $controller->shouldReceive('deleteTemporaryJournalFile')->zeroOrMoreTimes();
        $this->app->instance(JournalEntryController::class, $controller);

        $taxService = Mockery::mock(JournalTaxReconciliationService::class);
        $taxService->shouldReceive('reconcileImportChunk')
            ->zeroOrMoreTimes()
            ->andReturn(['has_more' => false, 'last_id' => 0]);
        $this->app->instance(JournalTaxReconciliationService::class, $taxService);
    }

    protected function tearDown(): void
    {
        foreach (array_unique($this->temporaryPaths) as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }

        Mockery::close();
        parent::tearDown();
    }

    public function test_artisan_import_persists_batches_and_skips_identical_but_reviews_conflicting_groups(): void
    {
        $firstWorkbook = $this->createJournalWorkbook('JE-CLI-1', 100, true);
        $firstBatch = $this->runImport($firstWorkbook, 'imported');

        $this->assertSame(2, DB::table('accounting_journal_import_rows')
            ->where('journal_import_id', $firstBatch->id)
            ->where('processing_status', 'completed')
            ->count());
        $this->assertSame(1, DB::table('accounting_acc_trans_mappings')->count());

        $identicalWorkbook = $this->createJournalWorkbook('JE-CLI-1', 100);
        $identicalBatch = $this->runImport($identicalWorkbook, 'imported');
        $identicalRow = DB::table('accounting_journal_import_rows')
            ->where('journal_import_id', $identicalBatch->id)
            ->first();

        $this->assertSame('completed', $identicalRow->processing_status);
        $this->assertSame(1, (int) $identicalRow->journal_mapping_id);
        $this->assertSame(1, DB::table('accounting_acc_trans_mappings')->count());

        $conflictingWorkbook = $this->createJournalWorkbook('JE-CLI-1', 200);
        $conflictingBatch = $this->runImport($conflictingWorkbook, 'partially_imported');
        $conflictingRow = DB::table('accounting_journal_import_rows')
            ->where('journal_import_id', $conflictingBatch->id)
            ->first();

        $this->assertSame('pending', $conflictingRow->processing_status);
        $this->assertStringContainsString('isi berbeda', $conflictingRow->processing_message);
        $this->assertSame(1, DB::table('accounting_acc_trans_mappings')->count());
    }

    public function test_artisan_command_validates_business_user_and_workbook_path(): void
    {
        $this->artisan('accounting:import-journal', [
            'workbook' => 'missing.xlsx',
            '--business-id' => 999,
            '--user-id' => 7,
        ])->expectsOutputToContain('Business #999 tidak ditemukan')
            ->assertExitCode(1);

        $this->artisan('accounting:import-journal', [
            'workbook' => 'missing.xlsx',
            '--business-id' => 2,
            '--user-id' => 999,
        ])->expectsOutputToContain('User #999 tidak ditemukan')
            ->assertExitCode(1);

        $this->artisan('accounting:import-journal', [
            'workbook' => 'missing.xlsx',
            '--business-id' => 2,
            '--user-id' => 7,
        ])->expectsOutputToContain('File workbook tidak ditemukan')
            ->assertExitCode(1);

        $this->artisan('accounting:import-journal', [
            'workbook' => 'missing.xlsx',
            '--business-id' => 2,
            '--user-id' => 7,
            '--chunk-delay-ms' => -1,
        ])->expectsOutputToContain('--chunk-delay-ms harus berupa bilangan bulat')
            ->assertExitCode(1);
    }

    public function test_cli_cancellation_keeps_the_batch_and_unstarted_rows_available_for_web(): void
    {
        $this->requestCancellationAfterJournalPost = true;
        $workbook = $this->createJournalWorkbook('JE-CANCEL-1', 100, false, 'JE-CANCEL-2');
        $batch = $this->runImport($workbook, 'cancelled');

        $statuses = DB::table('accounting_journal_import_rows')
            ->where('journal_import_id', $batch->id)
            ->orderBy('entry_index')
            ->pluck('processing_status')
            ->all();

        $this->assertSame(['completed', 'pending'], $statuses);
        $this->assertSame('cancelled', $batch->status);
        $this->assertSame(1, DB::table('accounting_acc_trans_mappings')->count());
    }

    public function test_conflicting_source_groups_in_one_workbook_stay_pending_without_posting(): void
    {
        $workbook = $this->createJournalWorkbook('JE-CONFLICT-1', 100, false, 'JE-CONFLICT-1', 200);
        $batch = $this->runImport($workbook, 'partially_imported');

        $this->assertSame(2, DB::table('accounting_journal_import_rows')
            ->where('journal_import_id', $batch->id)
            ->where('processing_status', 'pending')
            ->count());
        $this->assertSame(0, DB::table('accounting_acc_trans_mappings')->count());
    }

    private function runImport(string $workbook, string $expectedStatus): object
    {
        $this->artisan('accounting:import-journal', [
            'workbook' => $workbook,
            '--business-id' => 2,
            '--user-id' => 7,
            '--chunk-delay-ms' => 0,
        ])->expectsOutputToContain('Batch ID:')
            ->expectsOutputToContain('Status akhir: '.$expectedStatus)
            ->expectsOutputToContain('Berhasil:')
            ->assertExitCode(0);

        $batch = DB::table('accounting_journal_imports')->orderByDesc('id')->first();
        $this->assertStringStartsWith('journal_imports/', $batch->file_path);
        $this->temporaryPaths[] = $this->storedImportPath($batch->file_path);
        if (! empty($batch->analysis_path)) {
            $this->temporaryPaths[] = $this->storedImportPath($batch->analysis_path);
        }
        $this->temporaryPaths[] = $workbook;

        return $batch;
    }

    private function storedImportPath(string $path): string
    {
        if (strpos($path, 'journal_imports/') === 0) {
            return Storage::disk('journal_imports')->path(substr($path, strlen('journal_imports/')));
        }

        return public_path($path);
    }

    private function createJournalWorkbook(
        string $journalNumber,
        float $amount,
        bool $duplicateGroup = false,
        ?string $additionalJournalNumber = null,
        ?float $additionalAmount = null
    ): string
    {
        $directory = storage_path('framework/testing');
        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        $path = $directory.DIRECTORY_SEPARATOR.'journal-cli-'.uniqid('', true).'.xlsx';
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setCellValue('A1', 'Laporan Jurnal');
        $sheet->setCellValue('A3', '01/01/2025 - 31/12/2025');
        $sheet->setCellValue('B7', 'Journal Entry #'.$journalNumber.' | 01/01/2025');
        $sheet->fromArray([
            ['100', 'Kas', 'Penerimaan kas', $amount, 0],
            ['300', 'Pendapatan Jasa', 'Pendapatan jasa', 0, $amount],
        ], null, 'A8');
        if ($duplicateGroup || $additionalJournalNumber !== null) {
            $secondJournalNumber = $additionalJournalNumber ?? $journalNumber;
            $secondAmount = $additionalAmount ?? $amount;
            $sheet->setCellValue('B10', 'Journal Entry #'.$secondJournalNumber.' | 01/01/2025');
            $sheet->fromArray([
                ['100', 'Kas', 'Penerimaan kas', $secondAmount, 0],
                ['300', 'Pendapatan Jasa', 'Pendapatan jasa', 0, $secondAmount],
            ], null, 'A11');
        }

        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();
        $this->temporaryPaths[] = $path;

        return $path;
    }

    private function createSchema(): void
    {
        Schema::create('business', function ($table) {
            $table->increments('id');
            $table->string('name');
        });
        Schema::create('users', function ($table) {
            $table->increments('id');
            $table->string('name');
        });
        Schema::create('accounts', function ($table) {
            $table->increments('id');
            $table->unsignedInteger('business_id');
            $table->string('name');
            $table->string('account_number')->nullable();
            $table->softDeletes();
        });
        Schema::create('accounting_accounts', function ($table) {
            $table->increments('id');
            $table->unsignedInteger('business_id');
            $table->unsignedInteger('account_id')->nullable();
            $table->string('name');
            $table->string('gl_code')->nullable();
            $table->softDeletes();
        });
        Schema::create('accounting_journal_imports', function ($table) {
            $table->bigIncrements('id');
            $table->unsignedInteger('business_id');
            $table->string('file_name')->nullable();
            $table->string('source_date_range')->nullable();
            $table->integer('total_entries')->default(0);
            $table->integer('imported_entries')->default(0);
            $table->integer('skipped_entries')->default(0);
            $table->integer('unbalanced_entries')->default(0);
            $table->integer('duplicate_entries')->default(0);
            $table->integer('missing_accounts')->default(0);
            $table->integer('unsupported_entries')->default(0);
            $table->integer('processed_entries')->default(0);
            $table->text('last_message')->nullable();
            $table->text('error_message')->nullable();
            $table->string('file_path')->nullable();
            $table->string('analysis_path')->nullable();
            $table->longText('import_options')->nullable();
            $table->string('status')->default('imported');
            $table->longText('summary')->nullable();
            $table->integer('created_by');
            $table->unsignedBigInteger('prepare_cursor')->default(0);
            $table->unsignedBigInteger('tax_reconciliation_cursor')->default(0);
            $table->dateTime('cancel_requested_at')->nullable();
            $table->unsignedInteger('cancel_requested_by')->nullable();
            $table->dateTime('cancelled_at')->nullable();
            $table->unsignedInteger('cancelled_by')->nullable();
            $table->timestamps();
        });
        Schema::create('accounting_journal_import_rows', function ($table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('journal_import_id');
            $table->unsignedInteger('business_id');
            $table->unsignedInteger('entry_index');
            $table->string('source_type');
            $table->text('source_number')->nullable();
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
        Schema::create('accounting_acc_trans_mappings', function ($table) {
            $table->bigIncrements('id');
            $table->unsignedInteger('business_id');
            $table->unsignedBigInteger('journal_import_id')->nullable();
            $table->string('type');
            $table->string('import_source_key')->nullable();
        });
    }
}
