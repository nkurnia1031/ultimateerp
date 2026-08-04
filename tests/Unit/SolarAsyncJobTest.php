<?php

namespace Tests\Unit;

use App\Jobs\ProcessSolarImport;
use App\Services\SolarImportProcessService;
use App\Services\SolarImportService;
use PHPUnit\Framework\TestCase;

class SolarAsyncJobTest extends TestCase
{
    public function test_import_job_uses_solar_queue_and_completes_process(): void
    {
        $job = new ProcessSolarImport('batch-id', 7);
        $import = $this->createMock(SolarImportService::class);
        $process = $this->createMock(SolarImportProcessService::class);
        $import->expects($this->once())
            ->method('run')
            ->with('batch-id', 7)
            ->willReturn(['stock' => 2, 'pool' => 1, 'mess' => 0]);
        $process->expects($this->once())
            ->method('complete')
            ->with('batch-id', 7, $this->stringContains('2 Stock'));

        $job->handle($import, $process);

        $this->assertSame('solar', $job->queue);
        $this->assertSame(1800, $job->timeout);
    }
}
