<?php

namespace Modules\Essentials\Jobs;

use App\Services\UserImportBatchService;
use App\UserImportBatch;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Modules\Essentials\Entities\EssentialsAttendance;
use Modules\Essentials\Entities\Shift;
use Modules\Essentials\Services\AttendanceImportBatchService;
use App\User;

class ProcessAttendanceImportBatch implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 1800;

    public int $tries = 1;

    public function __construct(private int $batchId, private int $businessId)
    {
        $this->onQueue('imports');
    }

    public function handle(UserImportBatchService $batches): void
    {
        $batch = UserImportBatch::where('business_id', $this->businessId)
            ->where('importer_key', AttendanceImportBatchService::IMPORTER_KEY)
            ->find($this->batchId);
        if (! $batch || $batch->status !== 'queued_preview') {
            return;
        }
        $claimed = UserImportBatch::where('business_id', $this->businessId)
            ->where('importer_key', AttendanceImportBatchService::IMPORTER_KEY)
            ->where('id', $this->batchId)
            ->where('status', 'queued_preview')
            ->update([
                'status' => 'analyzing', 'stage' => 'analysis',
                'last_message' => 'Workbook Attendance sedang dianalisis.',
                'progress_current' => 0, 'progress_total' => 0, 'progress_percent' => 1,
                'started_at' => now(), 'heartbeat_at' => now(), 'updated_at' => now(),
            ]);
        if (! $claimed) {
            return;
        }

        Cache::put(AttendanceImportBatchService::progressCacheKey($this->batchId), [
            'stage' => 'analysis', 'current' => 0, 'total' => 0, 'percent' => 1,
            'message' => 'Workbook Attendance sedang dianalisis.', 'heartbeat_at' => now()->toDateTimeString(),
        ], now()->addMinutes(40));

        $temporaryPath = null;
        $stagingPath = (string) $batch->staging_path;
        try {
            $batch = $batch->fresh();
            if (filled($batch->staging_path) && Storage::disk('user_imports')->exists($batch->staging_path)) {
                $path = Storage::disk('user_imports')->path($batch->staging_path);
            } else {
                abort_unless($batch->hasSourceSnapshot(), 422, 'Snapshot workbook Attendance tidak tersedia untuk diproses ulang.');
                $temporaryPath = $batches->restoredWorkbookPath($batch);
                $path = $temporaryPath;
            }
            if (! is_file($path)) {
                throw new \RuntimeException('Workbook Attendance tidak tersedia.');
            }
            if (! $batch->hasSourceSnapshot()) {
                $batches->captureWorkbook($batch, $path);
            }
            if (! User::where('business_id', $this->businessId)->whereKey($batch->created_by)->exists()) {
                throw new \RuntimeException('Pengguna pembuat batch tidak lagi terdaftar pada business ini.');
            }
            $rows = \Excel::toArray([], $path)[0] ?? [];
            array_shift($rows);
            $progressTotal = count($rows);
            $prepared = [];
            $skippedOutcomes = [];
            $seenAttendance = [];
            $options = json_decode((string) $batch->options_json, true) ?: [];
            $ipAddress = (string) ($options['ip_address'] ?? 'import');

            DB::transaction(function () use ($rows, $batch, $batches, &$prepared, &$skippedOutcomes, &$seenAttendance, &$progressTotal, &$ipAddress): void {
                foreach ($rows as $key => $value) {
                    $sourceRow = $key + 2;
                    if (empty(array_filter($value, fn ($cell) => $cell !== null && $cell !== ''))) {
                        $skippedOutcomes[] = [
                            'sheet' => 'Attendance', 'source_row' => $sourceRow,
                            'status' => 'skipped', 'reason' => 'Baris kosong.',
                        ];
                        continue;
                    }
                    $rowNumber = $key + 1;
                    if (! empty($value[0])) {
                        $email = trim((string) $value[0]);
                        $user = User::where('business_id', $this->businessId)->where('email', $email)->first();
                        if (! $user) {
                            throw new \RuntimeException("User not found in row no. {$rowNumber}");
                        }
                    } else {
                        throw new \RuntimeException("Email is required in row no. {$rowNumber}");
                    }
                    if (empty($value[1])) {
                        throw new \RuntimeException("Clock in time is required in row no. {$rowNumber}");
                    }
                    $shiftId = null;
                    if (! empty($value[3])) {
                        $shift = Shift::where('business_id', $this->businessId)->where('name', trim((string) $value[3]))->first();
                        if (! $shift) {
                            throw new \RuntimeException("Shift not found in row no. {$rowNumber}");
                        }
                        $shiftId = $shift->id;
                    }
                    $prepared[] = [
                        'source_row' => $sourceRow,
                        'email' => $email,
                        'attributes' => [
                            'user_id' => $user->id,
                            'clock_in_time' => trim((string) $value[1]),
                            'clock_out_time' => ! empty($value[2]) ? trim((string) $value[2]) : null,
                            'essentials_shift_id' => $shiftId,
                            'clock_in_note' => ! empty($value[4]) ? trim((string) $value[4]) : null,
                            'clock_out_note' => ! empty($value[5]) ? trim((string) $value[5]) : null,
                            'ip_address' => ! empty($value[6]) ? trim((string) $value[6]) : $ipAddress,
                            'business_id' => $this->businessId,
                        ],
                    ];
                    $last = array_key_last($prepared);
                    $attendanceKey = implode('|', [
                        $this->businessId,
                        $user->id,
                        $prepared[$last]['attributes']['clock_in_time'],
                        $prepared[$last]['attributes']['clock_out_time'] ?? '',
                    ]);
                    $existing = EssentialsAttendance::where('business_id', $this->businessId)
                        ->where('user_id', $user->id)
                        ->where('clock_in_time', $prepared[$last]['attributes']['clock_in_time'])
                        ->where('clock_out_time', $prepared[$last]['attributes']['clock_out_time'])
                        ->first();
                    if (isset($seenAttendance[$attendanceKey]) || $existing) {
                        array_pop($prepared);
                        $skippedOutcomes[] = [
                            'sheet' => 'Attendance',
                            'source_row' => $sourceRow,
                            'status' => 'skipped',
                            'email' => $email,
                            'record_id' => $existing ? (int) $existing->id : null,
                            'reason' => 'Record Attendance dengan jam yang sama sudah ada pada file atau database.',
                        ];
                    } else {
                        $seenAttendance[$attendanceKey] = true;
                    }
                    $current = count($prepared);
                    Cache::put(AttendanceImportBatchService::progressCacheKey($this->batchId), [
                        'stage' => 'validation', 'current' => $current, 'total' => $progressTotal,
                        'percent' => $progressTotal > 0 ? min(49, (int) floor($current / $progressTotal * 50)) : 1,
                        'message' => "Memvalidasi Attendance baris {$sourceRow}.",
                        'heartbeat_at' => now()->toDateTimeString(),
                    ], now()->addMinutes(40));
                }

                $outcomes = $skippedOutcomes;
                foreach ($prepared as $index => $row) {
                    $attendance = EssentialsAttendance::create($row['attributes'])->fresh();
                    $outcomes[] = [
                        'sheet' => 'Attendance',
                        'source_row' => $row['source_row'],
                        'status' => 'created',
                        'email' => $row['email'],
                        'record_id' => (int) $attendance->id,
                        'attributes' => $this->modelAttributes($attendance),
                    ];
                    $current = $index + 1;
                    Cache::put(AttendanceImportBatchService::progressCacheKey($this->batchId), [
                        'stage' => 'saving', 'current' => $current, 'total' => count($prepared),
                        'percent' => count($prepared) > 0 ? min(99, 50 + (int) floor($current / count($prepared) * 49)) : 99,
                        'message' => "Menyimpan Attendance baris {$row['source_row']}.",
                        'heartbeat_at' => now()->toDateTimeString(),
                    ], now()->addMinutes(40));
                }

                $locked = UserImportBatch::where('business_id', $this->businessId)
                    ->where('importer_key', AttendanceImportBatchService::IMPORTER_KEY)
                    ->lockForUpdate()->findOrFail($this->batchId);
                if ($locked->status !== 'analyzing') {
                    throw new \RuntimeException('Status batch Attendance berubah sebelum commit.');
                }
                $results = [
                    'summary' => [
                        'source_rows' => count($rows),
                        'imported_rows' => count(array_filter($outcomes, fn (array $outcome) => ($outcome['status'] ?? null) === 'created')),
                        'skipped_rows' => count(array_filter($outcomes, fn (array $outcome) => ($outcome['status'] ?? null) === 'skipped')),
                    ],
                    'row_outcomes' => $outcomes,
                ];
                $batches->storePayload($locked, 'results', $results);
                $locked->forceFill([
                    'status' => 'imported', 'stage' => 'imported',
                    'progress_current' => count($outcomes), 'progress_total' => max(1, count($outcomes)),
                    'progress_percent' => 100,
                    'summary_json' => json_encode($results['summary'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                    'search_text' => $locked->file_name,
                    'last_message' => 'Import Attendance selesai.',
                    'error_message' => null, 'heartbeat_at' => null,
                    'completed_at' => now(), 'failed_at' => null,
                ])->save();
            });
            Cache::forget(AttendanceImportBatchService::progressCacheKey($this->batchId));
        } catch (\Throwable $exception) {
            $failed = UserImportBatch::where('business_id', $this->businessId)
                ->where('importer_key', AttendanceImportBatchService::IMPORTER_KEY)
                ->where('id', $this->batchId)
                ->where('status', 'analyzing')->first();
            if ($failed) {
                app(AttendanceImportBatchService::class)->fail($failed, $exception);
            }
            Cache::forget(AttendanceImportBatchService::progressCacheKey($this->batchId));
            report($exception);
        } finally {
            $this->discardStaging($batch->fresh());
            if ($temporaryPath && is_file($temporaryPath)) {
                @unlink($temporaryPath);
                @rmdir(dirname($temporaryPath));
            }
        }
    }

    public function failed(\Throwable $exception): void
    {
        $batch = UserImportBatch::where('business_id', $this->businessId)
            ->where('importer_key', AttendanceImportBatchService::IMPORTER_KEY)
            ->where('id', $this->batchId)
            ->whereIn('status', ['queued_preview', 'analyzing', 'importing'])->first();
        if ($batch) {
            app(AttendanceImportBatchService::class)->fail($batch, $exception);
            $this->discardStaging($batch->fresh());
        }
        Cache::forget(AttendanceImportBatchService::progressCacheKey($this->batchId));
    }

    private function discardStaging(UserImportBatch $batch): void
    {
        $path = (string) $batch->staging_path;
        if ($path === '') {
            return;
        }

        Storage::disk('user_imports')->delete($path);
        UserImportBatch::where('business_id', $this->businessId)
            ->where('importer_key', AttendanceImportBatchService::IMPORTER_KEY)
            ->where('id', $this->batchId)
            ->where('staging_path', $path)
            ->update(['staging_path' => null, 'updated_at' => now()]);
    }

    private function modelAttributes(EssentialsAttendance $attendance): array
    {
        return json_decode(json_encode($attendance->getAttributes(), JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
    }
}
