<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use JsonException;
use RuntimeException;

class SecurityAuditService
{
    private const REPORT_DIRECTORY = 'security-audit/reports';

    private const QUARANTINE_DIRECTORY = 'security-audit/quarantine';

    private const MAX_REPORT_BYTES = 15728640;

    private const MAX_FINDINGS = 50000;

    private const EXECUTABLE_EXTENSIONS = [
        'php', 'php3', 'php4', 'php5', 'php7', 'php8', 'php56', 'php70', 'php71',
        'php72', 'php73', 'php74', 'php80', 'php81', 'php82', 'php83', 'phtml',
        'pht', 'phar', 'phps', 'cgi', 'pl', 'py', 'sh', 'bash', 'exe', 'com', 'bat',
        'cmd', 'ps1', 'asp', 'aspx', 'jsp',
    ];

    private const MALICIOUS_SIGNALS = [
        'obfuscated_payload', 'direct_code_execution', 'remote_include',
        'preg_replace_e', 'command_execution', 'php_code_in_non_php_file',
        'server_handler_override',
    ];

    public function importUpload(UploadedFile $file, int $businessId, int $userId, string $userName): array
    {
        if (! $file->isValid()) {
            throw new RuntimeException('Upload laporan gagal. Silakan pilih ulang file JSON.');
        }

        if (strtolower((string) $file->getClientOriginalExtension()) !== 'json') {
            throw new RuntimeException('Laporan audit harus berupa file .json.');
        }

        $size = (int) $file->getSize();
        if ($size < 2 || $size > self::MAX_REPORT_BYTES) {
            throw new RuntimeException('Ukuran laporan audit tidak valid atau melebihi 15 MB.');
        }

        $contents = @file_get_contents($file->getRealPath());
        if ($contents === false) {
            throw new RuntimeException('File laporan tidak dapat dibaca.');
        }

        return $this->storeReport(
            $contents,
            $businessId,
            $userId,
            $userName,
            'upload',
            $this->safeFileName($file->getClientOriginalName())
        );
    }

    public function listReports(int $businessId): array
    {
        $directory = $this->reportDirectory();
        if (! is_dir($directory)) {
            return [];
        }

        $reports = [];
        foreach (glob($directory.DIRECTORY_SEPARATOR.'*.json') ?: [] as $path) {
            try {
                $wrapper = $this->readWrapper($path);
                if ((int) ($wrapper['business_id'] ?? 0) !== $businessId) {
                    continue;
                }
                $reports[] = $this->summary($wrapper);
            } catch (\Throwable $exception) {
                // Ignore malformed storage files; uploaded reports are validated before storage.
            }
        }

        usort($reports, fn (array $left, array $right): int => strcmp($right['imported_at'], $left['imported_at']));

        return $reports;
    }

    public function getReport(string $id, int $businessId): array
    {
        $path = $this->reportPath($id);
        if (! is_file($path)) {
            throw new RuntimeException('Laporan audit tidak ditemukan.');
        }

        $wrapper = $this->readWrapper($path);
        if ((int) ($wrapper['business_id'] ?? 0) !== $businessId) {
            throw new RuntimeException('Laporan audit tidak dapat diakses dari bisnis ini.');
        }

        $wrapper['assessment'] = $this->assess($wrapper['report']);
        $wrapper['cleanup'] = $this->cleanupAvailability($wrapper['report']);
        foreach ($wrapper['report']['findings'] as &$finding) {
            $normalizedPath = strtolower(str_replace('\\', '/', (string) ($finding['path'] ?? '')));
            $finding['_vendor_noise'] = $normalizedPath === 'vendor' || str_starts_with($normalizedPath, 'vendor/');
            $finding['_cleanup_eligible'] = $this->isCleanupEligible($finding);
        }
        unset($finding);

        return $wrapper;
    }

    public function downloadContents(string $id, int $businessId): string
    {
        $wrapper = $this->getReport($id, $businessId);

        return json_encode($wrapper['report'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).PHP_EOL;
    }

    public function cleanup(string $id, int $businessId, int $userId, string $userName): array
    {
        $wrapper = $this->getReport($id, $businessId);
        $availability = $wrapper['cleanup'];
        if (! $availability['root_matches']) {
            throw new RuntimeException('Root laporan berbeda dengan instalasi aktif. Pembersihan diblokir agar tidak salah target.');
        }

        $runId = now()->format('Ymd-His').'-'.Str::lower(Str::random(8));
        $quarantineDirectory = storage_path('app/'.self::QUARANTINE_DIRECTORY.'/'.$id.'/'.$runId);
        $this->ensureDirectory($quarantineDirectory);
        $root = realpath(base_path());
        if ($root === false) {
            throw new RuntimeException('Root aplikasi aktif tidak dapat diverifikasi.');
        }

        $removed = [];
        $skipped = [];
        foreach ($wrapper['report']['findings'] as $index => $finding) {
            if (! $this->isCleanupEligible($finding)) {
                continue;
            }

            $path = $this->normalizeRelativePath((string) ($finding['path'] ?? ''));
            $result = $this->quarantineFinding($root, $path, $finding, $quarantineDirectory, (int) $index);
            if ($result['status'] === 'removed') {
                $removed[] = $result;
            } else {
                $skipped[] = $result;
            }
        }

        $manifest = [
            'run_id' => $runId,
            'report_id' => $id,
            'executed_at' => now()->toIso8601String(),
            'executed_by' => ['id' => $userId, 'name' => $userName],
            'removed_count' => count($removed),
            'skipped_count' => count($skipped),
            'removed' => $removed,
            'skipped' => $skipped,
        ];
        $this->writeJson($quarantineDirectory.DIRECTORY_SEPARATOR.'manifest.json', $manifest);

        $wrapper['cleanup_runs'] = array_values(array_merge($wrapper['cleanup_runs'] ?? [], [$manifest]));
        $this->writeJson($this->reportPath($id), $wrapper);

        return $manifest;
    }

    private function storeReport(
        string $contents,
        int $businessId,
        int $userId,
        string $userName,
        string $source,
        string $originalName
    ): array {
        if (strlen($contents) > self::MAX_REPORT_BYTES) {
            throw new RuntimeException('Laporan audit melebihi batas 15 MB.');
        }

        $report = $this->decodeAndNormalize($contents);
        $id = (string) Str::uuid();
        $wrapper = [
            'id' => $id,
            'business_id' => $businessId,
            'source' => $source,
            'original_name' => $originalName,
            'imported_at' => now()->toIso8601String(),
            'imported_by' => ['id' => $userId, 'name' => $this->limit($userName, 150)],
            'cleanup_runs' => [],
            'report' => $report,
        ];

        $this->ensureDirectory($this->reportDirectory());
        $this->writeJson($this->reportPath($id), $wrapper);

        return $this->getReport($id, $businessId);
    }

    private function decodeAndNormalize(string $contents): array
    {
        try {
            $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('JSON laporan tidak valid: '.$exception->getMessage());
        }

        if (isset($decoded['report']) && is_array($decoded['report'])) {
            $decoded = $decoded['report'];
        }
        if (! is_array($decoded) || ! isset($decoded['root'], $decoded['generated_at'], $decoded['findings']) || ! is_array($decoded['findings'])) {
            throw new RuntimeException('Struktur JSON bukan hasil dari script audit keamanan.');
        }
        if (count($decoded['findings']) > self::MAX_FINDINGS) {
            throw new RuntimeException('Jumlah temuan melebihi batas aman 50.000 baris.');
        }

        $findings = [];
        foreach ($decoded['findings'] as $finding) {
            if (! is_array($finding)) {
                continue;
            }
            $severity = strtolower(trim((string) ($finding['severity'] ?? 'medium')));
            if (! in_array($severity, ['critical', 'high', 'medium', 'low'], true)) {
                $severity = 'medium';
            }
            $findings[] = [
                'severity' => $severity,
                'kind' => $this->limit((string) ($finding['kind'] ?? 'unknown'), 100),
                'path' => $this->limit(str_replace('\\', '/', (string) ($finding['path'] ?? '')), 2048),
                'mtime' => isset($finding['mtime']) ? (int) $finding['mtime'] : null,
                'modified_at' => $this->limit((string) ($finding['modified_at'] ?? ''), 100),
                'recent' => (bool) ($finding['recent'] ?? false),
                'size' => isset($finding['size']) ? max(0, (int) $finding['size']) : null,
                'sha256' => preg_match('/^[a-f0-9]{64}$/i', (string) ($finding['sha256'] ?? ''))
                    ? strtolower((string) $finding['sha256']) : null,
                'extension' => $this->limit(strtolower((string) ($finding['extension'] ?? '')), 30),
                'signals' => $this->stringList($finding['signals'] ?? [], 30, 100),
                'reasons' => $this->stringList($finding['reasons'] ?? [], 30, 500),
            ];
        }

        return [
            'generated_at' => $this->limit((string) $decoded['generated_at'], 100),
            'root' => $this->limit((string) $decoded['root'], 2048),
            'since' => $this->limit((string) ($decoded['since'] ?? ''), 100),
            'files_scanned' => max(0, (int) ($decoded['files_scanned'] ?? 0)),
            'git_changes' => $this->stringList($decoded['git_changes'] ?? [], 20000, 2048),
            'findings' => $findings,
            'skipped' => $this->stringList($decoded['skipped'] ?? [], 1000, 1000),
        ];
    }

    private function assess(array $report): array
    {
        $counts = ['critical' => 0, 'high' => 0, 'medium' => 0, 'low' => 0];
        $relevantCounts = $counts;
        $vendorNoise = 0;
        $webshells = 0;
        $payloads = 0;
        $permissionIssues = 0;
        $eligible = 0;

        foreach ($report['findings'] as $finding) {
            $severity = $finding['severity'] ?? 'medium';
            $counts[$severity] = ($counts[$severity] ?? 0) + 1;
            $path = strtolower(str_replace('\\', '/', (string) ($finding['path'] ?? '')));
            if ($path === 'vendor' || str_starts_with($path, 'vendor/')) {
                $vendorNoise++;
                continue;
            }
            $relevantCounts[$severity] = ($relevantCounts[$severity] ?? 0) + 1;
            if (($finding['kind'] ?? '') === 'upload_webshell_candidate') {
                $webshells++;
            }
            if (array_intersect($finding['signals'] ?? [], self::MALICIOUS_SIGNALS) !== []) {
                $payloads++;
            }
            if (in_array($finding['kind'] ?? '', ['permissive_environment_permissions', 'world_writable_directory'], true)) {
                $permissionIssues++;
            }
            if ($this->isCleanupEligible($finding)) {
                $eligible++;
            }
        }

        $risk = 'low';
        foreach (['critical', 'high', 'medium'] as $severity) {
            if (($relevantCounts[$severity] ?? 0) > 0) {
                $risk = $severity;
                break;
            }
        }

        $recommendations = [];
        if ($risk === 'critical' || $webshells > 0 || $payloads > 0) {
            $recommendations[] = [
                'priority' => 'Sekarang',
                'title' => 'Anggap instalasi sudah dikompromikan',
                'detail' => 'Isolasi akses publik bila memungkinkan, simpan backup/log, lalu pulihkan aplikasi dari source dan dependency yang diketahui bersih. Menghapus payload saja belum membuktikan tidak ada persistence lain.',
            ];
            $recommendations[] = [
                'priority' => 'Sekarang',
                'title' => 'Rotasi kredensial dan sesi',
                'detail' => 'Ganti password hosting/SSH, database, email, API, dan akun admin; cabut sesi aktif. Rotasi APP_KEY perlu direncanakan karena dapat membatalkan cookie dan data terenkripsi lama.',
            ];
            $recommendations[] = [
                'priority' => 'Forensik',
                'title' => 'Telusuri jalur masuk',
                'detail' => 'Periksa access log, error log, audit login, dan request upload pada waktu modifikasi temuan. Cocokkan IP, user, endpoint, serta file lain yang dibuat oleh proses yang sama.',
            ];
        }
        if ($permissionIssues > 0) {
            $recommendations[] = [
                'priority' => 'Tinggi',
                'title' => 'Perketat owner dan permission',
                'detail' => 'Batasi .env ke pemilik proses yang tepat (umumnya 600/640 sesuai hosting) dan hindari folder aplikasi world-writable. Verifikasi kembali setelah perubahan.',
            ];
        }
        $recommendations[] = [
            'priority' => 'Pencegahan',
            'title' => 'Matikan eksekusi script di folder upload',
            'detail' => 'Pertahankan validasi MIME/ekstensi dan blok PHP/PHTML/PHAR pada konfigurasi Apache/Nginx. Idealnya file upload disimpan di luar document root dan disajikan melalui controller.',
        ];
        if ($vendorNoise > 0) {
            $recommendations[] = [
                'priority' => 'Review',
                'title' => 'Jangan hapus temuan vendor secara otomatis',
                'detail' => number_format($vendorNoise, 0, ',', '.').' temuan berada di vendor dan banyak signature seperti exec/eval adalah kode library yang sah. Verifikasi dependency dengan composer.lock atau deploy ulang vendor dari build bersih.',
            ];
        }

        return [
            'risk' => $risk,
            'counts' => $counts,
            'relevant_counts' => $relevantCounts,
            'total' => array_sum($counts),
            'relevant_total' => array_sum($relevantCounts),
            'vendor_noise' => $vendorNoise,
            'webshells' => $webshells,
            'payloads' => $payloads,
            'permission_issues' => $permissionIssues,
            'cleanup_eligible' => $eligible,
            'recommendations' => $recommendations,
        ];
    }

    private function cleanupAvailability(array $report): array
    {
        $reportRoot = realpath((string) ($report['root'] ?? ''));
        $applicationRoot = realpath(base_path());
        $rootMatches = $reportRoot !== false && $applicationRoot !== false
            && $this->samePath($reportRoot, $applicationRoot);
        $eligible = 0;
        foreach ($report['findings'] as $finding) {
            if ($this->isCleanupEligible($finding)) {
                $eligible++;
            }
        }

        return [
            'root_matches' => $rootMatches,
            'report_root' => (string) ($report['root'] ?? ''),
            'application_root' => $applicationRoot ?: base_path(),
            'eligible_count' => $eligible,
            'can_cleanup' => $rootMatches && $eligible > 0,
        ];
    }

    private function isCleanupEligible(array $finding): bool
    {
        $path = strtolower($this->normalizeRelativePath((string) ($finding['path'] ?? '')));
        if ($path === '' || str_starts_with($path, 'vendor/') || $path === 'vendor') {
            return false;
        }
        if (in_array($path, ['public/index.php', 'public/install/index.php', 'public/.htaccess'], true)) {
            return false;
        }

        $inUpload = str_starts_with($path, 'public/uploads/');
        $inPublic = str_starts_with($path, 'public/');
        if (! $inPublic) {
            return false;
        }

        $extension = strtolower((string) ($finding['extension'] ?? pathinfo($path, PATHINFO_EXTENSION)));
        $signals = is_array($finding['signals'] ?? null) ? $finding['signals'] : [];
        $hasMaliciousSignal = array_intersect($signals, self::MALICIOUS_SIGNALS) !== [];
        $isExecutable = in_array($extension, self::EXECUTABLE_EXTENSIONS, true);

        if ($inUpload) {
            return ($finding['kind'] ?? '') === 'upload_webshell_candidate' || $isExecutable || $hasMaliciousSignal;
        }

        return $hasMaliciousSignal || ($isExecutable && in_array($finding['severity'] ?? '', ['critical', 'high'], true));
    }

    private function quarantineFinding(
        string $root,
        string $relativePath,
        array $finding,
        string $quarantineDirectory,
        int $index
    ): array {
        $base = ['path' => $relativePath, 'sha256' => $finding['sha256'] ?? null];
        if ($relativePath === '' || str_contains($relativePath, "\0") || str_starts_with($relativePath, '../')) {
            return $base + ['status' => 'skipped', 'reason' => 'Path laporan tidak aman.'];
        }

        $candidate = $root.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
        if (is_link($candidate)) {
            return $base + ['status' => 'skipped', 'reason' => 'Symbolic link tidak disentuh.'];
        }
        $resolved = realpath($candidate);
        if ($resolved === false || ! is_file($resolved)) {
            return $base + ['status' => 'skipped', 'reason' => 'File sudah tidak ada.'];
        }
        if (! $this->isInside($resolved, $root)) {
            return $base + ['status' => 'skipped', 'reason' => 'Target berada di luar root aplikasi.'];
        }

        $expectedHash = strtolower((string) ($finding['sha256'] ?? ''));
        if (! preg_match('/^[a-f0-9]{64}$/', $expectedHash)) {
            return $base + ['status' => 'skipped', 'reason' => 'Hash laporan tidak tersedia.'];
        }
        $currentHash = @hash_file('sha256', $resolved);
        if ($currentHash === false || ! hash_equals($expectedHash, strtolower($currentHash))) {
            return $base + ['status' => 'skipped', 'reason' => 'Isi file telah berubah sejak audit.'];
        }

        $destinationName = sprintf('%05d-%s.quarantine', $index, $currentHash);
        $destination = $quarantineDirectory.DIRECTORY_SEPARATOR.$destinationName;
        if (! @copy($resolved, $destination)) {
            return $base + ['status' => 'skipped', 'reason' => 'Gagal menyalin bukti ke karantina.'];
        }
        $quarantineHash = @hash_file('sha256', $destination);
        if ($quarantineHash === false || ! hash_equals($currentHash, $quarantineHash)) {
            @unlink($destination);
            return $base + ['status' => 'skipped', 'reason' => 'Verifikasi salinan karantina gagal.'];
        }
        @chmod($destination, 0600);
        if (! @unlink($resolved)) {
            return $base + ['status' => 'skipped', 'reason' => 'Bukti tersimpan, tetapi file aktif gagal dihapus.'];
        }

        return $base + [
            'status' => 'removed',
            'reason' => 'Dipindahkan dari aplikasi aktif ke karantina privat.',
            'quarantine_file' => $destinationName,
        ];
    }

    private function summary(array $wrapper): array
    {
        $assessment = $this->assess($wrapper['report']);

        return [
            'id' => $wrapper['id'],
            'source' => $wrapper['source'] ?? 'upload',
            'original_name' => $wrapper['original_name'] ?? 'security-audit.json',
            'imported_at' => $wrapper['imported_at'] ?? '',
            'generated_at' => $wrapper['report']['generated_at'] ?? '',
            'root' => $wrapper['report']['root'] ?? '',
            'risk' => $assessment['risk'],
            'total' => $assessment['total'],
            'relevant_total' => $assessment['relevant_total'],
            'cleanup_runs' => count($wrapper['cleanup_runs'] ?? []),
        ];
    }

    private function readWrapper(string $path): array
    {
        $contents = @file_get_contents($path);
        if ($contents === false) {
            throw new RuntimeException('Laporan tersimpan tidak dapat dibaca.');
        }

        try {
            $wrapper = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Laporan tersimpan rusak.');
        }
        if (! is_array($wrapper) || ! isset($wrapper['id'], $wrapper['report'])) {
            throw new RuntimeException('Format laporan tersimpan tidak dikenali.');
        }

        return $wrapper;
    }

    private function writeJson(string $path, array $value): void
    {
        $json = json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false || @file_put_contents($path, $json.PHP_EOL, LOCK_EX) === false) {
            throw new RuntimeException('Gagal menyimpan data audit ke storage privat.');
        }
        @chmod($path, 0600);
    }

    private function reportDirectory(): string
    {
        return storage_path('app/'.self::REPORT_DIRECTORY);
    }

    private function reportPath(string $id): string
    {
        if (! preg_match('/^[a-f0-9-]{36}$/i', $id)) {
            throw new RuntimeException('ID laporan tidak valid.');
        }

        return $this->reportDirectory().DIRECTORY_SEPARATOR.$id.'.json';
    }

    private function ensureDirectory(string $directory): void
    {
        if (! is_dir($directory) && ! File::makeDirectory($directory, 0750, true, true)) {
            throw new RuntimeException('Folder storage audit tidak dapat dibuat.');
        }
    }

    private function stringList($value, int $maxItems, int $maxLength): array
    {
        if (! is_array($value)) {
            return [];
        }

        $result = [];
        foreach (array_slice($value, 0, $maxItems) as $item) {
            if (is_scalar($item)) {
                $result[] = $this->limit((string) $item, $maxLength);
            }
        }

        return $result;
    }

    private function safeFileName(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = preg_replace('/[^A-Za-z0-9._ -]+/', '-', $name) ?: 'security-audit.json';

        return $this->limit(trim($name), 180);
    }

    private function normalizeRelativePath(string $path): string
    {
        $path = trim($path);
        if ($path === '' || str_contains($path, "\0") || preg_match('/^(?:[A-Za-z]:[\\\\\/]|[\\\\\/])/', $path) === 1) {
            return '';
        }
        $path = str_replace('\\', '/', $path);
        $segments = [];
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                return '';
            }
            $segments[] = $segment;
        }

        return implode('/', $segments);
    }

    private function isInside(string $path, string $root): bool
    {
        $path = rtrim(str_replace('\\', '/', $path), '/');
        $root = rtrim(str_replace('\\', '/', $root), '/');
        if (DIRECTORY_SEPARATOR === '\\') {
            $path = strtolower($path);
            $root = strtolower($root);
        }

        return $path !== $root && str_starts_with($path.'/', $root.'/');
    }

    private function samePath(string $left, string $right): bool
    {
        $left = rtrim(str_replace('\\', '/', $left), '/');
        $right = rtrim(str_replace('\\', '/', $right), '/');

        return DIRECTORY_SEPARATOR === '\\' ? strcasecmp($left, $right) === 0 : $left === $right;
    }

    private function limit(string $value, int $length): string
    {
        return function_exists('mb_substr') ? mb_substr($value, 0, $length) : substr($value, 0, $length);
    }
}
