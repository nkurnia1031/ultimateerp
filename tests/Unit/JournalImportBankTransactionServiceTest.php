<?php

namespace Tests\Unit;

use App\AccountTransaction;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\DateFactory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Factory as ValidatorFactory;
use Modules\Accounting\Services\JournalImportBankTransactionService;
use Modules\Accounting\Services\JournalImportDraftService;
use PHPUnit\Framework\TestCase;

class JournalImportBankTransactionServiceTest extends TestCase
{
    private ?Capsule $database = null;

    private ?Container $previousContainer = null;

    private $previousConnectionResolver = null;

    protected function tearDown(): void
    {
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);
        Container::setInstance($this->previousContainer);
        if ($this->previousConnectionResolver === null) {
            Model::unsetConnectionResolver();
        } else {
            Model::setConnectionResolver($this->previousConnectionResolver);
        }
        parent::tearDown();
    }

    public function test_transfer_creates_a_linked_pair_and_retry_and_revert_are_idempotent(): void
    {
        $this->useSqliteDatabase();
        $this->createTables();
        $row = $this->makeRow(1, 'Bank Transfer', 'create', [
            'payment_account_id' => 7,
            'transfer_account_id' => 8,
        ]);
        $entry = $this->entry('Bank Transfer', 'TRANSFER-1', 25);
        $import = (object) ['id' => 80, 'business_id' => 2, 'created_by' => 5];
        $service = $this->service();

        $main = $service->process($row, $entry, $import);
        $service->process($row, $entry, $import);

        $records = AccountTransaction::where('journal_import_row_id', 1)->orderBy('id')->get();
        $this->assertCount(2, $records);
        $debit = $records->firstWhere('type', 'debit');
        $credit = $records->firstWhere('type', 'credit');
        $this->assertSame('fund_transfer', $debit->sub_type);
        $this->assertSame(7, (int) $debit->account_id);
        $this->assertSame(8, (int) $credit->account_id);
        $this->assertSame((int) $credit->id, (int) $debit->transfer_transaction_id);
        $this->assertSame((int) $debit->id, (int) $credit->transfer_transaction_id);
        $this->assertSame((int) $debit->id, (int) $main->id);

        $service->revertImport(80, 2);
        $this->assertSame(0, AccountTransaction::where('journal_import_row_id', 1)->whereNull('deleted_at')->count());
    }

    public function test_deposit_and_withdrawal_use_the_correct_visible_ledger_directions(): void
    {
        $this->useSqliteDatabase();
        $this->createTables();
        $import = (object) ['id' => 81, 'business_id' => 2, 'created_by' => 5];
        $service = $this->service();

        $depositRow = $this->makeRow(2, 'Bank Deposit', 'create', ['payment_account_id' => 7]);
        $deposit = $service->process($depositRow, $this->entry('Bank Deposit', 'DEP-1', 32), $import);
        $this->assertSame('deposit', $deposit->sub_type);
        $this->assertSame('credit', $deposit->type);
        $this->assertSame(7, (int) $deposit->account_id);
        $this->assertNull($deposit->transfer_transaction_id);

        $withdrawalRow = $this->makeRow(3, 'Bank Withdrawal', 'create', ['payment_account_id' => 8]);
        $withdrawal = $service->process($withdrawalRow, $this->entry('Bank Withdrawal', 'WD-1', 12), $import);
        $this->assertSame('withdrawal', $withdrawal->sub_type);
        $this->assertSame('debit', $withdrawal->type);
        $this->assertSame(8, (int) $withdrawal->account_id);
    }

    public function test_linked_bank_source_with_amount_difference_processes_without_manual_approval(): void
    {
        $this->useSqliteDatabase();
        $this->createTables();
        $source = $this->insertSource(103, 7, 'credit', 'deposit', 20, 'DEP-DIFF');
        $row = $this->makeRow(10, 'Bank Deposit', 'link', ['payment_account_id' => 7], (int) $source->id, 84);
        DB::table('accounting_journal_import_rows')->where('id', 10)->update([
            'reconciliation_status' => 'auto_approved',
        ]);
        $import = (object) ['id' => 84, 'business_id' => 2, 'created_by' => 5];

        $linked = $this->service()->process($row, $this->entry('Bank Deposit', 'DEP-DIFF', 25), $import);

        $this->assertSame((int) $source->id, (int) $linked->id);
        $this->assertSame(20.0, (float) $linked->amount);
        $snapshot = json_decode((string) DB::table('accounting_journal_import_rows')->where('id', 10)->value('source_mutation_snapshot'), true);
        $this->assertSame('linked', $snapshot['action']);
    }

    public function test_reversal_requires_a_business_scoped_target_and_creates_one_inverse_row(): void
    {
        $this->useSqliteDatabase();
        $this->createTables();
        $target = $this->insertSource(100, 7, 'credit', 'deposit', 40, 'DEP-OLD');
        $row = $this->makeRow(4, 'Reversal', 'create', ['reversal_target_id' => (int) $target->id], null, 82);
        $entry = $this->entry('Reversal', 'REV-1', 40);
        $import = (object) ['id' => 82, 'business_id' => 2, 'created_by' => 5];
        $service = $this->service();

        $reversal = $service->process($row, $entry, $import);

        $this->assertSame('reversal', $reversal->sub_type);
        $this->assertSame('debit', $reversal->type);
        $this->assertSame((int) $target->id, (int) $reversal->transfer_transaction_id);
        $this->assertSame(40.0, (float) $reversal->amount);
        $service->process($row, $entry, $import);
        $this->assertSame(1, AccountTransaction::where('journal_import_row_id', 4)->count());

        $duplicateRow = $this->makeRow(5, 'Reversal', 'create', ['reversal_target_id' => (int) $target->id], null, 82);
        $issue = $service->readinessIssue($duplicateRow, $this->entry('Reversal', 'REV-2', 40), 2);
        $this->assertStringContainsString('sudah pernah dibalik', $issue);
    }

    public function test_update_completes_a_missing_transfer_pair_and_revert_restores_the_existing_source(): void
    {
        $this->useSqliteDatabase();
        $this->createTables();
        $source = $this->insertSource(102, 7, 'debit', 'fund_transfer', 20, 'TR-UPDATE');
        $row = $this->makeRow(9, 'Bank Transfer', 'update', [
            'payment_account_id' => 7,
            'transfer_account_id' => 8,
        ], (int) $source->id, 83);
        $entry = $this->entry('Bank Transfer', 'TR-UPDATE', 20);
        $import = (object) ['id' => 83, 'business_id' => 2, 'created_by' => 5];
        $service = $this->service();

        $service->process($row, $entry, $import);

        $completedSource = AccountTransaction::find($source->id);
        $counterpart = AccountTransaction::find($completedSource->transfer_transaction_id);
        $this->assertNotNull($counterpart);
        $this->assertSame(8, (int) $counterpart->account_id);
        $this->assertSame('credit', $counterpart->type);
        $this->assertSame((int) $completedSource->id, (int) $counterpart->transfer_transaction_id);

        $service->revertImport(83, 2);
        $restoredSource = AccountTransaction::find($source->id);
        $this->assertNull($restoredSource->transfer_transaction_id);
        $this->assertSame(0, AccountTransaction::where('journal_import_row_id', 9)->count());
    }

    public function test_missing_or_cross_business_accounts_and_conflicting_matched_accounts_are_blocked(): void
    {
        $this->useSqliteDatabase();
        $this->createTables();
        $service = $this->service();

        $missingAccount = $this->makeRow(6, 'Bank Withdrawal', 'create', []);
        $missingIssue = $service->readinessIssue($missingAccount, $this->entry('Bank Withdrawal', 'WD-2', 8), 2);
        $this->assertStringContainsString('Pilih akun bank/kas', $missingIssue);

        $crossBusiness = $this->makeRow(7, 'Bank Withdrawal', 'create', ['payment_account_id' => 9]);
        $crossBusinessIssue = $service->readinessIssue($crossBusiness, $this->entry('Bank Withdrawal', 'WD-3', 8), 2);
        $this->assertStringContainsString('business aktif', $crossBusinessIssue);

        $source = $this->insertSource(101, 7, 'debit', 'withdrawal', 8, 'WD-4');
        $conflict = $this->makeRow(8, 'Bank Withdrawal', 'update', ['payment_account_id' => 8], (int) $source->id);
        $conflictIssue = $service->readinessIssue($conflict, $this->entry('Bank Withdrawal', 'WD-4', 8), 2);
        $this->assertStringContainsString('berbeda dari pilihan draft', $conflictIssue);
    }

    private function service(): JournalImportBankTransactionService
    {
        return new JournalImportBankTransactionService(new JournalImportDraftService());
    }

    private function makeRow(int $id, string $sourceType, string $action, array $draftData, ?int $sourceRecordId = null, int $importId = 80): object
    {
        DB::table('accounting_journal_import_rows')->insert([
            'id' => $id,
            'journal_import_id' => $importId,
            'business_id' => 2,
            'source_type' => $sourceType,
            'source_action' => $action,
            'source_record_type' => $sourceRecordId === null ? null : AccountTransaction::class,
            'source_record_id' => $sourceRecordId,
            'draft_data' => json_encode($draftData),
            'source_mutation_snapshot' => null,
            'reconciliation_status' => 'matched',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return DB::table('accounting_journal_import_rows')->where('id', $id)->first();
    }

    private function entry(string $sourceType, string $sourceNumber, float $amount): array
    {
        return [
            'source_type' => $sourceType,
            'source_number' => $sourceNumber,
            'source_key' => $sourceType.'|'.$sourceNumber,
            'date' => '2026-10-25',
            'description' => 'Journal import test '.$sourceNumber,
            'debit_total' => $amount,
            'credit_total' => $amount,
            'is_unbalanced' => false,
            'is_duplicate' => false,
            'exists_in_system' => false,
            'lines' => [['debit' => $amount, 'credit' => 0], ['debit' => 0, 'credit' => $amount]],
        ];
    }

    private function insertSource(int $id, int $accountId, string $type, string $subType, float $amount, string $reference): AccountTransaction
    {
        return AccountTransaction::create([
            'id' => $id,
            'account_id' => $accountId,
            'type' => $type,
            'sub_type' => $subType,
            'amount' => $amount,
            'reff_no' => $reference,
            'operation_date' => '2026-10-25 00:00:00',
            'created_by' => 5,
            'note' => 'Existing source',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createTables(): void
    {
        $this->database->schema()->create('accounts', function ($table) {
            $table->increments('id');
            $table->unsignedInteger('business_id');
            $table->string('name')->nullable();
            $table->string('account_number')->nullable();
            $table->boolean('is_closed')->default(false);
            $table->softDeletes();
            $table->timestamps();
        });
        $this->database->schema()->create('account_transactions', function ($table) {
            $table->increments('id');
            $table->unsignedInteger('account_id');
            $table->string('type');
            $table->string('sub_type')->nullable();
            $table->decimal('amount', 22, 4);
            $table->string('reff_no')->nullable();
            $table->dateTime('operation_date');
            $table->unsignedInteger('created_by');
            $table->unsignedInteger('transaction_id')->nullable();
            $table->unsignedInteger('transaction_payment_id')->nullable();
            $table->unsignedInteger('transfer_transaction_id')->nullable();
            $table->text('note')->nullable();
            $table->unsignedBigInteger('journal_import_id')->nullable();
            $table->unsignedBigInteger('journal_import_row_id')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });
        $this->database->schema()->create('accounting_journal_import_rows', function ($table) {
            $table->increments('id');
            $table->unsignedBigInteger('journal_import_id');
            $table->unsignedInteger('business_id');
            $table->string('source_type');
            $table->string('source_action');
            $table->string('source_record_type')->nullable();
            $table->unsignedInteger('source_record_id')->nullable();
            $table->longText('draft_data')->nullable();
            $table->longText('source_mutation_snapshot')->nullable();
            $table->string('reconciliation_status')->default('not_checked');
            $table->timestamps();
        });

        DB::table('accounts')->insert([
            ['id' => 7, 'business_id' => 2, 'name' => 'Main bank', 'account_number' => '100', 'is_closed' => 0],
            ['id' => 8, 'business_id' => 2, 'name' => 'Cash', 'account_number' => '101', 'is_closed' => 0],
            ['id' => 9, 'business_id' => 3, 'name' => 'Other business bank', 'account_number' => '200', 'is_closed' => 0],
        ]);
    }

    private function useSqliteDatabase(): void
    {
        if (! in_array('sqlite', \PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('PDO SQLite tidak tersedia pada PHP runtime ini.');
        }

        $this->previousContainer = Container::getInstance();
        $container = new Container();
        Container::setInstance($container);
        $this->previousConnectionResolver = Model::getConnectionResolver();
        $this->database = new Capsule($container);
        $this->database->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $this->database->setAsGlobal();
        Model::setConnectionResolver($this->database->getDatabaseManager());
        $container->instance('db', $this->database->getDatabaseManager());
        $container->instance('date', new DateFactory());
        $container->instance('validator', new ValidatorFactory(new Translator(new ArrayLoader(), 'en'), $container));
        Facade::setFacadeApplication($container);
    }
}
