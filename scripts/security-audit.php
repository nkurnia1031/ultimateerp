<?php

declare(strict_types=1);

/**
 * Read-only filesystem audit for suspected webshells and persistence files.
 *
 * This script deliberately does not delete, move, chmod, or quarantine files.
 * Run it on the production host so it can inspect the production filesystem:
 *
 *   php scripts/security-audit.php --root=/home/account/domains/example.com/public_html --since="30 days ago"
 *   php scripts/security-audit.php --root=/home/account/domains/example.com/public_html --json --output=/tmp/audit.json
 *
 * Exit codes:
 *   0 = no findings
 *   1 = findings were reported
 *   2 = invalid arguments or audit failure
 */

final class SecurityAudit
{
    private const EXECUTABLE_EXTENSIONS = [
        'php', 'php3', 'php4', 'php5', 'php7', 'php74', 'php8', 'phtml', 'phar',
        'cgi', 'pl', 'py', 'sh', 'bash', 'asp', 'aspx', 'jsp',
    ];

    private const PERSISTENCE_FILES = [
        '.htaccess', '.user.ini', 'web.config', 'php.ini',
    ];

    private const KNOWN_PUBLIC_ENTRYPOINTS = [
        'public/index.php',
        'public/install/index.php',
    ];

    private const SKIPPED_DIRECTORIES = [
        '.git', 'node_modules', 'graphify-out',
        'storage/framework/cache', 'storage/framework/sessions',
        'storage/framework/views',
    ];

    private string $root;
    private int $since;
    private int $maxReadBytes;
    private bool $includeVendor;
    private bool $json;
    private array $findings = [];
    private array $gitChanges = [];
    private array $skipped = [];
    private int $filesScanned = 0;

    public function __construct(
        string $root,
        int $since,
        int $maxReadBytes,
        bool $includeVendor,
        bool $json
    ) {
        $resolvedRoot = realpath($root);
        if ($resolvedRoot === false || ! is_dir($resolvedRoot)) {
            throw new InvalidArgumentException("Root directory does not exist: {$root}");
        }

        $this->root = rtrim($resolvedRoot, DIRECTORY_SEPARATOR);
        $this->since = $since;
        $this->maxReadBytes = max(65536, $maxReadBytes);
        $this->includeVendor = $includeVendor;
        $this->json = $json;
    }

    public function run(): array
    {
        $this->collectGitChanges();
        $this->scanFilesystem();
        $this->scanSpecialFiles();

        usort($this->findings, function (array $left, array $right): int {
            $severity = ['critical' => 0, 'high' => 1, 'medium' => 2, 'low' => 3];
            $leftSeverity = $severity[$left['severity']] ?? 9;
            $rightSeverity = $severity[$right['severity']] ?? 9;

            return [$leftSeverity, $left['mtime']] <=> [$rightSeverity, $right['mtime']];
        });

        return [
            'generated_at' => date(DATE_ATOM),
            'root' => $this->root,
            'since' => date(DATE_ATOM, $this->since),
            'files_scanned' => $this->filesScanned,
            'git_changes' => $this->gitChanges,
            'findings' => $this->findings,
            'skipped' => $this->skipped,
        ];
    }

    private function scanFilesystem(): void
    {
        try {
            $directory = new RecursiveDirectoryIterator(
                $this->root,
                FilesystemIterator::SKIP_DOTS | FilesystemIterator::CURRENT_AS_FILEINFO
            );
            $iterator = new RecursiveIteratorIterator($directory, RecursiveIteratorIterator::LEAVES_ONLY);
        } catch (UnexpectedValueException $exception) {
            $this->skipped[] = 'Unable to traverse the audit root: ' . $exception->getMessage();

            return;
        }

        try {
            foreach ($iterator as $file) {
                if (! $file->isFile()) {
                    continue;
                }

                $absolutePath = $file->getPathname();
                $relativePath = $this->relativePath($absolutePath);

                if ($this->shouldSkip($relativePath)) {
                    continue;
                }

                $this->filesScanned++;
                $this->inspectFile($absolutePath, $relativePath, (int) $file->getMTime(), (int) $file->getSize());
            }
        } catch (UnexpectedValueException $exception) {
            $this->skipped[] = 'A directory could not be traversed: ' . $exception->getMessage();
        }
    }

    private function inspectFile(string $absolutePath, string $relativePath, int $mtime, int $size): void
    {
        $extension = strtolower(pathinfo($relativePath, PATHINFO_EXTENSION));
        $lowerPath = strtolower(str_replace('\\', '/', $relativePath));
        $isPublicUpload = str_starts_with($lowerPath, 'public/uploads/');
        $isStorage = str_starts_with($lowerPath, 'storage/app/');
        $isStorageLog = str_starts_with($lowerPath, 'storage/logs/');
        $isPublic = str_starts_with($lowerPath, 'public/');
        $isWritableArea = $isPublicUpload || $isStorage || (str_starts_with($lowerPath, 'storage/') && ! $isStorageLog);
        $isExecutable = in_array($extension, self::EXECUTABLE_EXTENSIONS, true);
        $isPersistenceFile = in_array(strtolower(basename($relativePath)), self::PERSISTENCE_FILES, true);
        $recent = $mtime >= $this->since;
        $reasons = [];
        $signals = [];
        $severity = null;

        if ($isPublicUpload && $isExecutable) {
            $severity = 'critical';
            $reasons[] = 'executable file inside public/uploads';
        } elseif ($isStorage && $isExecutable) {
            $severity = 'high';
            $reasons[] = 'executable file inside storage/app';
        } elseif ($isPublic && $isExecutable && ! in_array($lowerPath, self::KNOWN_PUBLIC_ENTRYPOINTS, true)) {
            $severity = 'high';
            $reasons[] = 'executable file inside public web root';
        }

        if ($isPersistenceFile && $isWritableArea) {
            $severity = $this->highestSeverity($severity, 'high');
            $reasons[] = 'web-server/PHP persistence file inside writable area';
        }

        $shouldRead = $isExecutable || $isPersistenceFile || $isWritableArea || $this->looksLikeCode($extension);
        $sample = $shouldRead ? $this->readSample($absolutePath, $size) : null;
        $scanSignals = $isExecutable || $isPersistenceFile || $isWritableArea;
        if ($scanSignals && $sample !== null && $sample !== '' && ! str_contains($sample, "\0")) {
            [$signals, $signalReasons] = $this->detectSignals($sample, $extension, $isPersistenceFile);
            $reasons = array_merge($reasons, $signalReasons);

            $hasObfuscation = in_array('obfuscated_payload', $signals, true);
            $hasDirectCodeExecution = in_array('direct_code_execution', $signals, true);
            $hasRemoteInclude = in_array('remote_include', $signals, true);
            $hasInput = in_array('request_input', $signals, true);
            $hasCommand = in_array('command_execution', $signals, true);
            $hasWrite = in_array('file_write', $signals, true);

            if ($hasObfuscation || $hasRemoteInclude || in_array('preg_replace_e', $signals, true)) {
                $severity = $this->highestSeverity($severity, $isWritableArea ? 'critical' : 'high');
            } elseif ($hasDirectCodeExecution) {
                $severity = $this->highestSeverity($severity, $isWritableArea ? 'critical' : 'high');
            } elseif (($hasInput && ($hasCommand || $hasWrite)) || ($hasCommand && $isWritableArea)) {
                $severity = $this->highestSeverity($severity, $isWritableArea ? 'critical' : 'high');
            } elseif ($signals !== [] && $isWritableArea) {
                $severity = $this->highestSeverity($severity, 'medium');
            }
        }

        if ($recent && $isWritableArea && ($isExecutable || $isPersistenceFile || $signals !== [])) {
            $reasons[] = 'modified after the audit time window';
            $severity = $this->highestSeverity($severity, 'medium');
        }

        if ($severity === null) {
            return;
        }

        $this->addFinding([
            'severity' => $severity,
            'kind' => $isPublicUpload && $isExecutable ? 'upload_webshell_candidate' : 'suspicious_file',
            'path' => $relativePath,
            'mtime' => $mtime,
            'modified_at' => date(DATE_ATOM, $mtime),
            'recent' => $recent,
            'size' => $size,
            'sha256' => is_readable($absolutePath) ? hash_file('sha256', $absolutePath) : null,
            'extension' => $extension,
            'signals' => array_values(array_unique($signals)),
            'reasons' => array_values(array_unique($reasons)),
        ]);
    }

    private function detectSignals(string $sample, string $extension, bool $isPersistenceFile): array
    {
        $rules = [
            'obfuscated_payload' => '/\b(?:eval|assert)\s*\(\s*(?:base64_decode|gzinflate|gzdecode|gzuncompress|str_rot13|hex2bin)\s*\(/i',
            'direct_code_execution' => '/\b(?:eval|assert)\s*\(/i',
            'encoded_payload' => '/\b(?:base64_decode|gzinflate|gzdecode|gzuncompress|str_rot13|hex2bin)\s*\(/i',
            'command_execution' => '/\b(?:shell_exec|passthru|proc_open|popen|system|exec)\s*\(/i',
            'request_input' => '/\$_(?:GET|POST|REQUEST|COOKIE|FILES)\b/i',
            'file_write' => '/\b(?:file_put_contents|fwrite|move_uploaded_file|chmod)\s*\(/i',
            'remote_include' => '/\b(?:include|include_once|require|require_once)\s*\(?\s*[\'\"]https?:\/\//i',
            'preg_replace_e' => '/preg_replace\s*\(\s*[\'\"][^\'\"]*\/e[\'\"]/i',
            'auto_prepend' => '/\bauto_(?:prepend|append)_file\b/i',
        ];

        $signals = [];
        $reasons = [];
        foreach ($rules as $signal => $pattern) {
            if (preg_match($pattern, $sample) === 1) {
                $signals[] = $signal;
                $reasons[] = "content signal: {$signal}";
            }
        }

        if ($isPersistenceFile && preg_match('/(?:AddHandler|SetHandler|php_value|RewriteRule|auto_prepend_file)/i', $sample) === 1) {
            $signals[] = 'server_handler_override';
            $reasons[] = 'handler or PHP override directive detected';
        }

        if ($extension !== 'php' && preg_match('/<\?(?:php|=)?/i', $sample) === 1) {
            $signals[] = 'php_code_in_non_php_file';
            $reasons[] = 'PHP opening tag in a non-PHP extension';
        }

        return [$signals, $reasons];
    }

    private function scanSpecialFiles(): void
    {
        $publicEnv = $this->root . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . '.env';
        if (is_file($publicEnv)) {
            $this->addFinding($this->fileFinding($publicEnv, 'critical', 'exposed_environment_file', [
                'public/.env is directly reachable if the web server serves dot-files',
            ]));
        }

        $rootEnv = $this->root . DIRECTORY_SEPARATOR . '.env';
        if (is_file($rootEnv) && (fileperms($rootEnv) & 0077) !== 0) {
            $this->addFinding($this->fileFinding($rootEnv, 'high', 'permissive_environment_permissions', [
                '.env is readable or writable by group/other users',
            ]));
        }

        foreach (['public/uploads', 'storage/app', 'bootstrap/cache'] as $relativeDirectory) {
            $directory = $this->root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativeDirectory);
            if (is_dir($directory) && (fileperms($directory) & 0002) !== 0) {
                $this->addFinding([
                    'severity' => 'high',
                    'kind' => 'world_writable_directory',
                    'path' => $relativeDirectory,
                    'mtime' => (int) filemtime($directory),
                    'modified_at' => date(DATE_ATOM, (int) filemtime($directory)),
                    'recent' => false,
                    'size' => null,
                    'sha256' => null,
                    'extension' => null,
                    'signals' => [],
                    'reasons' => ['directory is writable by all users'],
                ]);
            }
        }
    }

    private function collectGitChanges(): void
    {
        if (! is_dir($this->root . DIRECTORY_SEPARATOR . '.git')) {
            $this->skipped[] = 'No .git directory; tracked-file comparison was unavailable.';

            return;
        }

        $command = 'git -C ' . escapeshellarg($this->root) . ' status --porcelain=v1 --untracked-files=all';
        $output = @shell_exec($command);
        if ($output === null) {
            $this->skipped[] = 'git status could not be executed.';

            return;
        }

        foreach (preg_split('/\r?\n/', trim($output)) as $line) {
            if ($line !== '') {
                $this->gitChanges[] = $line;
            }
        }
    }

    private function readSample(string $path, int $size): ?string
    {
        if (! is_readable($path)) {
            return null;
        }

        $length = min($size, $this->maxReadBytes);
        if ($length <= 0) {
            return null;
        }

        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            return null;
        }

        $sample = @fread($handle, $length);
        fclose($handle);

        return $sample === false ? null : $sample;
    }

    private function fileFinding(string $absolutePath, string $severity, string $kind, array $reasons): array
    {
        $mtime = (int) filemtime($absolutePath);
        $relativePath = $this->relativePath($absolutePath);

        return [
            'severity' => $severity,
            'kind' => $kind,
            'path' => $relativePath,
            'mtime' => $mtime,
            'modified_at' => date(DATE_ATOM, $mtime),
            'recent' => $mtime >= $this->since,
            'size' => (int) filesize($absolutePath),
            'sha256' => is_readable($absolutePath) ? hash_file('sha256', $absolutePath) : null,
            'extension' => strtolower(pathinfo($relativePath, PATHINFO_EXTENSION)),
            'signals' => [],
            'reasons' => $reasons,
        ];
    }

    private function addFinding(array $finding): void
    {
        $this->findings[$finding['path'] . '|' . $finding['kind']] = $finding;
    }

    private function shouldSkip(string $relativePath): bool
    {
        $normalized = strtolower(str_replace('\\', '/', $relativePath));
        if ($normalized === 'scripts/security-audit.php') {
            return true;
        }
        foreach (self::SKIPPED_DIRECTORIES as $directory) {
            if (str_starts_with($normalized, strtolower($directory) . '/')) {
                return true;
            }
        }

        if (! $this->includeVendor && ($normalized === 'vendor' || str_starts_with($normalized, 'vendor/'))) {
            return true;
        }

        return false;
    }

    private function relativePath(string $absolutePath): string
    {
        return ltrim(str_replace('\\', '/', substr($absolutePath, strlen($this->root))), '/');
    }

    private function looksLikeCode(string $extension): bool
    {
        return in_array($extension, array_merge(self::EXECUTABLE_EXTENSIONS, ['js', 'json', 'xml', 'ini', 'conf', 'txt']), true);
    }

    private function highestSeverity(?string $current, string $candidate): string
    {
        $rank = ['low' => 1, 'medium' => 2, 'high' => 3, 'critical' => 4];
        if ($current === null || ($rank[$candidate] ?? 0) > ($rank[$current] ?? 0)) {
            return $candidate;
        }

        return $current;
    }
}

function securityAuditUsage(): void
{
    echo <<<'USAGE'
Usage:
  php scripts/security-audit.php [options]

Options:
  --root=PATH          Production application root. Defaults to this project root.
  --since=VALUE        Recent-file window, e.g. "30 days ago" or "2026-08-01".
                       Defaults to "30 days ago". Suspicious files are reported
                       even when older than this window.
  --include-vendor     Also scan vendor/ (slower, recommended for an incident).
  --max-read=BYTES     Maximum bytes read from one file. Default: 2097152.
  --json               Emit machine-readable JSON instead of a text report.
  --output=PATH        Write the report to a file. The scan remains read-only;
                       only this explicitly requested report file is written.
  --help               Show this help.

Examples:
  php scripts/security-audit.php --root=/home/account/domains/example.com/public_html --include-vendor
  php scripts/security-audit.php --root=/home/account/domains/example.com/public_html --since="7 days ago" --json --output=/tmp/audit.json
USAGE;
}

function securityAuditParseSince(?string $value): int
{
    $value = trim((string) ($value ?: '30 days ago'));
    $timestamp = ctype_digit($value) ? (int) $value : strtotime($value);
    if ($timestamp === false || $timestamp <= 0) {
        throw new InvalidArgumentException("Invalid --since value: {$value}");
    }

    return $timestamp;
}

function securityAuditText(array $report): string
{
    $counts = ['critical' => 0, 'high' => 0, 'medium' => 0, 'low' => 0];
    foreach ($report['findings'] as $finding) {
        $counts[$finding['severity']] = ($counts[$finding['severity']] ?? 0) + 1;
    }

    $lines = [
        'Security audit (read-only)',
        'Generated: ' . $report['generated_at'],
        'Root: ' . $report['root'],
        'Recent window: since ' . $report['since'],
        'Files scanned: ' . $report['files_scanned'],
        sprintf('Findings: critical=%d high=%d medium=%d low=%d', $counts['critical'], $counts['high'], $counts['medium'], $counts['low']),
    ];

    if ($report['git_changes'] !== []) {
        $lines[] = '';
        $lines[] = 'Git working tree changes (review independently):';
        foreach ($report['git_changes'] as $change) {
            $lines[] = '  ' . $change;
        }
    }

    foreach ($report['findings'] as $index => $finding) {
        $lines[] = '';
        $lines[] = sprintf(
            '[%s] %s: %s (%s, %d bytes)',
            strtoupper($finding['severity']),
            $finding['kind'],
            $finding['path'],
            $finding['modified_at'],
            $finding['size'] ?? 0
        );
        if ($finding['sha256']) {
            $lines[] = '  sha256: ' . $finding['sha256'];
        }
        if ($finding['signals'] !== []) {
            $lines[] = '  signals: ' . implode(', ', $finding['signals']);
        }
        foreach ($finding['reasons'] as $reason) {
            $lines[] = '  reason: ' . $reason;
        }
    }

    if ($report['skipped'] !== []) {
        $lines[] = '';
        $lines[] = 'Skipped/limitations:';
        foreach ($report['skipped'] as $skipped) {
            $lines[] = '  - ' . $skipped;
        }
    }

    $lines[] = '';
    $lines[] = 'Do not delete evidence before preserving the report, hashes, server logs, and a database/filesystem backup.';

    return implode(PHP_EOL, $lines) . PHP_EOL;
}

$options = getopt('', [
    'root::', 'since::', 'include-vendor', 'max-read::', 'json', 'output::', 'help',
]);

if (isset($options['help'])) {
    securityAuditUsage();
    exit(0);
}

try {
    $root = (string) ($options['root'] ?? dirname(__DIR__));
    $since = securityAuditParseSince(isset($options['since']) ? (string) $options['since'] : null);
    $maxRead = isset($options['max-read']) ? (int) $options['max-read'] : 2097152;
    $audit = new SecurityAudit(
        $root,
        $since,
        $maxRead,
        isset($options['include-vendor']),
        isset($options['json'])
    );
    $report = $audit->run();
    $output = isset($options['json']) ? json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL : securityAuditText($report);

    if (isset($options['output'])) {
        $outputPath = (string) $options['output'];
        if ($outputPath === '' || @file_put_contents($outputPath, $output) === false) {
            throw new RuntimeException("Unable to write report: {$outputPath}");
        }
        echo "Report written to {$outputPath}" . PHP_EOL;
    } else {
        echo $output;
    }

    $hasFindings = $report['findings'] !== [] || $report['git_changes'] !== [];
    exit($hasFindings ? 1 : 0);
} catch (Throwable $exception) {
    fwrite(STDERR, 'Audit failed: ' . $exception->getMessage() . PHP_EOL);
    exit(2);
}
