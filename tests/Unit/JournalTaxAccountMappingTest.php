<?php

namespace Tests\Unit;

use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Support\DateFactory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Factory as ValidationFactory;
use Illuminate\Validation\ValidationException;
use Modules\Accounting\Http\Controllers\JournalEntryController;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

class JournalTaxAccountMappingTest extends TestCase
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
        $container->instance('validator', new ValidationFactory(new Translator(new ArrayLoader(), 'en'), $container));
        Facade::setFacadeApplication($container);

        $schema = $this->database->schema();
        $schema->create('accounting_accounts', function ($table) {
            $table->increments('id');
            $table->unsignedInteger('business_id');
            $table->unsignedInteger('account_id')->nullable();
            $table->string('name');
            $table->string('gl_code')->nullable();
        });
        $schema->create('tax_rates', function ($table) {
            $table->increments('id');
            $table->unsignedInteger('business_id');
            $table->string('name');
            $table->decimal('amount', 10, 4);
            $table->boolean('is_tax_group')->default(false);
            $table->timestamp('deleted_at')->nullable();
        });
        $schema->create('accounting_journal_tax_account_mappings', function ($table) {
            $table->increments('id');
            $table->unsignedInteger('business_id');
            $table->unsignedInteger('accounting_account_id');
            $table->unsignedInteger('tax_rate_id');
            $table->unsignedInteger('created_by');
            $table->timestamps();
        });

        DB::table('accounting_accounts')->insert([
            ['id' => 5, 'business_id' => 1, 'account_id' => null, 'name' => 'VAT Payable', 'gl_code' => '2100'],
            ['id' => 6, 'business_id' => 2, 'account_id' => null, 'name' => 'VAT Payable', 'gl_code' => '2100'],
        ]);
        DB::table('tax_rates')->insert([
            ['id' => 10, 'business_id' => 1, 'name' => 'VAT 10%', 'amount' => 10, 'is_tax_group' => false],
            ['id' => 11, 'business_id' => 2, 'name' => 'VAT 11%', 'amount' => 11, 'is_tax_group' => false],
        ]);

        $this->controller = (new ReflectionClass(JournalEntryController::class))->newInstanceWithoutConstructor();
    }

    protected function tearDown(): void
    {
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);
        EloquentModel::unsetConnectionResolver();
        parent::tearDown();
    }

    public function test_mapping_is_reused_cleared_and_isolated_by_business(): void
    {
        $analysis = ['entries' => [self::entry()]];
        $this->controller->persistJournalTaxAccountMappings($analysis, 1, 70, [
            'tax_account_mappings' => [self::accountKey() => '10'],
        ]);
        $this->controller->persistJournalTaxAccountMappings($analysis, 1, 70, [
            'tax_account_mappings' => [self::accountKey() => '10'],
        ]);
        $this->controller->persistJournalTaxAccountMappings($analysis, 2, 80, [
            'tax_account_mappings' => [self::accountKey() => '11'],
        ]);

        $mappings = DB::table('accounting_journal_tax_account_mappings')->orderBy('business_id')->get();
        $this->assertCount(2, $mappings);
        $this->assertSame(5, (int) $mappings[0]->accounting_account_id);
        $this->assertSame(10, (int) $mappings[0]->tax_rate_id);
        $this->assertSame(6, (int) $mappings[1]->accounting_account_id);
        $this->assertSame(11, (int) $mappings[1]->tax_rate_id);

        $this->controller->persistJournalTaxAccountMappings($analysis, 1, 70, [
            'tax_account_mappings' => [self::accountKey() => '__clear__'],
        ]);

        $remaining = DB::table('accounting_journal_tax_account_mappings')->get();
        $this->assertCount(1, $remaining);
        $this->assertSame(2, (int) $remaining[0]->business_id);
    }

    public function test_mapping_rejects_a_tax_rate_from_another_business(): void
    {
        $this->expectException(ValidationException::class);

        $this->controller->persistJournalTaxAccountMappings(
            ['entries' => [self::entry()]],
            1,
            70,
            ['tax_account_mappings' => [self::accountKey() => '11']]
        );
    }

    private static function entry(): array
    {
        return ['lines' => [[
            'account_no' => '2100',
            'account_name' => 'VAT Payable',
        ]]];
    }

    private static function accountKey(): string
    {
        return md5(strtolower('2100|VAT Payable'));
    }
}
