<?php

namespace Tests\Unit;

use App\Transaction;
use App\Unit;
use App\Utils\ProductUtil;
use App\Utils\TransactionUtil;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\DateFactory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Modules\Accounting\Services\JournalImportDraftService;
use Modules\Accounting\Services\JournalImportStockService;
use PHPUnit\Framework\TestCase;
use Illuminate\Validation\Factory as ValidatorFactory;
use Illuminate\Validation\ValidationException;

class JournalImportStockServiceTest extends TestCase
{
    private ?Capsule $database = null;

    private ?Container $previousContainer = null;

    private $previousConnectionResolver = null;

    protected function tearDown(): void
    {
        \Mockery::close();
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

    public function test_stock_in_creates_opening_stock_with_selected_unit_and_retry_does_not_duplicate(): void
    {
        $this->useSqliteDatabase();
        $this->createTables();
        $this->seedMasters();
        $productUtil = \Mockery::mock(ProductUtil::class);
        $productUtil->shouldReceive('updateProductQuantity')->once()->with(10, 20, 30, 36.0, 0, null, false);
        $productUtil->shouldReceive('adjustStockOverSelling')->once();
        $transactionUtil = \Mockery::mock(TransactionUtil::class);
        $row = $this->makeRow(1, 'Stock In/Out', 'create', [
            'location_id' => 10,
            'movement_direction' => 'in',
            'details' => [['variation_id' => 30, 'unit_id' => 2, 'quantity' => 3, 'unit_price' => 10]],
        ]);
        $service = $this->service($productUtil, $transactionUtil);
        $import = (object) ['id' => 80, 'business_id' => 2, 'created_by' => 5];

        $transaction = $service->process($row, $this->entry('Stock In/Out', 'ST-IN-1'), $import);
        $retry = $service->process($row, $this->entry('Stock In/Out', 'ST-IN-1'), $import);
        $line = $transaction->purchase_lines()->first();

        $this->assertSame((int) $transaction->id, (int) $retry->id);
        $this->assertSame('opening_stock', $transaction->type);
        $this->assertSame(36.0, (float) $line->quantity);
        $this->assertSame(2, (int) $line->sub_unit_id);
        $this->assertSame(1, Transaction::where('business_id', 2)->count());
        $this->assertSame(1, $transaction->purchase_lines()->count());
    }

    public function test_stock_out_creates_stock_adjustment_without_posting_gl_from_stock_handler(): void
    {
        $this->useSqliteDatabase();
        $this->createTables();
        $this->seedMasters();
        $productUtil = \Mockery::mock(ProductUtil::class);
        $productUtil->shouldReceive('decreaseProductQuantity')->once()->with(20, 30, 10, 2.0);
        $transactionUtil = \Mockery::mock(TransactionUtil::class);
        $transactionUtil->shouldReceive('mapPurchaseSell')->once()->withArgs(function ($business, $lines, $type) {
            return $business['id'] === 2 && $business['location_id'] === 10 && $type === 'stock_adjustment' && $lines->count() === 1;
        });
        $row = $this->makeRow(2, 'Stock In/Out', 'create', [
            'location_id' => 10,
            'movement_direction' => 'out',
            'details' => [['variation_id' => 30, 'unit_id' => 1, 'quantity' => 2, 'unit_price' => 8]],
        ]);

        $transaction = $this->service($productUtil, $transactionUtil)->process(
            $row,
            $this->entry('Stock In/Out', 'ST-OUT-1'),
            (object) ['id' => 81, 'business_id' => 2, 'created_by' => 5]
        );

        $line = $transaction->stock_adjustment_lines()->first();
        $this->assertSame('stock_adjustment', $transaction->type);
        $this->assertSame(16.0, (float) $transaction->final_total);
        $this->assertSame(2.0, (float) $line->quantity);
        $this->assertSame(8.0, (float) $line->unit_price);
    }

    public function test_stock_details_require_unit_and_business_scoped_stock_product(): void
    {
        $this->useSqliteDatabase();
        $this->createTables();
        $this->seedMasters();
        $service = $this->service(\Mockery::mock(ProductUtil::class), \Mockery::mock(TransactionUtil::class));

        $missingUnit = $this->makeRow(3, 'Stock Adjustment', 'create', [
            'location_id' => 10,
            'details' => [['variation_id' => 30, 'quantity' => 1, 'unit_price' => 4]],
        ]);
        $this->assertStringContainsString('satuan', (string) $service->readinessIssue($missingUnit, $this->entry('Stock Adjustment', 'SA-1'), 2));

        $crossBusinessProduct = $this->makeRow(4, 'Opening Balance', 'create', [
            'location_id' => 10,
            'details' => [['variation_id' => 31, 'unit_id' => 1, 'quantity' => 1, 'unit_price' => 4]],
        ]);
        $this->assertStringContainsString('bukan milik business', (string) $service->readinessIssue($crossBusinessProduct, $this->entry('Opening Balance', 'OB-1'), 2));
    }

    public function test_revert_removes_created_opening_stock_and_restores_import_row_link(): void
    {
        $this->useSqliteDatabase();
        $this->createTables();
        $this->seedMasters();
        $productUtil = \Mockery::mock(ProductUtil::class);
        $productUtil->shouldReceive('updateProductQuantity')->once();
        $productUtil->shouldReceive('adjustStockOverSelling')->once();
        $productUtil->shouldReceive('decreaseProductQuantity')->once()->with(20, 30, 10, 2.0);
        $service = $this->service($productUtil, \Mockery::mock(TransactionUtil::class));
        $row = $this->makeRow(5, 'Opening Balance', 'create', [
            'location_id' => 10,
            'details' => [['variation_id' => 30, 'unit_id' => 1, 'quantity' => 2, 'unit_price' => 6]],
        ], null, 82);
        $import = (object) ['id' => 82, 'business_id' => 2, 'created_by' => 5];
        $transaction = $service->process($row, $this->entry('Opening Balance', 'OB-2'), $import);

        $this->assertSame(1, $service->revertImport(82, 2));
        $this->assertNull(Transaction::where('id', $transaction->id)->first());
        $this->assertSame(0, \App\PurchaseLine::where('transaction_id', $transaction->id)->count());
    }

    public function test_stock_draft_validation_accepts_only_product_base_or_configured_sub_units(): void
    {
        $this->useSqliteDatabase();
        $this->createTables();
        $this->seedMasters();
        $draftService = new JournalImportDraftService();

        $validated = $draftService->validateDraftData([
            'location_id' => 10,
            'movement_direction' => 'in',
            'details' => [['variation_id' => 30, 'unit_id' => 2, 'quantity' => 3, 'unit_price' => 10]],
        ], 2, 'Stock In/Out');
        $this->assertSame(2, (int) $validated['details'][0]['unit_id']);

        DB::table('units')->insert([
            'id' => 3,
            'business_id' => 2,
            'actual_name' => 'Unrelated',
            'short_name' => 'u',
        ]);
        try {
            $draftService->validateDraftData([
                'location_id' => 10,
                'details' => [['variation_id' => 30, 'unit_id' => 3, 'quantity' => 3, 'unit_price' => 10]],
            ], 2, 'Opening Balance');
            $this->fail('Satuan yang tidak terhubung ke produk harus ditolak.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('draft_data.details.0.unit_id', $exception->errors());
        }
    }

    private function service($productUtil, $transactionUtil): JournalImportStockService
    {
        return new JournalImportStockService(new JournalImportDraftService(), $productUtil, $transactionUtil);
    }

    private function makeRow(int $id, string $sourceType, string $action, array $draftData, ?int $sourceRecordId = null, int $importId = 80): object
    {
        DB::table('accounting_journal_import_rows')->insert([
            'id' => $id,
            'journal_import_id' => $importId,
            'business_id' => 2,
            'source_type' => $sourceType,
            'source_action' => $action,
            'source_record_type' => $sourceRecordId === null ? null : Transaction::class,
            'source_record_id' => $sourceRecordId,
            'draft_data' => json_encode($draftData),
            'entry_data' => json_encode(['date' => '2026-10-25', 'description' => 'Test stock row']),
            'source_mutation_snapshot' => null,
            'reconciliation_status' => 'matched',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return DB::table('accounting_journal_import_rows')->where('id', $id)->first();
    }

    private function entry(string $sourceType, string $sourceNumber): array
    {
        return [
            'source_type' => $sourceType,
            'source_number' => $sourceNumber,
            'source_link_status' => 'unmatched',
            'source_link_candidate_details' => [],
            'source_key' => $sourceType.'|'.$sourceNumber,
            'date' => '2026-10-25',
            'description' => 'Journal import test '.$sourceNumber,
            'debit_total' => 20,
            'credit_total' => 20,
            'is_unbalanced' => false,
            'is_duplicate' => false,
            'exists_in_system' => false,
            'lines' => [['debit' => 20, 'credit' => 0], ['debit' => 0, 'credit' => 20]],
        ];
    }

    private function createTables(): void
    {
        $schema = $this->database->schema();
        $schema->create('business', function ($table) {
            $table->increments('id');
            $table->string('accounting_method')->nullable();
        });
        $schema->create('business_locations', function ($table) {
            $table->increments('id');
            $table->unsignedInteger('business_id');
            $table->string('name')->nullable();
            $table->boolean('is_active')->default(true);
            $table->softDeletes();
        });
        $schema->create('products', function ($table) {
            $table->increments('id');
            $table->unsignedInteger('business_id');
            $table->unsignedInteger('unit_id')->nullable();
            $table->text('sub_unit_ids')->nullable();
            $table->boolean('enable_stock')->default(true);
            $table->boolean('is_inactive')->default(false);
            $table->softDeletes();
        });
        $schema->create('variations', function ($table) {
            $table->increments('id');
            $table->unsignedInteger('product_id');
            $table->softDeletes();
        });
        $schema->create('units', function ($table) {
            $table->increments('id');
            $table->unsignedInteger('business_id');
            $table->unsignedInteger('base_unit_id')->nullable();
            $table->decimal('base_unit_multiplier', 20, 4)->nullable();
            $table->string('actual_name')->nullable();
            $table->string('short_name')->nullable();
            $table->softDeletes();
        });
        $schema->create('transactions', function ($table) {
            $table->increments('id');
            $table->unsignedInteger('business_id');
            $table->unsignedInteger('created_by')->nullable();
            $table->string('type')->nullable();
            $table->dateTime('transaction_date')->nullable();
            $table->unsignedInteger('location_id')->nullable();
            $table->string('ref_no')->nullable();
            $table->string('invoice_no')->nullable();
            $table->text('additional_notes')->nullable();
            $table->string('status')->nullable();
            $table->string('payment_status')->nullable();
            $table->decimal('total_before_tax', 22, 4)->nullable();
            $table->decimal('final_total', 22, 4)->nullable();
            $table->unsignedInteger('opening_stock_product_id')->nullable();
            $table->string('adjustment_type')->nullable();
            $table->decimal('total_amount_recovered', 22, 4)->nullable();
            $table->softDeletes();
            $table->timestamps();
        });
        $schema->create('stock_adjustment_lines', function ($table) {
            $table->increments('id');
            $table->unsignedInteger('transaction_id');
            $table->unsignedInteger('product_id');
            $table->unsignedInteger('variation_id');
            $table->decimal('quantity', 22, 4);
            $table->decimal('unit_price', 22, 4)->nullable();
            $table->timestamps();
        });
        $schema->create('purchase_lines', function ($table) {
            $table->increments('id');
            $table->unsignedInteger('transaction_id')->nullable();
            $table->unsignedInteger('product_id');
            $table->unsignedInteger('variation_id');
            $table->decimal('quantity', 22, 4);
            $table->decimal('pp_without_discount', 22, 4)->nullable();
            $table->decimal('purchase_price', 22, 4)->nullable();
            $table->decimal('purchase_price_inc_tax', 22, 4)->nullable();
            $table->decimal('item_tax', 22, 4)->nullable();
            $table->unsignedInteger('tax_id')->nullable();
            $table->decimal('discount_percent', 22, 4)->nullable();
            $table->unsignedInteger('sub_unit_id')->nullable();
            $table->decimal('quantity_sold', 22, 4)->default(0);
            $table->decimal('quantity_adjusted', 22, 4)->default(0);
            $table->decimal('quantity_returned', 22, 4)->default(0);
            $table->decimal('mfg_quantity_used', 22, 4)->default(0);
            $table->timestamps();
        });
        $schema->create('accounting_journal_import_rows', function ($table) {
            $table->increments('id');
            $table->unsignedInteger('journal_import_id');
            $table->unsignedInteger('business_id');
            $table->string('source_type');
            $table->string('source_action');
            $table->string('source_record_type')->nullable();
            $table->unsignedInteger('source_record_id')->nullable();
            $table->longText('draft_data')->nullable();
            $table->longText('entry_data')->nullable();
            $table->longText('source_mutation_snapshot')->nullable();
            $table->string('reconciliation_status')->default('not_checked');
            $table->timestamps();
        });

        DB::table('business')->insert(['id' => 2, 'accounting_method' => 'fifo']);
        DB::table('business_locations')->insert(['id' => 10, 'business_id' => 2, 'name' => 'Gudang utama', 'is_active' => 1]);
    }

    private function seedMasters(): void
    {
        DB::table('units')->insert([
            ['id' => 1, 'business_id' => 2, 'base_unit_id' => null, 'base_unit_multiplier' => null, 'actual_name' => 'Piece', 'short_name' => 'pc'],
            ['id' => 2, 'business_id' => 2, 'base_unit_id' => 1, 'base_unit_multiplier' => 12, 'actual_name' => 'Box', 'short_name' => 'box'],
        ]);
        DB::table('products')->insert([
            ['id' => 20, 'business_id' => 2, 'unit_id' => 1, 'sub_unit_ids' => '[2]', 'enable_stock' => 1, 'is_inactive' => 0],
            ['id' => 21, 'business_id' => 3, 'unit_id' => 1, 'sub_unit_ids' => '[]', 'enable_stock' => 1, 'is_inactive' => 0],
        ]);
        DB::table('variations')->insert([
            ['id' => 30, 'product_id' => 20],
            ['id' => 31, 'product_id' => 21],
        ]);
    }

    private function useSqliteDatabase(): void
    {
        if (! in_array('sqlite', \PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('PDO SQLite tidak tersedia pada PHP runtime ini.');
        }

        Facade::clearResolvedInstances();
        $this->previousContainer = Container::getInstance();
        $container = new Container();
        Container::setInstance($container);
        $this->previousConnectionResolver = Model::getConnectionResolver();
        $this->database = new Capsule($container);
        $this->database->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $this->database->setAsGlobal();
        Model::setConnectionResolver($this->database->getDatabaseManager());
        $container->instance('db', $this->database->getDatabaseManager());
        $container->instance('date', new \Illuminate\Support\DateFactory());
        $container->instance('validator', new ValidatorFactory(new Translator(new ArrayLoader(), 'en'), $container));
        Facade::setFacadeApplication($container);
    }
}
