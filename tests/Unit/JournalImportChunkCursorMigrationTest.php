<?php

namespace Tests\Unit;

use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;

class JournalImportChunkCursorMigrationTest extends TestCase
{
    private ?Container $originalContainer = null;

    private ?Capsule $database = null;

    protected function setUp(): void
    {
        parent::setUp();
        if (! in_array('sqlite', \PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('PDO SQLite tidak tersedia pada PHP runtime ini.');
        }

        $this->originalContainer = Container::getInstance();
        $container = new Container();
        Container::setInstance($container);
        $this->database = new Capsule($container);
        $this->database->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $this->database->setAsGlobal();
        $container->instance('db', $this->database->getDatabaseManager());
        $container->instance('db.schema', $this->database->schema());
        Facade::setFacadeApplication($container);
        $this->database->schema()->create('accounting_journal_imports', function ($table) {
            $table->increments('id');
        });
    }

    protected function tearDown(): void
    {
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);
        if ($this->originalContainer !== null) {
            Container::setInstance($this->originalContainer);
        }
        parent::tearDown();
    }

    public function test_chunk_cursor_migration_adds_columns_idempotently_and_can_be_reverted(): void
    {
        $migrationPath = dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'Modules'.DIRECTORY_SEPARATOR.'Accounting'
            .DIRECTORY_SEPARATOR.'Database'.DIRECTORY_SEPARATOR.'Migrations'
            .DIRECTORY_SEPARATOR.'2026_10_26_000002_add_journal_import_chunk_cursors.php';
        $migration = require $migrationPath;

        $migration->up();
        $migration->up();

        $this->assertTrue($this->database->schema()->hasColumn('accounting_journal_imports', 'prepare_cursor'));
        $this->assertTrue($this->database->schema()->hasColumn('accounting_journal_imports', 'tax_reconciliation_cursor'));

        $migration->down();

        $this->assertFalse($this->database->schema()->hasColumn('accounting_journal_imports', 'prepare_cursor'));
        $this->assertFalse($this->database->schema()->hasColumn('accounting_journal_imports', 'tax_reconciliation_cursor'));
    }

    public function test_cancellation_migration_adds_columns_idempotently_and_can_be_reverted(): void
    {
        $migrationPath = dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'Modules'.DIRECTORY_SEPARATOR.'Accounting'
            .DIRECTORY_SEPARATOR.'Database'.DIRECTORY_SEPARATOR.'Migrations'
            .DIRECTORY_SEPARATOR.'2026_10_27_000001_add_journal_import_cancellation_fields.php';
        $migration = require $migrationPath;

        $migration->up();
        $migration->up();

        foreach (['cancel_requested_at', 'cancel_requested_by', 'cancelled_at', 'cancelled_by'] as $column) {
            $this->assertTrue($this->database->schema()->hasColumn('accounting_journal_imports', $column));
        }

        $migration->down();

        foreach (['cancel_requested_at', 'cancel_requested_by', 'cancelled_at', 'cancelled_by'] as $column) {
            $this->assertFalse($this->database->schema()->hasColumn('accounting_journal_imports', $column));
        }
    }

    public function test_legacy_solar_update_skips_ledgers_absent_from_a_fresh_database(): void
    {
        $migrationPath = dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'database'.DIRECTORY_SEPARATOR.'migrations'
            .DIRECTORY_SEPARATOR.'2025_09_15_004812_update_solar_tables_add_id_and_timestamps.php';
        $migration = require $migrationPath;

        $migration->up();
        $migration->down();

        foreach (['solar_mess', 'solar_pool', 'solar_stock'] as $tableName) {
            $this->assertFalse($this->database->schema()->hasTable($tableName));
        }
    }
}
