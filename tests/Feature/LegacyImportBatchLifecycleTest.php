<?php

namespace Tests\Feature;

use App\Product;
use App\Services\LegacyImportBatchService;
use App\Services\UserImportBatchService;
use App\UserImportBatch;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class LegacyImportBatchLifecycleTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (! in_array('sqlite', \PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('PDO SQLite is required for isolated legacy importer lifecycle tests.');
        }
        config(['database.default' => 'legacy_import_test', 'database.connections.legacy_import_test' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => false,
        ]]);
        DB::purge('legacy_import_test');
        DB::setDefaultConnection('legacy_import_test');
        Storage::fake('local');
        $this->withoutMiddleware([
            \App\Http\Middleware\IsInstalled::class,
            \App\Http\Middleware\SetSessionData::class,
            \App\Http\Middleware\AdminSidebarMenu::class,
        ]);
        $this->createBatchTable();
        $this->createDomainTables();
    }

    public function test_product_revert_removes_only_an_unchanged_batch_created_product(): void
    {
        $product = Product::create(['business_id' => 7, 'created_by' => 99, 'name' => 'Batch Item', 'sku' => 'BATCH-1', 'type' => 'single', 'enable_stock' => 0]);
        $batch = $this->batch('products');
        app(UserImportBatchService::class)->storePayload($batch, 'results', [
            'records' => [['id' => $product->id, 'name' => $product->name, 'sku' => $product->sku, 'attributes' => $this->normalizedAttributes($product->fresh())]],
            'created_transactions' => [], 'created_purchase_lines' => [],
        ]);
        $batch->update(['status' => 'imported']);

        app(LegacyImportBatchService::class)->revert($batch->fresh());

        $this->assertDatabaseMissing('products', ['id' => $product->id]);
        $this->assertSame('reverted', $batch->fresh()->status);
    }

    public function test_product_revert_is_blocked_after_the_product_changes(): void
    {
        $product = Product::create(['business_id' => 7, 'created_by' => 99, 'name' => 'Batch Item', 'sku' => 'BATCH-1', 'type' => 'single', 'enable_stock' => 0]);
        $batch = $this->batch('products');
        app(UserImportBatchService::class)->storePayload($batch, 'results', [
            'records' => [['id' => $product->id, 'name' => $product->name, 'sku' => $product->sku, 'attributes' => $this->normalizedAttributes($product->fresh())]],
            'created_transactions' => [], 'created_purchase_lines' => [],
        ]);
        $batch->update(['status' => 'imported']);
        $product->update(['name' => 'Edited after import']);

        try {
            app(LegacyImportBatchService::class)->revert($batch->fresh());
            $this->fail('Changed master data must block revert.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $exception) {
            $this->assertSame(422, $exception->getStatusCode());
        }

        $this->assertDatabaseHas('products', ['id' => $product->id, 'name' => 'Edited after import']);
        $this->assertSame('imported', $batch->fresh()->status);
    }

    public function test_product_result_tracks_only_created_ids_and_revert_blocks_changed_variations(): void
    {
        $batchProduct = Product::create(['business_id' => 7, 'created_by' => 99, 'name' => 'Batch Item', 'sku' => 'BATCH-1', 'type' => 'single', 'enable_stock' => 0]);
        $concurrentProduct = Product::create(['business_id' => 7, 'created_by' => 99, 'name' => 'Concurrent Item', 'sku' => 'BATCH-2', 'type' => 'single', 'enable_stock' => 0]);
        DB::table('variations')->insert(['product_id' => $batchProduct->id, 'sub_sku' => 'BATCH-1']);
        $variationId = (int) DB::getPdo()->lastInsertId();
        $batch = $this->batch('products');

        $result = app(LegacyImportBatchService::class)->complete($batch, [
            'source_rows' => 1,
            'rows' => [[0 => 'Batch Item', 5 => 'BATCH-1', 13 => 'single']],
        ], [$batchProduct->id]);

        $this->assertSame([(int) $batchProduct->id], array_column($result['records'], 'id'));
        $this->assertSame('created', $result['row_outcomes'][0]['status']);
        $this->assertSame('imported', $batch->fresh()->status);

        DB::table('variations')->where('id', $variationId)->update(['sub_sku' => 'CHANGED-AFTER-IMPORT']);
        try {
            app(LegacyImportBatchService::class)->revert($batch->fresh());
            $this->fail('Changed child variation data must block product revert.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $exception) {
            $this->assertSame(422, $exception->getStatusCode());
        }

        $this->assertDatabaseHas('products', ['id' => $batchProduct->id]);
        $this->assertDatabaseHas('products', ['id' => $concurrentProduct->id]);
    }

    public function test_product_revert_blocks_inventory_rows_that_cascade_from_variations(): void
    {
        $product = Product::create(['business_id' => 7, 'created_by' => 99, 'name' => 'Inventory Item', 'sku' => 'INV-1', 'type' => 'single', 'enable_stock' => 0]);
        DB::table('variations')->insert(['id' => 91, 'product_id' => $product->id, 'sub_sku' => 'INV-1']);
        $batch = $this->batch('products');
        app(LegacyImportBatchService::class)->complete($batch, [
            'source_rows' => 1,
            'rows' => [[0 => 'Inventory Item', 5 => 'INV-1', 13 => 'single']],
        ], [$product->id]);
        DB::table('inventory_products')->insert([
            'id' => 92, 'product_id' => $product->id, 'variation_id' => 91, 'transaction_id' => 11,
            'amount_after_inventory' => 3, 'Amount_difference' => 3, 'inventory_type' => 'stocktake', 'qty_before' => '0',
        ]);

        try {
            app(LegacyImportBatchService::class)->revert($batch->fresh());
            $this->fail('A recorded inventory count must block product deletion.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $exception) {
            $this->assertSame(422, $exception->getStatusCode());
            $this->assertStringContainsString('catatan inventaris', $exception->getMessage());
        }

        $this->assertDatabaseHas('products', ['id' => $product->id]);
        $this->assertDatabaseHas('inventory_products', ['id' => 92, 'variation_id' => 91]);
        $this->assertSame('imported', $batch->fresh()->status);
    }

    public function test_product_revert_blocks_variations_used_as_manufacturing_ingredients(): void
    {
        $product = Product::create(['business_id' => 7, 'created_by' => 99, 'name' => 'Recipe Item', 'sku' => 'RECIPE-1', 'type' => 'single', 'enable_stock' => 0]);
        DB::table('variations')->insert(['id' => 93, 'product_id' => $product->id, 'sub_sku' => 'RECIPE-1']);
        $batch = $this->batch('products');
        app(LegacyImportBatchService::class)->complete($batch, [
            'source_rows' => 1,
            'rows' => [[0 => 'Recipe Item', 5 => 'RECIPE-1', 13 => 'single']],
        ], [$product->id]);
        DB::table('mfg_recipe_ingredients')->insert(['id' => 94, 'variation_id' => 93]);

        try {
            app(LegacyImportBatchService::class)->revert($batch->fresh());
            $this->fail('A manufacturing recipe reference must block product deletion.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $exception) {
            $this->assertSame(422, $exception->getStatusCode());
            $this->assertStringContainsString('bahan resep', $exception->getMessage());
        }

        $this->assertDatabaseHas('products', ['id' => $product->id]);
        $this->assertDatabaseHas('mfg_recipe_ingredients', ['id' => 94, 'variation_id' => 93]);
        $this->assertSame('imported', $batch->fresh()->status);
    }

    public function test_product_and_opening_stock_upload_routes_queue_and_restore_business_scoped_sources(): void
    {
        Bus::fake();
        $path = $this->uploadWorkbookPath();
        $imports = [
            ['products', '/import-products/store', 'import.products.history.source', 'import.products.history.retry'],
            ['opening_stock', '/import-opening-stock/store', 'import.opening-stock.history.source', 'import.opening-stock.history.retry'],
        ];

        try {
            foreach ($imports as [$importerKey, $uploadRoute, $sourceRoute, $retryRoute]) {
                $this->asBusiness(7, false)->postJson($uploadRoute, [
                    'products_csv' => $this->uploadedWorkbook($path),
                ])->assertForbidden();

                $response = $this->asBusiness(7)->postJson($uploadRoute, [
                    'products_csv' => $this->uploadedWorkbook($path),
                ]);
                $response->assertStatus(202)->assertJsonPath('queued', true);
                $batch = UserImportBatch::findOrFail($response->json('batch_id'));
                $this->assertSame(7, (int) $batch->business_id);
                $this->assertSame($importerKey, $batch->importer_key);
                $this->assertSame('queued_import', $batch->status);

                app(UserImportBatchService::class)->captureWorkbook($batch, $path);
                $this->asBusiness(7)->get(route($sourceRoute, $batch->id))->assertOk()
                    ->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
                $this->asBusiness(8)->get(route($sourceRoute, $batch->id))->assertNotFound();
                $this->asBusiness(7, false)->get(route($sourceRoute, $batch->id))->assertForbidden();

                $failedBatch = UserImportBatch::create([
                    'business_id' => 7,
                    'created_by' => 99,
                    'importer_key' => $importerKey,
                    'source_key' => (string) \Illuminate\Support\Str::uuid(),
                    'file_name' => 'failed-'.$importerKey.'.xlsx',
                    'status' => 'failed',
                    'stage' => 'failed',
                ]);
                app(UserImportBatchService::class)->captureWorkbook($failedBatch, $path);
                $this->asBusiness(7, false)->postJson(route($retryRoute, $failedBatch->id))->assertForbidden();
                $this->asBusiness(8)->postJson(route($retryRoute, $failedBatch->id))->assertNotFound();
                $this->asBusiness(7)->postJson(route($retryRoute, $failedBatch->id))
                    ->assertOk()->assertJsonPath('status', 'queued_import');
                $this->asBusiness(7)->postJson(route($retryRoute, $failedBatch->id))->assertStatus(422);
            }

            Bus::assertDispatched(\App\Jobs\ProcessLegacyImportBatch::class, 4);
        } finally {
            @unlink($path);
        }
    }

    public function test_opening_stock_revert_restores_existing_transaction_and_location_quantity(): void
    {
        [$batch, $path] = $this->openingStockWorkbook();
        try {
            $batches = app(UserImportBatchService::class);
            $batches->captureWorkbook($batch, $path);
            $service = app(LegacyImportBatchService::class);
            $before = $service->prepare($batch, $path);
            DB::table('transactions')->where('id', 11)->update(['total_before_tax' => 150, 'final_total' => 150, 'updated_at' => now()]);
            DB::table('purchase_lines')->insert([
                'id' => 21, 'transaction_id' => 11, 'product_id' => 10, 'variation_id' => 12,
                'quantity' => 5, 'quantity_sold' => 0, 'quantity_adjusted' => 0,
            ]);
            DB::table('variation_location_details')->where('id', 30)->update(['qty_available' => 15]);
            $service->complete($batch->fresh(), $before);

            $service->revert($batch->fresh());

            $this->assertSame(100.0, (float) DB::table('transactions')->where('id', 11)->value('total_before_tax'));
            $this->assertSame(1, DB::table('purchase_lines')->where('transaction_id', 11)->count());
            $this->assertSame(10.0, (float) DB::table('variation_location_details')->where('id', 30)->value('qty_available'));
            $this->assertSame('reverted', $batch->fresh()->status);
        } finally {
            @unlink($path);
        }
    }

    public function test_opening_stock_revert_is_blocked_when_location_stock_changed_again(): void
    {
        [$batch, $path] = $this->openingStockWorkbook();
        try {
            $batches = app(UserImportBatchService::class);
            $batches->captureWorkbook($batch, $path);
            $service = app(LegacyImportBatchService::class);
            $before = $service->prepare($batch, $path);
            DB::table('transactions')->where('id', 11)->update(['total_before_tax' => 150, 'final_total' => 150, 'updated_at' => now()]);
            DB::table('purchase_lines')->insert([
                'id' => 21, 'transaction_id' => 11, 'product_id' => 10, 'variation_id' => 12,
                'quantity' => 5, 'quantity_sold' => 0, 'quantity_adjusted' => 0,
            ]);
            DB::table('variation_location_details')->where('id', 30)->update(['qty_available' => 15]);
            $service->complete($batch->fresh(), $before);
            DB::table('variation_location_details')->where('id', 30)->update(['qty_available' => 16]);

            try {
                $service->revert($batch->fresh());
                $this->fail('A later stock change must block revert.');
            } catch (\Symfony\Component\HttpKernel\Exception\HttpException $exception) {
                $this->assertSame(422, $exception->getStatusCode());
            }

            $this->assertSame(2, DB::table('purchase_lines')->where('transaction_id', 11)->count());
            $this->assertSame('imported', $batch->fresh()->status);
        } finally {
            @unlink($path);
        }
    }

    private function openingStockWorkbook(): array
    {
        DB::table('products')->insert(['id' => 10, 'business_id' => 7, 'created_by' => 99, 'name' => 'Stock Item', 'sku' => 'STOCK-10', 'type' => 'single', 'enable_stock' => 1]);
        DB::table('variations')->insert(['id' => 12, 'product_id' => 10, 'sub_sku' => 'STOCK-10']);
        DB::table('business_locations')->insert(['id' => 2, 'business_id' => 7, 'name' => 'Main']);
        DB::table('transactions')->insert([
            'id' => 11, 'business_id' => 7, 'type' => 'opening_stock', 'location_id' => 2, 'opening_stock_product_id' => 10,
            'total_before_tax' => 100, 'final_total' => 100, 'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00',
        ]);
        DB::table('purchase_lines')->insert([
            'id' => 20, 'transaction_id' => 11, 'product_id' => 10, 'variation_id' => 12,
            'quantity' => 10, 'quantity_sold' => 0, 'quantity_adjusted' => 0,
        ]);
        DB::table('variation_location_details')->insert(['id' => 30, 'product_id' => 10, 'variation_id' => 12, 'location_id' => 2, 'qty_available' => 10]);
        DB::table('product_locations')->insert(['id' => 40, 'product_id' => 10, 'location_id' => 2]);
        $batch = $this->batch('opening_stock', ['session_context' => ['financial_year' => ['start' => '2026-01-01']]]);
        $path = sys_get_temp_dir().'/legacy-opening-stock-'.bin2hex(random_bytes(8)).'.xlsx';
        $spreadsheet = new Spreadsheet();
        $spreadsheet->getActiveSheet()->fromArray([['SKU', 'Location', 'Quantity', 'Unit Cost'], ['STOCK-10', 'Main', 5, 10]], null, 'A1');
        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();

        return [$batch, $path];
    }

    private function batch(string $key, array $options = []): UserImportBatch
    {
        return UserImportBatch::create([
            'business_id' => 7, 'created_by' => 99, 'importer_key' => $key,
            'source_key' => (string) \Illuminate\Support\Str::uuid(), 'file_name' => 'legacy.xlsx',
            'status' => 'importing', 'stage' => 'importing',
            'options_json' => json_encode($options, JSON_THROW_ON_ERROR),
        ]);
    }

    private function normalizedAttributes(Product $product): array
    {
        return json_decode(json_encode($product->getAttributes(), JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
    }

    private function uploadWorkbookPath(): string
    {
        $path = sys_get_temp_dir().'/legacy-upload-'.bin2hex(random_bytes(8)).'.xlsx';
        $spreadsheet = new Spreadsheet();
        $spreadsheet->getActiveSheet()->fromArray([['Product', 'SKU'], ['Example Item', 'ITEM-1']], null, 'A1');
        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();

        return $path;
    }

    private function uploadedWorkbook(string $path): UploadedFile
    {
        return new UploadedFile(
            $path,
            'legacy-upload.xlsx',
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
        };
        $user->allowed = $allowed;
        $user->forceFill(['id' => 99, 'business_id' => $businessId, 'first_name' => 'Importer', 'user_type' => 'user', 'allow_login' => 1]);

        return $this->actingAs($user)->withSession([
            'user' => ['id' => 99, 'business_id' => $businessId],
            'business' => ['time_zone' => 'UTC', 'currency_symbol_placement' => 'before', 'currency_precision' => 2],
            'currency' => ['symbol' => 'Rp', 'decimal_separator' => ',', 'thousand_separator' => '.'],
        ]);
    }

    private function createBatchTable(): void
    {
        Schema::create('user_import_batches', function (Blueprint $table) {
            $table->bigIncrements('id'); $table->unsignedInteger('business_id'); $table->unsignedInteger('created_by')->nullable();
            $table->string('importer_key', 64); $table->string('source_key')->nullable(); $table->string('file_name')->nullable();
            $table->string('staging_path')->nullable(); $table->string('source_snapshot_path')->nullable(); $table->string('preview_path')->nullable(); $table->string('results_path')->nullable();
            $table->string('status', 32)->default('queued_import'); $table->string('stage', 64)->nullable();
            $table->unsignedInteger('progress_current')->default(0); $table->unsignedInteger('progress_total')->default(0); $table->unsignedTinyInteger('progress_percent')->default(0);
            $table->longText('summary_json')->nullable(); $table->longText('options_json')->nullable(); $table->longText('search_text')->nullable();
            $table->text('last_message')->nullable(); $table->longText('error_message')->nullable(); $table->dateTime('heartbeat_at')->nullable();
            $table->dateTime('started_at')->nullable(); $table->dateTime('completed_at')->nullable(); $table->dateTime('failed_at')->nullable(); $table->dateTime('reverted_at')->nullable(); $table->timestamps();
        });
    }

    private function createDomainTables(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->increments('id'); $table->unsignedInteger('business_id'); $table->unsignedInteger('created_by')->nullable();
            $table->string('name'); $table->string('sku')->nullable(); $table->string('type')->nullable(); $table->boolean('enable_stock')->default(false); $table->timestamps();
        });
        Schema::create('variations', function (Blueprint $table) { $table->increments('id'); $table->unsignedInteger('product_id'); $table->string('sub_sku')->nullable(); $table->softDeletes(); });
        Schema::create('inventory_products', function (Blueprint $table) {
            $table->increments('id'); $table->unsignedInteger('product_id'); $table->unsignedInteger('variation_id');
            $table->unsignedInteger('transaction_id'); $table->unsignedBigInteger('amount_after_inventory')->default(0);
            $table->integer('Amount_difference')->default(0); $table->string('inventory_type'); $table->string('qty_before');
        });
        Schema::create('mfg_recipe_ingredients', function (Blueprint $table) {
            $table->increments('id'); $table->unsignedInteger('variation_id');
        });
        Schema::create('business_locations', function (Blueprint $table) { $table->increments('id'); $table->unsignedInteger('business_id'); $table->string('name'); });
        Schema::create('transactions', function (Blueprint $table) {
            $table->bigIncrements('id'); $table->unsignedInteger('business_id'); $table->string('type'); $table->unsignedInteger('location_id');
            $table->unsignedInteger('opening_stock_product_id')->nullable(); $table->decimal('total_before_tax', 22, 4)->default(0); $table->decimal('final_total', 22, 4)->default(0); $table->timestamps();
        });
        Schema::create('purchase_lines', function (Blueprint $table) {
            $table->bigIncrements('id'); $table->unsignedBigInteger('transaction_id'); $table->unsignedInteger('product_id'); $table->unsignedInteger('variation_id');
            $table->decimal('quantity', 22, 4); $table->decimal('quantity_sold', 22, 4)->default(0); $table->decimal('quantity_adjusted', 22, 4)->default(0);
        });
        Schema::create('transaction_sell_lines', function (Blueprint $table) { $table->bigIncrements('id'); $table->unsignedInteger('product_id'); $table->unsignedInteger('variation_id'); });
        Schema::create('variation_location_details', function (Blueprint $table) {
            $table->bigIncrements('id'); $table->unsignedInteger('product_id'); $table->unsignedInteger('variation_id'); $table->unsignedInteger('location_id'); $table->decimal('qty_available', 22, 4);
        });
        Schema::create('product_locations', function (Blueprint $table) { $table->bigIncrements('id'); $table->unsignedInteger('product_id'); $table->unsignedInteger('location_id'); });
    }
}
