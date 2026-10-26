<?php

namespace Tests\Unit;

use App\Utils\TransactionUtil;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Support\DateFactory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;

class PurchaseIndexQueryTest extends TestCase
{
    private Capsule $database;

    protected function setUp(): void
    {
        parent::setUp();
        if (! in_array('sqlite', \PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('PDO SQLite tidak tersedia pada PHP runtime ini.');
        }

        $container = new Container();
        $this->database = new Capsule($container);
        $this->database->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $this->database->setAsGlobal();
        EloquentModel::setConnectionResolver($this->database->getDatabaseManager());
        $container->instance('db', $this->database->getDatabaseManager());
        $container->instance('date', new DateFactory());
        Facade::setFacadeApplication($container);

        $schema = $this->database->schema();
        $schema->create('transactions', function ($table) {
            $table->increments('id');
            $table->unsignedInteger('business_id');
            $table->unsignedInteger('location_id');
            $table->unsignedInteger('contact_id')->nullable();
            $table->unsignedInteger('created_by')->nullable();
            $table->string('type');
            $table->string('document')->nullable();
            $table->dateTime('transaction_date')->nullable();
            $table->string('ref_no')->nullable();
            $table->string('status')->nullable();
            $table->string('payment_status')->nullable();
            $table->decimal('total_before_tax', 22, 4)->nullable();
            $table->decimal('final_total', 22, 4)->nullable();
            $table->integer('pay_term_number')->nullable();
            $table->string('pay_term_type')->nullable();
            $table->string('custom_field_1')->nullable();
            $table->string('custom_field_2')->nullable();
            $table->string('custom_field_3')->nullable();
            $table->string('custom_field_4')->nullable();
            $table->unsignedInteger('return_parent_id')->nullable();
        });
        $schema->create('transaction_payments', function ($table) {
            $table->increments('id');
            $table->unsignedInteger('transaction_id');
            $table->decimal('amount', 22, 4)->default(0);
        });
        $schema->create('business_locations', function ($table) {
            $table->increments('id');
            $table->string('name');
        });
        $schema->create('contacts', function ($table) {
            $table->increments('id');
            $table->string('name')->nullable();
            $table->string('supplier_business_name')->nullable();
        });
        $schema->create('users', function ($table) {
            $table->increments('id');
            $table->string('surname')->nullable();
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
        });

        DB::table('business_locations')->insert(['id' => 1, 'name' => 'Main']);
        DB::table('transactions')->insert([
            'id' => 1,
            'business_id' => 1,
            'location_id' => 1,
            'type' => 'purchase',
            'transaction_date' => '2026-10-20 00:00:00',
            'total_before_tax' => 250,
            'final_total' => 275,
        ]);
    }

    protected function tearDown(): void
    {
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);
        EloquentModel::unsetConnectionResolver();
        parent::tearDown();
    }

    public function test_purchase_index_query_returns_the_transaction_before_tax_total(): void
    {
        $purchase = (new TransactionUtil())
            ->getListPurchases(1)
            ->setEagerLoads([])
            ->where('transactions.id', 1)
            ->first();

        $this->assertSame(250.0, (float) $purchase->total_before_tax);
        $this->assertNotSame((float) $purchase->final_total, (float) $purchase->total_before_tax);
    }

    public function test_sales_index_query_selects_the_transaction_before_tax_total(): void
    {
        $salesQuery = (new TransactionUtil())->getListSells(1)->getQuery();

        $this->assertContains('transactions.total_before_tax', $salesQuery->columns);
    }
}
