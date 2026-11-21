<?php

namespace Tests\Feature;

use App\UserImportBatch;
use App\Services\UserImportBatchService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Modules\Essentials\Jobs\ProcessAttendanceImportBatch;
use Modules\Essentials\Entities\EssentialsAttendance;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class AttendanceImportBatchLifecycleTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake();
        Storage::fake('local');
        $this->withoutMiddleware([
            \App\Http\Middleware\IsInstalled::class,
            \App\Http\Middleware\SetSessionData::class,
            \App\Http\Middleware\AdminSidebarMenu::class,
        ]);
    }

    public function test_ajax_upload_queues_one_business_scoped_batch_and_restores_the_workbook(): void
    {
        $path = $this->workbook();

        try {
            $first = $this->upload($path);
            $second = $this->upload($path);
            $first->assertStatus(202)->assertJsonPath('success', true);
            $second->assertStatus(202)->assertJsonPath('batch_id', $first->json('batch_id'));
            Bus::assertDispatchedTimes(ProcessAttendanceImportBatch::class, 1);

            $batch = UserImportBatch::findOrFail($first->json('batch_id'));
            $this->assertSame(7, (int) $batch->business_id);
            $this->assertSame('queued_preview', $batch->status);
            $this->assertTrue($batch->hasSourceSnapshot());
            $this->assertTrue(Storage::disk('local')->exists($batch->staging_path));

            $history = $this->actingAs($this->admin())
                ->withSession($this->businessSession(7))
                ->getJson(route('attendance.import.history', [
                    'draw' => 3,
                    'start' => 0,
                    'length' => 10,
                    'status' => 'queued_preview',
                ]));
            $history->assertOk()
                ->assertJsonPath('draw', 3)
                ->assertJsonPath('recordsTotal', 1)
                ->assertJsonPath('data.0.file_name', 'attendance-import.xlsx')
                ->assertJsonPath('data.0.has_snapshot', true);

            $this->actingAs($this->admin())
                ->withSession($this->businessSession(7))
                ->get(route('attendance.import.history.source', $batch->id))
                ->assertOk();
        } finally {
            @unlink($path);
        }
    }

    public function test_attendance_batch_detail_is_scoped_to_the_active_business(): void
    {
        $foreign = UserImportBatch::create([
            'business_id' => 8,
            'created_by' => 998,
            'importer_key' => 'attendance',
            'source_key' => (string) \Illuminate\Support\Str::uuid(),
            'file_name' => 'foreign.xlsx',
            'status' => 'imported',
        ]);

        $this->actingAs($this->admin())
            ->withSession($this->businessSession(7))
            ->getJson(route('attendance.import.history.detail', $foreign->id))
            ->assertNotFound();
    }

    public function test_revert_removes_unchanged_attendance_and_blocks_changed_rows(): void
    {
        $safe = $this->attendance('2026-11-03 08:00:00');
        $safeBatch = $this->importedBatchFor($safe);
        $this->actingAs($this->admin())
            ->withSession($this->businessSession(7))
            ->postJson(route('attendance.import.history.revert', $safeBatch->id))
            ->assertOk()
            ->assertJsonPath('success', true);
        $this->assertDatabaseMissing('essentials_attendances', ['id' => $safe->id]);

        $changed = $this->attendance('2026-11-04 08:00:00');
        $changedBatch = $this->importedBatchFor($changed);
        $changed->clock_in_note = 'Edited after import';
        $changed->save();

        $this->actingAs($this->admin())
            ->withSession($this->businessSession(7))
            ->postJson(route('attendance.import.history.revert', $changedBatch->id))
            ->assertStatus(422);
        $this->assertDatabaseHas('essentials_attendances', ['id' => $changed->id, 'clock_in_note' => 'Edited after import']);
    }

    public function test_completed_failed_job_discards_upload_and_retry_uses_the_saved_workbook_snapshot(): void
    {
        $path = $this->workbook();

        try {
            $response = $this->upload($path);
            $response->assertStatus(202);
            $batch = UserImportBatch::findOrFail($response->json('batch_id'));
            $stagingPath = $batch->staging_path;
            $this->assertNotEmpty($stagingPath);
            $this->assertTrue($batch->hasSourceSnapshot());

            (new ProcessAttendanceImportBatch((int) $batch->id, 7))->handle(app(UserImportBatchService::class));

            $batch->refresh();
            $this->assertSame('failed', $batch->status);
            $this->assertNull($batch->staging_path);
            $this->assertFalse(Storage::disk('local')->exists($stagingPath));
            $this->assertTrue($batch->hasSourceSnapshot());
            $this->actingAs($this->admin())
                ->withSession($this->businessSession(7))
                ->get(route('attendance.import.history.source', $batch->id))
                ->assertOk();

            $this->actingAs($this->admin())
                ->withSession($this->businessSession(7))
                ->postJson(route('attendance.import.history.retry', $batch->id))
                ->assertStatus(202)->assertJsonPath('status', 'queued_preview');
            $this->assertSame('queued_preview', $batch->fresh()->status);
            Bus::assertDispatchedTimes(ProcessAttendanceImportBatch::class, 2);
        } finally {
            @unlink($path);
        }
    }

    private function upload(string $path)
    {
        return $this->actingAs($this->admin())
            ->withSession($this->businessSession(7))
            ->postJson('/hrm/import-attendance', [
                'attendance' => new UploadedFile($path, 'attendance-import.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true),
            ]);
    }

    private function workbook(): string
    {
        $spreadsheet = new Spreadsheet();
        $spreadsheet->getActiveSheet()->fromArray([
            ['Email', 'Clock In', 'Clock Out', 'Shift', 'Clock In Note', 'Clock Out Note', 'IP Address'],
            ['employee@example.test', '2026-11-01 08:00:00', '2026-11-01 17:00:00', '', '', '', ''],
        ], null, 'A1');
        $path = tempnam(sys_get_temp_dir(), 'attendance-import-').'.xlsx';
        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();

        return $path;
    }

    private function attendance(string $clockIn): EssentialsAttendance
    {
        return EssentialsAttendance::create([
            'user_id' => 999,
            'business_id' => 7,
            'clock_in_time' => $clockIn,
            'clock_out_time' => '2026-11-03 17:00:00',
            'ip_address' => 'test',
            'clock_in_note' => null,
            'clock_out_note' => null,
            'essentials_shift_id' => null,
        ])->fresh();
    }

    private function importedBatchFor(EssentialsAttendance $attendance): UserImportBatch
    {
        $batch = UserImportBatch::create([
            'business_id' => 7,
            'created_by' => 999,
            'importer_key' => 'attendance',
            'source_key' => 'attendance-result-'.$attendance->id,
            'file_name' => 'attendance.xlsx',
            'status' => 'imported',
            'stage' => 'imported',
            'progress_percent' => 100,
        ]);
        app(UserImportBatchService::class)->storePayload($batch, 'results', [
            'summary' => ['imported_rows' => 1],
            'row_outcomes' => [[
                'sheet' => 'Attendance',
                'source_row' => 2,
                'status' => 'created',
                'record_id' => (int) $attendance->id,
                'attributes' => json_decode(json_encode($attendance->getAttributes(), JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR),
            ]],
        ]);

        return $batch->fresh();
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
        $admin->forceFill([
            'id' => 999,
            'business_id' => 7,
            'first_name' => 'Admin',
            'user_type' => 'user',
            'allow_login' => 1,
        ]);

        return $admin;
    }

    private function businessSession(int $businessId): array
    {
        return ['user' => ['business_id' => $businessId, 'id' => 999]];
    }
}
