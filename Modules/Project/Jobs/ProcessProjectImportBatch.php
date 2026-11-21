<?php

namespace Modules\Project\Jobs;

use App\Services\UserImportBatchService;
use App\User;
use App\UserImportBatch;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Modules\Project\Services\ProjectImportBatchService;
use Modules\Project\Services\ProjectWorkbookImporter;

class ProcessProjectImportBatch implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 1800;

    public int $tries = 1;

    public function __construct(private int $batchId, private int $businessId)
    {
        $this->onQueue('imports');
    }

    public function handle(ProjectWorkbookImporter $importer, UserImportBatchService $batches): void
    {
        $batch = UserImportBatch::where('business_id', $this->businessId)
            ->where('importer_key', ProjectImportBatchService::IMPORTER_KEY)
            ->find($this->batchId);
        if (! $batch || $batch->status !== 'queued_preview') {
            return;
        }

        $claimed = UserImportBatch::where('business_id', $this->businessId)
            ->where('importer_key', ProjectImportBatchService::IMPORTER_KEY)
            ->where('id', $this->batchId)
            ->where('status', 'queued_preview')
            ->update([
                'status' => 'analyzing',
                'stage' => 'processing_workbook',
                'last_message' => 'Workbook Project sedang dianalisis dan diproses.',
                'progress_current' => 0,
                'progress_total' => 0,
                'progress_percent' => 1,
                'started_at' => now(),
                'heartbeat_at' => now(),
                'updated_at' => now(),
            ]);
        if (! $claimed) {
            return;
        }
        Cache::put(ProjectImportBatchService::progressCacheKey($this->batchId), [
            'stage' => 'processing_workbook',
            'current' => 0,
            'total' => 0,
            'percent' => 1,
            'message' => 'Workbook Project sedang dianalisis.',
            'heartbeat_at' => now()->toDateTimeString(),
        ], now()->addMinutes(40));

        try {
            $batch = $batch->fresh();
            $sourcePath = Storage::disk('local')->path((string) $batch->staging_path);
            if (! is_file($sourcePath)) {
                throw new \RuntimeException('Workbook sumber Project tidak tersedia.');
            }
            $user = User::where('business_id', $this->businessId)->findOrFail((int) $batch->created_by);
            Auth::setUser($user);

            DB::transaction(function () use ($batch, $sourcePath, $user, $importer, $batches): void {
                $locked = UserImportBatch::where('business_id', $this->businessId)
                    ->where('importer_key', ProjectImportBatchService::IMPORTER_KEY)
                    ->lockForUpdate()
                    ->findOrFail($this->batchId);
                if ($locked->status !== 'analyzing') {
                    return;
                }

                $result = $importer->import($sourcePath, $this->businessId, (int) $user->id, function (array $progress): void {
                    $current = (int) ($progress['current'] ?? 0);
                    $total = max(0, (int) ($progress['total'] ?? 0));
                    Cache::put(ProjectImportBatchService::progressCacheKey($this->batchId), [
                        'stage' => (string) ($progress['stage'] ?? 'processing_workbook'),
                        'current' => $current,
                        'total' => $total,
                        'percent' => $total > 0 ? min(95, max(1, (int) floor($current / $total * 100))) : 1,
                        'message' => (string) ($progress['message'] ?? 'Workbook Project sedang diproses.'),
                        'heartbeat_at' => now()->toDateTimeString(),
                    ], now()->addMinutes(40));
                });
                $project = $result['project'];
                $resultPayload = [
                    'source_rows' => (int) ($result['source_rows'] ?? 0),
                    'project' => [
                        'id' => (int) $project->id,
                        'name' => (string) $project->name,
                        'updated_at' => (string) $project->fresh()->updated_at,
                        'attributes' => $this->modelAttributes($project->fresh()),
                        'task_ids' => $result['task_ids'] ?? [],
                        'tasks' => $result['tasks_snapshot'] ?? [],
                        'time_log_ids' => $result['time_log_ids'] ?? [],
                        'time_logs' => $result['time_logs_snapshot'] ?? [],
                        'invoice_id' => $result['invoice'] ? (int) $result['invoice']->id : null,
                    ],
                    'summary' => [
                        'tasks' => (int) $result['tasks'],
                        'time_logs' => (int) $result['time_logs'],
                        'invoice' => $result['invoice'] ? 1 : 0,
                    ],
                    'row_outcomes' => $result['row_outcomes'] ?? [],
                ];
                $batches->storePayload($locked, 'results', $resultPayload);
                $locked->forceFill([
                    'status' => 'imported',
                    'stage' => 'imported',
                    'progress_current' => max(1, (int) ($result['source_rows'] ?? 1)),
                    'progress_total' => max(1, (int) ($result['source_rows'] ?? 1)),
                    'progress_percent' => 100,
                    'summary_json' => json_encode($resultPayload['summary'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                    'search_text' => $project->name.' '.$batch->file_name,
                    'last_message' => 'Import Project selesai.',
                    'error_message' => null,
                    'heartbeat_at' => null,
                    'completed_at' => now(),
                    'failed_at' => null,
                ])->save();
            });
            Cache::forget(ProjectImportBatchService::progressCacheKey($this->batchId));
        } catch (\Throwable $exception) {
            $failedBatch = UserImportBatch::where('business_id', $this->businessId)
                ->where('importer_key', ProjectImportBatchService::IMPORTER_KEY)
                ->where('id', $this->batchId)
                ->whereIn('status', ['analyzing', 'importing'])
                ->first();
            if ($failedBatch) {
                app(ProjectImportBatchService::class)->fail($failedBatch, $exception);
            }
            Cache::forget(ProjectImportBatchService::progressCacheKey($this->batchId));
            report($exception);
        } finally {
            Auth::logout();
        }
    }

    public function failed(\Throwable $exception): void
    {
        $batch = UserImportBatch::where('business_id', $this->businessId)
            ->where('importer_key', ProjectImportBatchService::IMPORTER_KEY)
            ->where('id', $this->batchId)
            ->whereIn('status', ['queued_preview', 'analyzing', 'importing'])
            ->first();
        if ($batch) {
            app(ProjectImportBatchService::class)->fail($batch, $exception);
        }
        Cache::forget(ProjectImportBatchService::progressCacheKey($this->batchId));
    }

    private function modelAttributes($model): array
    {
        return json_decode(json_encode($model->getAttributes(), JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
    }
}
