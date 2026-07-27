<?php

declare(strict_types=1);

/**
 * Publish canonical module JavaScript into its public runtime directory.
 *
 * Usage:
 *   php scripts/publish-module-js.php Project
 *   php scripts/publish-module-js.php Project project.js
 *   php scripts/publish-module-js.php Accounting plugins/form-wizard.js
 */

function fail(string $message): void
{
    fwrite(STDERR, "Error: {$message}" . PHP_EOL);
    exit(1);
}

function normalizeRelativePath(string $path): string
{
    $path = str_replace('\\', '/', trim($path));

    if ($path === '' || str_starts_with($path, '/') || preg_match('/^[A-Za-z]:/', $path)) {
        fail('The JavaScript file must be a relative path.');
    }

    $segments = explode('/', $path);
    if (in_array('..', $segments, true) || in_array('', $segments, true)) {
        fail('The JavaScript file path may not contain empty or parent-directory segments.');
    }

    if (strtolower(pathinfo($path, PATHINFO_EXTENSION)) !== 'js') {
        fail('Only .js files can be published by this command.');
    }

    return implode(DIRECTORY_SEPARATOR, $segments);
}

$module = $argv[1] ?? '';
$requestedFile = $argv[2] ?? null;

if ($module === '' || ! preg_match('/^[A-Za-z0-9_-]+$/', $module)) {
    fail('Usage: php scripts/publish-module-js.php <Module> [relative/file.js]');
}

$projectRoot = dirname(__DIR__);
$moduleRoot = realpath($projectRoot . DIRECTORY_SEPARATOR . 'Modules' . DIRECTORY_SEPARATOR . $module);

if ($moduleRoot === false || ! is_dir($moduleRoot)) {
    fail("Module '{$module}' does not exist.");
}

$sourceRoot = realpath($moduleRoot . DIRECTORY_SEPARATOR . 'Resources' . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'js');
if ($sourceRoot === false || ! is_dir($sourceRoot)) {
    fail("Module '{$module}' has no Resources/assets/js directory.");
}

$targetRoot = $projectRoot
    . DIRECTORY_SEPARATOR . 'public'
    . DIRECTORY_SEPARATOR . 'modules'
    . DIRECTORY_SEPARATOR . strtolower(basename($moduleRoot))
    . DIRECTORY_SEPARATOR . 'js';

$files = [];

if ($requestedFile !== null) {
    $relativePath = normalizeRelativePath($requestedFile);
    $sourcePath = realpath($sourceRoot . DIRECTORY_SEPARATOR . $relativePath);

    if ($sourcePath === false || ! is_file($sourcePath)) {
        fail("Source JavaScript '{$requestedFile}' does not exist in module '{$module}'.");
    }

    $normalizedSourceRoot = rtrim(str_replace('\\', '/', $sourceRoot), '/');
    $normalizedSourcePath = str_replace('\\', '/', $sourcePath);
    if (! str_starts_with($normalizedSourcePath, $normalizedSourceRoot . '/')) {
        fail('The requested JavaScript file resolves outside the module source directory.');
    }

    $files[$relativePath] = $sourcePath;
} else {
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($sourceRoot, FilesystemIterator::SKIP_DOTS)
    );

    foreach ($iterator as $fileInfo) {
        if (! $fileInfo->isFile() || strtolower($fileInfo->getExtension()) !== 'js') {
            continue;
        }

        $sourcePath = $fileInfo->getPathname();
        $relativePath = ltrim(substr($sourcePath, strlen($sourceRoot)), '\\/');
        $files[$relativePath] = $sourcePath;
    }
}

if ($files === []) {
    fail("No JavaScript files were found for module '{$module}'.");
}

ksort($files);
$published = 0;
$unchanged = 0;

foreach ($files as $relativePath => $sourcePath) {
    $targetPath = $targetRoot . DIRECTORY_SEPARATOR . $relativePath;
    $targetDirectory = dirname($targetPath);

    if (! is_dir($targetDirectory) && ! mkdir($targetDirectory, 0775, true) && ! is_dir($targetDirectory)) {
        fail("Unable to create target directory '{$targetDirectory}'.");
    }

    $sourceHash = hash_file('sha256', $sourcePath);
    $targetHash = is_file($targetPath) ? hash_file('sha256', $targetPath) : null;

    if ($sourceHash === $targetHash) {
        $unchanged++;
        fwrite(STDOUT, "Unchanged: {$relativePath}" . PHP_EOL);
        continue;
    }

    if (! copy($sourcePath, $targetPath)) {
        fail("Unable to publish '{$relativePath}'.");
    }

    if (hash_file('sha256', $sourcePath) !== hash_file('sha256', $targetPath)) {
        fail("Verification failed after publishing '{$relativePath}'.");
    }

    $published++;
    fwrite(STDOUT, "Published: {$relativePath}" . PHP_EOL);
}

fwrite(
    STDOUT,
    "Done: {$published} published, {$unchanged} unchanged -> public/modules/"
        . strtolower(basename($moduleRoot))
        . "/js"
        . PHP_EOL
);
