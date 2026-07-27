<?php

namespace Tests\Unit;

use App\Services\SolarLedgerService;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Support\DateFactory;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;

class SolarLedgerDatabaseTest extends TestCase
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
        $container->instance('db', $this->database->getDatabaseManager());
        $container->instance('date', new DateFactory());
        Facade::setFacadeApplication($container);

        $schema = $this->database->schema();
        $schema->create('solar_opening_balances', function ($table) {
            $table->increments('id');
            $table->unsignedInteger('business_id');
            $table->string('ledger');
            $table->date('effective_date');
            $table->decimal('actual_balance', 20, 4)->nullable();
            $table->decimal('meter_balance', 20, 4)->nullable();
            $table->decimal('quantity_balance', 20, 4)->nullable();
            $table->timestamps();
        });
        $schema->create('solar_stock', function ($table) {
            $table->increments('id');
            $table->unsignedInteger('business_id');
            $table->date('tanggal');
            $table->decimal('stock_in', 20, 4)->default(0);
            $table->decimal('out_actual', 20, 4)->default(0);
            $table->decimal('stock_actual', 20, 4)->default(0);
            $table->decimal('out_meteran', 20, 4)->default(0);
            $table->decimal('stock_meteran', 20, 4)->default(0);
            $table->string('keterangan')->nullable();
            $table->string('source')->default('manual');
            $table->string('source_sheet')->nullable();
            $table->unsignedInteger('source_row')->nullable();
            $table->string('source_hash')->nullable();
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);
        parent::tearDown();
    }

    public function test_recalculation_uses_opening_balance_and_never_changes_another_business(): void
    {
        $now = '2026-06-01 00:00:00';
        $this->database->table('solar_opening_balances')->insert([
            'business_id' => 1,
            'ledger' => 'stock',
            'effective_date' => '2026-06-01',
            'actual_balance' => 100,
            'meter_balance' => 105,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $this->database->table('solar_stock')->insert([
            [
                'business_id' => 1,
                'tanggal' => '2026-06-02',
                'stock_in' => 20,
                'out_actual' => 5,
                'out_meteran' => 4,
                'stock_actual' => 0,
                'stock_meteran' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'business_id' => 2,
                'tanggal' => '2026-06-02',
                'stock_in' => 999,
                'out_actual' => 0,
                'out_meteran' => 0,
                'stock_actual' => 777,
                'stock_meteran' => 778,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);

        (new SolarLedgerService())->recalculate(1, 'stock');

        $businessOne = $this->database->table('solar_stock')->where('business_id', 1)->first();
        $businessTwo = $this->database->table('solar_stock')->where('business_id', 2)->first();
        $this->assertSame(115.0, (float) $businessOne->stock_actual);
        $this->assertSame(121.0, (float) $businessOne->stock_meteran);
        $this->assertSame(777.0, (float) $businessTwo->stock_actual);
        $this->assertSame(778.0, (float) $businessTwo->stock_meteran);
    }
}
