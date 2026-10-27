<?php

namespace Tests\Unit;

use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Support\DateFactory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use Modules\Accounting\Services\JournalImportCancellationService;
use PHPUnit\Framework\TestCase;

class JournalImportCancellationServiceTest extends TestCase
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
        $container->instance('date', new DateFactory());
        Facade::setFacadeApplication($container);

        $this->database->schema()->create('accounting_journal_imports', function ($table) {
            $table->increments('id');
            $table->unsignedInteger('business_id');
            $table->string('status');
            $table->dateTime('cancel_requested_at')->nullable();
            $table->unsignedInteger('cancel_requested_by')->nullable();
            $table->dateTime('cancelled_at')->nullable();
            $table->unsignedInteger('cancelled_by')->nullable();
            $table->integer('processed_entries')->default(0);
            $table->integer('imported_entries')->default(0);
            $table->integer('skipped_entries')->default(0);
            $table->text('last_message')->nullable();
            $table->dateTime('updated_at')->nullable();
        });
        $this->database->schema()->create('accounting_journal_import_rows', function ($table) {
            $table->increments('id');
            $table->unsignedInteger('journal_import_id');
            $table->unsignedInteger('business_id');
            $table->string('processing_status');
            $table->text('processing_message')->nullable();
            $table->dateTime('processing_started_at')->nullable();
            $table->dateTime('updated_at')->nullable();
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

    public function test_safe_checkpoint_preserves_finished_rows_and_returns_unfinished_rows_to_pending(): void
    {
        DB::table('accounting_journal_imports')->insert([
            'id' => 5,
            'business_id' => 2,
            'status' => 'importing',
            'cancel_requested_at' => now(),
            'cancel_requested_by' => 17,
        ]);

        foreach (['completed', 'failed', 'queued', 'running'] as $index => $status) {
            DB::table('accounting_journal_import_rows')->insert([
                'id' => $index + 1,
                'journal_import_id' => 5,
                'business_id' => 2,
                'processing_status' => $status,
            ]);
        }

        $service = new JournalImportCancellationService();
        $this->assertTrue($service->stopIfRequested(5, 2));

        $this->assertSame('cancelled', DB::table('accounting_journal_imports')->where('id', 5)->value('status'));
        $this->assertSame(17, (int) DB::table('accounting_journal_imports')->where('id', 5)->value('cancelled_by'));
        $this->assertSame(['completed', 'failed', 'pending', 'pending'], DB::table('accounting_journal_import_rows')->orderBy('id')->pluck('processing_status')->all());
        $this->assertSame(2, (int) DB::table('accounting_journal_imports')->where('id', 5)->value('processed_entries'));
        $this->assertSame(1, (int) DB::table('accounting_journal_imports')->where('id', 5)->value('imported_entries'));
        $this->assertSame(1, (int) DB::table('accounting_journal_imports')->where('id', 5)->value('skipped_entries'));
    }

    public function test_stop_request_is_business_scoped_and_queued_batches_cancel_immediately(): void
    {
        DB::table('accounting_journal_imports')->insert([
            'id' => 6,
            'business_id' => 2,
            'status' => 'queued_import',
        ]);

        $service = new JournalImportCancellationService();
        $this->assertSame('not_found', $service->requestStop(6, 3, 17)['result']);
        $this->assertSame('cancelled', $service->requestStop(6, 2, 17)['result']);
        $this->assertSame('cancelled', $service->requestStop(6, 2, 17)['result']);
        $this->assertSame('cancelled', DB::table('accounting_journal_imports')->where('id', 6)->value('status'));

        DB::table('accounting_journal_imports')->insert([
            'id' => 7,
            'business_id' => 2,
            'status' => 'imported',
        ]);
        $this->assertSame('not_active', $service->requestStop(7, 2, 17)['result']);
        $this->assertSame('imported', DB::table('accounting_journal_imports')->where('id', 7)->value('status'));
    }
}
