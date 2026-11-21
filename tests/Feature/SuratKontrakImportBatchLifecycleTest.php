<?php

namespace Tests\Feature;

use App\Services\UserImportBatchService;
use App\UserImportBatch;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Modules\Crm\Entities\SuratKontrak;
use Modules\Crm\Jobs\ProcessSuratKontrakImportBatch;
use Modules\Crm\Services\SuratKontrakImportBatchService;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class SuratKontrakImportBatchLifecycleTest extends TestCase
{
    private int $businessId;

    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake();
        Storage::fake('local');
        $this->withoutMiddleware([
            \App\Http\Middleware\SetSessionData::class,
            \App\Http\Middleware\AdminSidebarMenu::class,
        ]);
        $this->createBusinessFixture();
    }

    public function test_ajax_upload_queues_a_business_scoped_batch_with_restored_source_and_row_detail(): void
    {
        $path = $this->workbook('Kontrak A', 'PT Pelanggan A');
        try {
            $response = $this->upload($path);
            $response->assertStatus(202)->assertJsonPath('success', true);
            Bus::assertDispatchedTimes(ProcessSuratKontrakImportBatch::class, 1);

            $batch = UserImportBatch::where('importer_key', SuratKontrakImportBatchService::IMPORTER_KEY)->firstOrFail();
            $this->assertSame($this->businessId, (int) $batch->business_id);
            $this->assertSame('queued_preview', $batch->status);
            $this->assertTrue($batch->hasSourceSnapshot());
            $this->assertNull($batch->staging_path);

            $this->actingAs($this->admin())->withSession($this->businessSession($this->businessId))
                ->getJson(route('crm.surat-kontrak-import.history', ['draw' => 5, 'start' => 0, 'length' => 10, 'status' => 'queued_preview']))
                ->assertOk()->assertJsonPath('draw', 5)->assertJsonPath('recordsTotal', 1);
            $this->actingAs($this->admin())->withSession($this->businessSession($this->businessId))
                ->get(route('crm.surat-kontrak-import.source', $batch->id))->assertOk();

            app(SuratKontrakImportBatchService::class)->process($batch->fresh());
            $batch->refresh();
            $this->assertSame('imported', $batch->status);
            $this->assertDatabaseHas('surat_kontrak', ['business_id' => $this->businessId, 'import_batch' => $batch->id, 'pekerjaan' => 'Kontrak A']);

            $detail = $this->actingAs($this->admin())->withSession($this->businessSession($this->businessId))
                ->getJson(route('crm.surat-kontrak-import.detail', $batch->id));
            $detail->assertOk()->assertJsonPath('results.summary.imported_rows', 1)
                ->assertJsonPath('results.row_outcomes.0.sheet', 'Kontrak')
                ->assertJsonPath('results.row_outcomes.0.source_row', 2)
                ->assertJsonPath('results.row_outcomes.0.status', 'created');
        } finally {
            @unlink($path);
        }
    }

    public function test_contract_batch_revert_removes_unchanged_rows_and_blocks_changed_rows(): void
    {
        $safePath = $this->workbook('Kontrak aman', 'PT Pelanggan aman');
        $changedPath = $this->workbook('Kontrak berubah', 'PT Pelanggan berubah');
        try {
            $safeBatch = $this->queuedBatch($safePath, 'safe-spk');
            app(SuratKontrakImportBatchService::class)->process($safeBatch);
            $safeContract = SuratKontrak::where('import_batch', $safeBatch->id)->firstOrFail();
            $this->actingAs($this->admin())->withSession($this->businessSession($this->businessId))
                ->postJson(route('crm.surat-kontrak-import.revert', $safeBatch->id))
                ->assertOk()->assertJsonPath('status', 'reverted');
            $this->assertDatabaseMissing('surat_kontrak', ['id' => $safeContract->id]);

            $changedBatch = $this->queuedBatch($changedPath, 'changed-spk');
            app(SuratKontrakImportBatchService::class)->process($changedBatch);
            $changedContract = SuratKontrak::where('import_batch', $changedBatch->id)->firstOrFail();
            $changedContract->pekerjaan = 'Diubah sesudah import';
            $changedContract->save();
            $this->actingAs($this->admin())->withSession($this->businessSession($this->businessId))
                ->postJson(route('crm.surat-kontrak-import.revert', $changedBatch->id))
                ->assertStatus(422);
            $this->assertDatabaseHas('surat_kontrak', ['id' => $changedContract->id, 'pekerjaan' => 'Diubah sesudah import']);
        } finally {
            @unlink($safePath);
            @unlink($changedPath);
        }
    }

    public function test_contract_batch_detail_and_source_are_business_scoped_and_duplicate_upload_is_idempotent(): void
    {
        $path = $this->workbook('Kontrak lintas bisnis', 'PT Contoh');
        try {
            $first = $this->upload($path)->assertStatus(202);
            $batchId = $first->json('batch_id');
            $again = $this->upload($path)->assertStatus(202)->assertJsonPath('batch_id', $batchId);
            Bus::assertDispatchedTimes(ProcessSuratKontrakImportBatch::class, 1);

            $this->actingAs($this->admin())->withSession($this->businessSession($this->businessId + 1))
                ->getJson(route('crm.surat-kontrak-import.detail', $batchId))->assertNotFound();
            $this->actingAs($this->admin())->withSession($this->businessSession($this->businessId + 1))
                ->get(route('crm.surat-kontrak-import.source', $batchId))->assertNotFound();
        } finally {
            @unlink($path);
        }
    }

    public function test_failed_contract_batch_retry_is_queued_again_without_creating_a_second_batch(): void
    {
        $path = $this->workbook('Kontrak retry', 'PT Retry');
        try {
            $response = $this->upload($path)->assertStatus(202);
            $batch = UserImportBatch::where('importer_key', SuratKontrakImportBatchService::IMPORTER_KEY)->firstOrFail();
            $batch->forceFill(['status' => 'partial', 'stage' => 'partial'])->save();

            $this->actingAs($this->admin())->withSession($this->businessSession($this->businessId))
                ->postJson(route('crm.surat-kontrak-import.retry', $batch->id))
                ->assertOk()->assertJsonPath('status', 'queued_preview');
            $this->assertDatabaseHas('user_import_batches', ['id' => $batch->id, 'status' => 'queued_preview']);
            Bus::assertDispatchedTimes(ProcessSuratKontrakImportBatch::class, 2);
        } finally {
            @unlink($path);
        }
    }

    private function queuedBatch(string $path, string $sourceKey): UserImportBatch
    {
        $batch = app(UserImportBatchService::class)->create([
            'business_id' => $this->businessId,
            'created_by' => $this->userId,
            'importer_key' => SuratKontrakImportBatchService::IMPORTER_KEY,
            'source_key' => $sourceKey,
            'file_name' => 'kontrak.xlsx',
            'status' => 'queued_preview',
            'stage' => 'queued',
        ]);
        app(UserImportBatchService::class)->captureWorkbook($batch, $path);

        return $batch->fresh();
    }

    private function upload(string $path)
    {
        return $this->actingAs($this->admin())->withSession($this->businessSession($this->businessId))
            ->postJson('/crm/surat-kontrak/importproses', [
                'import_file' => new UploadedFile($path, 'kontrak.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true),
            ]);
    }

    private function workbook(string $job, string $contact): string
    {
        $spreadsheet = new Spreadsheet();
        $spreadsheet->getActiveSheet()->setTitle('Kontrak');
        $spreadsheet->getActiveSheet()->fromArray([
            ['Nomor', 'Contact ID', 'Pekerjaan', 'Mulai Kerja', 'Selesai', 'Tonase'],
            ['SPK-'.substr(sha1($job), 0, 8), $contact, $job, '2026-11-01', '2026-11-05', 143],
        ], null, 'A1');
        $path = tempnam(sys_get_temp_dir(), 'surat-kontrak-').'.xlsx';
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
                return true;
            }
        };
        $admin->forceFill(['id' => $this->userId, 'business_id' => $this->businessId, 'first_name' => 'Admin', 'user_type' => 'user', 'allow_login' => 1]);

        return $admin;
    }

    private function businessSession(int $businessId): array
    {
        return ['user' => ['business_id' => $businessId, 'id' => $this->userId]];
    }

    private function createBusinessFixture(): void
    {
        $currencyId = DB::table('currencies')->value('id');
        if (! $currencyId) {
            $currencyId = DB::table('currencies')->insertGetId([
                'country' => 'Test', 'currency' => 'Test', 'code' => 'TST', 'symbol' => 'T',
                'thousand_separator' => ',', 'decimal_separator' => '.', 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        $this->userId = (int) DB::table('users')->insertGetId([
            'surname' => 'Test', 'first_name' => 'Importer', 'username' => 'crm-import-'.bin2hex(random_bytes(6)),
            'password' => bcrypt('test'), 'user_type' => 'user', 'allow_login' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->businessId = (int) DB::table('business')->insertGetId([
            'name' => 'CRM Import Test', 'currency_id' => $currencyId, 'tax_number_1' => '', 'tax_label_1' => 'VAT',
            'owner_id' => $this->userId, 'time_zone' => 'UTC', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('users')->where('id', $this->userId)->update(['business_id' => $this->businessId]);
    }
}
