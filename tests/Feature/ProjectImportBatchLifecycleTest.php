<?php

namespace Tests\Feature;

use App\UserImportBatch;
use App\Services\UserImportBatchService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Modules\Project\Jobs\ProcessProjectImportBatch;
use Modules\Project\Entities\Project;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class ProjectImportBatchLifecycleTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake();
        Storage::fake('local');
        $this->withoutMiddleware([
            \App\Http\Middleware\SetSessionData::class,
            \App\Http\Middleware\AdminSidebarMenu::class,
        ]);
    }

    public function test_ajax_upload_queues_a_business_scoped_project_batch_with_restorable_source(): void
    {
        $path = $this->workbook();

        try {
            $response = $this->actingAs($this->admin())
                ->withSession($this->businessSession(7))
                ->postJson('/project/project-import', [
                    'project_file' => new UploadedFile($path, 'project-import.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true),
                    'confirm_import' => 1,
                ]);

            $response->assertStatus(202)
                ->assertJsonPath('success', true)
                ->assertJsonPath('status', 'queued_preview');
            Bus::assertDispatchedTimes(ProcessProjectImportBatch::class, 1);

            $batch = UserImportBatch::where('importer_key', 'project')->firstOrFail();
            $this->assertSame(7, (int) $batch->business_id);
            $this->assertSame('queued_preview', $batch->status);
            $this->assertTrue($batch->hasSourceSnapshot());
            $this->assertTrue(Storage::disk('local')->exists($batch->staging_path));

            $history = $this->actingAs($this->admin())
                ->withSession($this->businessSession(7))
                ->getJson(route('project.import.history', [
                    'draw' => 4,
                    'start' => 0,
                    'length' => 10,
                    'status' => 'queued_preview',
                ]));
            $history->assertOk()
                ->assertJsonPath('draw', 4)
                ->assertJsonPath('recordsTotal', 1)
                ->assertJsonPath('data.0.file_name', 'project-import.xlsx')
                ->assertJsonPath('data.0.has_snapshot', true);

            $this->actingAs($this->admin())
                ->withSession($this->businessSession(7))
                ->get(route('project.import.history.source', $batch->id))
                ->assertOk();
        } finally {
            @unlink($path);
        }
    }

    public function test_project_batch_details_are_not_accessible_from_another_business(): void
    {
        $foreign = UserImportBatch::create([
            'business_id' => 8,
            'created_by' => 998,
            'importer_key' => 'project',
            'source_key' => 'foreign-project-batch',
            'file_name' => 'foreign.xlsx',
            'status' => 'imported',
        ]);

        $this->actingAs($this->admin())
            ->withSession($this->businessSession(7))
            ->getJson(route('project.import.history.detail', $foreign->id))
            ->assertNotFound();
    }

    public function test_revert_removes_an_unchanged_project_and_blocks_a_project_changed_after_import(): void
    {
        $safeProject = $this->project('Project aman');
        $safeBatch = $this->importedBatchFor($safeProject);
        $this->actingAs($this->admin())
            ->withSession($this->businessSession(7))
            ->postJson(route('project.import.history.revert', $safeBatch->id))
            ->assertOk()
            ->assertJsonPath('success', true);
        $this->assertDatabaseMissing('pjt_projects', ['id' => $safeProject->id]);
        $this->assertDatabaseHas('user_import_batches', ['id' => $safeBatch->id, 'status' => 'reverted']);

        $changedProject = $this->project('Project berubah');
        $changedBatch = $this->importedBatchFor($changedProject);
        $changedProject->name = 'Project diubah setelah import';
        $changedProject->save();

        $this->actingAs($this->admin())
            ->withSession($this->businessSession(7))
            ->postJson(route('project.import.history.revert', $changedBatch->id))
            ->assertStatus(422);
        $this->assertDatabaseHas('pjt_projects', ['id' => $changedProject->id, 'name' => 'Project diubah setelah import']);
    }

    private function workbook(): string
    {
        $spreadsheet = new Spreadsheet();
        $spreadsheet->getActiveSheet()->setTitle('Project');
        foreach (['Tasks', 'Time Logs', 'Invoice', 'Invoice Lines', 'Referensi'] as $title) {
            $spreadsheet->createSheet()->setTitle($title);
        }
        $path = tempnam(sys_get_temp_dir(), 'project-import-').'.xlsx';
        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();

        return $path;
    }

    private function project(string $name): Project
    {
        return Project::create([
            'business_id' => 7,
            'name' => $name,
            'status' => 'not_started',
            'lead_id' => 999,
            'created_by' => 999,
            'settings' => ['enable_timelog' => true, 'enable_invoice' => false],
        ])->fresh();
    }

    private function importedBatchFor(Project $project): UserImportBatch
    {
        $batch = UserImportBatch::create([
            'business_id' => 7,
            'created_by' => 999,
            'importer_key' => 'project',
            'source_key' => 'project-result-'.$project->id,
            'file_name' => $project->name.'.xlsx',
            'status' => 'imported',
            'stage' => 'imported',
            'progress_percent' => 100,
        ]);
        app(UserImportBatchService::class)->storePayload($batch, 'results', [
            'project' => [
                'id' => (int) $project->id,
                'name' => $project->name,
                'updated_at' => (string) $project->updated_at,
                'attributes' => json_decode(json_encode($project->getAttributes(), JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR),
                'task_ids' => [],
                'tasks' => [],
                'time_log_ids' => [],
                'time_logs' => [],
                'invoice_id' => null,
            ],
            'summary' => ['tasks' => 0, 'time_logs' => 0, 'invoice' => 0],
            'row_outcomes' => [],
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
        return [
            'user' => ['business_id' => $businessId, 'id' => 999],
        ];
    }
}
