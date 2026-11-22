<?php

namespace Tests\Feature;

use App\Services\SellingPriceGroupImportBatchService;
use App\Services\UserImportBatchService;
use App\SellingPriceGroup;
use App\UserImportBatch;
use App\VariationGroupPrice;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class SellingPriceGroupImportBatchLifecycleTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (! in_array('sqlite', \PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('PDO SQLite is required for isolated Selling Price Group batch tests.');
        }

        config([
            'database.default' => 'selling_price_group_import_test',
            'database.connections.selling_price_group_import_test' => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
                'foreign_key_constraints' => false,
            ],
        ]);
        DB::purge('selling_price_group_import_test');
        DB::setDefaultConnection('selling_price_group_import_test');
        Storage::fake('local');
        Storage::fake('user_imports');
        $this->withoutMiddleware([
            \App\Http\Middleware\IsInstalled::class,
            \App\Http\Middleware\SetSessionData::class,
            \App\Http\Middleware\AdminSidebarMenu::class,
        ]);
        $this->createSchema();
        DB::table('products')->insert(['id' => 1, 'business_id' => 7]);
        DB::table('variations')->insert([
            'id' => 11,
            'product_id' => 1,
            'sub_sku' => 'SKU-11',
            'sell_price_inc_tax' => 100,
            'default_sell_price' => 90,
            'default_purchase_price' => 70,
            'profit_percent' => 28.57,
        ]);
        DB::table('selling_price_groups')->insert(['id' => 21, 'business_id' => 7, 'name' => 'Retail', 'is_active' => 1]);
        VariationGroupPrice::create(['variation_id' => 11, 'price_group_id' => 21, 'price_inc_tax' => 120]);
    }

    public function test_import_restores_previous_price_group_value_when_batch_is_reverted_safely(): void
    {
        [$batch, $path] = $this->batchAndWorkbook();

        try {
            app(UserImportBatchService::class)->captureWorkbook($batch, $path);
            app(SellingPriceGroupImportBatchService::class)->process($batch, $path);

            $this->assertSame('imported', $batch->fresh()->status);
            $this->assertSame(150.0, (float) VariationGroupPrice::firstOrFail()->price_inc_tax);

            app(SellingPriceGroupImportBatchService::class)->revert($batch->fresh());

            $this->assertSame(120.0, (float) VariationGroupPrice::firstOrFail()->price_inc_tax);
            $this->assertSame('reverted', $batch->fresh()->status);
        } finally {
            @unlink($path);
        }
    }

    public function test_revert_is_blocked_when_the_imported_price_changed_again(): void
    {
        [$batch, $path] = $this->batchAndWorkbook();

        try {
            app(UserImportBatchService::class)->captureWorkbook($batch, $path);
            app(SellingPriceGroupImportBatchService::class)->process($batch, $path);
            VariationGroupPrice::firstOrFail()->update(['price_inc_tax' => 160]);

            try {
                app(SellingPriceGroupImportBatchService::class)->revert($batch->fresh());
                $this->fail('Changed prices must block batch revert.');
            } catch (\Symfony\Component\HttpKernel\Exception\HttpException $exception) {
                $this->assertSame(422, $exception->getStatusCode());
            }

            $this->assertSame(160.0, (float) VariationGroupPrice::firstOrFail()->price_inc_tax);
            $this->assertSame('imported', $batch->fresh()->status);
        } finally {
            @unlink($path);
        }
    }

    public function test_history_detail_and_source_routes_enforce_permission_and_business_scope(): void
    {
        Bus::fake();
        [$batch, $path] = $this->batchAndWorkbook();

        try {
            app(UserImportBatchService::class)->captureWorkbook($batch, $path);

            $detail = $this->asBusiness(7)->getJson(route('selling-price-group.import.history.detail', $batch->id));
            $detail->assertOk()->assertJsonPath('batch.id', $batch->id);
            $this->asBusiness(8)->getJson(route('selling-price-group.import.history.detail', $batch->id))->assertNotFound();
            $this->asBusiness(7, false)->getJson(route('selling-price-group.import.history.detail', $batch->id))->assertForbidden();
            $this->asBusiness(7)->get(route('selling-price-group.import.history.source', $batch->id))->assertOk();

            $batch->forceFill(['status' => 'failed', 'stage' => 'failed'])->save();
            $this->asBusiness(7)->postJson(route('selling-price-group.import.history.retry', $batch->id))
                ->assertStatus(202)->assertJsonPath('status', 'queued_preview');
            Bus::assertDispatched(\App\Jobs\ProcessSellingPriceGroupImportBatch::class, 1);
        } finally {
            @unlink($path);
        }
    }

    public function test_upload_route_queues_workbook_only_for_a_user_with_product_permission(): void
    {
        Bus::fake();
        $path = $this->workbookPath();

        try {
            $this->asBusiness(7, false)->postJson('/import-product-price', [
                'product_group_prices' => $this->uploadedWorkbook($path),
            ])->assertForbidden();

            $response = $this->asBusiness(7)->postJson('/import-product-price', [
                'product_group_prices' => $this->uploadedWorkbook($path),
            ]);
            $response->assertStatus(202)->assertJsonPath('success', true)->assertJsonPath('status', 'queued_preview');

            $batch = UserImportBatch::findOrFail($response->json('batch_id'));
            $this->assertSame(7, (int) $batch->business_id);
            $this->assertSame(SellingPriceGroupImportBatchService::IMPORTER_KEY, $batch->importer_key);
            $this->assertSame('queued_preview', $batch->status);
            Bus::assertDispatched(\App\Jobs\ProcessSellingPriceGroupImportBatch::class, 1);
        } finally {
            @unlink($path);
        }
    }

    public function test_revert_route_enforces_permission_business_scope_and_snapshot_safety(): void
    {
        [$batch, $path] = $this->batchAndWorkbook();

        try {
            app(UserImportBatchService::class)->captureWorkbook($batch, $path);
            app(SellingPriceGroupImportBatchService::class)->process($batch, $path);

            $this->asBusiness(8)->postJson(route('selling-price-group.import.history.revert', $batch->id))->assertNotFound();
            $this->asBusiness(7, false)->postJson(route('selling-price-group.import.history.revert', $batch->id))->assertForbidden();
            $this->asBusiness(7)->postJson(route('selling-price-group.import.history.revert', $batch->id))
                ->assertOk()->assertJsonPath('status', 'reverted');
            $this->assertSame(120.0, (float) VariationGroupPrice::firstOrFail()->price_inc_tax);
            $this->assertSame('reverted', $batch->fresh()->status);
        } finally {
            @unlink($path);
        }

        [$unsafeBatch, $unsafePath] = $this->batchAndWorkbook();
        try {
            app(UserImportBatchService::class)->captureWorkbook($unsafeBatch, $unsafePath);
            app(SellingPriceGroupImportBatchService::class)->process($unsafeBatch, $unsafePath);
            VariationGroupPrice::firstOrFail()->update(['price_inc_tax' => 160]);

            $this->asBusiness(7)->postJson(route('selling-price-group.import.history.revert', $unsafeBatch->id))->assertStatus(422);
            $this->assertSame(160.0, (float) VariationGroupPrice::firstOrFail()->price_inc_tax);
            $this->assertSame('imported', $unsafeBatch->fresh()->status);
        } finally {
            @unlink($unsafePath);
        }
    }

    private function batchAndWorkbook(): array
    {
        $batch = UserImportBatch::create([
            'business_id' => 7,
            'created_by' => 99,
            'importer_key' => SellingPriceGroupImportBatchService::IMPORTER_KEY,
            'source_key' => (string) \Illuminate\Support\Str::uuid(),
            'file_name' => 'prices.xlsx',
            'status' => 'importing',
            'stage' => 'importing',
        ]);
        $path = sys_get_temp_dir().'/selling-price-group-'.bin2hex(random_bytes(8)).'.xlsx';
        $spreadsheet = new Spreadsheet();
        $spreadsheet->getActiveSheet()->fromArray([
            ['Product', 'SKU', 'Selling Price Including Tax', 'Retail'],
            ['Widget', 'SKU-11', 100, 150],
        ], null, 'A1');
        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();

        return [$batch, $path];
    }

    private function workbookPath(): string
    {
        $path = sys_get_temp_dir().'/selling-price-group-upload-'.bin2hex(random_bytes(8)).'.xlsx';
        $spreadsheet = new Spreadsheet();
        $spreadsheet->getActiveSheet()->fromArray([
            ['Product', 'SKU', 'Selling Price Including Tax', 'Retail'],
            ['Widget', 'SKU-11', 100, 150],
        ], null, 'A1');
        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();

        return $path;
    }

    private function uploadedWorkbook(string $path): UploadedFile
    {
        return new UploadedFile(
            $path,
            'prices.xlsx',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            null,
            true
        );
    }

    private function createSchema(): void
    {
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
        Schema::create('products', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('business_id');
        });
        Schema::create('variations', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('product_id');
            $table->string('sub_sku');
            $table->decimal('sell_price_inc_tax', 22, 4);
            $table->decimal('default_sell_price', 22, 4);
            $table->decimal('default_purchase_price', 22, 4);
            $table->decimal('profit_percent', 22, 4);
            $table->softDeletes();
            $table->timestamps();
        });
        Schema::create('selling_price_groups', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('business_id');
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->softDeletes();
            $table->timestamps();
        });
        Schema::create('variation_group_prices', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('variation_id');
            $table->unsignedInteger('price_group_id');
            $table->decimal('price_inc_tax', 22, 4);
            $table->timestamps();
            $table->unique(['variation_id', 'price_group_id']);
        });
        Schema::create('transaction_sell_lines', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('transaction_id');
            $table->unsignedInteger('variation_id');
        });
        Schema::create('transactions', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedInteger('business_id');
            $table->timestamps();
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
        };
        $user->allowed = $allowed;
        $user->forceFill(['id' => 99, 'business_id' => $businessId, 'first_name' => 'Price Admin', 'user_type' => 'user', 'allow_login' => 1]);

        return $this->actingAs($user)->withSession([
            'user' => ['id' => 99, 'business_id' => $businessId],
            'business' => ['time_zone' => 'UTC', 'currency_symbol_placement' => 'before', 'currency_precision' => 2],
            'currency' => ['symbol' => 'Rp', 'decimal_separator' => ',', 'thousand_separator' => '.'],
        ]);
    }
}
