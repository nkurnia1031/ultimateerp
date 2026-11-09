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

    public function test_resumed_reconciliation_reports_cumulative_progress_and_total(): void
    {
        $this->createTransaction(100, 'expense', 100, 5, 105);
        $this->createJournalMapping(500, 100);
        $this->createJournalLines(500, [
            [10, 100, 'debit'],
            [20, 10, 'debit'],
            [40, 110, 'credit'],
        ]);

        $progress = null;
        $result = $this->service->reconcileImport(
            50,
            1,
            true,
            function (int $processed, array $counts, ?int $cursor, int $total) use (&$progress): void {
                $progress = compact('processed', 'counts', 'cursor', 'total');
            },
            500,
            ['reconciled' => 1]
        );

        $this->assertSame(['reconciled' => 1, 'review' => 0, 'not_applicable' => 0], $result);
        $this->assertSame(1, $progress['processed']);
        $this->assertSame(1, $progress['total']);
        $this->assertSame(500, $progress['cursor']);
        $this->assertSame($result, $progress['counts']);
    }

    public function test_forced_reconciliation_refreshes_tax_but_preserves_original_revert_snapshot(): void
    {
        $this->createTransaction(101, 'expense', 100, 5, 105);
        $this->createJournalMapping(501, 101);
        $this->createJournalLines(501, [
            [10, 100, 'debit'],
            [20, 10, 'debit'],
            [40, 110, 'credit'],
        ]);

        $this->service->reconcileImport(50, 1);

        DB::table('transactions')->where('id', 101)->update([
            'tax_amount' => 1,
            'final_total' => 101,
        ]);

        $result = $this->service->reconcileImport(50, 1, true);
        $source = Transaction::find(101);
        $mapping = DB::table('accounting_acc_trans_mappings')->where('id', 501)->first();
        $snapshot = json_decode((string) $mapping->source_tax_snapshot, true);

        $this->assertSame(1, $result['reconciled']);
        $this->assertSame(10.0, (float) $source->tax_amount);
        $this->assertSame(110.0, (float) $source->final_total);
        $this->assertSame(5.0, (float) $snapshot['transaction_before']['tax_amount']);
        $this->assertSame(105.0, (float) $snapshot['transaction_before']['final_total']);
        $this->assertSame(10.0, (float) $snapshot['transaction_after']['tax_amount']);
        $this->assertSame(110.0, (float) $snapshot['transaction_after']['final_total']);

        $this->assertSame(1, $this->service->revertImport(50, 1));
        $restored = Transaction::find(101);
        $this->assertSame(5.0, (float) $restored->tax_amount);
        $this->assertSame(105.0, (float) $restored->final_total);
    }

    public function test_linked_expense_fills_missing_operational_tax_from_journal_and_can_be_reverted(): void
    {
        $this->createTransaction(106, 'expense', 100, 0, 100);
        DB::table('transactions')->where('id', 106)->update(['tax_id' => null]);
        DB::table('expense_lines')->insert(['transaction_id' => 106, 'amount' => 100]);
        $this->createJournalMapping(506, 106);
        $this->createJournalLines(506, [
            [10, 100, 'debit'],
            [20, 10, 'debit'],
            [40, 110, 'credit'],
        ]);
        $this->createSourceAction(506, 'link');

        $result = $this->service->reconcileImport(50, 1);

        $this->assertSame(['reconciled' => 1, 'review' => 0, 'not_applicable' => 0], $result);
        $source = Transaction::find(106);
        $this->assertSame(2, (int) $source->tax_id);
        $this->assertSame(10.0, (float) $source->tax_amount);
        $this->assertSame(100.0, (float) $source->total_before_tax);
        $this->assertSame(110.0, (float) $source->final_total);

        $this->assertSame(1, $this->service->revertImport(50, 1));
        $restored = Transaction::find(106);
        $this->assertNull($restored->tax_id);
        $this->assertSame(0.0, (float) $restored->tax_amount);
        $this->assertSame(100.0, (float) $restored->final_total);
    }

    public function test_linked_invoice_fills_missing_item_and_header_tax_from_journal(): void
    {
        DB::table('tax_rates')->insert([
            'id' => 3,
            'business_id' => 1,
            'name' => 'PPN 11',
            'amount' => 11,
            'is_tax_group' => false,
        ]);
        $this->createTransaction(107, 'purchase', 111, 0, 111);
        DB::table('transactions')->where('id', 107)->update(['tax_id' => null]);
        DB::table('purchase_lines')->insert([
            'id' => 7,
            'transaction_id' => 107,
            'quantity' => 2,
            'purchase_price' => 50,
            'tax_id' => null,
            'item_tax' => 0,
            'purchase_price_inc_tax' => 50,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->createJournalMapping(507, 107);
        $this->createJournalLines(507, [
            [10, 100, 'debit'],
            [20, 11, 'debit'],
            [40, 111, 'credit'],
        ]);
        $this->createSourceAction(507, 'link');

        $result = $this->service->reconcileImport(50, 1);

        $this->assertSame(['reconciled' => 1, 'review' => 0, 'not_applicable' => 0], $result);
        $source = Transaction::find(107);
        $line = DB::table('purchase_lines')->where('id', 7)->first();
        $this->assertSame(3, (int) $source->tax_id);
        $this->assertSame(11.0, (float) $source->tax_amount);
        $this->assertSame(100.0, (float) $source->total_before_tax);
        $this->assertSame(111.0, (float) $source->final_total);
        $this->assertSame(3, (int) $line->tax_id);
        $this->assertSame(5.5, (float) $line->item_tax);
        $this->assertSame(55.5, (float) $line->purchase_price_inc_tax);
    }

    public function test_linked_sales_invoice_uses_net_line_base_when_header_total_contains_missing_tax(): void
    {
        $this->createTransaction(109, 'sell', 110, 0, 110);
        DB::table('transactions')->where('id', 109)->update(['tax_id' => null]);
        DB::table('transaction_sell_lines')->insert([
            'id' => 9,
            'transaction_id' => 109,
            'quantity' => 2,
            'unit_price' => 50,
            'tax_id' => null,
            'item_tax' => 0,
            'unit_price_inc_tax' => 50,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->createJournalMapping(509, 109);
        $this->createJournalLines(509, [
            [40, 110, 'debit'],
            [21, 10, 'credit'],
            [30, 100, 'credit'],
        ]);
        $this->createSourceAction(509, 'link');

        $result = $this->service->reconcileImport(50, 1);

        $this->assertSame(['reconciled' => 1, 'review' => 0, 'not_applicable' => 0], $result);
        $source = Transaction::find(109);
        $line = DB::table('transaction_sell_lines')->where('id', 9)->first();
        $this->assertSame(100.0, (float) $source->total_before_tax);
        $this->assertSame(10.0, (float) $source->tax_amount);
        $this->assertSame(110.0, (float) $source->final_total);
        $this->assertSame(2, (int) $line->tax_id);
        $this->assertSame(5.0, (float) $line->item_tax);
        $this->assertSame(55.0, (float) $line->unit_price_inc_tax);
    }

    public function test_linked_expense_with_conflicting_existing_tax_is_left_for_review(): void
    {
        $this->createTransaction(108, 'expense', 100, 5, 105);
        DB::table('expense_lines')->insert(['transaction_id' => 108, 'amount' => 100]);
        $this->createJournalMapping(508, 108);
        $this->createJournalLines(508, [
            [10, 100, 'debit'],
            [20, 10, 'debit'],
            [40, 110, 'credit'],
        ]);
        $this->createSourceAction(508, 'link');

        $result = $this->service->reconcileImport(50, 1);

        $this->assertSame(['reconciled' => 0, 'review' => 1, 'not_applicable' => 0], $result);
        $source = Transaction::find(108);
        $mapping = DB::table('accounting_acc_trans_mappings')->where('id', 508)->first();
        $this->assertSame(1, (int) $source->tax_id);
        $this->assertSame(5.0, (float) $source->tax_amount);
        $this->assertSame(105.0, (float) $source->final_total);
        $this->assertStringContainsString('berbeda', $mapping->tax_reconciliation_note);
        $this->assertNull($mapping->source_tax_snapshot);
    }

    public function test_expense_rate_is_computed_from_source_basis_without_a_gl_tax_rate_mapping(): void
    {
        $this->createTransaction(105, 'expense', 27272727, 0, 27272727);
        DB::table('expense_lines')->insert([
            'transaction_id' => 105,
            'amount' => 27272727,
        ]);
        $this->createJournalMapping(505, 105);
        $this->createJournalLines(505, [
            [10, 27272727, 'debit'],
            [20, 2727272.7, 'debit'],
            [40, 29999999.7, 'credit'],
        ]);

        $result = $this->service->reconcileImport(50, 1);

        $this->assertSame(['reconciled' => 1, 'review' => 0, 'not_applicable' => 0], $result);
        $source = Transaction::find(105);
        $this->assertSame(2, (int) $source->tax_id);
        $this->assertSame(2727272.7, (float) $source->tax_amount);
        $this->assertSame(29999999.7, (float) $source->final_total);
    }

    public function test_reconciliation_returns_bounded_id_ordered_chunks(): void
    {
        $this->createJournalMapping(510, 999);
        $this->createJournalMapping(511, 998);

        $first = $this->service->reconcileImportChunk(50, 1, 0, 1);
        $second = $this->service->reconcileImportChunk(50, 1, $first['last_id'], 1);

        $this->assertSame(510, $first['last_id']);
        $this->assertTrue($first['has_more']);
        $this->assertSame(['reconciled' => 0, 'review' => 1, 'not_applicable' => 0], $first['result']);
        $this->assertSame(511, $second['last_id']);
        $this->assertFalse($second['has_more']);
        $this->assertSame(['reconciled' => 0, 'review' => 1, 'not_applicable' => 0], $second['result']);
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
            [21, 10, 'credit'],
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

    public function test_unmatched_transaction_derived_rate_is_flagged_without_changing_source(): void
    {
        $this->createTransaction(103, 'sell', 100, 5, 105);
        $this->createJournalMapping(503, 103);
        $this->createJournalLines(503, [
            [10, 115, 'debit'],
            [21, 10, 'credit'],
            [50, 5, 'credit'],
            [30, 100, 'credit'],
        ]);

        $result = $this->service->reconcileImport(50, 1);

        $this->assertSame(['reconciled' => 0, 'review' => 1, 'not_applicable' => 0], $result);
        $source = Transaction::find(103);
        $mapping = DB::table('accounting_acc_trans_mappings')->where('id', 503)->first();
        $this->assertSame(1, (int) $source->tax_id);
        $this->assertSame(5.0, (float) $source->tax_amount);
        $this->assertSame(105.0, (float) $source->final_total);
        $this->assertSame('review', $mapping->tax_reconciliation_status);
        $this->assertStringContainsString('tidak cocok dengan TaxRate aktif', $mapping->tax_reconciliation_note);
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
            [21, 8, 'credit'],
            [22, 12, 'credit'],
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

    public function test_legacy_authoritative_mode_replaces_existing_operational_vat_with_journal_vat(): void
    {
        DB::table('tax_rates')->insert([
            'id' => 3,
            'business_id' => 1,
            'name' => 'PPN 11',
            'amount' => 11,
            'is_tax_group' => false,
        ]);
        DB::table('accounting_journal_imports')->where('id', 50)->update([
            'import_options' => json_encode(['legacy_authoritative' => true]),
        ]);
        $this->createTransaction(112, 'sell', 100, 5, 105);
        DB::table('transaction_sell_lines')->insert([
            'id' => 12,
            'transaction_id' => 112,
            'quantity' => 2,
            'unit_price' => 50,
            'tax_id' => 1,
            'item_tax' => 2.5,
            'unit_price_inc_tax' => 52.5,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->createJournalMapping(512, 112);
        $this->createJournalLines(512, [
            [40, 111, 'debit'],
            [21, 11, 'credit'],
            [30, 100, 'credit'],
        ]);
        $this->createSourceAction(512, 'link');

        $result = $this->service->reconcileImport(50, 1);
        $source = Transaction::find(112);
        $line = DB::table('transaction_sell_lines')->where('id', 12)->first();

        $this->assertSame(['reconciled' => 1, 'review' => 0, 'not_applicable' => 0], $result);
        $this->assertSame(3, (int) $source->tax_id);
        $this->assertSame(11.0, (float) $source->tax_amount);
        $this->assertSame(111.0, (float) $source->final_total);
        $this->assertSame(3, (int) $line->tax_id);
        $this->assertSame(5.5, (float) $line->item_tax);
        $this->assertSame(55.5, (float) $line->unit_price_inc_tax);
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
        $schema->create('accounting_journal_imports', function ($table) {
            $table->increments('id');
            $table->unsignedInteger('business_id');
            $table->longText('import_options')->nullable();
        });
        DB::table('accounting_journal_imports')->insert([
            'id' => 50,
            'business_id' => 1,
            'import_options' => '{}',
        ]);
        $schema->create('accounting_accounts_transactions', function ($table) {
            $table->increments('id');
            $table->unsignedInteger('acc_trans_mapping_id');
            $table->string('sub_type');
            $table->unsignedInteger('accounting_account_id');
            $table->decimal('amount', 22, 4);
            $table->string('type');
        });
        $schema->create('accounting_accounts', function ($table) {
            $table->increments('id');
            $table->unsignedInteger('business_id');
            $table->string('gl_code')->nullable();
            $table->string('name');
        });
        $schema->create('expense_lines', function ($table) {
            $table->increments('id');
            $table->unsignedInteger('transaction_id');
            $table->decimal('amount', 22, 4);
        });
        $schema->create('accounting_journal_import_rows', function ($table) {
            $table->increments('id');
            $table->unsignedInteger('journal_import_id');
            $table->unsignedInteger('business_id');
            $table->unsignedInteger('journal_mapping_id')->nullable();
            $table->string('source_action')->nullable();
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
        DB::table('accounting_accounts')->insert([
            ['id' => 10, 'business_id' => 1, 'gl_code' => '5100', 'name' => 'Cost of Sales'],
            ['id' => 20, 'business_id' => 1, 'gl_code' => '10500', 'name' => 'VAT In'],
            ['id' => 21, 'business_id' => 1, 'gl_code' => '20500', 'name' => 'VAT Out'],
            ['id' => 22, 'business_id' => 1, 'gl_code' => '20501', 'name' => 'VAT Out Additional'],
            ['id' => 30, 'business_id' => 1, 'gl_code' => '4000', 'name' => 'Sales Revenue'],
            ['id' => 40, 'business_id' => 1, 'gl_code' => '1000', 'name' => 'Cash'],
            ['id' => 50, 'business_id' => 1, 'gl_code' => '20510', 'name' => 'PPh 23'],
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

    private function createSourceAction(int $mappingId, string $action): void
    {
        DB::table('accounting_journal_import_rows')->insert([
            'journal_import_id' => 50,
            'business_id' => 1,
            'journal_mapping_id' => $mappingId,
            'source_action' => $action,
        ]);
    }
}
