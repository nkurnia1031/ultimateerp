<?php

namespace Tests\Feature;

use App\Services\SalesPurchaseImportBatchService;
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

class SalesPurchaseImportBatchLifecycleTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (! in_array('sqlite', \PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('PDO SQLite is required for isolated Sales/Purchase batch tests.');
        }
        config(['database.default' => 'sales_purchase_import_test', 'database.connections.sales_purchase_import_test' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => false,
        ]]);
        DB::purge('sales_purchase_import_test');
        DB::setDefaultConnection('sales_purchase_import_test');
        Storage::fake('local');
        $this->withoutMiddleware([
            \App\Http\Middleware\IsInstalled::class,
            \App\Http\Middleware\SetSessionData::class,
            \App\Http\Middleware\AdminSidebarMenu::class,
        ]);
        $this->createSchema();
    }

    public function test_import_batch_is_business_scoped_and_its_source_can_be_restored(): void
    {
        $batch = $this->batch('sales_sales');
        $path = sys_get_temp_dir().'/sales-purchase-source-'.bin2hex(random_bytes(8)).'.xlsx';
        $spreadsheet = new Spreadsheet();
        $spreadsheet->getActiveSheet()->fromArray([['Invoice'], ['INV-1']], null, 'A1');
        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();
        try {
            $service = app(UserImportBatchService::class);
            $service->captureWorkbook($batch, $path);
            $restored = $service->restoredWorkbookPath($batch->fresh());
            $this->assertFileExists($restored);
            @unlink($restored);
            @rmdir(dirname($restored));
            try {
                $service->findForBusiness($batch->id, 8, 'sales_sales');
                $this->fail('A batch from another business must not be visible.');
            } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $exception) {
                $this->assertTrue(true);
            }
        } finally {
            @unlink($path);
        }
    }

    public function test_revert_is_blocked_if_a_created_sales_transaction_changed(): void
    {
        $batch = $this->batch('sales_sales', 'imported');
        DB::table('transactions')->insert([
            'id' => 40, 'business_id' => 7, 'created_by' => 99, 'type' => 'sell', 'location_id' => 2,
            'invoice_no' => 'INV-40', 'return_parent_id' => null,
        ]);
        $transaction = get_object_vars(DB::table('transactions')->where('id', 40)->first());
        app(UserImportBatchService::class)->storePayload($batch, 'results', [
            'kind' => 'sales', 'transactions' => ['40' => $transaction], 'line_table' => 'transaction_sell_lines',
            'lines' => [], 'payments' => [], 'after_stock' => [],
        ]);
        DB::table('transactions')->where('id', 40)->update(['invoice_no' => 'INV-EDITED']);

        try {
            app(SalesPurchaseImportBatchService::class)->revert($batch->fresh());
            $this->fail('A changed transaction must block batch revert.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $exception) {
            $this->assertSame(422, $exception->getStatusCode());
        }

        $this->assertSame('imported', $batch->fresh()->status);
        $this->assertSame('INV-EDITED', DB::table('transactions')->where('id', 40)->value('invoice_no'));
    }

    public function test_revert_blocks_a_created_contact_used_by_a_booking(): void
    {
        $batch = $this->batch('sales_sales', 'imported');
        DB::table('contacts')->insert(['id' => 7, 'business_id' => 7, 'name' => 'Batch Customer']);
        DB::table('transactions')->insert([
            'id' => 40, 'business_id' => 7, 'created_by' => 99, 'type' => 'sell', 'location_id' => 2,
            'invoice_no' => 'INV-40', 'return_parent_id' => null, 'contact_id' => 7,
        ]);
        DB::table('bookings')->insert(['id' => 50, 'contact_id' => 7]);
        $transaction = get_object_vars(DB::table('transactions')->where('id', 40)->first());
        $contact = get_object_vars(DB::table('contacts')->where('id', 7)->first());
        app(UserImportBatchService::class)->storePayload($batch, 'results', [
            'kind' => 'sales', 'transactions' => ['40' => $transaction], 'line_table' => 'transaction_sell_lines',
            'lines' => [], 'payments' => [], 'account_transactions' => [], 'contacts' => ['7' => $contact], 'after_stock' => [],
        ]);

        try {
            app(SalesPurchaseImportBatchService::class)->revert($batch->fresh());
            $this->fail('A contact referenced by a booking must block revert before the cascade delete.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $exception) {
            $this->assertSame(422, $exception->getStatusCode());
            $this->assertStringContainsString('dipakai data bisnis lain', $exception->getMessage());
        }

        $this->assertDatabaseHas('contacts', ['id' => 7, 'name' => 'Batch Customer']);
        $this->assertDatabaseHas('bookings', ['id' => 50, 'contact_id' => 7]);
        $this->assertSame('imported', $batch->fresh()->status);
    }

    public function test_sales_product_revert_blocks_inventory_records_that_cascade_from_variations(): void
    {
        DB::table('products')->insert(['id' => 5, 'business_id' => 7, 'name' => 'Batch Product']);
        DB::table('variations')->insert(['id' => 11, 'product_id' => 5, 'sub_sku' => 'BATCH-5']);
        DB::table('transactions')->insert([
            'id' => 40, 'business_id' => 7, 'created_by' => 99, 'type' => 'sell', 'location_id' => 2,
            'invoice_no' => 'INV-40', 'return_parent_id' => null, 'contact_id' => null,
        ]);
        $batch = $this->batch('sales_sales', 'importing');
        app(SalesPurchaseImportBatchService::class)->completeImport($batch, [
            'source_rows' => 1, 'options' => ['location_id' => 2],
        ], ['transaction_ids' => [40], 'product_ids' => [5]]);
        DB::table('inventory_products')->insert([
            'id' => 60, 'product_id' => 5, 'variation_id' => 11, 'transaction_id' => 40,
            'amount_after_inventory' => 1, 'Amount_difference' => 1, 'inventory_type' => 'stocktake', 'qty_before' => '0',
        ]);

        try {
            app(SalesPurchaseImportBatchService::class)->revert($batch->fresh());
            $this->fail('Inventory records must block deleting their referenced variation.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $exception) {
            $this->assertSame(422, $exception->getStatusCode());
            $this->assertStringContainsString('catatan inventaris', $exception->getMessage());
        }

        $this->assertDatabaseHas('products', ['id' => 5]);
        $this->assertDatabaseHas('inventory_products', ['id' => 60, 'variation_id' => 11]);
        $this->assertSame('imported', $batch->fresh()->status);

        DB::table('inventory_products')->where('id', 60)->delete();
        DB::table('mfg_recipe_ingredients')->insert(['id' => 61, 'variation_id' => 11]);
        try {
            app(SalesPurchaseImportBatchService::class)->revert($batch->fresh());
            $this->fail('A manufacturing recipe reference must block deleting its ingredient variation.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $exception) {
            $this->assertSame(422, $exception->getStatusCode());
            $this->assertStringContainsString('bahan resep', $exception->getMessage());
        }
        $this->assertDatabaseHas('products', ['id' => 5]);
        $this->assertDatabaseHas('mfg_recipe_ingredients', ['id' => 61, 'variation_id' => 11]);
    }

    public function test_import_result_tracks_only_ids_created_by_this_batch_and_source_row_results(): void
    {
        DB::table('transactions')->insert([
            ['id' => 40, 'business_id' => 7, 'created_by' => 99, 'type' => 'sell', 'location_id' => 2, 'invoice_no' => 'IN-BATCH'],
            ['id' => 41, 'business_id' => 7, 'created_by' => 99, 'type' => 'sell', 'location_id' => 2, 'invoice_no' => 'IN-PARALLEL'],
        ]);
        $batch = $this->batch('sales_sales', 'importing');
        $before = ['source_rows' => 2, 'options' => ['location_id' => 2]];
        $tracked = [
            'transaction_ids' => [40],
            'row_outcomes' => [['source_row' => 2, 'status' => 'imported', 'transaction_id' => 40, 'message' => null]],
        ];

        $result = app(SalesPurchaseImportBatchService::class)->completeImport($batch, $before, $tracked);

        $this->assertSame([40], array_map('intval', array_keys($result['transactions'])));
        $this->assertSame([['source_row' => 2, 'status' => 'imported', 'transaction_id' => 40, 'message' => null]], $result['row_outcomes']);
        $this->assertDatabaseHas('transactions', ['id' => 41, 'invoice_no' => 'IN-PARALLEL']);
    }

    public function test_failed_import_persists_failed_and_rolled_back_source_rows(): void
    {
        $batch = $this->batch('sales_sales', 'importing');
        $batch->options_json = json_encode(['operation' => 'import', 'source_row_count' => 3], JSON_THROW_ON_ERROR);
        $batch->save();

        app(SalesPurchaseImportBatchService::class)->fail($batch, new \RuntimeException('Unit tidak ditemukan pada row 3.'));

        $result = app(UserImportBatchService::class)->readPayload($batch->fresh(), 'results');
        $this->assertSame(['rolled_back', 'failed', 'rolled_back'], array_column($result['row_outcomes'], 'status'));
        $this->assertSame('Unit tidak ditemukan pada row 3.', $result['row_outcomes'][1]['message']);
    }

    public function test_confirmation_route_queues_once_and_history_is_permission_and_business_scoped(): void
    {
        Bus::fake();
        Schema::create('business_locations', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('business_id');
        });
        DB::table('business_locations')->insert(['id' => 2, 'business_id' => 7]);
        $batch = $this->batch('sales_sales', 'preview_ready');
        $payload = [
            'batch_id' => $batch->id,
            'import_fields' => ['invoice_no', 'customer_phone_number', 'product', 'quantity', 'unit_price'],
            'group_by' => 0,
            'location_id' => 2,
        ];

        $first = $this->asBusiness(7)->postJson('/import-sales', $payload);
        $first->assertStatus(202)->assertJsonPath('success', true);
        Bus::assertDispatched(\App\Jobs\ProcessSalesPurchaseImportBatch::class, 1);
        $this->asBusiness(7)->postJson('/import-sales', $payload)->assertStatus(422);

        $this->asBusiness(7)->getJson(route('sales.import.batch.detail', $batch->id))->assertOk();
        $this->asBusiness(8)->getJson(route('sales.import.batch.detail', $batch->id))->assertNotFound();
        $this->asBusiness(7, false)->getJson(route('sales.import.batch.detail', $batch->id))->assertForbidden();

        $failedBatch = $this->batch('sales_sales', 'failed');
        $sourcePath = sys_get_temp_dir().'/sales-retry-source-'.bin2hex(random_bytes(8)).'.xlsx';
        $spreadsheet = new Spreadsheet();
        $spreadsheet->getActiveSheet()->fromArray([['Invoice'], ['INV-RETRY']], null, 'A1');
        (new Xlsx($spreadsheet))->save($sourcePath);
        $spreadsheet->disconnectWorksheets();
        try {
            app(UserImportBatchService::class)->captureWorkbook($failedBatch, $sourcePath);
            $this->asBusiness(7)->get(route('sales.import.batch.source', $failedBatch->id))->assertOk();
            $this->asBusiness(8)->get(route('sales.import.batch.source', $failedBatch->id))->assertNotFound();
            $this->asBusiness(7)->postJson(route('sales.import.batch.retry', $failedBatch->id))
                ->assertOk()->assertJsonPath('status', 'queued');
            Bus::assertDispatched(\App\Jobs\ProcessSalesPurchaseImportBatch::class, 2);
        } finally {
            @unlink($sourcePath);
        }
    }

    public function test_purchase_confirmation_retry_and_source_routes_use_purchase_scope_and_permission(): void
    {
        Bus::fake();
        Schema::create('business_locations', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('business_id');
        });
        DB::table('business_locations')->insert(['id' => 2, 'business_id' => 7]);

        $batch = $this->batch('sales_purchase', 'preview_ready');
        $payload = [
            'batch_id' => $batch->id,
            'import_fields' => ['customer_phone_number', 'product', 'quantity', 'unit_price'],
            'group_by' => 0,
            'location_id' => 2,
        ];

        $this->asBusiness(7)->postJson('/import-pembelian', $payload)
            ->assertStatus(202)->assertJsonPath('success', true);
        $this->assertSame('queued_import', $batch->fresh()->status);
        Bus::assertDispatched(
            \App\Jobs\ProcessSalesPurchaseImportBatch::class,
            fn ($job) => $this->jobProperty($job, 'kind') === 'purchase'
                && $this->jobProperty($job, 'operation') === 'import'
        );
        $this->asBusiness(7)->postJson('/import-pembelian', $payload)->assertStatus(422);

        $this->asBusiness(8)->getJson(route('purchase.import.batch.detail', $batch->id))->assertNotFound();
        $this->asBusiness(7, false)->getJson(route('purchase.import.batch.detail', $batch->id))->assertForbidden();

        $failedBatch = $this->batch('sales_purchase', 'failed');
        $sourcePath = sys_get_temp_dir().'/purchase-retry-source-'.bin2hex(random_bytes(8)).'.xlsx';
        $spreadsheet = new Spreadsheet();
        $spreadsheet->getActiveSheet()->fromArray([['Invoice'], ['PUR-RETRY']], null, 'A1');
        (new Xlsx($spreadsheet))->save($sourcePath);
        $spreadsheet->disconnectWorksheets();
        try {
            $options = json_decode((string) $failedBatch->options_json, true) ?: [];
            $options['operation'] = 'import';
            $failedBatch->forceFill(['options_json' => json_encode($options, JSON_THROW_ON_ERROR)])->save();
            app(UserImportBatchService::class)->captureWorkbook($failedBatch, $sourcePath);

            $this->asBusiness(7)->get(route('purchase.import.batch.source', $failedBatch->id))->assertOk();
            $this->asBusiness(8)->get(route('purchase.import.batch.source', $failedBatch->id))->assertNotFound();
            $this->asBusiness(7, false)->postJson(route('purchase.import.batch.retry', $failedBatch->id))->assertForbidden();
            $this->asBusiness(7)->postJson(route('purchase.import.batch.retry', $failedBatch->id))
                ->assertOk()->assertJsonPath('status', 'queued');
            $this->assertSame('queued_import', $failedBatch->fresh()->status);
        } finally {
            @unlink($sourcePath);
        }
    }

    public function test_sales_and_purchase_preview_upload_routes_queue_scoped_batches(): void
    {
        Bus::fake();
        $path = $this->workbookPath('sales-purchase-preview');

        try {
            $this->asBusiness(7, false)->postJson('/import-sales/preview', ['sales' => $this->uploadedWorkbook($path)])->assertForbidden();
            $this->asBusiness(7, false)->postJson('/import-pembelian/preview', ['sales' => $this->uploadedWorkbook($path)])->assertForbidden();

            $sales = $this->asBusiness(7)->postJson('/import-sales/preview', ['sales' => $this->uploadedWorkbook($path)]);
            $purchase = $this->asBusiness(7)->postJson('/import-pembelian/preview', ['sales' => $this->uploadedWorkbook($path)]);
            $sales->assertStatus(202)->assertJsonPath('queued', true);
            $purchase->assertStatus(202)->assertJsonPath('queued', true);

            $this->assertDatabaseHas('user_import_batches', [
                'id' => $sales->json('batch_id'), 'business_id' => 7, 'importer_key' => 'sales_sales', 'status' => 'queued_preview',
            ]);
            $this->assertDatabaseHas('user_import_batches', [
                'id' => $purchase->json('batch_id'), 'business_id' => 7, 'importer_key' => 'sales_purchase', 'status' => 'queued_preview',
            ]);
            $this->asBusiness(8)->getJson(route('purchase.import.batch.detail', $purchase->json('batch_id')))->assertNotFound();
            $this->asBusiness(7, false)->getJson(route('sales.import.batch.detail', $sales->json('batch_id')))->assertForbidden();
            Bus::assertDispatched(\App\Jobs\ProcessSalesPurchaseImportBatch::class, 2);
        } finally {
            @unlink($path);
        }
    }

    public function test_sales_and_purchase_revert_routes_enforce_permission_business_and_batch_status(): void
    {
        $routes = [
            ['sales_sales', 'sales', 'sales.import.batch.revert'],
            ['sales_purchase', 'purchase', 'purchase.import.batch.revert'],
        ];

        foreach ($routes as [$importerKey, $kind, $route]) {
            $batch = $this->batch($importerKey, 'imported');
            app(UserImportBatchService::class)->storePayload($batch, 'results', [
                'kind' => $kind, 'transactions' => [], 'line_table' => $kind === 'purchase' ? 'purchase_lines' : 'transaction_sell_lines',
                'lines' => [], 'payments' => [], 'account_transactions' => [], 'contacts' => [],
                'created_products' => [], 'after_stock' => [],
            ]);

            $this->asBusiness(7, false)->postJson(route($route, $batch->id))->assertForbidden();
            $this->asBusiness(8)->postJson(route($route, $batch->id))->assertNotFound();
            $this->asBusiness(7)->postJson(route($route, $batch->id))
                ->assertOk()->assertJsonPath('status', 'reverted');
            $this->assertSame('reverted', $batch->fresh()->status);

            $wrongStatus = $this->batch($importerKey, 'failed');
            app(UserImportBatchService::class)->storePayload($wrongStatus, 'results', ['kind' => $kind]);
            $this->asBusiness(7)->postJson(route($route, $wrongStatus->id))->assertStatus(422);
            $this->assertSame('failed', $wrongStatus->fresh()->status);
        }
    }

    private function batch(string $key, string $status = 'preview_ready'): UserImportBatch
    {
        return UserImportBatch::create([
            'business_id' => 7, 'created_by' => 99, 'importer_key' => $key,
            'source_key' => (string) \Illuminate\Support\Str::uuid(), 'file_name' => 'sales.xlsx',
            'status' => $status, 'stage' => $status,
            'options_json' => json_encode(['operation' => $status === 'imported' ? 'import' : 'preview'], JSON_THROW_ON_ERROR),
        ]);
    }

    private function createSchema(): void
    {
        Schema::create('user_import_batches', function (Blueprint $table) {
            $table->bigIncrements('id'); $table->unsignedInteger('business_id'); $table->unsignedInteger('created_by')->nullable();
            $table->string('importer_key', 64); $table->string('source_key')->nullable(); $table->string('file_name')->nullable();
            $table->string('staging_path')->nullable(); $table->string('source_snapshot_path')->nullable(); $table->string('preview_path')->nullable(); $table->string('results_path')->nullable();
            $table->string('status', 32)->default('queued_preview'); $table->string('stage', 64)->nullable();
            $table->unsignedInteger('progress_current')->default(0); $table->unsignedInteger('progress_total')->default(0); $table->unsignedTinyInteger('progress_percent')->default(0);
            $table->longText('summary_json')->nullable(); $table->longText('options_json')->nullable(); $table->longText('search_text')->nullable();
            $table->text('last_message')->nullable(); $table->longText('error_message')->nullable(); $table->dateTime('heartbeat_at')->nullable();
            $table->dateTime('started_at')->nullable(); $table->dateTime('completed_at')->nullable(); $table->dateTime('failed_at')->nullable(); $table->dateTime('reverted_at')->nullable(); $table->timestamps();
        });
        Schema::create('transactions', function (Blueprint $table) {
            $table->bigIncrements('id'); $table->unsignedInteger('business_id'); $table->unsignedInteger('created_by'); $table->string('type');
            $table->unsignedInteger('location_id'); $table->unsignedInteger('contact_id')->nullable(); $table->string('invoice_no')->nullable(); $table->string('ref_no')->nullable();
            $table->unsignedBigInteger('return_parent_id')->nullable();
        });
        Schema::create('contacts', function (Blueprint $table) {
            $table->increments('id'); $table->unsignedInteger('business_id'); $table->string('name')->nullable();
        });
        Schema::create('bookings', function (Blueprint $table) {
            $table->increments('id'); $table->unsignedInteger('contact_id');
        });
        Schema::create('products', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('business_id');
            $table->string('name')->nullable();
            $table->timestamps();
        });
        Schema::create('variations', function (Blueprint $table) {
            $table->increments('id'); $table->unsignedInteger('product_id'); $table->string('sub_sku')->nullable();
        });
        Schema::create('inventory_products', function (Blueprint $table) {
            $table->increments('id'); $table->unsignedInteger('product_id'); $table->unsignedInteger('variation_id');
            $table->unsignedInteger('transaction_id'); $table->unsignedBigInteger('amount_after_inventory')->default(0);
            $table->integer('Amount_difference')->default(0); $table->string('inventory_type'); $table->string('qty_before');
        });
        Schema::create('mfg_recipe_ingredients', function (Blueprint $table) {
            $table->increments('id'); $table->unsignedInteger('variation_id');
        });
        Schema::create('transaction_sell_lines', function (Blueprint $table) {
            $table->bigIncrements('id'); $table->unsignedBigInteger('transaction_id'); $table->unsignedInteger('product_id'); $table->unsignedInteger('variation_id');
        });
        Schema::create('purchase_lines', function (Blueprint $table) {
            $table->bigIncrements('id'); $table->unsignedBigInteger('transaction_id'); $table->unsignedInteger('product_id'); $table->unsignedInteger('variation_id');
        });
        Schema::create('transaction_payments', function (Blueprint $table) {
            $table->bigIncrements('id'); $table->unsignedBigInteger('transaction_id'); $table->decimal('amount', 22, 4);
        });
        Schema::create('variation_location_details', function (Blueprint $table) {
            $table->bigIncrements('id'); $table->unsignedInteger('product_id'); $table->unsignedInteger('variation_id'); $table->unsignedInteger('location_id'); $table->decimal('qty_available', 22, 4);
        });
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
        $user->forceFill(['id' => 99, 'business_id' => $businessId, 'first_name' => 'Importer', 'user_type' => 'user', 'allow_login' => 1]);

        return $this->actingAs($user)->withSession([
            'user' => ['id' => 99, 'business_id' => $businessId],
            'business' => ['time_zone' => 'UTC', 'currency_symbol_placement' => 'before', 'currency_precision' => 2],
            'currency' => ['symbol' => 'Rp', 'decimal_separator' => ',', 'thousand_separator' => '.'],
        ]);
    }

    private function jobProperty(object $job, string $property): mixed
    {
        $reflection = new \ReflectionProperty($job, $property);
        $reflection->setAccessible(true);

        return $reflection->getValue($job);
    }

    private function workbookPath(string $prefix): string
    {
        $path = sys_get_temp_dir().'/'.$prefix.'-'.bin2hex(random_bytes(8)).'.xlsx';
        $spreadsheet = new Spreadsheet();
        $spreadsheet->getActiveSheet()->fromArray([['Invoice', 'Customer Phone', 'Product', 'Quantity', 'Unit Price'], ['INV-1', '0812', 'Item', 1, 10]], null, 'A1');
        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();

        return $path;
    }

    private function uploadedWorkbook(string $path): UploadedFile
    {
        return new UploadedFile(
            $path,
            'sales-import.xlsx',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            null,
            true
        );
    }
}
