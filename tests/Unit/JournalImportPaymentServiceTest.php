<?php

namespace Tests\Unit;

use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\DateFactory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Factory as ValidatorFactory;
use App\Utils\TransactionUtil;
use Mockery;
use Modules\Accounting\Services\JournalImportDraftService;
use Modules\Accounting\Services\JournalImportPaymentService;
use PHPUnit\Framework\TestCase;

class JournalImportPaymentServiceTest extends TestCase
{
    private ?Capsule $database = null;

    private ?Container $container = null;

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

    public function test_reverting_a_created_payment_restores_the_invoice_payment_status_snapshot(): void
    {
        $this->useSqliteDatabase();
        $this->createTables();
        $now = now();

        DB::table('business')->insert([
            'id' => 2,
            'custom_labels' => '{}',
        ]);
        DB::table('business_locations')->insert([
            'id' => 2,
            'business_id' => 2,
            'default_payment_accounts' => json_encode(['bank_transfer' => ['is_enabled' => true]]),
        ]);
        DB::table('accounts')->insert([
            'id' => 7,
            'business_id' => 2,
            'is_closed' => 0,
            'deleted_at' => null,
        ]);
        DB::table('transactions')->insert([
            'id' => 10,
            'business_id' => 2,
            'type' => 'sell',
            'status' => 'final',
            'contact_id' => 30,
            'location_id' => 2,
            'final_total' => 100,
            'payment_status' => 'partial',
        ]);
        DB::table('transaction_payments')->insert([
            'id' => 20,
            'transaction_id' => 10,
            'business_id' => 2,
            'is_return' => 0,
            'amount' => 75,
            'method' => 'bank_transfer',
            'paid_on' => '2026-10-01 10:00:00',
            'created_by' => 5,
            'paid_through_link' => 0,
            'is_advance' => 0,
            'payment_for' => 30,
            'payment_ref_no' => 'EXISTING-20',
            'account_id' => 7,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('transactions')->insert([
            'id' => 11,
            'business_id' => 2,
            'type' => 'purchase',
            'status' => 'final',
            'contact_id' => 31,
            'location_id' => 2,
            'final_total' => 100,
            'payment_status' => 'partial',
        ]);
        DB::table('transaction_payments')->insert([
            'id' => 21,
            'transaction_id' => 11,
            'business_id' => 2,
            'is_return' => 0,
            'amount' => 75,
            'method' => 'bank_transfer',
            'paid_on' => '2026-10-01 10:00:00',
            'created_by' => 5,
            'paid_through_link' => 0,
            'is_advance' => 0,
            'payment_for' => 31,
            'payment_ref_no' => 'EXISTING-21',
            'account_id' => 7,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('accounting_journal_import_rows')->insert([
            'id' => 1,
            'journal_import_id' => 1,
            'business_id' => 2,
            'entry_index' => 0,
            'source_type' => 'Receive Payment',
            'source_number' => 'PAYMENT-SNAPSHOT-1',
            'source_key' => 'Receive Payment|PAYMENT-SNAPSHOT-1|2026-10-25',
            'source_action' => 'create',
            'draft_data' => json_encode([
                'invoice_reference' => 'transaction:10',
                'payment_method' => 'bank_transfer',
                'payment_account_id' => 7,
            ]),
            'entry_data' => '{}',
        ]);
        DB::table('accounting_journal_import_rows')->insert([
            'id' => 2,
            'journal_import_id' => 2,
            'business_id' => 2,
            'entry_index' => 0,
            'source_type' => 'Purchase Payment',
            'source_number' => 'PURCHASE-PAYMENT-SNAPSHOT-1',
            'source_key' => 'Purchase Payment|PURCHASE-PAYMENT-SNAPSHOT-1|2026-10-25',
            'source_action' => 'create',
            'draft_data' => json_encode([
                'invoice_reference' => 'transaction:11',
                'payment_method' => 'bank_transfer',
                'payment_account_id' => 7,
            ]),
            'entry_data' => '{}',
        ]);

        $translator = new Translator(new ArrayLoader(), 'en');
        $this->container->instance('translator', $translator);
        $this->container->instance('validator', new ValidatorFactory($translator, $this->container));
        $transactionUtil = Mockery::mock(TransactionUtil::class);
        $transactionUtil->shouldReceive('updatePaymentStatus')
            ->twice()
            ->withArgs(fn ($transactionId, $total) => in_array((int) $transactionId, [10, 11], true) && (float) $total === 100.0)
            ->andReturnUsing(function (int $transactionId, float $total): string {
                $paid = (float) DB::table('transaction_payments')->where('transaction_id', $transactionId)->sum('amount');
                $status = $paid >= $total ? 'paid' : ($paid > 0 ? 'partial' : 'due');
                DB::table('transactions')->where('id', $transactionId)->update(['payment_status' => $status]);

                return $status;
            });

        $service = new JournalImportPaymentService(new JournalImportDraftService(), $transactionUtil);
        $row = DB::table('accounting_journal_import_rows')->where('id', 1)->first();
        $entry = [
            'source_type' => 'Receive Payment',
            'source_number' => 'PAYMENT-SNAPSHOT-1',
            'source_key' => 'Receive Payment|PAYMENT-SNAPSHOT-1|2026-10-25',
            'source_link_status' => 'unmatched',
            'source_link_candidate_details' => [],
            'date' => '2026-10-25',
            'description' => 'Snapshot rollback test',
            'debit_total' => 10,
            'credit_total' => 10,
            'lines' => [['debit' => 10, 'credit' => 0], ['debit' => 0, 'credit' => 10]],
        ];
        $import = (object) ['business_id' => 2, 'created_by' => 5];

        $payment = $service->process($row, $entry, $import);

        $this->assertSame(2, DB::table('transaction_payments')->where('transaction_id', 10)->count());
        $this->assertSame('partial', DB::table('transactions')->where('id', 10)->value('payment_status'));
        $snapshot = json_decode(DB::table('accounting_journal_import_rows')->where('id', 1)->value('source_mutation_snapshot'), true);
        $this->assertSame('partial', $snapshot['invoice_before']['payment_status']);

        $this->assertSame(1, $service->revertImport(1, 2));

        $this->assertFalse(DB::table('transaction_payments')->where('id', $payment->id)->exists());
        $this->assertSame(1, DB::table('transaction_payments')->where('transaction_id', 10)->count());
        $this->assertSame('partial', DB::table('transactions')->where('id', 10)->value('payment_status'));

        $purchaseRow = DB::table('accounting_journal_import_rows')->where('id', 2)->first();
        $purchaseEntry = [
            'source_type' => 'Purchase Payment',
            'source_number' => 'PURCHASE-PAYMENT-SNAPSHOT-1',
            'source_key' => 'Purchase Payment|PURCHASE-PAYMENT-SNAPSHOT-1|2026-10-25',
            'source_link_status' => 'unmatched',
            'source_link_candidate_details' => [],
            'date' => '2026-10-25',
            'description' => 'Purchase payment snapshot rollback test',
            'debit_total' => 10,
            'credit_total' => 10,
            'lines' => [['debit' => 10, 'credit' => 0], ['debit' => 0, 'credit' => 10]],
        ];
        $purchaseImport = (object) ['business_id' => 2, 'created_by' => 5];
        $purchasePayment = $service->process($purchaseRow, $purchaseEntry, $purchaseImport);

        $this->assertSame(11, (int) $purchasePayment->transaction_id);
        $this->assertSame('partial', DB::table('transactions')->where('id', 11)->value('payment_status'));
        $purchaseSnapshot = json_decode(DB::table('accounting_journal_import_rows')->where('id', 2)->value('source_mutation_snapshot'), true);
        $this->assertSame('partial', $purchaseSnapshot['invoice_before']['payment_status']);
        $this->assertSame(1, $service->revertImport(2, 2));
        $this->assertFalse(DB::table('transaction_payments')->where('id', $purchasePayment->id)->exists());
        $this->assertSame(1, DB::table('transaction_payments')->where('transaction_id', 11)->count());
        $this->assertSame('partial', DB::table('transactions')->where('id', 11)->value('payment_status'));
    }

    private function createTables(): void
    {
        $schema = $this->database->schema();
        $schema->create('business', function ($table) {
            $table->increments('id');
            $table->longText('custom_labels')->nullable();
        });
        $schema->create('business_locations', function ($table) {
            $table->increments('id');
            $table->unsignedInteger('business_id');
            $table->longText('default_payment_accounts')->nullable();
        });
        $schema->create('accounts', function ($table) {
            $table->increments('id');
            $table->unsignedInteger('business_id');
            $table->boolean('is_closed')->default(false);
            $table->dateTime('deleted_at')->nullable();
        });
        $schema->create('transactions', function ($table) {
            $table->increments('id');
            $table->unsignedInteger('business_id');
            $table->string('type');
            $table->string('status');
            $table->unsignedInteger('contact_id')->nullable();
            $table->unsignedInteger('location_id')->nullable();
            $table->decimal('final_total', 22, 4);
            $table->string('payment_status');
        });
        $schema->create('transaction_payments', function ($table) {
            $table->increments('id');
            $table->unsignedInteger('transaction_id')->nullable();
            $table->unsignedInteger('business_id');
            $table->boolean('is_return')->default(false);
            $table->decimal('amount', 22, 4);
            $table->string('method')->nullable();
            $table->string('payment_type')->nullable();
            $table->dateTime('paid_on')->nullable();
            $table->unsignedInteger('created_by')->nullable();
            $table->boolean('paid_through_link')->default(false);
            $table->boolean('is_advance')->default(false);
            $table->unsignedInteger('payment_for')->nullable();
            $table->text('note')->nullable();
            $table->string('payment_ref_no')->nullable();
            $table->unsignedInteger('account_id')->nullable();
            $table->timestamps();
        });
        $schema->create('accounting_journal_import_rows', function ($table) {
            $table->increments('id');
            $table->unsignedInteger('journal_import_id');
            $table->unsignedInteger('business_id');
            $table->unsignedInteger('entry_index');
            $table->string('source_type');
            $table->string('source_number')->nullable();
            $table->string('source_key')->nullable();
            $table->string('source_action');
            $table->string('source_record_type')->nullable();
            $table->unsignedInteger('source_record_id')->nullable();
            $table->longText('draft_data')->nullable();
            $table->longText('entry_data')->nullable();
            $table->longText('source_mutation_snapshot')->nullable();
            $table->timestamps();
        });
    }

    private function useSqliteDatabase(): void
    {
        if (! in_array('sqlite', \PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('PDO SQLite tidak tersedia pada PHP runtime ini.');
        }

        $this->previousContainer = Container::getInstance();
        $this->container = new Container();
        Container::setInstance($this->container);
        $this->previousConnectionResolver = Model::getConnectionResolver();
        $this->database = new Capsule($this->container);
        $this->database->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $this->database->setAsGlobal();
        Model::setConnectionResolver($this->database->getDatabaseManager());
        $this->container->instance('db', $this->database->getDatabaseManager());
        $this->container->instance('date', new DateFactory());
        Facade::setFacadeApplication($this->container);
    }
}
