<?php

namespace Tests\Unit;

use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Support\Facades\DB;
use Mockery;
use Modules\Accounting\Jobs\AnalyzeJournalImportJob;
use Modules\Accounting\Jobs\ProcessJournalImportJob;
use Tests\TestCase;

class JournalImportJobFailureTest extends TestCase
{
    public function test_analysis_job_marks_active_batch_failed_when_worker_gives_up(): void
    {
        $this->assertFailedBatchUpdate(
            new AnalyzeJournalImportJob(23),
            23,
            ['queued', 'analyzing'],
            'Worker menghentikan analisis sebelum selesai.',
            'queue retry window elapsed'
        );
    }

    public function test_import_job_marks_active_batch_failed_when_worker_gives_up(): void
    {
        $this->assertFailedBatchUpdate(
            new ProcessJournalImportJob(24),
            24,
            ['preparing_import', 'queued_import', 'importing', 'reconciling', 'processing'],
            'Worker menghentikan import sebelum selesai.',
            'queue retry window elapsed',
            true
        );
    }

    public function test_journal_job_timeout_is_shorter_than_queue_retry_window(): void
    {
        $retryAfter = (int) config('queue.connections.database.retry_after');

        foreach ([new AnalyzeJournalImportJob(25), new ProcessJournalImportJob(26)] as $job) {
            $this->assertGreaterThan(0, $job->timeout);
            $this->assertLessThan($retryAfter, $job->timeout);
        }
    }

    private function assertFailedBatchUpdate($job, int $importId, array $activeStatuses, string $lastMessage, string $errorMessage, bool $hasRowStates = false): void
    {
        if ($hasRowStates) {
            $rowQuery = Mockery::mock();
            $rowQuery->shouldReceive('where')->once()->with('journal_import_id', $importId)->andReturnSelf();
            $rowQuery->shouldReceive('whereIn')->once()->with('processing_status', ['queued', 'running'])->andReturnSelf();
            $rowQuery->shouldReceive('update')->once()->with(Mockery::on(function (array $values) use ($errorMessage) {
                return ($values['processing_status'] ?? null) === 'failed'
                    && ($values['processing_error'] ?? null) === $errorMessage
                    && array_key_exists('updated_at', $values);
            }))->andReturn(1);
            DB::shouldReceive('table')->once()->with('accounting_journal_import_rows')->andReturn($rowQuery);
        }

        $query = Mockery::mock();
        $query->shouldReceive('where')->once()->with('id', $importId)->andReturnSelf();
        $query->shouldReceive('whereIn')->once()->with('status', $activeStatuses)->andReturnSelf();

        $updates = null;
        $query->shouldReceive('update')->once()->andReturnUsing(function (array $values) use (&$updates) {
            $updates = $values;

            return 1;
        });

        DB::shouldReceive('table')->once()->with('accounting_journal_imports')->andReturn($query);

        $job->failed(new MaxAttemptsExceededException($errorMessage));

        $this->assertSame('failed', $updates['status']);
        $this->assertSame($lastMessage, $updates['last_message']);
        $this->assertSame($errorMessage, $updates['error_message']);
        $this->assertArrayHasKey('updated_at', $updates);
    }
}
