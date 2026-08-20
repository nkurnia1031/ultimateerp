<?php

namespace Tests\Unit;

use App\Services\SecurityAuditService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class SecurityAuditServiceTest extends TestCase
{
    private array $reportIds = [];

    protected function tearDown(): void
    {
        foreach ($this->reportIds as $reportId) {
            File::delete(storage_path('app/security-audit/reports/'.$reportId.'.json'));
        }

        parent::tearDown();
    }

    public function test_it_separates_relevant_findings_from_vendor_noise(): void
    {
        $report = $this->service()->importUpload(
            $this->reportFile(base_path()),
            987654,
            1,
            'Audit Test'
        );
        $this->reportIds[] = $report['id'];

        $this->assertSame('critical', $report['assessment']['risk']);
        $this->assertSame(3, $report['assessment']['total']);
        $this->assertSame(2, $report['assessment']['relevant_total']);
        $this->assertSame(1, $report['assessment']['vendor_noise']);
        $this->assertSame(1, $report['assessment']['cleanup_eligible']);
        $this->assertTrue($report['cleanup']['root_matches']);
    }

    public function test_foreign_report_root_locks_cleanup(): void
    {
        $report = $this->service()->importUpload(
            $this->reportFile('/srv/a-different-installation'),
            987654,
            1,
            'Audit Test'
        );
        $this->reportIds[] = $report['id'];

        $this->assertFalse($report['cleanup']['root_matches']);
        $this->assertFalse($report['cleanup']['can_cleanup']);
    }

    private function reportFile(string $root): UploadedFile
    {
        $payload = [
            'generated_at' => '2026-08-20T08:00:00+07:00',
            'root' => $root,
            'since' => '2026-07-20T08:00:00+07:00',
            'files_scanned' => 100,
            'git_changes' => [],
            'skipped' => [],
            'findings' => [
                [
                    'severity' => 'critical',
                    'kind' => 'upload_webshell_candidate',
                    'path' => 'public/uploads/media/payload.php74',
                    'size' => 12,
                    'sha256' => str_repeat('a', 64),
                    'extension' => 'php74',
                    'signals' => ['direct_code_execution'],
                    'reasons' => ['executable file inside public/uploads'],
                ],
                [
                    'severity' => 'high',
                    'kind' => 'suspicious_file',
                    'path' => 'vendor/example/package.php',
                    'size' => 100,
                    'sha256' => str_repeat('b', 64),
                    'extension' => 'php',
                    'signals' => ['command_execution'],
                    'reasons' => ['content signal: command_execution'],
                ],
                [
                    'severity' => 'high',
                    'kind' => 'permissive_environment_permissions',
                    'path' => '.env',
                    'size' => 200,
                    'sha256' => str_repeat('c', 64),
                    'extension' => 'env',
                    'signals' => [],
                    'reasons' => ['.env is readable by other users'],
                ],
            ],
        ];

        return UploadedFile::fake()->createWithContent(
            'security-audit.json',
            json_encode($payload, JSON_UNESCAPED_SLASHES)
        );
    }

    private function service(): SecurityAuditService
    {
        return app(SecurityAuditService::class);
    }
}
