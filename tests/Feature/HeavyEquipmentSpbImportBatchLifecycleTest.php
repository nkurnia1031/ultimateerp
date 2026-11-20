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
use Modules\AssetManagement\Entities\HeavyEquipmentTrip;
use Modules\AssetManagement\Jobs\ProcessHeavyEquipmentSpbImportJob;
use Modules\AssetManagement\Jobs\ProcessHeavyEquipmentSpbPreviewJob;
use Modules\AssetManagement\Services\HeavyEquipmentSpbImportBatchService;
use Modules\AssetManagement\Services\HeavyEquipmentSpbImportService;
use Modules\AssetManagement\Services\HeavyEquipmentTripService;
use Tests\TestCase;

class HeavyEquipmentSpbImportBatchLifecycleTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(\App\Http\Middleware\AdminSidebarMenu::class);

        if (! in_array('sqlite', \PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('PDO SQLite is required for isolated SPB import batch tests.');
        }

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite' => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
                'foreign_key_constraints' => false,
            ],
            'cache.default' => 'array',
        ]);
        DB::purge('sqlite');
        DB::setDefaultConnection('sqlite');
        Storage::fake('local');

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
        Schema::create('heavy_equipment_trips', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('business_id');
            $table->unsignedBigInteger('import_batch_id')->nullable();
            $table->string('trip_no');
            $table->string('ticket_no')->nullable();
            $table->string('source_fingerprint')->nullable();
            $table->unsignedInteger('route_id')->nullable();
            $table->string('status')->default('draft');
            $table->string('invoice_status')->default('unbilled');
            $table->string('payroll_status')->default('unsettled');
            $table->string('notes')->nullable();
            $table->timestamps();
        });
        Schema::create('assets', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('business_id');
            $table->string('asset_code')->nullable();
            $table->string('name');
        });
        Schema::create('users', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('business_id')->nullable();
            $table->string('surname')->nullable();
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('username')->nullable();
            $table->string('user_type')->nullable();
            $table->softDeletes();
        });
        Schema::create('heavy_equipment_trip_billings', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('trip_id');
        });
        Schema::create('heavy_equipment_driver_settlements', function (Blueprint $table) {
            $table->increments('id');
        });
        Schema::create('heavy_equipment_settlement_trips', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('trip_id');
            $table->unsignedInteger('settlement_id');
        });
        Schema::create('heavy_equipment_routes', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('business_id');
            $table->string('name');
            $table->unsignedBigInteger('source_import_batch_id')->nullable();
            $table->timestamps();
        });
        Schema::create('heavy_equipment_route_rates', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('route_id');
        });
        Schema::create('heavy_equipment_vendor_loadings', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('loading_document_id');
            $table->string('loading_no');
        });
        Schema::create('heavy_equipment_vendor_loading_documents', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('business_id');
            $table->unsignedInteger('route_id')->nullable();
        });
    }

    public function test_ajax_upload_creates_business_scoped_queued_batch_and_dispatches_preview_job(): void
    {
        Bus::fake();
        $response = $this->withHeader('Accept', 'application/json')
            ->actingAs($this->admin())
            ->withSession($this->businessSession(7))
            ->post(route('asset.fleet.trips.import.preview'), [
                'file' => UploadedFile::fake()->createWithContent('spb.csv', "No Polisi,No Tiket,Tanggal,Netto,Harga,Keterangan\nBM 9133,TK-1,2026-11-01,10,1000,Baginda\n"),
            ]);

        $response->assertStatus(202)->assertJsonPath('batch.status', 'queued_preview');
        $batchId = (int) $response->json('batch.id');
        $batch = UserImportBatch::findOrFail($batchId);
        $this->assertSame(7, (int) $batch->business_id);
        $this->assertSame('spb', $batch->importer_key);
        $this->assertTrue(Storage::disk('local')->exists($batch->staging_path));
        Bus::assertDispatched(ProcessHeavyEquipmentSpbPreviewJob::class);

        DB::table('assets')->insert(['business_id' => 7, 'asset_code' => 'BM 9133', 'name' => 'BM 9133']);
        DB::table('users')->insert(['business_id' => 7, 'first_name' => 'Baginda', 'last_name' => '', 'surname' => '', 'username' => '', 'user_type' => 'user']);
        $this->previewBatchService()->processPreview($batchId);
        $batch->refresh();
        $this->assertSame('preview_ready', $batch->status);
        $this->assertNull($batch->staging_path);
        $this->assertTrue($batch->hasSourceSnapshot());
        $this->assertSame(1, (int) json_decode($batch->summary_json, true)['source_rows']);
    }

    public function test_batch_status_and_history_are_scoped_to_the_active_business(): void
    {
        $visible = $this->batch(['business_id' => 7, 'status' => 'analyzing']);
        $this->batch(['business_id' => 8, 'status' => 'imported']);

        $this->withHeader('Accept', 'application/json')
            ->actingAs($this->admin())
            ->withSession($this->businessSession(7))
            ->get(route('asset.fleet.trips.import.batches.index'))
            ->assertOk()
            ->assertJsonPath('recordsTotal', 1)
            ->assertJsonPath('data.0.id', $visible->id);

        $this->withHeader('Accept', 'application/json')
            ->actingAs($this->admin())
            ->withSession($this->businessSession(8))
            ->get(route('asset.fleet.trips.import.batches.status', $visible->id))
            ->assertNotFound();
    }

    public function test_confirm_queues_the_durable_preview_and_updates_the_same_batch(): void
    {
        Bus::fake();
        $batch = $this->batch(['business_id' => 7, 'status' => 'preview_ready']);
        $preview = ['rows' => [], 'summary' => ['source_rows' => 3, 'ready_rows' => 3, 'error_rows' => 0]];
        app(UserImportBatchService::class)->storePayload($batch, 'preview', $preview);
        $token = '00000000-0000-4000-8000-000000000058';
        \Illuminate\Support\Facades\Cache::put('heavy_equipment_spb_import:999:'.$token, [
            'business_id' => 7,
            'user_id' => 999,
            'batch_id' => $batch->id,
            'preview' => $preview,
        ], now()->addHour());

        $this->withHeader('Accept', 'application/json')
            ->actingAs($this->admin())
            ->withSession($this->businessSession(7))
            ->post(route('asset.fleet.trips.import.confirm'), ['token' => $token])
            ->assertStatus(202)
            ->assertJsonPath('batch_id', $batch->id);

        $this->assertSame('queued_import', $batch->fresh()->status);
        $this->assertSame(3, (int) $batch->fresh()->progress_total);
        Bus::assertDispatched(ProcessHeavyEquipmentSpbImportJob::class);
    }

    public function test_source_snapshot_download_rebuilds_a_logical_xlsx_without_staging_binary(): void
    {
        $batch = $this->batch(['business_id' => 7, 'file_name' => 'rekap-spb.xls', 'status' => 'preview_ready']);
        app(UserImportBatchService::class)->storePayload($batch, 'source_snapshot', [
            'sheets' => [[
                'title' => 'BMT',
                'rows' => [
                    1 => ['A' => 'No Tiket', 'B' => 'Tonase'],
                    2 => ['A' => 'TK-1', 'B' => 1.5],
                ],
            ]],
        ]);

        $response = $this->actingAs($this->admin())
            ->withSession($this->businessSession(7))
            ->get(route('asset.fleet.trips.import.batches.download', $batch->id));

        $response->assertOk()->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $path = tempnam(sys_get_temp_dir(), 'spb-restored-');
        file_put_contents($path, $response->streamedContent());
        $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($path);
        $this->assertSame('TK-1', $spreadsheet->getActiveSheet()->getCell('A2')->getValue());
        $this->assertSame(1.5, $spreadsheet->getActiveSheet()->getCell('B2')->getValue());
        $spreadsheet->disconnectWorksheets();
        @unlink($path);
        $this->assertNull($batch->fresh()->staging_path);

        $this->withHeader('Accept', 'application/json')
            ->actingAs($this->admin())
            ->withSession($this->businessSession(8))
            ->get(route('asset.fleet.trips.import.batches.download', $batch->id))
            ->assertNotFound();
    }

    public function test_revert_removes_unchanged_draft_trips_and_refuses_changed_trips_atomically(): void
    {
        $unchangedBatch = $this->batch(['business_id' => 7, 'status' => 'imported']);
        $trip = $this->trip($unchangedBatch->id, 'SPB-1');
        $this->saveImportedResult($unchangedBatch, $trip);
        $this->withHeader('Accept', 'application/json')
            ->actingAs($this->admin())
            ->withSession($this->businessSession(7))
            ->post(route('asset.fleet.trips.import.batches.revert', $unchangedBatch->id))
            ->assertOk()
            ->assertJsonPath('success', true);
        $this->assertDatabaseMissing('heavy_equipment_trips', ['id' => $trip->id]);
        $this->assertSame('reverted', $unchangedBatch->fresh()->status);

        $changedBatch = $this->batch(['business_id' => 7, 'status' => 'partial']);
        $changedTrip = $this->trip($changedBatch->id, 'SPB-2');
        $this->saveImportedResult($changedBatch, $changedTrip);
        $changedTrip->notes = 'Diubah setelah import';
        $changedTrip->save();

        $this->withHeader('Accept', 'application/json')
            ->actingAs($this->admin())
            ->withSession($this->businessSession(7))
            ->post(route('asset.fleet.trips.import.batches.revert', $changedBatch->id))
            ->assertUnprocessable()
            ->assertJsonFragment(['success' => false]);
        $this->assertDatabaseHas('heavy_equipment_trips', ['id' => $changedTrip->id]);
        $this->assertSame('partial', $changedBatch->fresh()->status);
    }

    public function test_revert_removes_a_route_created_by_the_batch_even_after_result_recovery(): void
    {
        $batch = $this->batch(['business_id' => 7, 'status' => 'imported']);
        $route = \Modules\AssetManagement\Entities\HeavyEquipmentRoute::create([
            'business_id' => 7,
            'name' => 'Rute Impor',
            'source_import_batch_id' => $batch->id,
        ]);
        $trip = $this->trip($batch->id, 'SPB-ROUTE-1');
        $trip->route_id = $route->id;
        $trip->save();
        $this->saveImportedResult($batch, $trip);

        $this->batchService()->revert($batch, 7);

        $this->assertDatabaseMissing('heavy_equipment_trips', ['id' => $trip->id]);
        $this->assertDatabaseMissing('heavy_equipment_routes', ['id' => $route->id]);
    }

    public function test_revert_refuses_to_delete_a_route_used_by_vendor_loading(): void
    {
        $batch = $this->batch(['business_id' => 7, 'status' => 'imported']);
        $route = \Modules\AssetManagement\Entities\HeavyEquipmentRoute::create([
            'business_id' => 7,
            'name' => 'Rute Bersama',
            'source_import_batch_id' => $batch->id,
        ]);
        $trip = $this->trip($batch->id, 'SPB-ROUTE-2');
        $trip->route_id = $route->id;
        $trip->save();
        $this->saveImportedResult($batch, $trip);
        $documentId = DB::table('heavy_equipment_vendor_loading_documents')->insertGetId([
            'business_id' => 7,
            'route_id' => $route->id,
        ]);
        DB::table('heavy_equipment_vendor_loadings')->insert([
            'loading_document_id' => $documentId,
            'loading_no' => 'LV-USED-1',
        ]);

        try {
            $this->batchService()->revert($batch, 7);
            $this->fail('Revert harus menolak rute yang sudah dipakai Loading Vendor.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('LV-USED-1', $exception->getMessage());
        }
        $this->assertDatabaseHas('heavy_equipment_trips', ['id' => $trip->id]);
        $this->assertDatabaseHas('heavy_equipment_routes', ['id' => $route->id]);
    }

    private function batch(array $attributes = []): UserImportBatch
    {
        return UserImportBatch::create(array_merge([
            'business_id' => 7,
            'created_by' => 999,
            'importer_key' => 'spb',
            'file_name' => 'test-spb.csv',
            'status' => 'preview_ready',
            'stage' => 'preview_ready',
            'last_message' => 'Preview siap.',
        ], $attributes));
    }

    private function trip(int $batchId, string $tripNo): HeavyEquipmentTrip
    {
        return HeavyEquipmentTrip::create([
            'business_id' => 7,
            'import_batch_id' => $batchId,
            'trip_no' => $tripNo,
            'ticket_no' => $tripNo.'-T',
            'source_fingerprint' => 'source-'.$tripNo,
            'route_id' => null,
            'status' => 'draft',
            'invoice_status' => 'unbilled',
            'payroll_status' => 'unsettled',
        ]);
    }

    private function saveImportedResult(UserImportBatch $batch, HeavyEquipmentTrip $trip): void
    {
        $trip->refresh();
        $attributes = $trip->getAttributes();
        unset($attributes['created_at'], $attributes['updated_at']);
        ksort($attributes);
        $fingerprint = hash('sha256', json_encode($attributes, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION));
        app(UserImportBatchService::class)->storePayload($batch, 'results', [
            'source-'.$trip->trip_no => [
                'status' => 'imported',
                'trip_id' => $trip->id,
                'trip_no' => $trip->trip_no,
                'post_import_fingerprint' => $fingerprint,
            ],
        ]);
    }

    private function batchService(): HeavyEquipmentSpbImportBatchService
    {
        return new HeavyEquipmentSpbImportBatchService(
            \Mockery::mock(HeavyEquipmentSpbImportService::class),
            \Mockery::mock(HeavyEquipmentTripService::class),
            app(UserImportBatchService::class)
        );
    }

    private function previewBatchService(): HeavyEquipmentSpbImportBatchService
    {
        return new HeavyEquipmentSpbImportBatchService(
            app(HeavyEquipmentSpbImportService::class),
            \Mockery::mock(HeavyEquipmentTripService::class),
            app(UserImportBatchService::class)
        );
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
        $admin->forceFill([
            'id' => 999,
            'business_id' => 7,
            'first_name' => 'Test',
            'last_name' => 'Admin',
            'surname' => 'Admin',
            'user_type' => 'user',
            'allow_login' => 1,
        ]);

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
