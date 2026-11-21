<?php

namespace Tests\Feature;

use App\Services\UserImportBatchService;
use App\UserImportBatch;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Modules\AssetManagement\Entities\HeavyEquipmentRateBatch;
use Modules\AssetManagement\Jobs\AnalyzeHeavyEquipmentRateImportJob;
use Modules\AssetManagement\Jobs\ProcessHeavyEquipmentRateImportJob;
use Modules\AssetManagement\Services\HeavyEquipmentRateImportHistoryService;
use Modules\AssetManagement\Services\HeavyEquipmentRateService;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class HeavyEquipmentRateImportLifecycleTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (! in_array('sqlite', \PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('PDO SQLite is required for isolated Heavy Equipment rate import tests.');
        }

        config([
            'database.default' => 'heavy_equipment_rate_test',
            'database.connections.heavy_equipment_rate_test' => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
                'foreign_key_constraints' => false,
            ],
            'cache.default' => 'array',
        ]);
        DB::purge('heavy_equipment_rate_test');
        DB::setDefaultConnection('heavy_equipment_rate_test');
        Storage::fake('local');
        $this->withoutMiddleware([
            \App\Http\Middleware\SetSessionData::class,
            \App\Http\Middleware\AdminSidebarMenu::class,
        ]);
        $this->createSchemas();
    }

    public function test_upload_is_queued_and_analysis_keeps_a_recoverable_source_snapshot(): void
    {
        Bus::fake();
        DB::table('assets')->insert(['id' => 101, 'business_id' => 7, 'name' => 'BM 9133', 'asset_code' => 'BM 9133']);
        $workbookPath = $this->workbook([
            ['No', 'Rute', 'Unit', 'Borongan Supir/Ton', 'Uang Jalan', 'Harga/Ton', 'Keterangan'],
            [1, 'CI Rate Route', 'BM 9133', 25000, 50000, 75000, 'tarif sumber'],
            [2, '', 'BM 9133', 25000, 50000, 75000, 'baris tidak valid'],
        ]);

        try {
            $response = $this->actingAs($this->admin())
                ->withSession($this->businessSession(7))
                ->post(route('asset.fleet.rates.upload'), [
                    'file' => new UploadedFile($workbookPath, 'rates-ci.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', UPLOAD_ERR_OK, true),
                    'effective_from' => '2026-11-01',
                ]);

            $response->assertStatus(202)->assertJsonPath('success', true)->assertJsonPath('queued', true);
            $batchId = (int) $response->json('batch_id');
            $batch = UserImportBatch::findOrFail($batchId);
            $stagingPath = $batch->staging_path;
            $this->assertSame('heavy_equipment_rate', $batch->importer_key);
            $this->assertSame('queued_preview', $batch->status);
            $this->assertTrue(Storage::disk('local')->exists($stagingPath));
            Bus::assertDispatched(AnalyzeHeavyEquipmentRateImportJob::class);

            app(HeavyEquipmentRateImportHistoryService::class)->processPreview($batchId);

            $batch->refresh();
            $this->assertSame('preview_ready', $batch->status);
            $this->assertTrue($batch->hasSourceSnapshot());
            $this->assertNull($batch->staging_path);
            Storage::disk('local')->assertMissing($stagingPath);
            $this->assertDatabaseHas('heavy_equipment_rate_batches', [
                'user_import_batch_id' => $batchId,
                'business_id' => 7,
                'status' => 'draft',
            ]);

            $history = $this->actingAs($this->admin())
                ->withSession($this->businessSession(7))
                ->get(route('asset.fleet.rates.import.batches.index'));
            $history->assertOk()->assertJsonPath('recordsTotal', 1)
                ->assertJsonPath('data.0.can_confirm', true)
                ->assertJsonPath('data.0.can_download', true)
                ->assertJsonPath('data.0.error_message', 'Baris 3: Rute dan Unit wajib diisi.');

            $details = $this->actingAs($this->admin())
                ->withSession($this->businessSession(7))
                ->get(route('asset.fleet.rates.import.batches.details', $batchId));
            $details->assertOk()->assertJsonPath('batch.status', 'preview_ready')
                ->assertJsonPath('rows.0.route', 'CI Rate Route')
                ->assertJsonPath('rows.0.units.0.asset', 'BM 9133')
                ->assertJsonPath('rows.1.errors.0.message', 'Rute dan Unit wajib diisi.');

            $filteredHistory = $this->actingAs($this->admin())
                ->withSession($this->businessSession(7))
                ->get(route('asset.fleet.rates.import.batches.index', ['status' => 'failed']));
            $filteredHistory->assertOk()->assertJsonPath('recordsFiltered', 0);

            $download = $this->actingAs($this->admin())
                ->withSession($this->businessSession(7))
                ->get(route('asset.fleet.rates.import.batches.download', $batchId));
            $download->assertOk()->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
            $restoredPath = tempnam(sys_get_temp_dir(), 'rate-restored-');
            file_put_contents($restoredPath, $download->streamedContent());
            $restored = \PhpOffice\PhpSpreadsheet\IOFactory::load($restoredPath);
            $this->assertSame('CI Rate Route', $restored->getActiveSheet()->getCell('B2')->getValue());
            $this->assertSame('tarif sumber', $restored->getActiveSheet()->getCell('G2')->getValue());
            $restored->disconnectWorksheets();
            @unlink($restoredPath);

            $this->actingAs($this->admin())
                ->withSession($this->businessSession(8))
                ->get(route('asset.fleet.rates.import.batches.download', $batchId))
                ->assertNotFound();
        } finally {
            @unlink($workbookPath);
        }
    }

    public function test_failed_analysis_remains_in_searchable_history_and_cleans_staging(): void
    {
        Bus::fake();
        $workbookPath = $this->workbook([['kolom tidak cocok', 'data']]);

        try {
            $response = $this->actingAs($this->admin())
                ->withSession($this->businessSession(7))
                ->post(route('asset.fleet.rates.upload'), [
                    'file' => new UploadedFile($workbookPath, 'bad-rate.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', UPLOAD_ERR_OK, true),
                    'effective_from' => '2026-11-01',
                ]);
            $response->assertStatus(202);
            $batch = UserImportBatch::findOrFail((int) $response->json('batch_id'));
            $stagingPath = $batch->staging_path;

            app(HeavyEquipmentRateImportHistoryService::class)->processPreview((int) $batch->id);

            $batch->refresh();
            $this->assertSame('failed', $batch->status);
            $this->assertNull($batch->staging_path);
            $this->assertNotEmpty($batch->error_message);
            Storage::disk('local')->assertMissing($stagingPath);

            $history = $this->actingAs($this->admin())
                ->withSession($this->businessSession(7))
                ->get(route('asset.fleet.rates.import.batches.index', ['status' => 'failed', 'search' => ['value' => 'Header Excel tidak ditemukan']]));
            $history->assertOk()->assertJsonPath('recordsFiltered', 1)
                ->assertJsonPath('data.0.status', 'failed');
        } finally {
            @unlink($workbookPath);
        }
    }

    public function test_confirm_runs_once_in_queue_and_revert_restores_prior_rate_state(): void
    {
        Bus::fake();
        $routeId = DB::table('heavy_equipment_routes')->insertGetId([
            'business_id' => 7,
            'name' => 'Rute Test',
            'is_active' => 1,
            'created_by' => 999,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('assets')->insert(['id' => 101, 'business_id' => 7, 'name' => 'BM 9133', 'asset_code' => 'BM 9133']);
        $previousBatch = HeavyEquipmentRateBatch::create([
            'business_id' => 7,
            'file_name' => 'Manual - prior rate',
            'effective_from' => '2026-01-01',
            'status' => 'active',
            'uploaded_by' => 999,
        ]);
        $previousRateId = $this->rate($previousBatch->id, $routeId, 101, '2026-01-01', 'active');

        $shared = $this->sharedBatch(['status' => 'preview_ready', 'file_name' => 'rate-confirm.xlsx']);
        $rateBatch = HeavyEquipmentRateBatch::create([
            'user_import_batch_id' => $shared->id,
            'business_id' => 7,
            'file_name' => 'rate-confirm.xlsx',
            'effective_from' => '2026-11-01',
            'status' => 'draft',
            'source_row_count' => 1,
            'valid_row_count' => 1,
            'uploaded_by' => 999,
        ]);
        $rateId = $this->rate($rateBatch->id, $routeId, 101, '2026-11-01', 'draft');

        $confirm = $this->actingAs($this->admin())
            ->withSession($this->businessSession(7))
            ->post(route('asset.fleet.rates.import.batches.confirm', $shared->id));
        $confirm->assertStatus(202)->assertJsonPath('queued', true);
        Bus::assertDispatched(ProcessHeavyEquipmentRateImportJob::class);
        $this->assertSame('queued_import', $shared->fresh()->status);

        $duplicateConfirm = $this->actingAs($this->admin())
            ->withSession($this->businessSession(7))
            ->post(route('asset.fleet.rates.import.batches.confirm', $shared->id));
        $duplicateConfirm->assertStatus(409);
        Bus::assertDispatchedTimes(ProcessHeavyEquipmentRateImportJob::class, 1);

        $crossBusinessConfirm = $this->actingAs($this->admin())
            ->withSession($this->businessSession(8))
            ->post(route('asset.fleet.rates.import.batches.confirm', $shared->id));
        $crossBusinessConfirm->assertNotFound();

        app(HeavyEquipmentRateImportHistoryService::class)->processImport((int) $shared->id);
        $this->assertSame('imported', $shared->fresh()->status);
        $this->assertDatabaseHas('heavy_equipment_route_rates', [
            'id' => $previousRateId,
            'status' => 'expired',
        ]);
        $effectiveTo = DB::table('heavy_equipment_route_rates')->where('id', $previousRateId)->value('effective_to');
        $this->assertSame('2026-10-31', \Carbon\Carbon::parse($effectiveTo)->toDateString());
        $this->assertDatabaseHas('heavy_equipment_route_rates', ['id' => $rateId, 'status' => 'active']);

        $revert = $this->actingAs($this->admin())
            ->withSession($this->businessSession(7))
            ->post(route('asset.fleet.rates.import.batches.revert', $shared->id));
        $revert->assertOk()->assertJsonPath('success', true);
        $this->assertSame('reverted', $shared->fresh()->status);
        $this->assertDatabaseHas('heavy_equipment_route_rates', ['id' => $previousRateId, 'status' => 'active', 'effective_to' => null]);
        $this->assertDatabaseHas('heavy_equipment_route_rates', ['id' => $rateId, 'status' => 'cancelled']);
    }

    public function test_revert_blocks_changed_or_used_imported_rates_and_legacy_batches_without_snapshot(): void
    {
        $routeId = DB::table('heavy_equipment_routes')->insertGetId([
            'business_id' => 7,
            'name' => 'Rute Guard',
            'is_active' => 1,
            'created_by' => 999,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('assets')->insert(['id' => 101, 'business_id' => 7, 'name' => 'BM 9133', 'asset_code' => 'BM 9133']);
        $shared = $this->sharedBatch(['status' => 'preview_ready', 'file_name' => 'rate-guard.xlsx']);
        $rateBatch = HeavyEquipmentRateBatch::create([
            'user_import_batch_id' => $shared->id,
            'business_id' => 7,
            'file_name' => 'rate-guard.xlsx',
            'effective_from' => '2026-11-01',
            'status' => 'draft',
            'uploaded_by' => 999,
        ]);
        $rateId = $this->rate($rateBatch->id, $routeId, 101, '2026-11-01', 'draft');
        app(HeavyEquipmentRateService::class)->confirm($rateBatch);
        DB::table('heavy_equipment_route_rates')->where('id', $rateId)->update(['driver_rate_per_ton' => 90000]);

        $response = $this->actingAs($this->admin())
            ->withSession($this->businessSession(7))
            ->post(route('asset.fleet.rates.import.batches.revert', $shared->id));
        $response->assertStatus(409)->assertJsonPath('success', false);
        $this->assertDatabaseHas('heavy_equipment_route_rates', ['id' => $rateId, 'status' => 'active']);

        $legacyBatch = HeavyEquipmentRateBatch::create([
            'business_id' => 7,
            'file_name' => 'legacy-rates.xlsx',
            'effective_from' => '2025-01-01',
            'status' => 'active',
            'uploaded_by' => 999,
        ]);
        $legacy = app(HeavyEquipmentRateImportHistoryService::class)->ensureHistoryEnvelope($legacyBatch);
        $history = $this->actingAs($this->admin())
            ->withSession($this->businessSession(7))
            ->get(route('asset.fleet.rates.import.batches.index'));
        $history->assertOk()->assertJsonPath('data.1.can_revert', false)
            ->assertJsonPath('data.1.has_snapshot', false);

        $legacyResponse = $this->actingAs($this->admin())
            ->withSession($this->businessSession(7))
            ->post(route('asset.fleet.rates.import.batches.revert', $legacy->id));
        $legacyResponse->assertStatus(409);
        $this->assertDatabaseHas('heavy_equipment_rate_batches', ['id' => $legacyBatch->id, 'status' => 'active']);
    }

    public function test_revert_is_blocked_when_an_imported_rate_has_been_used_by_a_trip(): void
    {
        $routeId = DB::table('heavy_equipment_routes')->insertGetId([
            'business_id' => 7,
            'name' => 'Rute Terpakai',
            'is_active' => 1,
            'created_by' => 999,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('assets')->insert(['id' => 101, 'business_id' => 7, 'name' => 'BM 9133', 'asset_code' => 'BM 9133']);
        $shared = $this->sharedBatch(['status' => 'queued_import', 'file_name' => 'rate-used.xlsx']);
        $rateBatch = HeavyEquipmentRateBatch::create([
            'user_import_batch_id' => $shared->id,
            'business_id' => 7,
            'file_name' => 'rate-used.xlsx',
            'effective_from' => '2026-11-01',
            'status' => 'draft',
            'source_row_count' => 1,
            'valid_row_count' => 1,
            'uploaded_by' => 999,
        ]);
        $rateId = $this->rate($rateBatch->id, $routeId, 101, '2026-11-01', 'draft');

        app(HeavyEquipmentRateImportHistoryService::class)->processImport((int) $shared->id);
        DB::table('heavy_equipment_trips')->insert(['route_rate_id' => $rateId, 'trip_no' => 'SPB-USED-001']);

        $response = $this->actingAs($this->admin())
            ->withSession($this->businessSession(7))
            ->post(route('asset.fleet.rates.import.batches.revert', $shared->id));
        $response->assertStatus(409)->assertJsonPath('success', false);
        $this->assertDatabaseHas('heavy_equipment_route_rates', ['id' => $rateId, 'status' => 'active']);
        $this->assertSame('imported', $shared->fresh()->status);
    }

    public function test_failed_activation_can_be_retried_once_only_when_preview_payloads_remain(): void
    {
        Bus::fake();
        $shared = $this->sharedBatch([
            'status' => 'failed',
            'stage' => 'failed',
            'file_name' => 'rate-retry.xlsx',
            'progress_total' => 1,
            'error_message' => 'Gagal mengaktifkan tarif.',
        ]);
        app(UserImportBatchService::class)->storePayload($shared, 'preview', ['rate_batch_id' => 11, 'source_rows' => 1]);
        app(UserImportBatchService::class)->storePayload($shared, 'source_snapshot', ['sheets' => [['title' => 'Rates', 'rows' => [1 => ['A' => 'Rute']]]]]);
        HeavyEquipmentRateBatch::create([
            'user_import_batch_id' => $shared->id,
            'business_id' => 7,
            'file_name' => 'rate-retry.xlsx',
            'effective_from' => '2026-11-01',
            'status' => 'draft',
            'source_row_count' => 1,
            'uploaded_by' => 999,
        ]);

        $crossBusinessRetry = $this->actingAs($this->admin())
            ->withSession($this->businessSession(8))
            ->post(route('asset.fleet.rates.import.batches.retry', $shared->id));
        $crossBusinessRetry->assertNotFound();

        $response = $this->actingAs($this->admin())
            ->withSession($this->businessSession(7))
            ->post(route('asset.fleet.rates.import.batches.retry', $shared->id));
        $response->assertStatus(202)->assertJsonPath('queued', true);
        Bus::assertDispatchedTimes(ProcessHeavyEquipmentRateImportJob::class, 1);
        $this->assertSame('queued_import', $shared->fresh()->status);

        $duplicate = $this->actingAs($this->admin())
            ->withSession($this->businessSession(7))
            ->post(route('asset.fleet.rates.import.batches.retry', $shared->id));
        $duplicate->assertStatus(409);
        Bus::assertDispatchedTimes(ProcessHeavyEquipmentRateImportJob::class, 1);
    }

    private function createSchemas(): void
    {
        Schema::create('user_import_batches', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedInteger('business_id');
            $table->unsignedInteger('created_by')->nullable();
            $table->string('importer_key', 64);
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
            $table->text('last_message')->nullable();
            $table->longText('error_message')->nullable();
            $table->dateTime('heartbeat_at')->nullable();
            $table->dateTime('started_at')->nullable();
            $table->dateTime('completed_at')->nullable();
            $table->dateTime('failed_at')->nullable();
            $table->dateTime('reverted_at')->nullable();
            $table->timestamps();
        });
        Schema::create('heavy_equipment_rate_batches', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedBigInteger('user_import_batch_id')->nullable();
            $table->unsignedInteger('business_id');
            $table->string('file_name');
            $table->string('source_hash', 64)->nullable();
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->string('status', 20)->default('draft');
            $table->unsignedInteger('source_row_count')->default(0);
            $table->unsignedInteger('valid_row_count')->default(0);
            $table->unsignedInteger('unit_count')->default(0);
            $table->unsignedInteger('route_count')->default(0);
            $table->text('error_message')->nullable();
            $table->unsignedInteger('uploaded_by')->nullable();
            $table->dateTime('imported_at')->nullable();
            $table->longText('revert_snapshot_json')->nullable();
            $table->timestamps();
        });
        Schema::create('heavy_equipment_routes', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('business_id');
            $table->string('name');
            $table->string('origin')->nullable();
            $table->string('destination')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('created_by')->nullable();
            $table->timestamps();
        });
        Schema::create('heavy_equipment_route_rates', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('business_id');
            $table->unsignedInteger('route_id');
            $table->unsignedInteger('batch_id');
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->decimal('driver_rate_per_ton', 22, 4)->default(0);
            $table->decimal('travel_allowance', 22, 4)->default(0);
            $table->decimal('sale_rate_per_ton', 22, 4)->nullable();
            $table->unsignedInteger('source_row')->nullable();
            $table->string('source_unit_text')->nullable();
            $table->string('source_status', 20)->default('imported');
            $table->dateTime('source_modified_at')->nullable();
            $table->unsignedInteger('source_modified_by')->nullable();
            $table->text('notes')->nullable();
            $table->string('status', 20)->default('draft');
            $table->timestamps();
        });
        Schema::create('heavy_equipment_rate_units', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('route_rate_id');
            $table->unsignedInteger('asset_id')->nullable();
            $table->string('unit_label');
            $table->timestamps();
        });
        Schema::create('heavy_equipment_trips', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('route_rate_id')->nullable();
            $table->string('trip_no');
        });
        Schema::create('assets', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('business_id');
            $table->string('name');
            $table->string('asset_code')->nullable();
        });
        Schema::create('users', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('business_id')->nullable();
            $table->string('first_name')->nullable();
        });
    }

    private function sharedBatch(array $attributes = []): UserImportBatch
    {
        return UserImportBatch::create(array_merge([
            'business_id' => 7,
            'created_by' => 999,
            'importer_key' => 'heavy_equipment_rate',
            'file_name' => 'rate-test.xlsx',
            'status' => 'preview_ready',
            'stage' => 'preview_ready',
            'last_message' => 'Preview siap.',
        ], $attributes));
    }

    private function rate(int $batchId, int $routeId, int $assetId, string $effectiveFrom, string $status): int
    {
        $rateId = (int) DB::table('heavy_equipment_route_rates')->insertGetId([
            'business_id' => 7,
            'route_id' => $routeId,
            'batch_id' => $batchId,
            'effective_from' => $effectiveFrom,
            'driver_rate_per_ton' => 25000,
            'travel_allowance' => 50000,
            'sale_rate_per_ton' => 75000,
            'source_row' => 2,
            'source_unit_text' => 'BM 9133',
            'source_status' => 'imported',
            'notes' => 'fixture',
            'status' => $status,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('heavy_equipment_rate_units')->insert([
            'route_rate_id' => $rateId,
            'asset_id' => $assetId,
            'unit_label' => 'BM 9133',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $rateId;
    }

    private function workbook(array $rows): string
    {
        $path = tempnam(sys_get_temp_dir(), 'rate-source-').'.xlsx';
        $spreadsheet = new Spreadsheet();
        $spreadsheet->getActiveSheet()->fromArray($rows, null, 'A1');
        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();

        return $path;
    }

    private function admin(): \Illuminate\Foundation\Auth\User
    {
        $admin = new class extends \Illuminate\Foundation\Auth\User
        {
            public function can($ability, $arguments = [])
            {
                return $ability === 'superadmin';
            }

            public function hasRole($roles, $guard = null): bool
            {
                return true;
            }
        };
        $admin->forceFill(['id' => 999, 'business_id' => 7, 'first_name' => 'Test', 'user_type' => 'user']);

        return $admin;
    }

    private function businessSession(int $businessId): array
    {
        return [
            'user' => ['business_id' => $businessId],
            'business' => ['currency_symbol_placement' => 'before', 'currency_precision' => 2],
            'currency' => ['symbol' => 'Rp', 'decimal_separator' => ',', 'thousand_separator' => '.'],
        ];
    }
}
