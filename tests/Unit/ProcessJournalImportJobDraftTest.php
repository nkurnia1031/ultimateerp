<?php

namespace Tests\Unit;

use Illuminate\Container\Container;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Support\DateFactory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use Mockery;
use Modules\Accounting\Http\Controllers\JournalEntryController;
use Modules\Accounting\Jobs\AnalyzeJournalImportJob;
use Modules\Accounting\Jobs\ProcessJournalImportJob;
use Modules\Accounting\Services\JournalImportCancellationService;
use Modules\Accounting\Services\JournalImportDraftService;
use Modules\Accounting\Services\JournalImportProgressService;
use Modules\Accounting\Services\JournalImportExpenseService;
use Modules\Accounting\Services\JournalImportInvoiceService;
use Modules\Accounting\Services\JournalImportPaymentService;
use Modules\Accounting\Services\JournalImportBankTransactionService;
use Modules\Accounting\Services\JournalImportQueueService;
use PHPUnit\Framework\TestCase;

class ProcessJournalImportJobDraftTest extends TestCase
{
    private ?Capsule $database = null;

    private ?Container $originalContainer = null;

    private array $dispatchedJobs = [];

    protected function tearDown(): void
    {
        try {
            Mockery::close();
        } finally {
            Facade::clearResolvedInstances();
            Facade::setFacadeApplication(null);
            if ($this->originalContainer !== null) {
                Container::setInstance($this->originalContainer);
            }
        }
        parent::tearDown();
    }

    public function test_job_reads_saved_draft_and_retry_does_not_post_the_same_source_key_twice(): void
    {
        $this->useSqliteDatabase();
        $this->createTables();
        $now = now();
        DB::table('accounting_journal_imports')->insert([
            'id' => 1,
            'business_id' => 3,
            'created_by' => 8,
            'status' => 'queued_import',
            'import_options' => '{}',
            'processed_entries' => 0,
            'imported_entries' => 0,
            'skipped_entries' => 0,
            'total_entries' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $entry = [
            'source_type' => 'Journal Entry',
            'source_number' => 'JE-DRAFT-1',
            'source_key' => 'Journal Entry|JE-DRAFT-1',
            'date' => '2026-10-01',
            'description' => 'Saved draft entry',
            'is_unbalanced' => false,
            'is_duplicate' => false,
            'exists_in_system' => false,
            'debit_total' => 100,
            'credit_total' => 100,
            'lines' => [['debit' => 100, 'credit' => 0], ['debit' => 0, 'credit' => 100]],
        ];
        DB::table('accounting_journal_import_rows')->insert([
            'id' => 1,
            'journal_import_id' => 1,
            'business_id' => 3,
            'entry_index' => 0,
            'source_type' => 'Journal Entry',
            'source_number' => 'JE-DRAFT-1',
            'source_key' => $entry['source_key'],
            'source_action' => 'journal',
            'entry_data' => json_encode($entry),
            'draft_data' => '{}',
            'processing_status' => 'queued',
            'processing_attempts' => 0,
            'is_reviewed' => false,
            'reconciliation_status' => 'not_checked',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $controller = Mockery::mock(JournalEntryController::class);
        $controller->shouldReceive('processJournalImportEntriesForBatch')->once()->andReturnUsing(function ($import, array $entries) {
            $this->assertSame('Saved draft entry', $entries[0]['description']);
            DB::table('accounting_acc_trans_mappings')->insert([
                'business_id' => $import->business_id,
                'journal_import_id' => $import->id,
                'type' => 'journal_entry',
                'import_source_key' => $entries[0]['source_key'],
            ]);

            return ['imported' => 1, 'skipped' => 0];
        });
        $controller->shouldReceive('deleteTemporaryJournalFile')->twice();

        (new ProcessJournalImportJob(1))->handle($controller, new JournalImportQueueService(), Mockery::mock(JournalImportInvoiceService::class), Mockery::mock(JournalImportExpenseService::class), Mockery::mock(JournalImportPaymentService::class), Mockery::mock(JournalImportBankTransactionService::class));
        $this->runTaxContinuation($controller);

        $this->assertSame('completed', DB::table('accounting_journal_import_rows')->where('id', 1)->value('processing_status'));
        $this->assertSame(1, DB::table('accounting_acc_trans_mappings')->count());
        $this->assertSame('imported', DB::table('accounting_journal_imports')->where('id', 1)->value('status'));

        DB::table('accounting_journal_import_rows')->where('id', 1)->update(['processing_status' => 'queued']);
        DB::table('accounting_journal_imports')->where('id', 1)->update(['status' => 'queued_import']);
        $retryController = Mockery::mock(JournalEntryController::class);
        $retryController->shouldNotReceive('processJournalImportEntriesForBatch');
        $retryController->shouldReceive('deleteTemporaryJournalFile')->twice();

        (new ProcessJournalImportJob(1))->handle($retryController, new JournalImportQueueService(), Mockery::mock(JournalImportInvoiceService::class), Mockery::mock(JournalImportExpenseService::class), Mockery::mock(JournalImportPaymentService::class), Mockery::mock(JournalImportBankTransactionService::class));
        $this->runTaxContinuation($retryController);

        $this->assertSame(1, DB::table('accounting_acc_trans_mappings')->count());
        $this->assertSame('completed', DB::table('accounting_journal_import_rows')->where('id', 1)->value('processing_status'));
    }

    public function test_worker_stops_after_current_row_and_returns_unstarted_rows_to_pending(): void
    {
        $this->useSqliteDatabase();
        $this->createTables();
        $now = now();
        DB::table('accounting_journal_imports')->insert([
            'id' => 1,
            'business_id' => 3,
            'created_by' => 8,
            'status' => 'importing',
            'import_options' => '{}',
            'total_entries' => 2,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        foreach ([1, 2] as $id) {
            $entry = [
                'source_type' => 'Journal Entry',
                'source_number' => 'JE-CANCEL-'.$id,
                'source_key' => 'Journal Entry|JE-CANCEL-'.$id,
                'date' => '2026-10-01',
                'description' => 'Cancellation row '.$id,
                'is_unbalanced' => false,
                'is_duplicate' => false,
                'exists_in_system' => false,
                'debit_total' => 100,
                'credit_total' => 100,
                'lines' => [['debit' => 100, 'credit' => 0], ['debit' => 0, 'credit' => 100]],
            ];
            DB::table('accounting_journal_import_rows')->insert([
                'id' => $id,
                'journal_import_id' => 1,
                'business_id' => 3,
                'entry_index' => $id,
                'source_type' => 'Journal Entry',
                'source_number' => $entry['source_number'],
                'source_key' => $entry['source_key'],
                'source_action' => 'journal',
                'entry_data' => json_encode($entry),
                'draft_data' => '{}',
                'processing_status' => 'queued',
                'processing_attempts' => 0,
                'is_reviewed' => false,
                'reconciliation_status' => 'not_checked',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $controller = Mockery::mock(JournalEntryController::class);
        $controller->shouldReceive('processJournalImportEntriesForBatch')->once()->andReturnUsing(function ($import, array $entries) {
            $this->assertSame('JE-CANCEL-1', $entries[0]['source_number']);
            DB::table('accounting_acc_trans_mappings')->insert([
                'business_id' => $import->business_id,
                'journal_import_id' => $import->id,
                'type' => 'journal_entry',
                'import_source_key' => $entries[0]['source_key'],
            ]);
            DB::table('accounting_journal_imports')->where('id', $import->id)->update([
                'cancel_requested_at' => now(),
                'cancel_requested_by' => 8,
            ]);

            return ['imported' => 1, 'skipped' => 0];
        });
        $controller->shouldNotReceive('deleteTemporaryJournalFile');

        (new ProcessJournalImportJob(1))->handle(
            $controller,
            new JournalImportQueueService(),
            Mockery::mock(JournalImportInvoiceService::class),
            Mockery::mock(JournalImportExpenseService::class),
            Mockery::mock(JournalImportPaymentService::class),
            Mockery::mock(JournalImportBankTransactionService::class)
        );

        $this->assertSame('cancelled', DB::table('accounting_journal_imports')->where('id', 1)->value('status'));
        $this->assertSame('completed', DB::table('accounting_journal_import_rows')->where('id', 1)->value('processing_status'));
        $this->assertSame('pending', DB::table('accounting_journal_import_rows')->where('id', 2)->value('processing_status'));
        $this->assertSame(1, DB::table('accounting_acc_trans_mappings')->count());
    }

    public function test_analysis_job_stops_at_a_workbook_checkpoint_without_marking_batch_failed(): void
    {
        $this->useSqliteDatabase();
        $this->createTables();
        $now = now();
        DB::table('accounting_journal_imports')->insert([
            'id' => 1,
            'business_id' => 3,
            'created_by' => 8,
            'status' => 'queued',
            'file_path' => 'uploads/journal-imports/test.xlsx',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $controller = Mockery::mock(JournalEntryController::class);
        $controller->shouldReceive('analyzeJournalWorkbook')->once()->andReturnUsing(function ($path, $businessId, $progressCallback) {
            DB::table('accounting_journal_imports')->where('id', 1)->update([
                'cancel_requested_at' => now(),
                'cancel_requested_by' => 8,
            ]);
            $progressCallback(['processed_rows' => 500, 'message' => 'Menganalisis baris 500.']);
        });
        $controller->shouldNotReceive('writeTemporaryJournalAnalysis');
        $draftService = Mockery::mock(JournalImportDraftService::class);
        $draftService->shouldNotReceive('persistEntries');

        (new AnalyzeJournalImportJob(1))->handle(
            $controller,
            $draftService,
            new JournalImportCancellationService(),
            new JournalImportProgressService()
        );

        $this->assertSame('cancelled', DB::table('accounting_journal_imports')->where('id', 1)->value('status'));
        $this->assertSame(0, DB::table('accounting_journal_import_rows')->count());
    }

    public function test_duplicate_analysis_job_does_not_reopen_an_already_analyzed_batch(): void
    {
        $this->useSqliteDatabase();
        $this->createTables();
        $now = now();
        DB::table('accounting_journal_imports')->insert([
            'id' => 1,
            'business_id' => 3,
            'created_by' => 8,
            'status' => 'analyzed',
            'file_path' => 'uploads/journal-imports/test.xlsx',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $controller = Mockery::mock(JournalEntryController::class);
        $controller->shouldNotReceive('analyzeJournalWorkbook');
        $controller->shouldNotReceive('writeTemporaryJournalAnalysis');
        $draftService = Mockery::mock(JournalImportDraftService::class);
        $draftService->shouldNotReceive('persistEntries');

        (new AnalyzeJournalImportJob(1))->handle(
            $controller,
            $draftService,
            new JournalImportCancellationService(),
            new JournalImportProgressService()
        );

        $this->assertSame('analyzed', DB::table('accounting_journal_imports')->where('id', 1)->value('status'));
    }

    public function test_stop_requested_during_final_tax_chunk_finishes_cancellation(): void
    {
        $this->useSqliteDatabase();
        $this->createTables();
        $now = now();
        DB::table('accounting_journal_imports')->insert([
            'id' => 1,
            'business_id' => 3,
            'created_by' => 8,
            'status' => 'reconciling',
            'total_entries' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $stopRequested = false;
        $connection = DB::connection();
        $connection->setEventDispatcher(new \Illuminate\Events\Dispatcher(Container::getInstance()));
        $connection->listen(function ($query) use (&$stopRequested) {
            if ($stopRequested || ! str_contains(strtolower($query->sql), 'count(*) as row_count')) {
                return;
            }

            $stopRequested = true;
            (new JournalImportCancellationService())->requestStop(1, 3, 8);
        });

        $controller = Mockery::mock(JournalEntryController::class);
        $controller->shouldNotReceive('deleteTemporaryJournalFile');

        $this->runTaxContinuation($controller);

        $batch = DB::table('accounting_journal_imports')->where('id', 1)->first();
        $this->assertTrue($stopRequested);
        $this->assertSame('cancelled', $batch->status);
        $this->assertSame(8, (int) $batch->cancel_requested_by);
        $this->assertSame(8, (int) $batch->cancelled_by);
        $this->assertNotNull($batch->cancelled_at);
    }

    public function test_payment_waits_without_creating_a_payment_when_its_invoice_is_not_ready(): void
    {
        $this->useSqliteDatabase();
        $this->createTables();
        $now = now();
        DB::table('accounting_journal_imports')->insert([
            'id' => 1,
            'business_id' => 3,
            'created_by' => 8,
            'status' => 'queued_import',
            'import_options' => '{}',
            'processed_entries' => 0,
            'imported_entries' => 0,
            'skipped_entries' => 0,
            'total_entries' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $entry = [
            'source_type' => 'Receive Payment',
            'source_number' => 'PAYMENT-WAIT-1',
            'source_key' => 'Receive Payment|PAYMENT-WAIT-1',
            'date' => '2026-10-01',
            'description' => 'Payment waits for final invoice',
            'is_unbalanced' => false,
            'is_duplicate' => false,
            'exists_in_system' => false,
            'debit_total' => 100,
            'credit_total' => 100,
            'lines' => [['debit' => 100, 'credit' => 0], ['debit' => 0, 'credit' => 100]],
        ];
        DB::table('accounting_journal_import_rows')->insert([
            'id' => 1,
            'journal_import_id' => 1,
            'business_id' => 3,
            'entry_index' => 0,
            'source_type' => 'Receive Payment',
            'source_number' => 'PAYMENT-WAIT-1',
            'source_key' => $entry['source_key'],
            'source_action' => 'create',
            'entry_data' => json_encode($entry),
            'draft_data' => '{"invoice_reference":"transaction:44","payment_method":"cash","payment_account_id":7}',
            'processing_status' => 'queued',
            'processing_attempts' => 0,
            'is_reviewed' => false,
            'reconciliation_status' => 'matched',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $controller = Mockery::mock(JournalEntryController::class);
        $controller->shouldNotReceive('processJournalImportEntriesForBatch');
        $controller->shouldNotReceive('deleteTemporaryJournalFile');

        $paymentService = Mockery::mock(JournalImportPaymentService::class);
        $paymentService->shouldReceive('dependencyIssue')
            ->once()
            ->withArgs(function ($row, $providedEntry, $businessId) use ($entry) {
                return $row->id === 1
                    && $providedEntry['source_key'] === $entry['source_key']
                    && $businessId === 3;
            })
            ->andReturn('Pembayaran tertunda: invoice belum berstatus final.');
        $paymentService->shouldNotReceive('process');

        (new ProcessJournalImportJob(1))->handle(
            $controller,
            new JournalImportQueueService(),
            Mockery::mock(JournalImportInvoiceService::class),
            Mockery::mock(JournalImportExpenseService::class),
            $paymentService,
            Mockery::mock(JournalImportBankTransactionService::class)
        );
        $this->runTaxContinuation($controller);

        $row = DB::table('accounting_journal_import_rows')->where('id', 1)->first();
        $this->assertSame('pending', $row->processing_status);
        $this->assertStringContainsString('invoice belum berstatus final', $row->processing_message);
        $this->assertNull($row->source_record_id);
        $this->assertSame(0, DB::table('accounting_acc_trans_mappings')->count());
        $this->assertSame('partially_imported', DB::table('accounting_journal_imports')->where('id', 1)->value('status'));
    }

    public function test_job_processes_a_bounded_chunk_and_dispatches_a_continuation(): void
    {
        $this->useSqliteDatabase();
        $this->createTables();
        $now = now();
        DB::table('accounting_journal_imports')->insert([
            'id' => 1,
            'business_id' => 3,
            'created_by' => 8,
            'status' => 'queued_import',
            'import_options' => '{}',
            'processed_entries' => 0,
            'imported_entries' => 0,
            'skipped_entries' => 0,
            'total_entries' => 26,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $rows = [];
        for ($index = 0; $index < 26; $index++) {
            $sourceKey = 'Journal Entry|CHUNK-'.$index;
            $entry = [
                'source_type' => 'Journal Entry',
                'source_number' => 'CHUNK-'.$index,
                'source_key' => $sourceKey,
                'date' => '2026-10-01',
                'description' => 'Chunk row '.$index,
                'is_unbalanced' => false,
                'is_duplicate' => false,
                'exists_in_system' => false,
                'debit_total' => 100,
                'credit_total' => 100,
                'lines' => [['debit' => 100, 'credit' => 0], ['debit' => 0, 'credit' => 100]],
            ];
            $rows[] = [
                'journal_import_id' => 1,
                'business_id' => 3,
                'entry_index' => $index,
                'source_type' => 'Journal Entry',
                'source_number' => $entry['source_number'],
                'source_key' => $sourceKey,
                'source_action' => 'journal',
                'entry_data' => json_encode($entry),
                'draft_data' => '{}',
                'processing_status' => 'queued',
                'processing_attempts' => 0,
                'is_reviewed' => false,
                'reconciliation_status' => 'not_checked',
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        DB::table('accounting_journal_import_rows')->insert($rows);

        $controller = Mockery::mock(JournalEntryController::class);
        $controller->shouldReceive('processJournalImportEntriesForBatch')
            ->times(25)
            ->andReturnUsing(function ($import, array $entries) {
                DB::table('accounting_acc_trans_mappings')->insert([
                    'business_id' => $import->business_id,
                    'journal_import_id' => $import->id,
                    'type' => 'journal_entry',
                    'import_source_key' => $entries[0]['source_key'],
                ]);

                return ['imported' => 1, 'skipped' => 0];
            });

        (new ProcessJournalImportJob(1))->handle(
            $controller,
            new JournalImportQueueService(),
            Mockery::mock(JournalImportInvoiceService::class),
            Mockery::mock(JournalImportExpenseService::class),
            Mockery::mock(JournalImportPaymentService::class),
            Mockery::mock(JournalImportBankTransactionService::class)
        );

        $this->assertSame(25, DB::table('accounting_journal_import_rows')->where('processing_status', 'completed')->count());
        $this->assertSame(1, DB::table('accounting_journal_import_rows')->where('processing_status', 'queued')->count());
        $this->assertSame('importing', DB::table('accounting_journal_imports')->where('id', 1)->value('status'));
        $this->assertCount(1, $this->dispatchedJobs);
        $this->assertInstanceOf(ProcessJournalImportJob::class, $this->dispatchedJobs[0]);
    }

    public function test_prepare_phase_yields_after_a_chunk(): void
    {
        $this->useSqliteDatabase();
        $this->createTables();
        $now = now();
        DB::table('accounting_journal_imports')->insert([
            'id' => 1,
            'business_id' => 3,
            'created_by' => 8,
            'status' => 'preparing_import',
            'import_options' => '{}',
            'processed_entries' => 0,
            'imported_entries' => 0,
            'skipped_entries' => 0,
            'total_entries' => 26,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $controller = Mockery::mock(JournalEntryController::class);
        $queueService = Mockery::mock(JournalImportQueueService::class);
        $queueService->shouldReceive('queueRowsForBatch')
            ->once()
            ->with(1, 3, [], 0, 25)
            ->andReturn(['queued' => 25, 'processed_rows' => 25, 'last_id' => 25, 'has_more' => true]);

        (new ProcessJournalImportJob(1, 'prepare'))->handle(
            $controller,
            $queueService,
            Mockery::mock(JournalImportInvoiceService::class),
            Mockery::mock(JournalImportExpenseService::class),
            Mockery::mock(JournalImportPaymentService::class),
            Mockery::mock(JournalImportBankTransactionService::class)
        );

        $this->assertSame('preparing_import', DB::table('accounting_journal_imports')->where('id', 1)->value('status'));
        $this->assertCount(1, $this->dispatchedJobs);
    }

    public function test_payment_source_is_attached_to_the_imported_journal_mapping(): void
    {
        $this->useSqliteDatabase();
        $this->createTables();
        $now = now();
        DB::table('accounting_journal_imports')->insert([
            'id' => 1,
            'business_id' => 3,
            'created_by' => 8,
            'status' => 'queued_import',
            'import_options' => '{}',
            'processed_entries' => 0,
            'imported_entries' => 0,
            'skipped_entries' => 0,
            'total_entries' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $sourceKey = 'Receive Payment|PAYMENT-READY-1|2026-10-01';
        $entry = [
            'source_type' => 'Receive Payment',
            'source_number' => 'PAYMENT-READY-1',
            'source_key' => $sourceKey,
            'source_link_status' => 'unmatched',
            'source_link_candidate_details' => [],
            'date' => '2026-10-01',
            'description' => 'Payment mapped to journal',
            'is_unbalanced' => false,
            'is_duplicate' => false,
            'exists_in_system' => false,
            'debit_total' => 100,
            'credit_total' => 100,
            'lines' => [['debit' => 100, 'credit' => 0], ['debit' => 0, 'credit' => 100]],
        ];
        DB::table('accounting_journal_import_rows')->insert([
            'id' => 1,
            'journal_import_id' => 1,
            'business_id' => 3,
            'entry_index' => 0,
            'source_type' => 'Receive Payment',
            'source_number' => 'PAYMENT-READY-1',
            'source_key' => $sourceKey,
            'source_action' => 'create',
            'entry_data' => json_encode($entry),
            'draft_data' => '{}',
            'processing_status' => 'queued',
            'processing_attempts' => 0,
            'is_reviewed' => false,
            'reconciliation_status' => 'matched',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $payment = new \App\TransactionPayment();
        $payment->id = 812;
        $controller = Mockery::mock(JournalEntryController::class);
        $controller->shouldReceive('processJournalImportEntriesForBatch')
            ->once()
            ->andReturnUsing(function ($import, array $entries) use ($sourceKey) {
                $this->assertSame(\App\TransactionPayment::class, $entries[0]['source_record_type']);
                $this->assertSame(812, $entries[0]['source_record_id']);
                DB::table('accounting_acc_trans_mappings')->insert([
                    'business_id' => $import->business_id,
                    'journal_import_id' => $import->id,
                    'type' => 'journal_entry',
                    'import_source_key' => $sourceKey,
                ]);

                return ['imported' => 1, 'skipped' => 0];
            });
        $controller->shouldReceive('deleteTemporaryJournalFile')->twice()->with(null);

        $queueService = Mockery::mock(JournalImportQueueService::class);
        $queueService->shouldReceive('entryIssue')->once()->andReturn(null);
        $paymentService = Mockery::mock(JournalImportPaymentService::class);
        $paymentService->shouldReceive('dependencyIssue')->once()->andReturn(null);
        $paymentService->shouldReceive('process')->once()->andReturn($payment);

        (new ProcessJournalImportJob(1))->handle(
            $controller,
            $queueService,
            Mockery::mock(JournalImportInvoiceService::class),
            Mockery::mock(JournalImportExpenseService::class),
            $paymentService,
            Mockery::mock(JournalImportBankTransactionService::class)
        );
        $this->runTaxContinuation($controller);

        $row = DB::table('accounting_journal_import_rows')->where('id', 1)->first();
        $this->assertSame('completed', $row->processing_status, (string) $row->processing_error);
        $this->assertSame(\App\TransactionPayment::class, $row->source_record_type);
        $this->assertSame(812, (int) $row->source_record_id);
        $this->assertNotNull($row->journal_mapping_id);
        $this->assertSame('imported', DB::table('accounting_journal_imports')->where('id', 1)->value('status'));
    }

    public function test_bank_transaction_source_is_attached_to_the_imported_journal_mapping(): void
    {
        $this->useSqliteDatabase();
        $this->createTables();
        $now = now();
        DB::table('accounting_journal_imports')->insert([
            'id' => 1,
            'business_id' => 3,
            'created_by' => 8,
            'status' => 'queued_import',
            'import_options' => '{}',
            'processed_entries' => 0,
            'imported_entries' => 0,
            'skipped_entries' => 0,
            'total_entries' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $sourceKey = 'Bank Withdrawal|WITHDRAWAL-1|2026-10-25';
        $entry = [
            'source_type' => 'Bank Withdrawal',
            'source_number' => 'WITHDRAWAL-1',
            'source_key' => $sourceKey,
            'source_link_status' => 'unmatched',
            'source_link_candidate_details' => [],
            'date' => '2026-10-25',
            'description' => 'Bank withdrawal mapped to journal',
            'is_unbalanced' => false,
            'is_duplicate' => false,
            'exists_in_system' => false,
            'debit_total' => 90,
            'credit_total' => 90,
            'lines' => [['debit' => 90, 'credit' => 0], ['debit' => 0, 'credit' => 90]],
        ];
        DB::table('accounting_journal_import_rows')->insert([
            'id' => 1,
            'journal_import_id' => 1,
            'business_id' => 3,
            'entry_index' => 0,
            'source_type' => 'Bank Withdrawal',
            'source_number' => 'WITHDRAWAL-1',
            'source_key' => $sourceKey,
            'source_action' => 'create',
            'entry_data' => json_encode($entry),
            'draft_data' => '{"payment_account_id":7}',
            'processing_status' => 'queued',
            'processing_attempts' => 0,
            'is_reviewed' => false,
            'reconciliation_status' => 'matched',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $source = new \App\AccountTransaction();
        $source->id = 501;
        $queueService = Mockery::mock(JournalImportQueueService::class);
        $queueService->shouldReceive('entryIssue')->once()->andReturn(null);
        $bankService = Mockery::mock(JournalImportBankTransactionService::class);
        $bankService->shouldReceive('process')->once()->andReturn($source);
        $controller = Mockery::mock(JournalEntryController::class);
        $controller->shouldReceive('processJournalImportEntriesForBatch')
            ->once()
            ->andReturnUsing(function ($import, array $entries) use ($sourceKey) {
                $this->assertSame(\App\AccountTransaction::class, $entries[0]['source_record_type']);
                $this->assertSame(501, $entries[0]['source_record_id']);
                DB::table('accounting_acc_trans_mappings')->insert([
                    'business_id' => $import->business_id,
                    'journal_import_id' => $import->id,
                    'type' => 'journal_entry',
                    'import_source_key' => $sourceKey,
                ]);

                return ['imported' => 1, 'skipped' => 0];
            });
        $controller->shouldReceive('deleteTemporaryJournalFile')->twice()->with(null);

        (new ProcessJournalImportJob(1))->handle(
            $controller,
            $queueService,
            Mockery::mock(JournalImportInvoiceService::class),
            Mockery::mock(JournalImportExpenseService::class),
            Mockery::mock(JournalImportPaymentService::class),
            $bankService
        );
        $this->runTaxContinuation($controller);

        $row = DB::table('accounting_journal_import_rows')->where('id', 1)->first();
        $this->assertSame('completed', $row->processing_status, (string) $row->processing_error);
        $this->assertSame(\App\AccountTransaction::class, $row->source_record_type);
        $this->assertSame(501, (int) $row->source_record_id);
        $this->assertNotNull($row->journal_mapping_id);
        $this->assertSame('imported', DB::table('accounting_journal_imports')->where('id', 1)->value('status'));
    }

    private function createTables(): void
    {
        $this->database->schema()->create('accounting_journal_imports', function ($table) {
            $table->increments('id');
            $table->unsignedInteger('business_id');
            $table->unsignedInteger('created_by');
            $table->string('status');
            $table->longText('import_options')->nullable();
            $table->integer('processed_entries')->default(0);
            $table->integer('imported_entries')->default(0);
            $table->integer('skipped_entries')->default(0);
            $table->integer('total_entries')->default(0);
            $table->longText('progress_data')->nullable();
            $table->unsignedBigInteger('prepare_cursor')->default(0);
            $table->unsignedBigInteger('tax_reconciliation_cursor')->default(0);
            $table->dateTime('cancel_requested_at')->nullable();
            $table->unsignedInteger('cancel_requested_by')->nullable();
            $table->dateTime('cancelled_at')->nullable();
            $table->unsignedInteger('cancelled_by')->nullable();
            $table->text('last_message')->nullable();
            $table->text('error_message')->nullable();
            $table->string('file_path')->nullable();
            $table->string('analysis_path')->nullable();
            $table->longText('summary')->nullable();
            $table->timestamps();
        });
        $this->database->schema()->create('accounting_journal_import_rows', function ($table) {
            $table->increments('id');
            $table->unsignedInteger('journal_import_id');
            $table->unsignedInteger('business_id');
            $table->unsignedInteger('entry_index');
            $table->string('source_type');
            $table->text('source_number')->nullable();
            $table->text('source_key')->nullable();
            $table->string('source_action');
            $table->string('source_record_type')->nullable();
            $table->unsignedInteger('source_record_id')->nullable();
            $table->longText('entry_data');
            $table->longText('draft_data')->nullable();
            $table->boolean('is_reviewed')->default(false);
            $table->unsignedInteger('processing_attempts')->default(0);
            $table->string('processing_status')->default('pending');
            $table->text('processing_message')->nullable();
            $table->text('processing_error')->nullable();
            $table->dateTime('processing_started_at')->nullable();
            $table->dateTime('processed_at')->nullable();
            $table->unsignedInteger('journal_mapping_id')->nullable();
            $table->string('reconciliation_status')->default('not_checked');
            $table->decimal('journal_total', 22, 4)->nullable();
            $table->decimal('business_total', 22, 4)->nullable();
            $table->decimal('difference_amount', 22, 4)->nullable();
            $table->unsignedInteger('difference_approved_by')->nullable();
            $table->dateTime('difference_approved_at')->nullable();
            $table->text('difference_approval_reason')->nullable();
            $table->timestamps();
        });
        $this->database->schema()->create('accounting_acc_trans_mappings', function ($table) {
            $table->increments('id');
            $table->unsignedInteger('business_id');
            $table->unsignedInteger('journal_import_id')->nullable();
            $table->string('type');
            $table->string('import_source_key')->nullable();
            $table->string('source_link_status')->nullable();
            $table->unsignedInteger('source_record_id')->nullable();
        });
    }

    private function useSqliteDatabase(): void
    {
        if (! in_array('sqlite', \PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('PDO SQLite tidak tersedia pada PHP runtime ini.');
        }

        $this->originalContainer = Container::getInstance();
        $container = new Container();
        Container::setInstance($container);
        $this->database = new Capsule($container);
        $this->database->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $this->database->setAsGlobal();
        $container->instance('db', $this->database->getDatabaseManager());
        $container->instance('date', new DateFactory());
        $this->dispatchedJobs = [];
        $dispatcher = Mockery::mock(Dispatcher::class);
        $dispatcher->shouldReceive('dispatch')->zeroOrMoreTimes()->andReturnUsing(function ($job) {
            $this->dispatchedJobs[] = $job;

            return $job;
        });
        $container->instance(Dispatcher::class, $dispatcher);
        Facade::setFacadeApplication($container);
    }

    private function runTaxContinuation(JournalEntryController $controller): void
    {
        (new ProcessJournalImportJob(1, 'tax'))->handle(
            $controller,
            new JournalImportQueueService(),
            Mockery::mock(JournalImportInvoiceService::class),
            Mockery::mock(JournalImportExpenseService::class),
            Mockery::mock(JournalImportPaymentService::class),
            Mockery::mock(JournalImportBankTransactionService::class)
        );
    }
}
