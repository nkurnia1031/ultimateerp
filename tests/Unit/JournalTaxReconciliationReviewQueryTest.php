<?php

namespace Tests\Unit;

use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Support\DateFactory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use Modules\Accounting\Http\Controllers\JournalEntryController;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

class JournalTaxReconciliationReviewQueryTest extends TestCase
{
    private Capsule $database;

    private JournalEntryController $controller;

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

        $schema = $this->database->schema();
        $schema->create('accounting_journal_imports', function ($table) {
            $table->increments('id');
            $table->unsignedInteger('business_id');
            $table->string('file_name');
            $table->timestamp('created_at')->nullable();
        });
        $schema->create('accounting_acc_trans_mappings', function ($table) {
            $table->increments('id');
            $table->unsignedInteger('business_id');
            $table->unsignedInteger('journal_import_id');
            $table->string('tax_reconciliation_status');
            $table->timestamp('updated_at')->nullable();
            $table->string('ref_no');
            $table->unsignedInteger('source_record_id')->nullable();
            $table->text('tax_reconciliation_note')->nullable();
            $table->decimal('journal_tax_amount', 22, 4)->nullable();
            $table->decimal('calculated_tax_amount', 22, 4)->nullable();
            $table->decimal('journal_total_amount', 22, 4)->nullable();
            $table->decimal('recalculated_final_total', 22, 4)->nullable();
        });

        $this->controller = (new ReflectionClass(JournalEntryController::class))->newInstanceWithoutConstructor();
    }

    protected function tearDown(): void
    {
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);
        EloquentModel::unsetConnectionResolver();
        parent::tearDown();
    }

    public function test_review_query_returns_only_current_business_review_rows_with_import_details(): void
    {
        DB::table('accounting_journal_imports')->insert([
            ['id' => 1, 'business_id' => 7, 'file_name' => 'current.xlsx', 'created_at' => '2026-10-20 10:00:00'],
            ['id' => 2, 'business_id' => 8, 'file_name' => 'other.xlsx', 'created_at' => '2026-10-20 10:00:00'],
        ]);
        DB::table('accounting_acc_trans_mappings')->insert([
            ['id' => 1, 'business_id' => 7, 'journal_import_id' => 1, 'tax_reconciliation_status' => 'review', 'updated_at' => '2026-10-21 10:00:00', 'ref_no' => 'SALE-7'],
            ['id' => 2, 'business_id' => 8, 'journal_import_id' => 2, 'tax_reconciliation_status' => 'review', 'updated_at' => '2026-10-22 10:00:00', 'ref_no' => 'SALE-8'],
            ['id' => 3, 'business_id' => 7, 'journal_import_id' => 1, 'tax_reconciliation_status' => 'matched', 'updated_at' => '2026-10-23 10:00:00', 'ref_no' => 'SALE-OK'],
        ]);

        $method = new ReflectionMethod(JournalEntryController::class, 'getTaxReconciliationReviews');
        $reviews = $method->invoke($this->controller, 7);

        $this->assertCount(1, $reviews);
        $this->assertSame('current.xlsx', $reviews->first()->file_name);
        $this->assertSame('SALE-7', $reviews->first()->ref_no);
    }
}
