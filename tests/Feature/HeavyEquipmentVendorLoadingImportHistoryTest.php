<?php

namespace Tests\Feature;

use App\Services\UserImportBatchService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Modules\AssetManagement\Jobs\AnalyzeHeavyEquipmentVendorLoadingImportJob;
use Modules\AssetManagement\Jobs\ProcessHeavyEquipmentVendorLoadingImportJob;
use Modules\AssetManagement\Http\Controllers\HeavyEquipmentVendorLoadingController;
use Modules\AssetManagement\Services\HeavyEquipmentVendorLoadingDocumentService;
use Modules\AssetManagement\Services\HeavyEquipmentVendorLoadingImportHistoryService;
use Modules\AssetManagement\Services\HeavyEquipmentVendorLoadingImportReverseService;
use Modules\AssetManagement\Services\HeavyEquipmentVendorLoadingImportService;
use Modules\AssetManagement\Services\HeavyEquipmentVendorLoadingService;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

class HeavyEquipmentVendorLoadingImportHistoryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (! in_array('sqlite', \PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('PDO SQLite is required for isolated Vendor import history tests.');
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
        Storage::fake('user_imports');
        $this->withoutMiddleware([
            \App\Http\Middleware\SetSessionData::class,
            \App\Http\Middleware\AdminSidebarMenu::class,
            \App\Http\Middleware\Timezone::class,
        ]);

        $this->createSchemas();
        $this->app->instance(HeavyEquipmentVendorLoadingService::class, \Mockery::mock(HeavyEquipmentVendorLoadingService::class));
        $this->app->instance(HeavyEquipmentVendorLoadingImportService::class, \Mockery::mock(HeavyEquipmentVendorLoadingImportService::class));
        $this->app->instance(HeavyEquipmentVendorLoadingDocumentService::class, \Mockery::mock(HeavyEquipmentVendorLoadingDocumentService::class));
        $this->app->instance(HeavyEquipmentVendorLoadingImportReverseService::class, app(HeavyEquipmentVendorLoadingImportReverseService::class));
    }

    public function test_history_detail_and_download_are_shared_batch_scoped_to_business_not_uploader(): void
    {
        $legacyId = $this->domainBatch(['business_id' => 7, 'created_by' => 111, 'status' => 'imported']);
        $shared = app(HeavyEquipmentVendorLoadingImportHistoryService::class)
            ->createForDomainBatch($legacyId, 7, 111, 'vendor-history-fixture.xlsx');
        app(HeavyEquipmentVendorLoadingImportHistoryService::class)->syncFromDomainBatch($legacyId);
        app(UserImportBatchService::class)->storePayload($shared, 'source_snapshot', [
            'sheets' => [[
                'title' => 'Bendera A',
                'rows' => [1 => ['A' => 'No Tiket', 'B' => 'Unit'], 2 => ['A' => 'TK-901', 'B' => 'Mitra 9']],
            ]],
        ]);
        app(UserImportBatchService::class)->storePayload($shared, 'preview', [
            'sheets' => [[
                'sheet_key' => 'sheet-a',
                'title' => 'Bendera A',
                'rows' => [['source_row' => 2, 'ticket_no' => 'TK-901']],
            ]],
        ]);
        DB::table('heavy_equipment_vendor_loading_import_batches')->where('id', $legacyId)->update([
            'results_json' => json_encode(['sheet-a' => [
                'title' => 'Bendera A',
                'documents' => 1,
                'lines' => 1,
                'line_results' => [[
                    'source_sheet' => 'Bendera A',
                    'source_row' => 2,
                    'ticket_no' => 'TK-901',
                    'status' => 'imported',
                    'document_id' => 91,
                    'document_no' => 'LVD-202611-0091',
                    'line_id' => 901,
                ]],
            ]]),
        ]);

        $history = $this->actingAs($this->admin())
            ->withSession($this->businessSession(7))
            ->get(route('asset.fleet.vendor-loadings.import.batches.index'));
        $history->assertOk()->assertJsonPath('recordsTotal', 1)
            ->assertJsonPath('data.0.id', $shared->id)
            ->assertJsonPath('data.0.domain_batch_id', $legacyId);

        $status = $this->actingAs($this->admin())
            ->withSession($this->businessSession(7))
            ->get(route('asset.fleet.vendor-loadings.import.batches.status', $legacyId));
        $status->assertOk()->assertJsonPath('batch.status', 'imported')
            ->assertJsonPath('batch.history_batch_id', $shared->id);

        $detail = $this->actingAs($this->admin())
            ->withSession($this->businessSession(7))
            ->get(route('asset.fleet.vendor-loadings.import.batches.details', $shared->id));
        $detail->assertOk()->assertJsonPath('rows.0.ticket_no', 'TK-901')
            ->assertJsonPath('rows.0.document_no', 'LVD-202611-0091')
            ->assertJsonPath('rows.0.line_id', 901);

        $importOnlyDetail = $this->actingAs($this->admin(['asset.fleet_vendor_loadings.import']))
            ->withSession($this->businessSession(7))
            ->get(route('asset.fleet.vendor-loadings.import.batches.details', $shared->id));
        $importOnlyDetail->assertOk()->assertJsonPath('rows.0.document_url', null)
            ->assertJsonPath('rows.0.document_no', 'LVD-202611-0091');

        $download = $this->actingAs($this->admin())
            ->withSession($this->businessSession(7))
            ->get(route('asset.fleet.vendor-loadings.import.batches.download', $shared->id));
        $download->assertOk()->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $path = tempnam(sys_get_temp_dir(), 'vendor-restored-');
        file_put_contents($path, $download->streamedContent());
        $spreadsheet = IOFactory::load($path);
        $this->assertSame('Bendera A', $spreadsheet->getActiveSheet()->getTitle());
        $this->assertSame('TK-901', $spreadsheet->getActiveSheet()->getCell('A2')->getValue());
        $spreadsheet->disconnectWorksheets();
        @unlink($path);

        $this->actingAs($this->admin())
            ->withSession($this->businessSession(8))
            ->get(route('asset.fleet.vendor-loadings.import.batches.details', $shared->id))
            ->assertNotFound();
        $this->actingAs($this->admin())
            ->withSession($this->businessSession(8))
            ->get(route('asset.fleet.vendor-loadings.import.batches.download', $shared->id))
            ->assertNotFound();
        $this->actingAs($this->admin())
            ->withSession($this->businessSession(8))
            ->get(route('asset.fleet.vendor-loadings.import.batches.index'))
            ->assertOk()->assertJsonPath('recordsTotal', 0);
    }

    public function test_history_backfills_legacy_batches_without_fabricating_a_source_snapshot(): void
    {
        $legacyId = $this->domainBatch(['business_id' => 7, 'created_by' => 111, 'status' => 'partial']);
        DB::table('heavy_equipment_vendor_loading_import_batches')->where('id', $legacyId)->update([
            'preview_path' => 'vendor-loading-imports/7/111/legacy.preview.json',
            'results_json' => json_encode(['old-sheet' => ['title' => 'Old', 'documents' => 1, 'lines' => 2, 'rows' => 2]]),
        ]);
        Storage::disk('user_imports')->put('vendor-loading-imports/7/111/legacy.preview.json', json_encode([
            'sheets' => [['sheet_key' => 'old-sheet', 'title' => 'Old', 'rows' => []]],
        ]));

        $response = $this->actingAs($this->admin())
            ->withSession($this->businessSession(7))
            ->get(route('asset.fleet.vendor-loadings.import.batches.index'));

        $response->assertOk()->assertJsonPath('recordsTotal', 1)->assertJsonPath('data.0.status', 'partial')
            ->assertJsonPath('data.0.has_snapshot', false);
        $this->assertNotNull(DB::table('heavy_equipment_vendor_loading_import_batches')->where('id', $legacyId)->value('user_import_batch_id'));
    }

    public function test_failed_analysis_cleans_staging_workbook_and_updates_shared_history(): void
    {
        $legacyId = $this->domainBatch([
            'business_id' => 7,
            'created_by' => 111,
            'status' => 'analyzing',
            'file_path' => 'vendor-loading-imports/7/111/failed.xlsx',
        ]);
        Storage::disk('user_imports')->put('vendor-loading-imports/7/111/failed.xlsx', 'workbook');
        $shared = app(HeavyEquipmentVendorLoadingImportHistoryService::class)
            ->createForDomainBatch($legacyId, 7, 111, 'failed.xlsx');
        $shared->forceFill(['staging_path' => 'vendor-loading-imports/7/111/failed.xlsx'])->save();

        (new AnalyzeHeavyEquipmentVendorLoadingImportJob($legacyId))->failed(new \RuntimeException('worker stopped'));

        Storage::disk('user_imports')->assertMissing('vendor-loading-imports/7/111/failed.xlsx');
        $this->assertNull(DB::table('heavy_equipment_vendor_loading_import_batches')->where('id', $legacyId)->value('file_path'));
        $this->assertNull($shared->fresh()->staging_path);
        $this->assertSame('failed', $shared->fresh()->status);
    }

    public function test_failed_vendor_import_can_be_retried_when_preview_exists_and_no_documents_were_created(): void
    {
        Queue::fake();
        $previewPath = 'vendor-loading-imports/7/111/51.preview.json';
        Storage::disk('user_imports')->put($previewPath, json_encode(['sheets' => [['sheet_key' => 'sheet-a', 'rows' => []]]]));
        $legacyId = $this->domainBatch([
            'business_id' => 7,
            'created_by' => 111,
            'status' => 'failed',
            'stage' => 'failed',
            'imported_documents' => 0,
            'imported_lines' => 0,
            'preview_path' => $previewPath,
            'results_json' => json_encode(['sheet-a' => ['status' => 'error']]),
            'errors_json' => json_encode(['previous failure']),
        ]);
        $shared = app(HeavyEquipmentVendorLoadingImportHistoryService::class)
            ->createForDomainBatch($legacyId, 7, 111, 'vendor-retry-fixture.xlsx');

        $history = $this->actingAs($this->admin())
            ->withSession($this->businessSession(7))
            ->get(route('asset.fleet.vendor-loadings.import.batches.index'));
        $history->assertOk()->assertJsonPath('data.0.can_retry', true);

        $response = $this->actingAs($this->admin())
            ->withSession($this->businessSession(7))
            ->post(route('asset.fleet.vendor-loadings.import.batches.retry', $legacyId));

        $response->assertStatus(202)->assertJsonPath('batch_id', $legacyId)
            ->assertJsonPath('history_batch_id', $shared->id);
        Queue::assertPushed(ProcessHeavyEquipmentVendorLoadingImportJob::class, fn ($job) => true);
        $this->assertDatabaseHas('heavy_equipment_vendor_loading_import_batches', [
            'id' => $legacyId,
            'status' => 'queued_import',
            'results_json' => '{}',
            'errors_json' => '[]',
        ]);
        $this->assertSame('queued_import', $shared->fresh()->status);
    }

    public function test_vendor_import_retry_blocks_batches_with_documents_and_other_businesses(): void
    {
        Queue::fake();
        $previewPath = 'vendor-loading-imports/7/111/52.preview.json';
        Storage::disk('user_imports')->put($previewPath, json_encode(['sheets' => []]));
        $legacyId = $this->domainBatch([
            'business_id' => 7,
            'created_by' => 111,
            'status' => 'failed',
            'imported_documents' => 0,
            'preview_path' => $previewPath,
        ]);
        DB::table('heavy_equipment_vendor_loading_documents')->insert([
            'business_id' => 7,
            'import_batch_id' => $legacyId,
            'document_no' => 'LVD-RETRY-GUARD',
            'status' => 'draft',
            'expense_status' => 'not_linked',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $sameBusiness = $this->actingAs($this->admin())
            ->withSession($this->businessSession(7))
            ->get(route('asset.fleet.vendor-loadings.import.batches.index'));
        $sameBusiness->assertOk()->assertJsonPath('data.0.can_retry', false);
        $blocked = $this->actingAs($this->admin())
            ->withSession($this->businessSession(7))
            ->post(route('asset.fleet.vendor-loadings.import.batches.retry', $legacyId));
        $blocked->assertStatus(409)->assertJsonPath('success', false);

        $crossBusiness = $this->actingAs($this->admin())
            ->withSession($this->businessSession(8))
            ->post(route('asset.fleet.vendor-loadings.import.batches.retry', $legacyId));
        $crossBusiness->assertNotFound();
        Queue::assertNothingPushed();
        $this->assertDatabaseHas('heavy_equipment_vendor_loading_import_batches', [
            'id' => $legacyId,
            'status' => 'failed',
        ]);
    }

    public function test_reverse_is_business_scoped_and_refuses_verified_or_expense_linked_documents(): void
    {
        $legacyId = $this->domainBatch(['business_id' => 7, 'created_by' => 111, 'status' => 'imported', 'imported_documents' => 1]);
        DB::table('heavy_equipment_vendor_loading_documents')->insert([
            'id' => 91,
            'business_id' => 7,
            'import_batch_id' => $legacyId,
            'document_no' => 'LVD-202611-0091',
            'status' => 'verified',
            'verified_at' => now(),
            'expense_status' => 'not_linked',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $shared = app(HeavyEquipmentVendorLoadingImportHistoryService::class)
            ->createForDomainBatch($legacyId, 7, 111, 'vendor-reverse-fixture.xlsx');
        app(HeavyEquipmentVendorLoadingImportHistoryService::class)->syncFromDomainBatch($legacyId);

        $this->actingAs($this->admin())
            ->withSession($this->businessSession(7))
            ->post(route('asset.fleet.vendor-loadings.import.batches.reverse', $legacyId))
            ->assertStatus(409)
            ->assertJsonFragment(['success' => false]);

        $this->assertDatabaseHas('heavy_equipment_vendor_loading_documents', ['id' => 91]);
        $this->assertSame('imported', $shared->fresh()->status);

        $this->actingAs($this->admin())
            ->withSession($this->businessSession(8))
            ->post(route('asset.fleet.vendor-loadings.import.batches.reverse', $legacyId))
            ->assertNotFound();

        $linkedBatchId = $this->domainBatch(['business_id' => 7, 'created_by' => 111, 'status' => 'imported', 'imported_documents' => 1]);
        DB::table('heavy_equipment_vendor_loading_documents')->insert([
            'id' => 92,
            'business_id' => 7,
            'import_batch_id' => $linkedBatchId,
            'document_no' => 'LVD-202611-0092',
            'status' => 'draft',
            'expense_transaction_id' => 44,
            'expense_status' => 'linked',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->actingAs($this->admin())
            ->withSession($this->businessSession(7))
            ->post(route('asset.fleet.vendor-loadings.import.batches.reverse', $linkedBatchId))
            ->assertStatus(409);
        $this->assertDatabaseHas('heavy_equipment_vendor_loading_documents', ['id' => 92]);
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
        Schema::create('heavy_equipment_vendor_loading_import_batches', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('user_import_batch_id')->nullable();
            $table->unsignedInteger('business_id');
            $table->unsignedInteger('created_by');
            $table->string('file_name');
            $table->string('file_path')->nullable();
            $table->string('preview_path')->nullable();
            $table->string('status', 32)->default('queued_preview');
            $table->string('stage', 64)->default('queued');
            $table->unsignedInteger('total_sheets')->default(0);
            $table->unsignedInteger('processed_sheets')->default(0);
            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('processed_rows')->default(0);
            $table->unsignedInteger('ready_rows')->default(0);
            $table->unsignedInteger('issue_rows')->default(0);
            $table->unsignedInteger('missing_date_rows')->default(0);
            $table->unsignedInteger('imported_documents')->default(0);
            $table->unsignedInteger('imported_lines')->default(0);
            $table->unsignedInteger('skipped_rows')->default(0);
            $table->longText('overrides_json')->nullable();
            $table->longText('permissions_json')->nullable();
            $table->longText('results_json')->nullable();
            $table->longText('errors_json')->nullable();
            $table->text('last_message')->nullable();
            $table->longText('error_message')->nullable();
            $table->dateTime('last_heartbeat_at')->nullable();
            $table->dateTime('started_at')->nullable();
            $table->dateTime('finished_at')->nullable();
            $table->dateTime('expires_at')->nullable();
            $table->dateTime('reversed_at')->nullable();
            $table->unsignedInteger('reversed_by')->nullable();
            $table->unsignedInteger('reversed_documents')->default(0);
            $table->unsignedInteger('reversed_lines')->default(0);
            $table->timestamps();
        });
        Schema::create('heavy_equipment_vendor_loading_documents', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('business_id');
            $table->unsignedBigInteger('import_batch_id')->nullable();
            $table->string('document_no');
            $table->string('status')->default('draft');
            $table->unsignedInteger('expense_transaction_id')->nullable();
            $table->string('expense_status')->default('not_linked');
            $table->dateTime('verified_at')->nullable();
            $table->timestamps();
        });
        Schema::create('heavy_equipment_vendor_loadings', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('loading_document_id');
            $table->string('ticket_no')->nullable();
        });
    }

    private function domainBatch(array $attributes = []): int
    {
        return (int) DB::table('heavy_equipment_vendor_loading_import_batches')->insertGetId(array_merge([
            'business_id' => 7,
            'created_by' => 111,
            'file_name' => 'vendor-history-fixture.xlsx',
            'status' => 'imported',
            'stage' => 'imported',
            'total_sheets' => 1,
            'processed_sheets' => 1,
            'total_rows' => 1,
            'processed_rows' => 1,
            'imported_documents' => 1,
            'imported_lines' => 1,
            'results_json' => '{}',
            'last_message' => 'Import selesai.',
            'finished_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ], $attributes));
    }

    private function admin(array $abilities = ['superadmin']): \Illuminate\Foundation\Auth\User
    {
        $admin = new class($abilities) extends \Illuminate\Foundation\Auth\User
        {
            public function __construct(private array $abilities)
            {
                parent::__construct();
            }

            public function can($ability, $arguments = [])
            {
                return in_array($ability, $this->abilities, true);
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
