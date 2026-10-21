<?php

namespace Tests\Unit;

use App\Utils\TransactionUtil;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Support\DateFactory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;

class SellProductSearchTest extends TestCase
{
    private Capsule $database;

    private TransactionUtil $transactionUtil;

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

        $schema = $this->database->schema();
        $schema->create('transactions', function ($table) {
            $table->increments('id');
            $table->unsignedInteger('business_id');
            $table->string('type');
        });
        $schema->create('products', function ($table) {
            $table->increments('id');
            $table->string('name');
            $table->string('type');
            $table->string('sku')->nullable();
        });
        $schema->create('product_variations', function ($table) {
            $table->increments('id');
            $table->string('name');
        });
        $schema->create('variations', function ($table) {
            $table->increments('id');
            $table->unsignedInteger('product_variation_id')->nullable();
            $table->string('name')->nullable();
            $table->string('sub_sku')->nullable();
        });
        $schema->create('transaction_sell_lines', function ($table) {
            $table->increments('id');
            $table->unsignedInteger('transaction_id');
            $table->unsignedInteger('product_id')->nullable();
            $table->unsignedInteger('variation_id')->nullable();
            $table->unsignedInteger('parent_sell_line_id')->nullable();
        });
        $schema->create('pjt_invoice_lines', function ($table) {
            $table->increments('id');
            $table->unsignedInteger('transaction_id');
            $table->string('description');
        });

        $this->transactionUtil = new TransactionUtil();
    }

    protected function tearDown(): void
    {
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);
        parent::tearDown();
    }

    public function test_product_and_variation_search_keeps_one_row_per_transaction(): void
    {
        DB::table('transactions')->insert([
            ['id' => 1, 'business_id' => 1, 'type' => 'sell'],
            ['id' => 2, 'business_id' => 1, 'type' => 'sell'],
            ['id' => 3, 'business_id' => 2, 'type' => 'sell'],
            ['id' => 4, 'business_id' => 1, 'type' => 'sell'],
        ]);
        DB::table('products')->insert([
            ['id' => 1, 'name' => 'Safety Helmet', 'type' => 'variable', 'sku' => 'HELMET'],
            ['id' => 2, 'name' => 'Safety Gloves', 'type' => 'single', 'sku' => 'GLOVE'],
            ['id' => 3, 'name' => 'Project Task Marker', 'type' => 'single', 'sku' => 'TASK'],
        ]);
        DB::table('product_variations')->insert(['id' => 1, 'name' => 'Color']);
        DB::table('variations')->insert([
            ['id' => 1, 'product_variation_id' => 1, 'name' => 'Orange', 'sub_sku' => 'HELMET-ORANGE'],
            ['id' => 2, 'product_variation_id' => 1, 'name' => 'Blue', 'sub_sku' => 'HELMET-BLUE'],
        ]);
        DB::table('transaction_sell_lines')->insert([
            ['id' => 1, 'transaction_id' => 1, 'product_id' => 1, 'variation_id' => 1, 'parent_sell_line_id' => null],
            ['id' => 2, 'transaction_id' => 1, 'product_id' => 2, 'variation_id' => null, 'parent_sell_line_id' => null],
            ['id' => 3, 'transaction_id' => 1, 'product_id' => 3, 'variation_id' => null, 'parent_sell_line_id' => 1],
            ['id' => 4, 'transaction_id' => 2, 'product_id' => 1, 'variation_id' => 2, 'parent_sell_line_id' => null],
            ['id' => 5, 'transaction_id' => 3, 'product_id' => 1, 'variation_id' => 1, 'parent_sell_line_id' => null],
        ]);
        DB::table('pjt_invoice_lines')->insert([
            'id' => 1,
            'transaction_id' => 4,
            'description' => 'Project Task Marker',
        ]);

        $productMatches = $this->sellQuery();
        $this->transactionUtil->filterSellsByProduct($productMatches, 'Safety Gloves');
        $this->assertSame([1], $productMatches->pluck('transactions.id')->all());

        $variationMatches = $this->sellQuery();
        $this->transactionUtil->filterSellsByProduct($variationMatches, 'Orange');
        $this->assertSame([1], $variationMatches->pluck('transactions.id')->all());

        $attributeMatches = $this->sellQuery();
        $this->transactionUtil->filterSellsByProduct($attributeMatches, 'Color');
        $this->assertSame([1, 2], $attributeMatches->orderBy('transactions.id')->pluck('transactions.id')->all());

        $childLineMatches = $this->sellQuery();
        $this->transactionUtil->filterSellsByProduct($childLineMatches, 'Project Task Marker');
        $this->assertSame([], $childLineMatches->pluck('transactions.id')->all());

        $businessScopedMatches = $this->sellQuery();
        $this->transactionUtil->filterSellsByProduct($businessScopedMatches, 'Safety Helmet');
        $this->assertSame([1, 2], $businessScopedMatches->orderBy('transactions.id')->pluck('transactions.id')->all());
    }

    public function test_product_summary_includes_variation_parts_and_ignores_child_lines(): void
    {
        $variation = (object) [
            'name' => 'Large',
            'product_variation' => (object) ['name' => 'Size'],
        ];
        $variableLine = (object) [
            'product_id' => 10,
            'parent_sell_line_id' => null,
            'product' => (object) ['name' => 'Safety Vest', 'type' => 'variable'],
            'variations' => $variation,
        ];
        $singleLine = (object) [
            'product_id' => 11,
            'parent_sell_line_id' => null,
            'product' => (object) ['name' => 'Work Boots', 'type' => 'single'],
            'variations' => null,
        ];
        $childLine = (object) [
            'product_id' => 12,
            'parent_sell_line_id' => 10,
            'product' => (object) ['name' => 'Child modifier', 'type' => 'single'],
            'variations' => null,
        ];

        $summary = $this->transactionUtil->formatSellProductSummary(collect([$variableLine, $singleLine, $childLine]));

        $this->assertSame('Safety Vest - Size - Large||Work Boots', $summary);
    }

    private function sellQuery()
    {
        return DB::table('transactions')
            ->where('transactions.business_id', 1)
            ->where('transactions.type', 'sell');
    }
}
