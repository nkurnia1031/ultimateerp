<?php

namespace Modules\Project\Services;

use App\Services\UserImportBatchService;
use App\UserImportBatch;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Modules\Project\Entities\Project;
use Modules\Project\Entities\ProjectTask;
use Modules\Project\Entities\ProjectTimeLog;
use Modules\Project\Entities\ProjectTransaction;

class ProjectImportBatchService
{
    public const IMPORTER_KEY = 'project';

    public function __construct(private UserImportBatchService $batches)
    {
    }

    public static function progressCacheKey(int $batchId): string
    {
        return 'project_import_batch_progress:'.$batchId;
    }

    public function history(Request $request, int $businessId, bool $canRevert): array
    {
        $table = $this->batches->dataTable($request, $businessId, self::IMPORTER_KEY);

        $table['data'] = collect($table['data'])->map(function (array $row) use ($canRevert) {
            $cached = Cache::get(self::progressCacheKey((int) $row['id']), []);
            if (is_array($cached) && $cached !== [] && $row['active']) {
                $row['stage'] = $cached['stage'] ?? $row['stage'];
                $row['progress_current'] = (int) ($cached['current'] ?? $row['progress_current']);
                $row['progress_total'] = (int) ($cached['total'] ?? $row['progress_total']);
                $row['progress_percent'] = (int) ($cached['percent'] ?? $row['progress_percent']);
                $row['last_message'] = $cached['message'] ?? $row['last_message'];
                $row['heartbeat_at'] = $cached['heartbeat_at'] ?? $row['heartbeat_at'];
            }
            $row['detail_url'] = route('project.import.history.detail', $row['id']);
            $row['restore_url'] = $row['has_snapshot'] ? route('project.import.history.source', $row['id']) : null;
            $row['revert_url'] = route('project.import.history.revert', $row['id']);
            $row['can_revert'] = $canRevert && $row['status'] === 'imported';
            $row['can_retry'] = $row['status'] === 'failed' && $row['has_snapshot'];
            $row['retry_url'] = route('project.import.history.retry', $row['id']);

            return $row;
        })->values()->all();

        return $table;
    }

    public function detail(int $batchId, int $businessId): array
    {
        $batch = $this->batches->findForBusiness($batchId, $businessId, self::IMPORTER_KEY);
        $results = $batch->results_path ? $this->batches->readPayload($batch, 'results') : [];

        return [
            'batch' => $batch,
            'results' => $results,
        ];
    }

    public function revert(UserImportBatch $batch): void
    {
        abort_unless($batch->status === 'imported', 422, 'Batch Project ini tidak berada pada status imported.');
        $results = $this->batches->readPayload($batch, 'results');
        $projectId = (int) ($results['project']['id'] ?? 0);
        abort_if($projectId < 1, 422, 'Data Project hasil import tidak ditemukan.');

        DB::transaction(function () use ($batch, $results, $projectId) {
            $locked = UserImportBatch::where('business_id', $batch->business_id)
                ->where('importer_key', self::IMPORTER_KEY)
                ->lockForUpdate()
                ->findOrFail($batch->id);
            abort_unless($locked->status === 'imported', 422, 'Batch Project ini sudah berubah status.');

            $project = Project::where('business_id', $batch->business_id)
                ->lockForUpdate()
                ->findOrFail($projectId);
            $this->assertUnchangedAndUnreferenced($project, $results);

            $taskIds = array_map('intval', $results['project']['task_ids'] ?? []);
            $timeLogIds = array_map('intval', $results['project']['time_log_ids'] ?? []);

            if ($timeLogIds !== []) {
                DB::table('project_time_log_tags')->whereIn('project_time_log_id', $timeLogIds)->delete();
                ProjectTimeLog::where('project_id', $project->id)->whereIn('id', $timeLogIds)->delete();
            }
            if ($taskIds !== []) {
                DB::table('project_task_tags')->whereIn('project_task_id', $taskIds)->delete();
                DB::table('pjt_project_task_members')->whereIn('project_task_id', $taskIds)->delete();
                ProjectTask::where('project_id', $project->id)->whereIn('id', $taskIds)->delete();
            }

            DB::table('pjt_project_members')->where('project_id', $project->id)->delete();
            DB::table('categorizables')
                ->where('categorizable_type', Project::class)
                ->where('categorizable_id', $project->id)
                ->delete();
            $project->delete();

            $locked->forceFill([
                'status' => 'reverted',
                'stage' => 'reverted',
                'last_message' => 'Project hasil batch berhasil dikembalikan.',
                'progress_percent' => 100,
                'heartbeat_at' => null,
                'reverted_at' => now(),
                'updated_at' => now(),
            ])->save();
        });
    }

    public function fail(UserImportBatch $batch, \Throwable $exception): void
    {
        $message = mb_substr($exception->getMessage(), 0, 65000);
        $sheet = null;
        $sourceRow = null;
        if (preg_match('/^(Project|Tasks|Time Logs|Invoice|Invoice Lines)(?: baris)?\s+(\d+)/iu', $message, $matches)) {
            $sheet = $matches[1];
            $sourceRow = (int) $matches[2];
        } elseif (str_starts_with($message, 'Project:')) {
            $sheet = 'Project';
            $sourceRow = 2;
        } elseif (str_starts_with($message, 'Invoice:')) {
            $sheet = 'Invoice';
            $sourceRow = 2;
        }

        $results = [
            'summary' => ['failed_rows' => 1],
            'row_outcomes' => [[
                'sheet' => $sheet,
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
            'last_message' => 'Import Project gagal.',
            'error_message' => $message,
            'summary_json' => json_encode($results['summary'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'heartbeat_at' => null,
            'completed_at' => now(),
            'failed_at' => now(),
        ])->save();
    }

    private function assertUnchangedAndUnreferenced(Project $project, array $results): void
    {
        $snapshot = $results['project'];
        if ($project->name !== ($snapshot['name'] ?? null)
            || (string) $project->updated_at !== (string) ($snapshot['updated_at'] ?? null)
            || $this->modelAttributes($project) !== ($snapshot['attributes'] ?? [])) {
            abort(422, 'Project sudah berubah setelah import sehingga tidak dapat direvert dengan aman.');
        }

        if (! empty($snapshot['invoice_id'])) {
            abort(422, 'Batch membuat invoice Project; hapus atau pulihkan invoice tersebut secara terpisah sebelum revert.');
        }

        $taskIds = array_map('intval', $snapshot['task_ids'] ?? []);
        $timeLogIds = array_map('intval', $snapshot['time_log_ids'] ?? []);
        if ($project->tasks()->whereNotIn('id', $taskIds ?: [0])->exists()
            || $project->timeLogs()->whereNotIn('id', $timeLogIds ?: [0])->exists()
            || $project->heavyEquipmentTrips()->exists()
            || $project->bonusAyakEntries()->exists()
            || ProjectTransaction::where('pjt_project_id', $project->id)->exists()
            || $project->documentsAndnote()->exists()) {
            abort(422, 'Project sudah memiliki aktivitas atau dependensi setelah import sehingga revert diblokir.');
        }

        if ($taskIds !== []) {
            $tasks = ProjectTask::where('project_id', $project->id)->whereIn('id', $taskIds)->get()->keyBy('id');
            if ($tasks->count() !== count($taskIds)) {
                abort(422, 'Task hasil import berubah setelah import sehingga revert diblokir.');
            }
            foreach (($snapshot['tasks'] ?? []) as $task) {
                $current = $tasks->get((int) $task['id']);
                if (! $current || (string) $current->updated_at !== (string) $task['updated_at']
                    || $this->modelAttributes($current) !== ($task['attributes'] ?? [])) {
                    abort(422, 'Task hasil import berubah setelah import sehingga revert diblokir.');
                }
                if ($current->comments()->exists()) {
                    abort(422, 'Task hasil import sudah memiliki komentar sehingga revert diblokir.');
                }
            }
        }

        if ($timeLogIds !== []) {
            $timeLogs = ProjectTimeLog::where('project_id', $project->id)->whereIn('id', $timeLogIds)->get()->keyBy('id');
            if ($timeLogs->count() !== count($timeLogIds)) {
                abort(422, 'Time log hasil import berubah setelah import sehingga revert diblokir.');
            }
            foreach (($snapshot['time_logs'] ?? []) as $timeLog) {
                $current = $timeLogs->get((int) $timeLog['id']);
                if (! $current || (string) $current->updated_at !== (string) $timeLog['updated_at']
                    || $this->modelAttributes($current) !== ($timeLog['attributes'] ?? [])) {
                    abort(422, 'Time log hasil import berubah setelah import sehingga revert diblokir.');
                }
            }
        }
    }

    private function modelAttributes($model): array
    {
        return json_decode(json_encode($model->getAttributes(), JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
    }
}
