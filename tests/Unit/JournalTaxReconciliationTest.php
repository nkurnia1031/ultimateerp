<?php

namespace Tests\Unit;

use App\Transaction;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Support\DateFactory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use Modules\Accounting\Services\JournalTaxReconciliationService;
use PHPUnit\Framework\TestCase;

class JournalTaxReconciliationTest extends TestCase
{
    private Capsule $database;

    private JournalTaxReconciliationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        if (! in_array('sqlite', \PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('PDO SQLite tidak tersedia pada PHP runtime ini.');
        }

        $container = new Container();
        $this->database = new Capsule($container);
        $this->database->addConnection([
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
        $this->database->setAsGlobal();
        EloquentModel::setConnectionResolver($this->database->getDatabaseManager());
        $container->instance('db', $this->database->getDatabaseManager());
        $container->instance('date', new DateFactory());
        Facade::setFacadeApplication($container);

        $this->createSchema();
        $this->service = new JournalTaxReconciliationService();
        $this->createTaxRatesAndMapping();
    }

    protected function tearDown(): void
    {
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);
        EloquentModel::unsetConnectionResolver();
        parent::tearDown();
    }

    public function test_expense_uses_journal_tax_preserves_basis_and_retries_idempotently(): void
    {
        $this->createTransaction(100, 'expense', 100, 5, 105);
        $this->createJournalMapping(500, 100);
        $this->createJournalLines(500, [
            [10, 100, 'debit'],
            [20, 10, 'debit'],
            [40, 110, 'credit'],
        ]);

        $result = $this->service->reconcileImport(50, 1);

        $this->assertSame(['reconciled' => 1, 'review' => 0, 'not_applicable' => 0], $result);
        $source = Transaction::find(100);
        $mapping = DB::table('accounting_acc_trans_mappings')->where('id', 500)->first();
        $this->assertSame(100.0, (float) $source->total_before_tax);
        $this->assertSame(10.0, (float) $source->tax_amount);
        $this->assertSame(110.0, (float) $source->final_total);
        $this->assertSame(110.0, (float) $mapping->journal_total_amount);
        $this->assertSame(110.0, (float) $mapping->recalculated_final_total);

        $originalSnapshot = $mapping->source_tax_snapshot;
        $retryResult = $this->service->reconcileImport(50, 1);
        $retryMapping = DB::table('accounting_acc_trans_mappings')->where('id', 500)->first();
        $this->assertSame(1, $retryResult['reconciled']);
        $this->assertSame($originalSnapshot, $retryMapping->source_tax_snapshot);
    }

    public function test_sales_item_tax_is_updated_but_total_mismatch_is_reported_for_review(): void
    {
        $this->createTransaction(101, 'sell', 100, 5, 105);
        DB::table('transaction_sell_lines')->insert([
            'id' => 1,
            'transaction_id' => 101,
            'quantity' => 1,
            'unit_price' => 100,
            'tax_id' => 1,
            'item_tax' => 5,
            'unit_price_inc_tax' => 105,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->createJournalMapping(501, 101);
        $this->createJournalLines(501, [
            [10, 120, 'debit'],
            [20, 10, 'credit'],
            [30, 110, 'credit'],
        ]);

        $result = $this->service->reconcileImport(50, 1);

        $this->assertSame(['reconciled' => 0, 'review' => 1, 'not_applicable' => 0], $result);
        $source = Transaction::find(101);
        $line = DB::table('transaction_sell_lines')->where('id', 1)->first();
        $mapping = DB::table('accounting_acc_trans_mappings')->where('id', 501)->first();
        $this->assertSame(100.0, (float) $source->total_before_tax);
        $this->assertSame(10.0, (float) $source->tax_amount);
        $this->assertSame(110.0, (float) $source->final_total);
        $this->assertSame(2, (int) $line->tax_id);
        $this->assertSame(10.0, (float) $line->item_tax);
        $this->assertSame(110.0, (float) $line->unit_price_inc_tax);
        $this->assertSame(120.0, (float) $mapping->journal_total_amount);
        $this->assertSame(110.0, (float) $mapping->recalculated_final_total);
        $this->assertSame('review', $mapping->tax_reconciliation_status);
        $this->assertStringContainsString('berbeda dari total debit Journal', $mapping->tax_reconciliation_note);

        $retryResult = $this->service->reconcileImport(50, 1);
        $this->assertSame(1, $retryResult['review']);
        $this->assertSame(100.0, (float) Transaction::find(101)->total_before_tax);
    }

    public function test_purchase_item_tax_matches_journal_and_reverts_from_snapshot(): void
    {
        $this->createTransaction(102, 'purchase', 100, 5, 105);
        DB::table('purchase_lines')->insert([
            'id' => 2,
            'transaction_id' => 102,
            'quantity' => 2,
            'purchase_price' => 50,
            'tax_id' => 1,
            'item_tax' => 2.5,
            'purchase_price_inc_tax' => 52.5,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->createJournalMapping(502, 102);
        $this->createJournalLines(502, [
            [10, 100, 'debit'],
            [20, 10, 'debit'],
            [30, 110, 'credit'],
        ]);

        $result = $this->service->reconcileImport(50, 1);

        $this->assertSame(1, $result['reconciled']);
        $line = DB::table('purchase_lines')->where('id', 2)->first();
        $this->assertSame(2, (int) $line->tax_id);
        $this->assertSame(5.0, (float) $line->item_tax);
        $this->assertSame(55.0, (float) $line->purchase_price_inc_tax);

        $this->assertSame(1, $this->service->revertImport(50, 1));
        $restored = DB::table('purchase_lines')->where('id', 2)->first();
        $this->assertSame(1, (int) $restored->tax_id);
        $this->assertSame(2.5, (float) $restored->item_tax);
        $this->assertSame(52.5, (float) $restored->purchase_price_inc_tax);
        $this->assertSame(105.0, (float) Transaction::find(102)->final_total);
    }

    public function test_multiple_tax_rates_for_one_journal_entry_are_flagged_without_changing_source(): void
    {
        $this->createTransaction(103, 'sell', 100, 5, 105);
        $this->createJournalMapping(503, 103);
        DB::table('accounting_journal_tax_account_mappings')->insert([
            'business_id' => 1,
            'accounting_account_id' => 30,
            'tax_rate_id' => 1,
        ]);
        $this->createJournalLines(503, [
            [10, 115, 'debit'],
            [20, 10, 'credit'],
            [30, 5, 'credit'],
            [40, 100, 'credit'],
        ]);

        $result = $this->service->reconcileImport(50, 1);

        $this->assertSame(['reconciled' => 0, 'review' => 1, 'not_applicable' => 0], $result);
        $source = Transaction::find(103);
        $mapping = DB::table('accounting_acc_trans_mappings')->where('id', 503)->first();
        $this->assertSame(1, (int) $source->tax_id);
        $this->assertSame(5.0, (float) $source->tax_amount);
        $this->assertSame(105.0, (float) $source->final_total);
        $this->assertSame('review', $mapping->tax_reconciliation_status);
        $this->assertStringContainsString('lebih dari satu TaxRate/grup', $mapping->tax_reconciliation_note);
    }

    public function test_sales_with_multiple_invoice_items_reconciles_each_item_from_journal_group_tax(): void
    {
        DB::table('tax_rates')->insert([
            ['id' => 3, 'business_id' => 1, 'name' => 'Combined VAT', 'amount' => 0, 'is_tax_group' => true],
            ['id' => 4, 'business_id' => 1, 'name' => 'VAT A', 'amount' => 4, 'is_tax_group' => false],
            ['id' => 5, 'business_id' => 1, 'name' => 'VAT B', 'amount' => 6, 'is_tax_group' => false],
        ]);
        DB::table('group_sub_taxes')->insert([
            ['group_tax_id' => 3, 'tax_id' => 4],
            ['group_tax_id' => 3, 'tax_id' => 5],
        ]);
        DB::table('accounting_journal_tax_account_mappings')
            ->where('business_id', 1)
            ->where('accounting_account_id', 20)
            ->update(['tax_rate_id' => 3]);

        $this->createTransaction(104, 'sell', 200, 5, 205);
        DB::table('transaction_sell_lines')->insert([
            [
                'id' => 4, 'transaction_id' => 104, 'quantity' => 1, 'unit_price' => 50,
                'tax_id' => 1, 'item_tax' => 2.5, 'unit_price_inc_tax' => 52.5,
                'created_at' => now(), 'updated_at' => now(),
            ],
            [
                'id' => 5, 'transaction_id' => 104, 'quantity' => 2, 'unit_price' => 75,
                'tax_id' => 1, 'item_tax' => 3.75, 'unit_price_inc_tax' => 78.75,
                'created_at' => now(), 'updated_at' => now(),
            ],
        ]);
        $this->createJournalMapping(504, 104);
        $this->createJournalLines(504, [
            [10, 220, 'debit'],
            [20, 20, 'credit'],
            [40, 200, 'credit'],
        ]);

        $result = $this->service->reconcileImport(50, 1);

        $this->assertSame(['reconciled' => 1, 'review' => 0, 'not_applicable' => 0], $result);
        $source = Transaction::find(104);
        $lines = DB::table('transaction_sell_lines')->where('transaction_id', 104)->orderBy('id')->get();
        $this->assertSame(200.0, (float) $source->total_before_tax);
        $this->assertSame(20.0, (float) $source->tax_amount);
        $this->assertSame(220.0, (float) $source->final_total);
        $this->assertSame([3, 3], $lines->pluck('tax_id')->map(fn ($id) => (int) $id)->all());
        $this->assertSame([5.0, 7.5], $lines->pluck('item_tax')->map(fn ($amount) => (float) $amount)->all());
        $this->assertSame([55.0, 82.5], $lines->pluck('unit_price_inc_tax')->map(fn ($amount) => (float) $amount)->all());
    }

    private function createSchema(): void
    {
        $schema = $this->database->schema();
        $schema->create('transactions', function ($table) {
            $table->increments('id');
            $table->unsignedInteger('business_id');
            $table->string('type');
            $table->string('sub_type')->nullable();
            $table->unsignedInteger('tax_id')->nullable();
            $table->decimal('tax_amount', 22, 4)->nullable();
            $table->decimal('total_before_tax', 22, 4)->nullable();
            $table->decimal('final_total', 22, 4)->nullable();
            $table->timestamps();
        });
        $schema->create('tax_rates', function ($table) {
            $table->increments('id');
            $table->unsignedInteger('business_id');
            $table->string('name');
            $table->decimal('amount', 10, 4);
            $table->boolean('is_tax_group')->default(false);
            $table->timestamp('deleted_at')->nullable();
            $table->timestamps();
        });
        $schema->create('group_sub_taxes', function ($table) {
            $table->unsignedInteger('group_tax_id');
            $table->unsignedInteger('tax_id');
        });
        $schema->create('accounting_acc_trans_mappings', function ($table) {
            $table->increments('id');
            $table->unsignedInteger('journal_import_id');
            $table->unsignedInteger('business_id');
            $table->string('source_link_status');
            $table->string('source_record_type');
            $table->unsignedInteger('source_record_id');
            $table->string('tax_reconciliation_status')->nullable();
            $table->text('tax_reconciliation_note')->nullable();
            $table->decimal('journal_tax_amount', 22, 4)->nullable();
            $table->decimal('calculated_tax_amount', 22, 4)->nullable();
            $table->decimal('journal_total_amount', 22, 4)->nullable();
            $table->decimal('recalculated_final_total', 22, 4)->nullable();
            $table->text('source_tax_snapshot')->nullable();
            $table->timestamps();
        });
        $schema->create('accounting_accounts_transactions', function ($table) {
            $table->increments('id');
            $table->unsignedInteger('acc_trans_mapping_id');
            $table->string('sub_type');
            $table->unsignedInteger('accounting_account_id');
            $table->decimal('amount', 22, 4);
            $table->string('type');
        });
        $schema->create('accounting_journal_tax_account_mappings', function ($table) {
            $table->increments('id');
            $table->unsignedInteger('business_id');
            $table->unsignedInteger('accounting_account_id');
            $table->unsignedInteger('tax_rate_id');
        });
        $schema->create('transaction_sell_lines', function ($table) {
            $table->increments('id');
            $table->unsignedInteger('transaction_id');
            $table->decimal('quantity', 22, 4);
            $table->decimal('unit_price', 22, 4)->nullable();
            $table->unsignedInteger('tax_id')->nullable();
            $table->decimal('item_tax', 22, 4)->nullable();
            $table->decimal('unit_price_inc_tax', 22, 4)->nullable();
            $table->timestamps();
        });
        $schema->create('purchase_lines', function ($table) {
            $table->increments('id');
            $table->unsignedInteger('transaction_id');
            $table->decimal('quantity', 22, 4);
            $table->decimal('purchase_price', 22, 4)->nullable();
            $table->unsignedInteger('tax_id')->nullable();
            $table->decimal('item_tax', 22, 4)->nullable();
            $table->decimal('purchase_price_inc_tax', 22, 4)->nullable();
            $table->timestamps();
        });
    }

    private function createTaxRatesAndMapping(): void
    {
        DB::table('tax_rates')->insert([
            ['id' => 1, 'business_id' => 1, 'name' => 'Old VAT', 'amount' => 5, 'is_tax_group' => false],
            ['id' => 2, 'business_id' => 1, 'name' => 'VAT', 'amount' => 10, 'is_tax_group' => false],
        ]);
        DB::table('accounting_journal_tax_account_mappings')->insert([
            'business_id' => 1,
            'accounting_account_id' => 20,
            'tax_rate_id' => 2,
        ]);
    }

    private function createTransaction(int $id, string $type, float $basis, float $tax, float $total): void
    {
        DB::table('transactions')->insert([
            'id' => $id,
            'business_id' => 1,
            'type' => $type,
            'sub_type' => null,
            'tax_id' => 1,
            'tax_amount' => $tax,
            'total_before_tax' => $basis,
            'final_total' => $total,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createJournalMapping(int $id, int $sourceId): void
    {
        DB::table('accounting_acc_trans_mappings')->insert([
            'id' => $id,
            'journal_import_id' => 50,
            'business_id' => 1,
            'source_link_status' => 'matched',
            'source_record_type' => Transaction::class,
            'source_record_id' => $sourceId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createJournalLines(int $mappingId, array $lines): void
    {
        foreach ($lines as [$accountId, $amount, $type]) {
            DB::table('accounting_accounts_transactions')->insert([
                'acc_trans_mapping_id' => $mappingId,
                'sub_type' => 'journal_entry',
                'accounting_account_id' => $accountId,
                'amount' => $amount,
                'type' => $type,
            ]);
        }
    }
}
