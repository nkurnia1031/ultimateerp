<?php

namespace Tests\Feature;

use App\Services\PurchaseProductLineImportBatchService;
use App\Services\UserImportBatchService;
use App\UserImportBatch;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class PurchaseProductLineImportBatchLifecycleTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (! in_array('sqlite', \PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('PDO SQLite is required for isolated Purchase product-line batch tests.');
        }
        config(['database.default' => 'purchase_product_import_test', 'database.connections.purchase_product_import_test' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => false,
        ]]);
        DB::purge('purchase_product_import_test');
        DB::setDefaultConnection('purchase_product_import_test');
        Storage::fake('local');
        Storage::fake('user_imports');
        $this->withoutMiddleware([
            \App\Http\Middleware\IsInstalled::class,
            \App\Http\Middleware\SetSessionData::class,
            \App\Http\Middleware\AdminSidebarMenu::class,
        ]);
        Schema::create('user_import_batches', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedInteger('business_id');
            $table->unsignedInteger('created_by')->nullable();
            $table->string('importer_key', 64);
            $table->string('source_key')->nullable();
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
        });
        Schema::create('business_locations', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('business_id');
        });
        DB::table('business_locations')->insert(['id' => 5, 'business_id' => 7]);
        DB::table('business_locations')->insert(['id' => 6, 'business_id' => 8]);
    }

    public function test_queued_import_keeps_row_errors_restores_workbook_and_cancels_preview_safely(): void
    {
        [$batch, $path] = $this->batchAndWorkbook();

        try {
            app(UserImportBatchService::class)->captureWorkbook($batch, $path);
            $result = app(PurchaseProductLineImportBatchService::class)->process($batch, $path);

            $this->assertSame('preview_ready', $batch->fresh()->status);
            $this->assertSame(1, $result['summary']['error_rows']);
            $this->assertSame('error', $result['row_outcomes'][0]['status']);
            $restoredPath = app(UserImportBatchService::class)->restoredWorkbookPath($batch->fresh());
            try {
                $restored = IOFactory::load($restoredPath);
                $this->assertSame('FOREIGN-SKU', $restored->getActiveSheet()->getCell('A2')->getValue());
                $restored->disconnectWorksheets();
            } finally {
                @unlink($restoredPath);
                @rmdir(dirname($restoredPath));
            }

            app(PurchaseProductLineImportBatchService::class)->revert($batch->fresh());
            $this->assertSame('reverted', $batch->fresh()->status);
        } finally {
            @unlink($path);
        }
    }

    public function test_batch_history_is_scoped_to_the_active_business(): void
    {
        $batch = UserImportBatch::create([
            'business_id' => 7,
            'created_by' => 99,
            'importer_key' => PurchaseProductLineImportBatchService::IMPORTER_KEY,
            'source_key' => (string) \Illuminate\Support\Str::uuid(),
            'file_name' => 'purchase-lines.xlsx',
            'status' => 'preview_ready',
            'stage' => 'preview_ready',
        ]);

        try {
            app(UserImportBatchService::class)->findForBusiness($batch->id, 8, PurchaseProductLineImportBatchService::IMPORTER_KEY);
            $this->fail('A batch from another business must not be visible.');
        } catch (ModelNotFoundException $exception) {
            $this->assertTrue(true);
        }
    }

    public function test_detail_route_enforces_purchase_permission_business_scope_and_live_progress(): void
    {
        $batch = UserImportBatch::create([
            'business_id' => 7,
            'created_by' => 99,
            'importer_key' => PurchaseProductLineImportBatchService::IMPORTER_KEY,
            'source_key' => (string) \Illuminate\Support\Str::uuid(),
            'file_name' => 'purchase-lines.xlsx',
            'status' => 'analyzing',
            'stage' => 'analyzing',
        ]);
        Cache::put(app(PurchaseProductLineImportBatchService::class)->progressKey((int) $batch->id), [
            'progress_current' => 4,
            'progress_total' => 10,
            'progress_percent' => 38,
            'last_message' => 'Baris 4 sedang dianalisis.',
        ], 3600);

        $detail = $this->asBusiness(7)->getJson(route('purchase.product-import.history.detail', $batch->id));
        $detail->assertOk()->assertJsonPath('batch.progress_current', 4)->assertJsonPath('batch.progress_percent', 38);
        $this->asBusiness(8)->getJson(route('purchase.product-import.history.detail', $batch->id))->assertNotFound();
        $this->asBusiness(7, false)->getJson(route('purchase.product-import.history.detail', $batch->id))->assertForbidden();
    }

    public function test_upload_route_queues_purchase_preview_with_permission_and_business_location_checks(): void
    {
        Bus::fake();
        $path = $this->workbookPath('PURCHASE-ROUTE-SKU');

        try {
            $this->asBusiness(7, false)->postJson('/import-purchase-products', [
                'file' => $this->uploadedWorkbook($path), 'location_id' => 5, 'row_count' => 1,
            ])->assertForbidden();

            $this->asBusiness(7)->postJson('/import-purchase-products', [
                'file' => $this->uploadedWorkbook($path), 'location_id' => 6, 'row_count' => 1,
            ])->assertNotFound();

            $response = $this->asBusiness(7)->postJson('/import-purchase-products', [
                'file' => $this->uploadedWorkbook($path), 'location_id' => 5, 'row_count' => 1,
            ]);
            $response->assertStatus(202)->assertJsonPath('success', true)->assertJsonPath('queued', true);
            $batch = UserImportBatch::findOrFail($response->json('batch_id'));
            $this->assertSame(7, (int) $batch->business_id);
            $this->assertSame(PurchaseProductLineImportBatchService::IMPORTER_KEY, $batch->importer_key);
            $this->assertSame('queued_preview', $batch->status);
            Bus::assertDispatched(\App\Jobs\ProcessPurchaseProductLineImportBatch::class, 1);
        } finally {
            @unlink($path);
        }
    }

    public function test_revert_route_enforces_permission_business_scope_and_preview_state(): void
    {
        $batch = $this->batchWithStatus('preview_ready');

        $this->asBusiness(7, false)->postJson(route('purchase.product-import.history.revert', $batch->id))->assertForbidden();
        $this->asBusiness(8)->postJson(route('purchase.product-import.history.revert', $batch->id))->assertNotFound();
        $this->asBusiness(7)->postJson(route('purchase.product-import.history.revert', $batch->id))
            ->assertOk()->assertJsonPath('status', 'reverted');
        $this->assertSame('reverted', $batch->fresh()->status);

        $importedBatch = $this->batchWithStatus('imported');
        $this->asBusiness(7)->postJson(route('purchase.product-import.history.revert', $importedBatch->id))->assertStatus(422);
        $this->assertSame('imported', $importedBatch->fresh()->status);
    }

    public function test_source_and_retry_routes_use_snapshot_and_enforce_purchase_scope(): void
    {
        Bus::fake();
        $batch = $this->batchWithStatus('failed');
        $path = $this->workbookPath('PURCHASE-RETRY-SKU');

        try {
            app(UserImportBatchService::class)->captureWorkbook($batch, $path);

            $this->asBusiness(7, false)->get(route('purchase.product-import.history.source', $batch->id))->assertForbidden();
            $this->asBusiness(8)->get(route('purchase.product-import.history.source', $batch->id))->assertNotFound();
            $this->asBusiness(7)->get(route('purchase.product-import.history.source', $batch->id))->assertOk();
            $this->asBusiness(7, false)->postJson(route('purchase.product-import.history.retry', $batch->id))->assertForbidden();
            $this->asBusiness(8)->postJson(route('purchase.product-import.history.retry', $batch->id))->assertNotFound();
            $this->asBusiness(7)->postJson(route('purchase.product-import.history.retry', $batch->id))
                ->assertOk()->assertJsonPath('status', 'queued_preview');

            $this->assertSame('queued_preview', $batch->fresh()->status);
            Bus::assertDispatched(\App\Jobs\ProcessPurchaseProductLineImportBatch::class, 1);
        } finally {
            @unlink($path);
        }
    }

    private function batchAndWorkbook(): array
    {
        $batch = UserImportBatch::create([
            'business_id' => 7,
            'created_by' => 99,
            'importer_key' => PurchaseProductLineImportBatchService::IMPORTER_KEY,
            'source_key' => (string) \Illuminate\Support\Str::uuid(),
            'file_name' => 'purchase-lines.xlsx',
            'status' => 'analyzing',
            'stage' => 'analyzing',
            'options_json' => json_encode(['location_id' => 5, 'row_count' => 0], JSON_THROW_ON_ERROR),
        ]);
        $path = sys_get_temp_dir().'/purchase-product-import-'.bin2hex(random_bytes(8)).'.xlsx';
        $spreadsheet = new Spreadsheet();
        $spreadsheet->getActiveSheet()->fromArray([
            ['SKU', 'Quantity', 'Cost'],
            ['FOREIGN-SKU', 3, 12.5],
        ], null, 'A1');
        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();

        return [$batch, $path];
    }

    private function batchWithStatus(string $status): UserImportBatch
    {
        return UserImportBatch::create([
            'business_id' => 7,
            'created_by' => 99,
            'importer_key' => PurchaseProductLineImportBatchService::IMPORTER_KEY,
            'source_key' => (string) \Illuminate\Support\Str::uuid(),
            'file_name' => 'purchase-lines.xlsx',
            'status' => $status,
            'stage' => $status,
        ]);
    }

    private function workbookPath(string $sku): string
    {
        $path = sys_get_temp_dir().'/purchase-route-upload-'.bin2hex(random_bytes(8)).'.xlsx';
        $spreadsheet = new Spreadsheet();
        $spreadsheet->getActiveSheet()->fromArray([['SKU', 'Quantity', 'Cost'], [$sku, 1, 10]], null, 'A1');
        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();

        return $path;
    }

    private function uploadedWorkbook(string $path): UploadedFile
    {
        return new UploadedFile(
            $path,
            'purchase-lines.xlsx',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            null,
            true
        );
    }

    private function asBusiness(int $businessId, bool $allowed = true): self
    {
        $user = new class extends \Illuminate\Foundation\Auth\User
        {
            public bool $allowed = true;

            public function can($ability, $arguments = [])
            {
                return $this->allowed;
            }

            public function permitted_locations()
            {
                return 'all';
            }
        };
        $user->allowed = $allowed;
        $user->forceFill(['id' => 99, 'business_id' => $businessId, 'first_name' => 'Purchase Admin', 'user_type' => 'user', 'allow_login' => 1]);

        return $this->actingAs($user)->withSession([
            'user' => ['id' => 99, 'business_id' => $businessId],
            'business' => ['time_zone' => 'UTC', 'currency_symbol_placement' => 'before', 'currency_precision' => 2],
            'currency' => ['symbol' => 'Rp', 'decimal_separator' => ',', 'thousand_separator' => '.'],
        ]);
    }
}
