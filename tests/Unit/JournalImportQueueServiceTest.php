<?php

namespace Tests\Unit;

use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Support\DateFactory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Factory as ValidatorFactory;
use Illuminate\Validation\ValidationException;
use Modules\Accounting\Services\JournalImportQueueService;
use PHPUnit\Framework\TestCase;

class JournalImportQueueServiceTest extends TestCase
{
    private ?Capsule $database = null;

    protected function tearDown(): void
    {
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);
        parent::tearDown();
    }

    public function test_only_complete_balanced_journal_entry_rows_are_queueable_in_this_processing_slice(): void
    {
        $service = new JournalImportQueueService();
        $entry = [
            'source_type' => 'Journal Entry',
            'source_key' => 'Journal Entry|JE-1',
            'is_unbalanced' => false,
            'is_duplicate' => false,
            'exists_in_system' => false,
            'lines' => [['debit' => 100, 'credit' => 0], ['debit' => 0, 'credit' => 100]],
        ];

        $this->assertTrue($service->queueDecision($entry, 'journal', 'pending')['eligible']);
        $this->assertFalse($service->queueDecision($entry, 'journal', 'completed')['eligible']);
        $this->assertTrue($service->queueDecision(array_merge($entry, ['source_type' => 'Sales Invoice']), 'create', 'pending')['eligible']);
        $this->assertFalse($service->queueDecision(array_merge($entry, ['is_unbalanced' => true]), 'journal', 'pending')['eligible']);
        $this->assertFalse($service->queueDecision(array_merge($entry, ['is_duplicate' => true]), 'journal', 'pending')['eligible']);
        $this->assertFalse($service->queueDecision(array_merge($entry, ['lines' => []]), 'journal', 'pending')['eligible']);
        $this->assertTrue($service->queueDecision($entry, 'journal', 'pending', 'mismatch')['eligible']);
        $this->assertTrue($service->queueDecision($entry, 'journal', 'pending', 'approved')['eligible']);
        $this->assertTrue($service->queueDecision($entry, 'journal', 'pending', 'auto_approved', (object) ['is_reviewed' => false])['eligible']);
        $this->assertTrue($service->queueDecision($entry, 'journal', 'pending', 'auto_approved', (object) ['is_reviewed' => true])['eligible']);
        $this->assertFalse($service->queueDecision($entry, 'journal', 'queued')['eligible']);
    }

    public function test_queueing_uses_saved_rows_ignores_review_marker_and_keeps_ineligible_rows_pending(): void
    {
        $this->useSqliteDatabase();
        $this->createDraftRowsTable();
        $this->database->schema()->create('accounting_journal_imports', function ($table) {
            $table->increments('id');
            $table->unsignedInteger('business_id');
            $table->longText('import_options')->nullable();
            $table->timestamps();
        });
        DB::table('accounting_journal_imports')->insert([
            'id' => 20,
            'business_id' => 3,
            'import_options' => '{}',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->database->schema()->create('accounting_acc_trans_mappings', function ($table) {
            $table->increments('id');
            $table->unsignedInteger('business_id');
            $table->string('type');
            $table->string('import_source_key')->nullable();
        });

        $validEntry = [
            'source_type' => 'Journal Entry',
            'source_key' => 'Journal Entry|JE-QUEUE-1',
            'is_unbalanced' => false,
            'is_duplicate' => false,
            'exists_in_system' => false,
            'lines' => [['debit' => 50, 'credit' => 0], ['debit' => 0, 'credit' => 50]],
        ];
        $this->insertDraftRow(1, 'Journal Entry', $validEntry, 'journal', true);
        $this->insertDraftRow(2, 'Journal Entry', array_merge($validEntry, [
            'source_key' => 'Journal Entry|JE-QUEUE-2',
            'is_unbalanced' => true,
        ]), 'journal');
        $this->insertDraftRow(3, 'Sales Invoice', array_merge($validEntry, [
            'source_type' => 'Sales Invoice',
            'source_key' => 'Sales Invoice|INV-QUEUE-3',
        ]), 'create');
        $this->insertDraftRow(4, 'Journal Entry', array_merge($validEntry, [
            'source_key' => 'Journal Entry|JE-EXISTING',
            'exists_in_system' => true,
        ]), 'journal');
        DB::table('accounting_acc_trans_mappings')->insert([
            'business_id' => 3,
            'type' => 'journal_entry',
            'import_source_key' => 'Journal Entry|JE-EXISTING',
        ]);

        $service = new JournalImportQueueService();
        $firstChunk = DB::transaction(function () use ($service) {
            return $service->queueRowsForBatch(20, 3, null, 0, 2);
        });
        $secondChunk = DB::transaction(function () use ($service, $firstChunk) {
            return $service->queueRowsForBatch(20, 3, null, $firstChunk['last_id'], 2);
        });
        $queued = $firstChunk['queued'] + $secondChunk['queued'];

        $this->assertTrue($firstChunk['has_more']);
        $this->assertFalse($secondChunk['has_more']);
        $this->assertSame(1, $queued);
        $this->assertSame('queued', DB::table('accounting_journal_import_rows')->where('id', 1)->value('processing_status'));
        $this->assertSame('pending', DB::table('accounting_journal_import_rows')->where('id', 2)->value('processing_status'));
        $this->assertSame('pending', DB::table('accounting_journal_import_rows')->where('id', 3)->value('processing_status'));
        $this->assertSame('completed', DB::table('accounting_journal_import_rows')->where('id', 4)->value('processing_status'));
        $this->assertSame(1, (int) DB::table('accounting_journal_import_rows')->where('id', 4)->value('journal_mapping_id'));
    }

    public function test_discrepancy_approval_requires_an_open_mismatch_and_records_audit_fields(): void
    {
        $this->useSqliteDatabase();
        $this->createDraftRowsTable();
        DB::table('accounting_journal_import_rows')->insert([
            'id' => 1,
            'journal_import_id' => 20,
            'business_id' => 3,
            'entry_index' => 4,
            'source_type' => 'Sales Invoice',
            'source_action' => 'create',
            'entry_data' => '{}',
            'draft_data' => '{}',
            'reconciliation_status' => 'mismatch',
            'journal_total' => 100,
            'business_total' => 99,
            'difference_amount' => -1,
            'processing_status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $row = (new JournalImportQueueService())->approveDiscrepancy(1, 3, 8, 'Approved by review');

        $this->assertSame('approved', $row->reconciliation_status);
        $this->assertSame(8, (int) $row->difference_approved_by);
        $this->assertSame('Approved by review', $row->difference_approval_reason);
        $this->assertNotEmpty($row->difference_approved_at);
    }

    public function test_discrepancy_approval_rejects_missing_mismatch_or_empty_reason(): void
    {
        $this->useSqliteDatabase();
        $this->createDraftRowsTable();
        DB::table('accounting_journal_import_rows')->insert([
            'id' => 1,
            'journal_import_id' => 20,
            'business_id' => 3,
            'entry_index' => 4,
            'source_type' => 'Sales Invoice',
            'source_action' => 'create',
            'entry_data' => '{}',
            'draft_data' => '{}',
            'reconciliation_status' => 'matched',
            'journal_total' => 100,
            'business_total' => 100,
            'difference_amount' => 0,
            'processing_status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $service = new JournalImportQueueService();
        try {
            $service->approveDiscrepancy(1, 3, 8, 'Need override');
            $this->fail('A matched row cannot receive a discrepancy override.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('row', $exception->errors());
        }

        DB::table('accounting_journal_import_rows')->where('id', 1)->update([
            'reconciliation_status' => 'mismatch',
            'difference_amount' => 1,
        ]);
        $this->expectException(ValidationException::class);
        $service->approveDiscrepancy(1, 3, 8, '   ');
    }

    public function test_reconciliation_auto_approves_mismatch_and_invalidates_stale_approval(): void
    {
        $this->useSqliteDatabase();
        $this->createDraftRowsTable();
        DB::table('accounting_journal_import_rows')->insert([
            'id' => 1,
            'journal_import_id' => 20,
            'business_id' => 3,
            'entry_index' => 4,
            'source_type' => 'Sales Invoice',
            'source_action' => 'create',
            'entry_data' => '{}',
            'draft_data' => '{}',
            'processing_status' => 'pending',
            'reconciliation_status' => 'not_checked',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $service = new JournalImportQueueService();
        $mismatch = $service->recordReconciliation(1, 3, 100, 99, 0.01);
        $this->assertSame('auto_approved', $mismatch->reconciliation_status);
        $this->assertSame(1.0, (float) $mismatch->difference_amount);
        $this->assertNull($mismatch->difference_approved_by);
        $this->assertNull($mismatch->difference_approved_at);
        $this->assertNull($mismatch->difference_approval_reason);

        DB::table('accounting_journal_import_rows')->where('id', 1)->update([
            'reconciliation_status' => 'approved',
            'difference_approved_by' => 8,
            'difference_approved_at' => now(),
            'difference_approval_reason' => 'Approved by review',
        ]);

        $sameTotals = $service->recordReconciliation(1, 3, 100, 99, 0.01);
        $this->assertSame('approved', $sameTotals->reconciliation_status);
        $this->assertSame(8, (int) $sameTotals->difference_approved_by);
        $this->assertNotEmpty($sameTotals->difference_approved_at);
        $this->assertSame('Approved by review', $sameTotals->difference_approval_reason);

        $changedTotals = $service->recordReconciliation(1, 3, 100, 98, 0.01);
        $this->assertSame('auto_approved', $changedTotals->reconciliation_status);
        $this->assertNull($changedTotals->difference_approved_by);
        $this->assertNull($changedTotals->difference_approved_at);
        $this->assertNull($changedTotals->difference_approval_reason);
    }

    public function test_unexplained_difference_is_auto_approved_and_queueable_without_review(): void
    {
        $this->useSqliteDatabase();
        $this->createDraftRowsTable();
        DB::table('accounting_journal_import_rows')->insert([
            'id' => 1,
            'journal_import_id' => 20,
            'business_id' => 3,
            'entry_index' => 4,
            'source_type' => 'Expense',
            'source_action' => 'update',
            'entry_data' => '{}',
            'draft_data' => '{}',
            'processing_status' => 'pending',
            'reconciliation_status' => 'not_checked',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $service = new JournalImportQueueService();
        $row = $service->recordReconciliation(1, 3, 27272727, 29999999.7);

        $this->assertSame('auto_approved', $row->reconciliation_status);
        $this->assertNull($row->difference_approved_by);
        $this->assertNull($row->difference_approved_at);
        $this->assertNull($row->difference_approval_reason);
        $this->assertTrue($service->queueDecision(
            [
                'source_type' => 'Expense',
                'source_key' => 'Expense|11873',
                'is_unbalanced' => false,
                'is_duplicate' => false,
                'exists_in_system' => false,
                'lines' => [['debit' => 1, 'credit' => 0]],
            ],
            'update',
            'pending',
            'auto_approved',
            (object) ['is_reviewed' => false]
        )['eligible']);
    }

    public function test_bulk_discrepancy_approval_marks_selected_rows_reviewed_with_one_audit_reason(): void
    {
        $this->useSqliteDatabase();
        $this->createDraftRowsTable();
        foreach ([['mismatch', 'pending'], ['auto_approved', 'failed']] as $index => [$status, $processingStatus]) {
            DB::table('accounting_journal_import_rows')->insert([
                'id' => $index + 1,
                'journal_import_id' => 20,
                'business_id' => 3,
                'entry_index' => $index + 1,
                'source_type' => 'Expense',
                'entry_data' => '{}',
                'draft_data' => '{}',
                'reconciliation_status' => $status,
                'difference_amount' => 5,
                'processing_status' => $processingStatus,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $rows = (new JournalImportQueueService())->approveDiscrepancies(20, 3, 8, [1, 2], 'Reviewed as one batch');

        $this->assertCount(2, $rows);
        foreach ($rows as $row) {
            $this->assertSame('approved', $row->reconciliation_status);
            $this->assertSame(8, (int) $row->difference_approved_by);
            $this->assertSame(1, (int) $row->is_reviewed);
            $this->assertSame(8, (int) $row->reviewed_by);
            $this->assertSame('Reviewed as one batch', $row->difference_approval_reason);
            $this->assertNotEmpty($row->difference_approved_at);
        }
    }

    public function test_bulk_discrepancy_approval_is_atomic_when_a_selected_row_is_already_queued(): void
    {
        $this->useSqliteDatabase();
        $this->createDraftRowsTable();
        foreach ([['mismatch', 'pending'], ['auto_approved', 'queued']] as $index => [$status, $processingStatus]) {
            DB::table('accounting_journal_import_rows')->insert([
                'id' => $index + 1,
                'journal_import_id' => 20,
                'business_id' => 3,
                'entry_index' => $index + 1,
                'source_type' => 'Expense',
                'entry_data' => '{}',
                'draft_data' => '{}',
                'reconciliation_status' => $status,
                'difference_amount' => 5,
                'processing_status' => $processingStatus,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        try {
            (new JournalImportQueueService())->approveDiscrepancies(20, 3, 8, [1, 2], 'Reviewed as one batch');
            $this->fail('The selected set must be rejected as a whole if one row is already queued.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('row_ids', $exception->errors());
        }
        $this->assertSame('mismatch', DB::table('accounting_journal_import_rows')->where('id', 1)->value('reconciliation_status'));
        $this->assertSame(0, (int) DB::table('accounting_journal_import_rows')->where('id', 1)->value('is_reviewed'));
    }

    private function createDraftRowsTable(): void
    {
        $this->database->schema()->create('accounting_journal_import_rows', function ($table) {
            $table->increments('id');
            $table->unsignedBigInteger('journal_import_id');
            $table->unsignedInteger('business_id');
            $table->unsignedInteger('entry_index');
            $table->string('source_type', 100);
            $table->string('source_action', 16)->default('review');
            $table->string('source_record_type', 64)->nullable();
            $table->unsignedBigInteger('source_record_id')->nullable();
            $table->text('source_key')->nullable();
            $table->longText('entry_data');
            $table->longText('draft_data')->nullable();
            $table->boolean('is_reviewed')->default(false);
            $table->unsignedInteger('reviewed_by')->nullable();
            $table->dateTime('reviewed_at')->nullable();
            $table->string('processing_status', 20)->default('pending');
            $table->unsignedInteger('processing_attempts')->default(0);
            $table->text('processing_message')->nullable();
            $table->text('processing_error')->nullable();
            $table->dateTime('processing_started_at')->nullable();
            $table->dateTime('processed_at')->nullable();
            $table->unsignedBigInteger('journal_mapping_id')->nullable();
            $table->string('reconciliation_status', 20)->default('not_checked');
            $table->decimal('journal_total', 22, 4)->nullable();
            $table->decimal('business_total', 22, 4)->nullable();
            $table->decimal('difference_amount', 22, 4)->nullable();
            $table->unsignedInteger('difference_approved_by')->nullable();
            $table->dateTime('difference_approved_at')->nullable();
            $table->text('difference_approval_reason')->nullable();
            $table->timestamps();
        });
    }

    private function insertDraftRow(int $id, string $sourceType, array $entry, string $action, bool $reviewed = false): void
    {
        DB::table('accounting_journal_import_rows')->insert([
            'id' => $id,
            'journal_import_id' => 20,
            'business_id' => 3,
            'entry_index' => $id,
            'source_type' => $sourceType,
            'source_action' => $action,
            'source_key' => $entry['source_key'] ?? null,
            'entry_data' => json_encode($entry),
            'draft_data' => '{}',
            'is_reviewed' => $reviewed,
            'processing_status' => 'pending',
            'reconciliation_status' => 'not_checked',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function useSqliteDatabase(): void
    {
        if (! in_array('sqlite', \PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('PDO SQLite tidak tersedia pada PHP runtime ini.');
        }

        $container = new Container();
        $this->database = new Capsule($container);
        $this->database->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $this->database->setAsGlobal();
        $container->instance('db', $this->database->getDatabaseManager());
        $container->instance('date', new DateFactory());
        $container->instance('validator', new ValidatorFactory(new Translator(new ArrayLoader(), 'en'), $container));
        Facade::setFacadeApplication($container);
    }
}
