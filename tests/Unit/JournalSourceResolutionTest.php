<?php

namespace Tests\Unit;

use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Support\DateFactory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use Modules\Accounting\Http\Controllers\JournalEntryController;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

class JournalSourceResolutionTest extends TestCase
{
    private Capsule $database;

    private ReflectionMethod $resolver;

    private object $controller;

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
        $container->instance('db', $this->database->getDatabaseManager());
        $container->instance('date', new DateFactory());
        Facade::setFacadeApplication($container);

        $this->database->schema()->create('transactions', function ($table) {
            $table->increments('id');
            $table->unsignedInteger('business_id');
            $table->string('type');
            $table->string('invoice_no')->nullable();
            $table->string('ref_no')->nullable();
            $table->dateTime('transaction_date');
            $table->decimal('total_before_tax', 22, 4)->nullable();
            $table->decimal('tax_amount', 22, 4)->nullable();
            $table->decimal('final_total', 22, 4)->default(0);
            $table->unsignedInteger('contact_id')->nullable();
            $table->unsignedInteger('location_id')->nullable();
            $table->unsignedInteger('expense_category_id')->nullable();
        });
        $this->database->schema()->create('contacts', function ($table) {
            $table->increments('id');
            $table->unsignedInteger('business_id');
            $table->string('name')->nullable();
            $table->string('supplier_business_name')->nullable();
        });
        $this->database->schema()->create('business_locations', function ($table) {
            $table->increments('id');
            $table->unsignedInteger('business_id');
            $table->string('name')->nullable();
        });
        $this->database->schema()->create('expense_categories', function ($table) {
            $table->increments('id');
            $table->unsignedInteger('business_id');
            $table->string('name')->nullable();
        });
        $this->database->schema()->create('transaction_payments', function ($table) {
            $table->increments('id');
            $table->unsignedInteger('transaction_id');
            $table->string('payment_ref_no')->nullable();
            $table->dateTime('paid_on')->nullable();
            $table->decimal('amount', 22, 4)->default(0);
        });
        $this->database->schema()->create('accounts', function ($table) {
            $table->increments('id');
            $table->unsignedInteger('business_id');
        });
        $this->database->schema()->create('account_transactions', function ($table) {
            $table->increments('id');
            $table->unsignedInteger('account_id');
            $table->unsignedInteger('accounting_account_id')->nullable();
            $table->string('sub_type')->nullable();
            $table->string('reff_no')->nullable();
            $table->dateTime('operation_date')->nullable();
            $table->decimal('amount', 22, 4)->default(0);
            $table->dateTime('deleted_at')->nullable();
        });

        $this->controller = (new ReflectionClass(JournalEntryController::class))->newInstanceWithoutConstructor();
        $this->resolver = new ReflectionMethod(JournalEntryController::class, 'resolveJournalSourceLinks');
        $this->resolver->setAccessible(true);
    }

    protected function tearDown(): void
    {
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);
        parent::tearDown();
    }

    public function test_unique_explicit_reference_links_even_when_source_date_differs(): void
    {
        DB::table('transactions')->insert([
            'id' => 10,
            'business_id' => 1,
            'type' => 'sell',
            'invoice_no' => 'INV-100',
            'ref_no' => 'REF-100',
            'transaction_date' => '2025-01-03 00:00:00',
            'final_total' => 100,
        ]);

        $links = $this->resolve([self::entry('INV-100', '2025-02-03', 100)], 1);

        $this->assertSame('matched', $links[0]['source_link_status']);
        $this->assertSame(10, $links[0]['source_record_id']);
        $this->assertStringContainsString('tanggal sumber berbeda', $links[0]['source_link_note']);
    }

    public function test_date_or_amount_can_disambiguate_duplicate_references(): void
    {
        DB::table('transactions')->insert([
            ['id' => 20, 'business_id' => 1, 'type' => 'sell', 'invoice_no' => 'INV-DUP', 'ref_no' => null, 'transaction_date' => '2025-01-01 00:00:00', 'final_total' => 100],
            ['id' => 21, 'business_id' => 1, 'type' => 'sell', 'invoice_no' => 'INV-DUP', 'ref_no' => null, 'transaction_date' => '2025-02-01 00:00:00', 'final_total' => 200],
        ]);

        $links = $this->resolve([self::entry('INV-DUP', '2025-02-01', 200)], 1);

        $this->assertSame('matched', $links[0]['source_link_status']);
        $this->assertSame(21, $links[0]['source_record_id']);
    }

    public function test_duplicate_reference_with_no_unique_validation_remains_ambiguous(): void
    {
        DB::table('transactions')->insert([
            ['id' => 30, 'business_id' => 1, 'type' => 'sell', 'invoice_no' => 'INV-AMB', 'ref_no' => null, 'transaction_date' => '2025-01-01 00:00:00', 'final_total' => 100],
            ['id' => 31, 'business_id' => 1, 'type' => 'sell', 'invoice_no' => 'INV-AMB', 'ref_no' => null, 'transaction_date' => '2025-01-01 00:00:00', 'final_total' => 100],
        ]);

        $links = $this->resolve([self::entry('INV-AMB', '2025-01-01', 100)], 1);

        $this->assertSame('ambiguous', $links[0]['source_link_status']);
        $this->assertNull($links[0]['source_record_id']);
    }

    public function test_resolution_remains_business_and_source_type_scoped(): void
    {
        DB::table('transactions')->insert([
            ['id' => 40, 'business_id' => 1, 'type' => 'sell', 'invoice_no' => 'INV-SCOPE', 'ref_no' => null, 'transaction_date' => '2025-01-01 00:00:00', 'final_total' => 100],
            ['id' => 41, 'business_id' => 1, 'type' => 'purchase', 'invoice_no' => 'INV-SCOPE', 'ref_no' => null, 'transaction_date' => '2025-01-01 00:00:00', 'final_total' => 100],
            ['id' => 42, 'business_id' => 2, 'type' => 'sell', 'invoice_no' => 'INV-SCOPE', 'ref_no' => null, 'transaction_date' => '2025-01-01 00:00:00', 'final_total' => 100],
        ]);

        $links = $this->resolve([self::entry('INV-SCOPE', '2025-01-01', 100)], 1);

        $this->assertSame('matched', $links[0]['source_link_status']);
        $this->assertSame(40, $links[0]['source_record_id']);
    }

    public function test_all_thirteen_workbook_source_types_have_explicit_resolution_rules(): void
    {
        $types = [
            'Journal Entry', 'Sales Invoice', 'Purchase Invoice', 'Expense',
            'Purchase Payment', 'Receive Payment', 'Bank Deposit', 'Bank Transfer',
            'Bank Withdrawal', 'Stock Adjustment', 'Stock In/Out', 'Opening Balance', 'Reversal',
        ];
        $entries = [];
        foreach ($types as $index => $type) {
            $entries[] = [
                'source_type' => $type,
                'source_number' => 'MISSING-'.$index,
                'date' => '2025-01-01',
                'debit_total' => 100,
            ];
        }

        $links = $this->resolve($entries, 1);

        $this->assertCount(13, $links);
        $this->assertSame('not_applicable', $links[0]['source_link_status']);
        foreach (array_slice($links, 1) as $link) {
            $this->assertSame('unmatched', $link['source_link_status']);
            $this->assertStringContainsString('Tidak ditemukan sumber', $link['source_link_note']);
        }

        $unknown = $this->resolve([[
            'source_type' => 'Unsupported Type',
            'source_number' => 'X-1',
            'date' => '2025-01-01',
            'debit_total' => 0,
        ]], 1);
        $this->assertStringContainsString('belum memiliki aturan', $unknown[0]['source_link_note']);
    }

    private function resolve(array $entries, int $businessId): array
    {
        return $this->resolver->invoke($this->controller, $entries, $businessId);
    }

    private static function entry(string $sourceNumber, string $date, float $amount): array
    {
        return [
            'source_type' => 'Sales Invoice',
            'source_number' => $sourceNumber,
            'date' => $date,
            'debit_total' => $amount,
        ];
    }
}
