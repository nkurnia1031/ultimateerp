<?php

namespace Tests\Unit;

use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Support\DateFactory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use Modules\Accounting\Services\JournalImportTaxResolver;
use PHPUnit\Framework\TestCase;

class JournalImportTaxResolverTest extends TestCase
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
        $schema->create('tax_rates', function ($table) {
            $table->increments('id');
            $table->unsignedInteger('business_id');
            $table->string('name');
            $table->decimal('amount', 10, 4);
            $table->boolean('is_tax_group')->default(false);
            $table->timestamp('deleted_at')->nullable();
        });
        $schema->create('group_sub_taxes', function ($table) {
            $table->unsignedInteger('group_tax_id');
            $table->unsignedInteger('tax_id');
        });
        DB::table('tax_rates')->insert([
            ['id' => 10, 'business_id' => 1, 'name' => 'PPN 10', 'amount' => 10, 'is_tax_group' => false],
            ['id' => 11, 'business_id' => 2, 'name' => 'PPN 11', 'amount' => 11, 'is_tax_group' => false],
            ['id' => 12, 'business_id' => 1, 'name' => 'PPh 23', 'amount' => -2, 'is_tax_group' => false],
            ['id' => 13, 'business_id' => 1, 'name' => 'PPN 10 dan PPh 23', 'amount' => 0, 'is_tax_group' => true],
        ]);
        DB::table('group_sub_taxes')->insert([
            ['group_tax_id' => 13, 'tax_id' => 10],
            ['group_tax_id' => 13, 'tax_id' => 12],
        ]);
    }

    protected function tearDown(): void
    {
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);
        EloquentModel::unsetConnectionResolver();
        parent::tearDown();
    }

    public function test_tax_rate_is_derived_from_journal_tax_and_taxable_base_without_a_gl_rate_mapping(): void
    {
        $entry = ['lines' => [
            ['account_no' => '5-50000', 'account_name' => 'Cost of Sales', 'debit' => 27272727, 'credit' => 0],
            ['account_no' => '1-10500', 'account_name' => 'VAT In', 'debit' => 2727272.7, 'credit' => 0],
            ['account_no' => '1-10004', 'account_name' => 'MANDIRI - BMT', 'debit' => 0, 'credit' => 29999999.7],
        ]];

        $resolved = (new JournalImportTaxResolver())->resolveForEntry($entry, 1);

        $this->assertSame(10, $resolved['id']);
        $this->assertSame(10.0, $resolved['rate']);
        $this->assertSame(27272727.0, $resolved['taxable_base']);
        $this->assertSame(2727272.7, $resolved['tax_amount']);
        $this->assertSame('input', $resolved['direction']);
    }

    public function test_distinct_tax_components_match_the_business_tax_group_from_their_computed_rates(): void
    {
        $entry = ['lines' => [
            ['account_no' => '5100', 'account_name' => 'Cost of Sales', 'debit' => 100, 'credit' => 0],
            ['account_no' => '10500', 'account_name' => 'VAT In', 'debit' => 10, 'credit' => 0],
            ['account_no' => '20510', 'account_name' => 'PPh 23', 'debit' => 0, 'credit' => 2],
            ['account_no' => '1000', 'account_name' => 'Cash', 'debit' => 0, 'credit' => 108],
        ]];

        $resolved = (new JournalImportTaxResolver())->resolveForEntry($entry, 1, 100);

        $this->assertSame(13, $resolved['id']);
        $this->assertSame(8.0, $resolved['rate']);
        $this->assertSame(8.0, $resolved['tax_amount']);
    }

    public function test_a_tax_amount_that_does_not_match_a_business_rate_stays_unresolved(): void
    {
        $entry = ['lines' => [
            ['account_no' => '5100', 'account_name' => 'Cost of Sales', 'debit' => 100, 'credit' => 0],
            ['account_no' => '10500', 'account_name' => 'VAT In', 'debit' => 12, 'credit' => 0],
            ['account_no' => '1000', 'account_name' => 'Cash', 'debit' => 0, 'credit' => 112],
        ]];

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('tidak cocok dengan TaxRate aktif');

        (new JournalImportTaxResolver())->resolveForEntry($entry, 1, 100);
    }
}