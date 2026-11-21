<?php

namespace Tests\Feature;

use App\BonusAyak;
use App\Services\BonusAyakImportHistoryService;
use App\UserImportBatch;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Auth\User as AuthenticatableUser;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class BonusAyakImportSharedBatchTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (! in_array('sqlite', \PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('PDO SQLite is required for isolated Bonus Ayak import tests.');
        }

        config([
            'database.default' => 'bonus_ayak_import_test',
            'database.connections.bonus_ayak_import_test' => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
                'foreign_key_constraints' => false,
            ],
        ]);
        DB::purge('bonus_ayak_import_test');
        DB::setDefaultConnection('bonus_ayak_import_test');
        Storage::fake('local');
        $this->withoutMiddleware([
            \App\Http\Middleware\SetSessionData::class,
            \App\Http\Middleware\AdminSidebarMenu::class,
        ]);
        $this->createSchemas();
        $this->createDriverAndAsset();
    }

    public function test_upload_keeps_the_preview_and_records_it_in_a_shared_business_batch(): void
    {
        $path = $this->workbook();

        try {
            $batch = $this->uploadPreview($path);

            $this->assertSame('bonus_ayak', $batch->importer_key);
            $this->assertSame('preview_ready', $batch->status);
            $this->assertSame(1, $batch->progress_current);
            $this->assertSame(100, $batch->progress_percent);
            $this->assertSame('bonus-ayak.xlsx', $batch->file_name);
            $this->assertSame(7, (int) $batch->business_id);
            $this->assertSame(999, (int) $batch->created_by);
            $this->assertNotEmpty($batch->preview_path);
            Storage::disk('local')->assertExists($batch->preview_path);
        } finally {
            @unlink($path);
        }
    }

    public function test_confirmed_rows_are_linked_to_the_shared_batch(): void
    {
        $path = $this->workbook();

        try {
            $batch = $this->uploadPreview($path);
            $previewPath = $batch->preview_path;

            $response = $this->actingAs($this->admin())
                ->withSession($this->businessSession(7))
                ->post(route('bonus-ayak.import-confirm', $batch->source_key), ['entries' => [0]]);

            $response->assertRedirect(route('bonus-ayak.index'));
            $bonus = BonusAyak::where('user_import_batch_id', $batch->id)->firstOrFail();

            $this->assertSame(55, (int) $bonus->trip);
            $this->assertSame(60000, (int) $bonus->bonus);
            $this->assertSame('imported', $batch->fresh()->status);
            $this->assertSame(1, json_decode($batch->fresh()->summary_json, true)['imported_rows']);
            $this->assertNull($batch->fresh()->preview_path);
            Storage::disk('local')->assertMissing($previewPath);
        } finally {
            @unlink($path);
        }
    }

    public function test_cancelling_a_preview_marks_the_batch_cancelled_without_saving_rows(): void
    {
        $path = $this->workbook();

        try {
            $batch = $this->uploadPreview($path);
            $previewPath = $batch->preview_path;

            $response = $this->actingAs($this->admin())
                ->withSession($this->businessSession(7))
                ->post(route('bonus-ayak.import-cancel', $batch->source_key));

            $response->assertRedirect(route('bonus-ayak.index'));
            $this->assertSame('cancelled', $batch->fresh()->status);
            $this->assertNull($batch->fresh()->preview_path);
            $this->assertSame(0, BonusAyak::count());
            Storage::disk('local')->assertMissing($previewPath);
        } finally {
            @unlink($path);
        }
    }

    public function test_preview_batch_cannot_be_opened_from_another_business_or_user(): void
    {
        $path = $this->workbook();

        try {
            $batch = $this->uploadPreview($path);

            $this->actingAs($this->admin(998))
                ->withSession($this->businessSession(8, 998))
                ->get(route('bonus-ayak.import-preview', $batch->source_key))
                ->assertNotFound();

            $this->assertSame('preview_ready', $batch->fresh()->status);
        } finally {
            @unlink($path);
        }
    }

    public function test_an_open_legacy_preview_is_adopted_by_the_shared_batch(): void
    {
        $token = (string) \Illuminate\Support\Str::uuid();
        $legacyPath = 'bonus-ayak-import-previews/999-7-'.$token.'.json';
        $payload = [
            'token' => $token,
            'user_id' => 999,
            'business_id' => 7,
            'created_at' => now()->toIso8601String(),
            'original_name' => 'legacy-bonus-ayak.xlsx',
            'entries' => [['valid' => true, 'source_row' => 2]],
            'errors' => [],
        ];
        Storage::disk('local')->put($legacyPath, json_encode($payload, JSON_THROW_ON_ERROR));

        [$batch, $preview] = app(BonusAyakImportHistoryService::class)->loadPreview($token, 7, 999);

        $this->assertSame($token, $batch->source_key);
        $this->assertSame('preview_ready', $batch->status);
        $this->assertSame('legacy-bonus-ayak.xlsx', $batch->file_name);
        $this->assertSame($payload, $preview);
        Storage::disk('local')->assertMissing($legacyPath);
        Storage::disk('local')->assertExists($batch->preview_path);
    }

    private function uploadPreview(string $path): UserImportBatch
    {
        $response = $this->actingAs($this->admin())
            ->withSession($this->businessSession(7))
            ->post(route('bonus-ayak.import'), [
                'file' => new UploadedFile(
                    $path,
                    'bonus-ayak.xlsx',
                    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                    UPLOAD_ERR_OK,
                    true
                ),
            ]);

        $response->assertRedirect();

        return UserImportBatch::where('business_id', 7)
            ->where('importer_key', 'bonus_ayak')
            ->firstOrFail();
    }

    private function workbook(): string
    {
        $path = sys_get_temp_dir().'/bonus-ayak-source-'.bin2hex(random_bytes(8)).'.xlsx';
        $spreadsheet = new Spreadsheet();
        $spreadsheet->getActiveSheet()->fromArray([
            ['tanggal', 'nama_operator', 'unit', 'trip'],
            ['2026-11-20', 'Budi Santoso', 'Excavator 1', 55],
        ], null, 'A1');
        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();

        return $path;
    }

    private function admin(int $id = 999): AuthenticatableUser
    {
        $admin = new class extends AuthenticatableUser
        {
            public function can($ability, $arguments = [])
            {
                return $ability === 'superadmin';
            }
        };
        $admin->forceFill([
            'id' => $id,
            'business_id' => 7,
            'first_name' => 'Test',
            'user_type' => 'user',
            'allow_login' => 1,
        ]);

        return $admin;
    }

    private function businessSession(int $businessId, int $userId = 999): array
    {
        return ['user' => ['id' => $userId, 'business_id' => $businessId]];
    }

    private function createDriverAndAsset(): void
    {
        DB::table('users')->insert([
            'id' => 11,
            'business_id' => 7,
            'user_type' => 'user',
            'email' => 'budi@example.test',
            'username' => 'budi',
            'surname' => '',
            'first_name' => 'Budi',
            'last_name' => 'Santoso',
        ]);
        DB::table('assets')->insert([
            'id' => 22,
            'business_id' => 7,
            'name' => 'Excavator 1',
            'asset_code' => 'EX-1',
        ]);
    }

    private function createSchemas(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('business_id')->nullable();
            $table->string('user_type')->nullable();
            $table->string('email')->nullable();
            $table->string('username')->nullable();
            $table->string('surname')->nullable();
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->softDeletes();
        });
        Schema::create('assets', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedInteger('business_id');
            $table->string('name');
            $table->string('asset_code')->nullable();
        });
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
            $table->unique(['business_id', 'importer_key', 'source_key']);
        });
        Schema::create('bonus_ayak', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedInteger('business_id');
            $table->unsignedInteger('project_id')->nullable();
            $table->date('tanggal');
            $table->string('nama_operator');
            $table->unsignedInteger('driver_id')->nullable();
            $table->string('unit');
            $table->unsignedBigInteger('asset_id')->nullable();
            $table->unsignedInteger('trip');
            $table->unsignedBigInteger('bonus');
            $table->unsignedInteger('created_by')->nullable();
            $table->string('source_fingerprint', 64)->nullable();
            $table->unsignedBigInteger('user_import_batch_id')->nullable();
            $table->timestamps();
            $table->unique(['business_id', 'source_fingerprint']);
        });
    }
}
