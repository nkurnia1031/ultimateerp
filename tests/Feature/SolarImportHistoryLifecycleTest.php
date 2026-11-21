<?php

namespace Tests\Feature;

use App\Jobs\ProcessSolarImport;
use App\Jobs\ProcessSolarPreview;
use App\Services\SolarImportPreviewService;
use App\Services\SolarImportProcessService;
use App\Services\UserImportBatchService;
use App\UserImportBatch;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class SolarImportHistoryLifecycleTest extends TestCase
{
    private SolarImportPreviewService $previewService;

    protected function setUp(): void
    {
        parent::setUp();

        if (! in_array('sqlite', \PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('PDO SQLite is required for isolated Solar import history tests.');
        }

        config([
            'database.default' => 'solar_import_history_test',
            'database.connections.solar_import_history_test' => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
                'foreign_key_constraints' => false,
            ],
            'cache.default' => 'array',
        ]);
        DB::purge('solar_import_history_test');
        DB::setDefaultConnection('solar_import_history_test');
        Storage::fake('local');
        $this->withoutMiddleware([
            \App\Http\Middleware\SetSessionData::class,
            \App\Http\Middleware\AdminSidebarMenu::class,
        ]);
        $this->createSchemas();

        $this->previewService = \Mockery::mock(SolarImportPreviewService::class);
        $this->previewService->shouldReceive('summary')
            ->zeroOrMoreTimes()
            ->andReturn($this->solarSummary());
        $this->previewService->shouldReceive('referenceMappings')
            ->zeroOrMoreTimes()
            ->andReturn(['asset' => [], 'driver' => []]);
        $this->app->instance(SolarImportPreviewService::class, $this->previewService);
    }

    public function test_upload_preview_confirmation_and_workbook_restore_use_the_shared_history(): void
    {
        Bus::fake();
        $path = $this->workbook();

        try {
            $upload = $this->asBusiness(7)
                ->post(route('solar.reconcile.upload'), [
                    'file' => new UploadedFile(
                        $path,
                        'solar-source.xlsx',
                        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                        UPLOAD_ERR_OK,
                        true
                    ),
                ]);

            $upload->assertStatus(202)->assertJsonPath('success', true);
            Bus::assertDispatched(ProcessSolarPreview::class, 1);

            $domain = DB::table('solar_import_batches')->where('id', $upload->json('batch_id'))->first();
            $this->assertNotNull($domain->user_import_batch_id);
            $this->assertNotEmpty($domain->file_path);
            $shared = UserImportBatch::findOrFail((int) $domain->user_import_batch_id);
            $this->assertSame('solar', $shared->importer_key);
            $this->assertSame('domain:'.$domain->id, $shared->source_key);
            $this->assertSame('queued_preview', $shared->status);
            $stagingPath = $domain->file_path;
            Storage::disk('local')->assertExists($stagingPath);
            $queuedStatus = $this->asBusiness(7)->get(route('solar.imports.status', $domain->id));
            $queuedStatus->assertOk()->assertJsonPath('state', 'queued')->assertJsonPath('operation', 'preview');

            $this->previewService->shouldReceive('rebuild')
                ->once()
                ->andReturn(['items' => [['ledger' => 'stock']]]);
            (new ProcessSolarPreview((string) $domain->id, 7))
                ->handle($this->previewService, app(SolarImportProcessService::class));

            $domain = DB::table('solar_import_batches')->where('id', $domain->id)->first();
            $shared->refresh();
            $this->assertSame('completed', $domain->process_state);
            $this->assertNull($domain->file_path);
            $this->assertSame('preview_ready', $shared->status);
            $this->assertTrue($shared->hasSourceSnapshot());
            $this->assertNull($shared->staging_path);
            Storage::disk('local')->assertMissing($stagingPath);
            $previewStatus = $this->asBusiness(7)->get(route('solar.imports.status', $domain->id));
            $previewStatus->assertOk()->assertJsonPath('state', 'completed')->assertJsonPath('domain_status', 'preview')->assertJsonPath('percent', 100);

            $history = $this->asBusiness(7)->get(route('solar.imports.data', [
                'draw' => 1,
                'start' => 0,
                'length' => 10,
                'status' => 'preview_ready',
                'search' => ['value' => 'solar-source.xlsx'],
            ]));
            $history->assertOk()->assertJsonPath('recordsTotal', 1)
                ->assertJsonPath('recordsFiltered', 1)
                ->assertJsonPath('data.0.domain_batch_id', $domain->id)
                ->assertJsonPath('data.0.status', 'preview_ready');
            $this->assertNotEmpty($history->json('data.0.restore_url'));

            $download = $this->asBusiness(7)->get(route('solar.imports.restore', $domain->id));
            $download->assertOk()->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
            $restoredPath = tempnam(sys_get_temp_dir(), 'solar-restored-');
            file_put_contents($restoredPath, $download->streamedContent());
            $restored = IOFactory::load($restoredPath);
            $this->assertSame('Stock', $restored->getSheet(0)->getTitle());
            $this->assertSame('Sumber Solar', $restored->getSheet(0)->getCell('A2')->getValue());
            $this->assertSame('Catatan', $restored->getSheet(1)->getTitle());
            $this->assertSame('Sheet kedua', $restored->getSheet(1)->getCell('A1')->getValue());
            $restored->disconnectWorksheets();
            @unlink($restoredPath);

            $confirm = $this->asBusiness(7)->post(route('solar.imports.confirm', $domain->id));
            $confirm->assertStatus(202)->assertJsonPath('success', true);
            Bus::assertDispatched(ProcessSolarImport::class, 1);
            $this->assertSame('queued_import', $shared->fresh()->status);
            $queuedImportStatus = $this->asBusiness(7)->get(route('solar.imports.status', $domain->id));
            $queuedImportStatus->assertOk()->assertJsonPath('state', 'queued')->assertJsonPath('operation', 'import');

            $this->asBusiness(8)->get(route('solar.imports.restore', $domain->id))->assertNotFound();
        } finally {
            @unlink($path);
        }
    }

    public function test_history_backfills_legacy_batches_and_detail_rows_are_business_scoped(): void
    {
        $legacyId = (string) \Illuminate\Support\Str::uuid();
        $this->domainBatch($legacyId, 7, 'Legacy solar workbook.xlsx', 'imported', now()->subDay());
        DB::table('solar_import_preview_rows')->insert([
            'batch_id' => $legacyId,
            'business_id' => 7,
            'ledger' => 'stock',
            'source_row' => 12,
            'raw_date' => '2026-03-05',
            'normalized_date' => '2026-03-05',
            'status' => 'different',
            'matched_by' => 'date',
            'differences' => json_encode(['Saldo akhir berbeda']),
            'excel_payload' => json_encode(['keterangan' => 'Solar kiriman A', 'stock_in' => 240]),
            'app_payload' => json_encode(['stock_in' => 200]),
            'is_importable' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $history = $this->asBusiness(7)->get(route('solar.imports.data', [
            'draw' => 3,
            'start' => 0,
            'length' => 1,
            'status' => 'imported',
            'search' => ['value' => 'Legacy solar workbook.xlsx'],
        ]));
        $history->assertOk()->assertJsonPath('draw', 3)
            ->assertJsonPath('recordsTotal', 1)
            ->assertJsonPath('recordsFiltered', 1)
            ->assertJsonPath('data.0.status', 'imported')
            ->assertJsonPath('data.0.has_snapshot', false)
            ->assertJsonPath('data.0.restore_url', null);
        $this->assertNotNull(DB::table('solar_import_batches')->where('id', $legacyId)->value('user_import_batch_id'));

        $dateFilteredHistory = $this->asBusiness(7)->get(route('solar.imports.data', [
            'draw' => 6,
            'start' => 0,
            'length' => 25,
            'start_date' => now()->toDateString(),
            'end_date' => now()->toDateString(),
        ]));
        $dateFilteredHistory->assertOk()->assertJsonPath('recordsTotal', 1)->assertJsonPath('recordsFiltered', 0);

        $rows = $this->asBusiness(7)->get(route('solar.imports.rows', [
            'batchId' => $legacyId,
            'draw' => 4,
            'start' => 0,
            'length' => 1,
            'search' => ['value' => 'Saldo akhir berbeda'],
        ]));
        $rows->assertOk()->assertJsonPath('draw', 4)
            ->assertJsonPath('recordsFiltered', 1)
            ->assertJsonPath('data.0.sheet', 'STOCK')
            ->assertJsonPath('data.0.source_row', 12)
            ->assertJsonPath('data.0.detail', 'Saldo akhir berbeda');

        $this->asBusiness(8)->get(route('solar.imports.data', ['draw' => 5, 'start' => 0, 'length' => 25]))
            ->assertOk()->assertJsonPath('recordsTotal', 0);
        $this->asBusiness(8)->get(route('solar.imports.rows', $legacyId))->assertNotFound();
    }

    public function test_rollback_stays_business_scoped_and_requires_newest_import_first(): void
    {
        $olderId = (string) \Illuminate\Support\Str::uuid();
        $newerId = (string) \Illuminate\Support\Str::uuid();
        $this->domainBatch($olderId, 7, 'older.xlsx', 'imported', now()->subDay());
        $this->domainBatch($newerId, 7, 'newer.xlsx', 'imported', now());
        $olderShared = $this->linkedSharedBatch($olderId, 7, 'older.xlsx', 'imported');
        $newerShared = $this->linkedSharedBatch($newerId, 7, 'newer.xlsx', 'imported');
        $this->solarStock($olderId, 7, '2026-01-01', 10);
        $this->solarStock($newerId, 7, '2026-01-02', 20);

        $blocked = $this->asBusiness(7)->post(route('solar.imports.rollback', $olderId));
        $blocked->assertStatus(409)->assertJsonPath('success', false);
        $this->assertDatabaseHas('solar_stock', ['import_batch_id' => $olderId]);

        $crossBusiness = $this->asBusiness(8)->post(route('solar.imports.rollback', $newerId));
        $crossBusiness->assertNotFound();

        $rollback = $this->asBusiness(7)->post(route('solar.imports.rollback', $newerId));
        $rollback->assertOk()->assertJsonPath('success', true);
        $this->assertDatabaseHas('solar_import_batches', ['id' => $newerId, 'status' => 'rolled_back']);
        $this->assertDatabaseMissing('solar_stock', ['import_batch_id' => $newerId]);
        $this->assertDatabaseHas('solar_stock', ['import_batch_id' => $olderId]);
        $this->assertSame('reverted', $newerShared->fresh()->status);
        $this->assertSame('imported', $olderShared->fresh()->status);
    }

    private function createSchemas(): void
    {
        Schema::create('user_import_batches', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedInteger('business_id');
            $table->unsignedInteger('created_by')->nullable();
            $table->string('importer_key', 64);
            $table->string('source_key', 64)->nullable();
            $table->string('file_name')->nullable();
            $table->string('staging_path')->nullable();
            $table->string('source_snapshot_path')->nullable();
            $table->string('preview_path')->nullable();
            $table->string('results_path')->nullable();
            $table->string('status', 32)->default('queued_preview');
            $table->string('stage', 64)->nullable();
            $table->unsignedInteger('progress_current')->default(0);
            $table->unsignedInteger('progress_total')->default(0);
            $table->unsignedTinyInteger('progress_percent')->default(0);
            $table->longText('summary_json')->nullable();
            $table->longText('options_json')->nullable();
            $table->longText('search_text')->nullable();
            $table->text('last_message')->nullable();
            $table->longText('error_message')->nullable();
            $table->dateTime('heartbeat_at')->nullable();
            $table->dateTime('started_at')->nullable();
            $table->dateTime('completed_at')->nullable();
            $table->dateTime('failed_at')->nullable();
            $table->dateTime('reverted_at')->nullable();
            $table->timestamps();
            $table->unique(['business_id', 'importer_key', 'source_key'], 'user_import_source_key_unique');
        });
        Schema::create('solar_import_batches', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('business_id')->index();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('user_import_batch_id')->nullable()->unique();
            $table->string('file_name')->nullable();
            $table->string('file_hash', 64)->nullable();
            $table->string('file_path')->nullable();
            $table->string('status', 20)->default('preview');
            $table->string('operation', 20)->default('preview');
            $table->string('process_state', 20)->default('completed');
            $table->string('stage', 30)->nullable();
            $table->unsignedInteger('progress_current')->default(0);
            $table->unsignedInteger('progress_total')->default(0);
            $table->unsignedTinyInteger('progress_percent')->default(0);
            $table->string('progress_message')->nullable();
            $table->json('counts')->nullable();
            $table->json('opening_snapshot')->nullable();
            $table->json('date_overrides')->nullable();
            $table->json('reference_mappings')->nullable();
            $table->json('source_sheets')->nullable();
            $table->json('preview_warnings')->nullable();
            $table->text('error_message')->nullable();
            $table->dateTime('heartbeat_at')->nullable();
            $table->dateTime('started_at')->nullable();
            $table->dateTime('completed_at')->nullable();
            $table->dateTime('failed_at')->nullable();
            $table->dateTime('cancel_requested_at')->nullable();
            $table->dateTime('confirmed_at')->nullable();
            $table->dateTime('rolled_back_at')->nullable();
            $table->timestamps();
        });
        Schema::create('solar_import_batch_events', function (Blueprint $table) {
            $table->uuid('batch_id');
            $table->unsignedBigInteger('business_id');
            $table->unsignedInteger('sequence');
            $table->string('stage');
            $table->string('level', 12)->default('info');
            $table->text('message');
            $table->unsignedTinyInteger('progress_percent')->nullable();
            $table->unsignedInteger('progress_current')->nullable();
            $table->unsignedInteger('progress_total')->nullable();
            $table->json('context')->nullable();
            $table->dateTime('created_at');
            $table->unique(['batch_id', 'sequence']);
        });
        Schema::create('solar_import_preview_rows', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->uuid('batch_id');
            $table->unsignedBigInteger('business_id');
            $table->string('ledger', 20);
            $table->unsignedInteger('source_row')->nullable();
            $table->text('raw_date')->nullable();
            $table->date('normalized_date')->nullable();
            $table->string('status', 30);
            $table->string('matched_by', 30)->nullable();
            $table->boolean('balance_only')->default(false);
            $table->json('differences')->nullable();
            $table->json('excel_payload')->nullable();
            $table->json('app_payload')->nullable();
            $table->boolean('is_importable')->default(false);
            $table->timestamps();
        });

        foreach (['solar_stock', 'solar_pool', 'solar_mess'] as $ledger) {
            Schema::create($ledger, function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('business_id');
                $table->string('source')->nullable();
                $table->string('source_sheet')->nullable();
                $table->unsignedInteger('source_row')->nullable();
                $table->string('source_hash')->nullable();
                $table->string('source_key')->nullable();
                $table->uuid('import_batch_id')->nullable();
                $table->date('tanggal')->nullable();
                $table->decimal('stock_in', 16, 4)->default(0);
                $table->decimal('out_actual', 16, 4)->default(0);
                $table->decimal('stock_actual', 16, 4)->default(0);
                $table->decimal('out_meteran', 16, 4)->default(0);
                $table->decimal('stock_meteran', 16, 4)->default(0);
                $table->decimal('qty_in', 16, 4)->default(0);
                $table->decimal('qty_out', 16, 4)->default(0);
                $table->decimal('current_stock', 16, 4)->default(0);
                $table->string('unit')->nullable();
                $table->string('operator')->nullable();
                $table->text('keterangan')->nullable();
                $table->timestamps();
            });
        }
        Schema::create('solar_equipment_usages', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('business_id');
            $table->uuid('import_batch_id')->nullable();
            $table->date('usage_date')->nullable();
            $table->unsignedBigInteger('asset_id')->nullable();
            $table->string('equipment_name');
            $table->decimal('quantity_gln', 16, 4)->default(0);
            $table->timestamps();
        });
        Schema::create('solar_opening_balances', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('business_id');
            $table->string('ledger', 20);
            $table->date('effective_date')->nullable();
            $table->decimal('actual_balance', 16, 4)->nullable();
            $table->decimal('meter_balance', 16, 4)->nullable();
            $table->decimal('quantity_balance', 16, 4)->nullable();
            $table->timestamps();
        });
    }

    private function solarSummary(): array
    {
        return [
            'only_excel' => 1,
            'stock' => 1,
            'pool' => 0,
            'mess' => 0,
            'usage' => 0,
            'sheets' => [['ledger' => 'stock', 'total' => 1]],
            'warnings' => [],
        ];
    }

    private function workbook(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'solar-source-');
        @unlink($path);
        $path .= '.xlsx';
        $workbook = new Spreadsheet();
        $workbook->getActiveSheet()->setTitle('Stock');
        $workbook->getActiveSheet()->fromArray([['Keterangan'], ['Sumber Solar']], null, 'A1');
        $notes = $workbook->createSheet();
        $notes->setTitle('Catatan');
        $notes->setCellValue('A1', 'Sheet kedua');
        (new Xlsx($workbook))->save($path);
        $workbook->disconnectWorksheets();

        return $path;
    }

    private function domainBatch(string $id, int $businessId, string $fileName, string $status, $createdAt): void
    {
        DB::table('solar_import_batches')->insert([
            'id' => $id,
            'business_id' => $businessId,
            'created_by' => 999,
            'file_name' => $fileName,
            'file_hash' => hash('sha256', $fileName),
            'status' => $status,
            'operation' => 'import',
            'process_state' => 'completed',
            'stage' => 'finished',
            'progress_percent' => 100,
            'progress_message' => 'Import selesai.',
            'counts' => json_encode(['stock' => 1, 'pool' => 0, 'mess' => 0, 'usage' => 0]),
            'opening_snapshot' => json_encode([]),
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);
    }

    private function linkedSharedBatch(string $domainId, int $businessId, string $fileName, string $status): UserImportBatch
    {
        $shared = UserImportBatch::create([
            'business_id' => $businessId,
            'created_by' => 999,
            'importer_key' => 'solar',
            'source_key' => 'domain:'.$domainId,
            'file_name' => $fileName,
            'status' => $status,
            'stage' => $status,
            'progress_percent' => 100,
            'summary_json' => json_encode(['stock' => 1, 'pool' => 0, 'mess' => 0, 'usage' => 0]),
            'last_message' => 'Import selesai.',
        ]);
        DB::table('solar_import_batches')->where('id', $domainId)->update(['user_import_batch_id' => $shared->id]);

        return $shared;
    }

    private function solarStock(string $batchId, int $businessId, string $date, float $qty): void
    {
        DB::table('solar_stock')->insert([
            'business_id' => $businessId,
            'source' => 'excel',
            'source_sheet' => 'Stock',
            'source_row' => 2,
            'source_hash' => hash('sha256', $batchId),
            'source_key' => $batchId,
            'import_batch_id' => $batchId,
            'tanggal' => $date,
            'stock_in' => $qty,
            'out_actual' => 0,
            'stock_actual' => $qty,
            'out_meteran' => 0,
            'stock_meteran' => $qty,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function asBusiness(int $businessId): self
    {
        $user = new class extends \Illuminate\Foundation\Auth\User
        {
            public function can($ability, $arguments = [])
            {
                return true;
            }
        };
        $user->forceFill([
            'id' => 999,
            'business_id' => $businessId,
            'first_name' => 'Solar Admin',
            'user_type' => 'user',
            'allow_login' => 1,
        ]);

        return $this->withHeader('Accept', 'application/json')
            ->actingAs($user)
            ->withSession([
                'user' => ['id' => 999, 'business_id' => $businessId],
                'business' => ['currency_symbol_placement' => 'before', 'currency_precision' => 2],
                'currency' => ['symbol' => 'Rp', 'decimal_separator' => ',', 'thousand_separator' => '.'],
            ]);
    }
}
