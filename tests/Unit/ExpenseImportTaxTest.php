<?php

namespace Tests\Unit;

use App\Services\ExpenseImportService;
use App\Transaction;
use App\Utils\TransactionUtil;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Support\DateFactory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class ExpenseImportTaxTest extends TestCase
{
    private Capsule $database;

    private ExpenseImportService $service;

    private ReflectionMethod $validateRow;

    private ReflectionMethod $validateGroupTaxes;

    private ReflectionMethod $importGroup;

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
        $this->createMasterData();
        $this->service = new ExpenseImportService(new TransactionUtil());
        $this->validateRow = new ReflectionMethod(ExpenseImportService::class, 'validateRow');
        $this->validateRow->setAccessible(true);
        $this->validateGroupTaxes = new ReflectionMethod(ExpenseImportService::class, 'validateGroupTaxes');
        $this->validateGroupTaxes->setAccessible(true);
        $this->importGroup = new ReflectionMethod(ExpenseImportService::class, 'importGroup');
        $this->importGroup->setAccessible(true);
    }

    protected function tearDown(): void
    {
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);
        EloquentModel::unsetConnectionResolver();
        parent::tearDown();
    }

    public function test_known_tax_is_matched_and_unknown_tax_is_flagged_for_preview_mapping(): void
    {
        $known = $this->validate('VAT');
        $unknown = $this->validate('Special Tax');
        $mapped = $this->validate('Special Tax', 'REF-MAPPED', 110, 'Mapped expense', ['tax_name:special tax' => 3]);

        $this->assertSame('matched', $known['normalized_payload']['tax_status']);
        $this->assertSame(2, (int) $known['normalized_payload']['tax_id']);
        $this->assertTrue($known['is_importable'], implode(' | ', $known['errors']));
        $this->assertSame('unknown', $unknown['normalized_payload']['tax_status']);
        $this->assertFalse($unknown['is_importable']);
        $this->assertStringContainsString('Pilih TaxRate yang tersedia dari preview', implode(' ', $unknown['errors']));
        $this->assertSame('mapped', $mapped['normalized_payload']['tax_status']);
        $this->assertSame(3, (int) $mapped['normalized_payload']['tax_id']);
        $this->assertTrue($mapped['is_importable']);
        $this->assertSame(2, DB::table('tax_rates')->count(), 'Preview mapping must not create TaxRate rows.');
    }

    public function test_reference_group_inherits_one_known_tax_but_rejects_conflicting_rates(): void
    {
        $known = $this->validate('VAT', 'REF-INHERIT');
        $blank = $this->validate('', 'REF-INHERIT');
        $inherited = $this->validateGroupTaxes->invoke($this->service, [$known, $blank]);

        $this->assertSame(2, (int) $inherited[1]['normalized_payload']['tax_id']);
        $this->assertSame('inherited', $inherited[1]['normalized_payload']['tax_status']);
        $this->assertTrue($inherited[0]['is_importable'], implode(' | ', $inherited[0]['errors']));
        $this->assertTrue($inherited[1]['is_importable'], implode(' | ', $inherited[1]['errors']));

        $conflict = $this->validateGroupTaxes->invoke($this->service, [
            $this->validate('VAT', 'REF-CONFLICT'),
            $this->validate('Reduced VAT', 'REF-CONFLICT'),
        ]);

        $this->assertFalse($conflict[0]['is_importable']);
        $this->assertFalse($conflict[1]['is_importable']);
        $this->assertStringContainsString('satu TaxRate atau grup pajak', implode(' ', $conflict[0]['errors']));
    }

    public function test_import_group_preserves_inclusive_total_and_calculates_tax_from_existing_rate(): void
    {
        $rows = collect([
            $this->previewRow($this->validate('VAT', 'REF-110', 110, 'Biaya A')),
            $this->previewRow($this->validate('VAT', 'REF-110', 110, 'Biaya B')),
        ]);

        $this->importGroup->invoke($this->service, $rows, 1, 9, 'batch-1', 1);

        $transaction = Transaction::where('business_id', 1)->where('type', 'expense')->first();
        $this->assertNotNull($transaction);
        $this->assertSame(2, (int) $transaction->tax_id);
        $this->assertSame(200.0, (float) $transaction->total_before_tax);
        $this->assertSame(20.0, (float) $transaction->tax_amount);
        $this->assertSame(220.0, (float) $transaction->final_total);
        $this->assertSame(2, DB::table('expense_lines')->where('transaction_id', $transaction->id)->count());
    }

    private function validate(string $taxName, string $reference = 'REF-1', float $total = 110, string $description = 'Test expense', array $overrides = []): array
    {
        return $this->validateRow->invoke($this->service, [
            'LOCATION' => 'Main Location',
            'CATEGORY' => 'Office',
            'SUB-CATEGORY' => '',
            'DESCRIPTION' => $description,
            'REFERENCE NO' => $reference,
            'TAGS' => '',
            'EXPENSE DATE' => '2026-10-21',
            'EXPENSE FOR' => '',
            'EXPENSE FOR CONTACT ID' => '',
            'ATTACH DOCUMENT' => '',
            'APPLICABLE TAX' => $taxName,
            'EXPENSE NOTE' => '',
            'TOTAL AMOUNT' => $total,
            'PAID AMOUNT' => 0,
            'PAID ON DATE' => '',
            'PAYMENT METHOD' => '',
            'PAYMENT ACCOUNT' => '',
            'PAYMENT NOTE' => '',
        ], 2, $this->validationContext(), $overrides);
    }

    private function validationContext(): array
    {
        $location = (object) ['id' => 1, 'name' => 'Main Location'];
        $category = (object) ['id' => 1, 'name' => 'Office', 'parent_id' => null];
        $taxes = collect([
            (object) ['id' => 2, 'name' => 'VAT'],
            (object) ['id' => 3, 'name' => 'Reduced VAT'],
        ]);

        return [
            'locations' => collect([$location])->keyBy(fn ($item) => strtolower($item->name)),
            'categories' => collect([$category]),
            'categoryByName' => collect([$category])->groupBy(fn ($item) => strtolower($item->name)),
            'contacts' => collect(),
            'users' => collect(),
            'taxesByName' => $taxes->keyBy(fn ($tax) => strtolower($tax->name)),
            'taxesById' => $taxes->keyBy('id'),
            'paymentTypes' => [],
        ];
    }

    private function previewRow(array $validated): object
    {
        return (object) [
            'normalized_payload' => json_encode($validated['normalized_payload'], JSON_UNESCAPED_UNICODE),
        ];
    }

    private function createSchema(): void
    {
        $schema = $this->database->schema();
        $schema->create('business_locations', function ($table) {
            $table->increments('id');
            $table->unsignedInteger('business_id');
            $table->string('name');
        });
        $schema->create('expense_categories', function ($table) {
            $table->increments('id');
            $table->unsignedInteger('business_id');
            $table->string('name');
            $table->unsignedInteger('parent_id')->nullable();
            $table->timestamp('deleted_at')->nullable();
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
        $schema->create('transactions', function ($table) {
            $table->increments('id');
            $table->unsignedInteger('business_id');
            $table->unsignedInteger('location_id')->nullable();
            $table->unsignedInteger('created_by')->nullable();
            $table->unsignedInteger('expense_category_id')->nullable();
            $table->unsignedInteger('expense_sub_category_id')->nullable();
            $table->unsignedInteger('expense_for')->nullable();
            $table->unsignedInteger('contact_id')->nullable();
            $table->string('ref_no')->nullable();
            $table->dateTime('transaction_date');
            $table->text('additional_notes')->nullable();
            $table->string('document')->nullable();
            $table->unsignedInteger('tax_id')->nullable();
            $table->decimal('tax_amount', 22, 4)->nullable();
            $table->decimal('total_before_tax', 22, 4)->nullable();
            $table->decimal('final_total', 22, 4)->nullable();
            $table->string('status')->nullable();
            $table->string('payment_status')->nullable();
            $table->string('type')->nullable();
            $table->unsignedInteger('import_batch')->nullable();
            $table->dateTime('import_time')->nullable();
            $table->string('import_file')->nullable();
            $table->timestamps();
        });
        $schema->create('expense_lines', function ($table) {
            $table->increments('id');
            $table->unsignedInteger('transaction_id');
            $table->unsignedInteger('category_id')->nullable();
            $table->unsignedInteger('sub_category_id')->nullable();
            $table->text('description')->nullable();
            $table->decimal('amount', 22, 4);
            $table->timestamps();
        });
        $schema->create('expense_import_batches', function ($table) {
            $table->string('id')->primary();
            $table->string('file_name')->nullable();
        });
    }

    private function createMasterData(): void
    {
        DB::table('business_locations')->insert(['id' => 1, 'business_id' => 1, 'name' => 'Main Location']);
        DB::table('expense_categories')->insert(['id' => 1, 'business_id' => 1, 'name' => 'Office', 'parent_id' => null]);
        DB::table('tax_rates')->insert([
            ['id' => 2, 'business_id' => 1, 'name' => 'VAT', 'amount' => 10, 'is_tax_group' => false],
            ['id' => 3, 'business_id' => 1, 'name' => 'Reduced VAT', 'amount' => 5, 'is_tax_group' => false],
        ]);
        DB::table('expense_import_batches')->insert(['id' => 'batch-1', 'file_name' => 'unit-test.xlsx']);
    }
}
