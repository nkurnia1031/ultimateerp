<?php

namespace Modules\Essentials\Services;

use App\Services\UserImportBatchService;
use App\UserImportBatch;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Modules\Essentials\Entities\EssentialsAttendance;

class AttendanceImportBatchService
{
    public const IMPORTER_KEY = 'attendance';

    public function __construct(private UserImportBatchService $batches)
    {
    }

    public static function progressCacheKey(int $batchId): string
    {
        return 'attendance_import_batch_progress:'.$batchId;
    }

    public function history(Request $request, int $businessId, bool $canRevert): array
    {
        $table = $this->batches->dataTable($request, $businessId, self::IMPORTER_KEY);
        $table['data'] = collect($table['data'])->map(function (array $row) {
            $cached = Cache::get(self::progressCacheKey((int) $row['id']), []);
            if (is_array($cached) && $cached !== [] && $row['active']) {
                $row['stage'] = $cached['stage'] ?? $row['stage'];
                $row['progress_current'] = (int) ($cached['current'] ?? $row['progress_current']);
                $row['progress_total'] = (int) ($cached['total'] ?? $row['progress_total']);
                $row['progress_percent'] = (int) ($cached['percent'] ?? $row['progress_percent']);
                $row['last_message'] = $cached['message'] ?? $row['last_message'];
                $row['heartbeat_at'] = $cached['heartbeat_at'] ?? $row['heartbeat_at'];
            }
            $row['detail_url'] = route('attendance.import.history.detail', $row['id']);
            $row['restore_url'] = $row['has_snapshot'] ? route('attendance.import.history.source', $row['id']) : null;
            $row['revert_url'] = route('attendance.import.history.revert', $row['id']);
            $row['retry_url'] = route('attendance.import.history.retry', $row['id']);
            $row['can_retry'] = $row['status'] === 'failed' && $row['has_snapshot'];
            $row['can_revert'] = $canRevert && $row['status'] === 'imported';

            return $row;
        })->values()->all();

        return $table;
    }

    public function detail(int $batchId, int $businessId): array
    {
        $batch = $this->batches->findForBusiness($batchId, $businessId, self::IMPORTER_KEY);

        return [
            'batch' => $batch,
            'results' => $batch->results_path ? $this->batches->readPayload($batch, 'results') : [],
        ];
    }

    public function fail(UserImportBatch $batch, \Throwable $exception): void
    {
        $message = mb_substr($exception->getMessage(), 0, 65000);
        $sourceRow = null;
        if (preg_match('/row no\.\s*(\d+)/i', $message, $matches)) {
            $sourceRow = (int) $matches[1] + 1;
        }
        $results = [
            'summary' => ['failed_rows' => 1],
            'row_outcomes' => [[
                'sheet' => 'Attendance',
                'source_row' => $sourceRow,
                'status' => 'failed',
                'error' => $message,
            ]],
        ];
        try {
            $this->batches->storePayload($batch, 'results', $results);
        } catch (\Throwable $storageException) {
            report($storageException);
        }
        $batch->forceFill([
            'status' => 'failed',
            'stage' => 'failed',
            'last_message' => 'Import Attendance gagal.',
            'error_message' => $message,
            'summary_json' => json_encode($results['summary'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'heartbeat_at' => null,
            'completed_at' => now(),
            'failed_at' => now(),
        ])->save();
    }

    public function revert(UserImportBatch $batch): void
    {
        abort_unless($batch->status === 'imported', 422, 'Batch Attendance ini tidak berada pada status imported.');
        $results = $this->batches->readPayload($batch, 'results');
        $rows = $results['row_outcomes'] ?? [];

        DB::transaction(function () use ($batch, $rows) {
            $locked = UserImportBatch::where('business_id', $batch->business_id)
                ->where('importer_key', self::IMPORTER_KEY)
                ->lockForUpdate()
                ->findOrFail($batch->id);
            abort_unless($locked->status === 'imported', 422, 'Batch Attendance ini sudah berubah status.');

            foreach ($rows as $row) {
                if (($row['status'] ?? null) !== 'created') {
                    continue;
                }
                $attendance = EssentialsAttendance::where('business_id', $batch->business_id)
                    ->lockForUpdate()
                    ->find((int) ($row['record_id'] ?? 0));
                abort_if(! $attendance, 422, 'Record Attendance hasil import tidak tersedia; revert diblokir.');
                if ($this->attributes($attendance) !== ($row['attributes'] ?? [])) {
                    abort(422, 'Attendance hasil import sudah berubah sehingga revert diblokir.');
                }

                $month = (int) date('n', strtotime($attendance->clock_in_time));
                $year = (int) date('Y', strtotime($attendance->clock_in_time));
                $hasPayroll = DB::table('transactions')
                    ->where('business_id', $batch->business_id)
                    ->where('type', 'payroll')
                    ->where('expense_for', $attendance->user_id)
                    ->whereYear('transaction_date', $year)
                    ->whereMonth('transaction_date', $month)
                    ->exists();
                abort_if($hasPayroll, 422, 'Attendance bulan ini sudah terkait payroll sehingga revert diblokir.');
            }

            $ids = collect($rows)->where('status', 'created')->pluck('record_id')->map(fn ($id) => (int) $id)->all();
            if ($ids !== []) {
                EssentialsAttendance::where('business_id', $batch->business_id)->whereIn('id', $ids)->delete();
            }
            $locked->forceFill([
                'status' => 'reverted',
                'stage' => 'reverted',
                'last_message' => 'Record Attendance hasil batch berhasil dikembalikan.',
                'progress_percent' => 100,
                'heartbeat_at' => null,
                'reverted_at' => now(),
                'updated_at' => now(),
            ])->save();
        });
    }

    private function attributes(EssentialsAttendance $attendance): array
    {
        return json_decode(json_encode($attendance->getAttributes(), JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
    }
}
