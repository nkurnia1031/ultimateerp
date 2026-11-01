<?php

namespace Modules\Accounting\Services;

use DateTimeImmutable;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

class LegacyImportSourceCatalog
{
    public function scan(string $directory, ?int $year = null): array
    {
        $root = realpath($directory);
        if ($root === false || ! is_dir($root) || ! is_readable($root)) {
            throw new RuntimeException('Folder import-legacy tidak ditemukan atau tidak dapat dibaca.');
        }

        $files = [];
        $firstPathByHash = [];
        $sourceFiles = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (! $file->isFile() || $file->isLink() || ! $file->isReadable()) {
                continue;
            }

            $absolutePath = $file->getPathname();
            $relativePath = str_replace('\\', '/', substr($absolutePath, strlen($root) + 1));
            $sourceFiles[$relativePath] = $file;
        }

        ksort($sourceFiles, SORT_STRING);
        foreach ($sourceFiles as $relativePath => $file) {
            $absolutePath = $file->getPathname();
            $classification = $this->classify($relativePath);
            $period = $this->periodFromName($relativePath);

            if ($year !== null && $period['start'] !== null && $period['end'] !== null
                && ($year < (int) substr($period['start'], 0, 4) || $year > (int) substr($period['end'], 0, 4))) {
                continue;
            }

            $hash = hash_file('sha256', $absolutePath);
            if ($hash === false) {
                throw new RuntimeException('Tidak dapat menghitung fingerprint file: '.$relativePath);
            }

            $files[] = [
                'path' => $relativePath,
                'size_bytes' => (int) $file->getSize(),
                'sha256' => $hash,
                'classification' => $classification,
                'period_start' => $period['start'],
                'period_end' => $period['end'],
                'duplicate_of' => $firstPathByHash[$hash] ?? null,
            ];
            $firstPathByHash[$hash] ??= $relativePath;
        }

        $counts = [];
        $yearCoverage = [];
        foreach ($files as $file) {
            $classification = $file['classification'];
            $counts[$classification] = ($counts[$classification] ?? 0) + 1;
            if ($file['period_start'] !== null) {
                $startYear = (int) substr($file['period_start'], 0, 4);
                $endYear = (int) substr($file['period_end'], 0, 4);
                for ($periodYear = $startYear; $periodYear <= $endYear; $periodYear++) {
                    $yearCoverage[$periodYear] = ($yearCoverage[$periodYear] ?? 0) + 1;
                }
            }
        }

        ksort($counts);
        ksort($yearCoverage);

        return [
            'root' => $root,
            'year_filter' => $year,
            'file_count' => count($files),
            'duplicate_file_count' => count(array_filter($files, fn (array $file): bool => $file['duplicate_of'] !== null)),
            'counts' => $counts,
            'year_coverage' => $yearCoverage,
            'files' => $files,
        ];
    }

    private function classify(string $relativePath): string
    {
        $normalized = '/'.ltrim(str_replace('\\', '/', strtolower($relativePath)), '/');
        $filename = basename($normalized);
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        if (str_contains($filename, '_errors') || str_contains($filename, 'template')) {
            return 'excluded_template_or_error';
        }
        if (preg_match('/(^|\/)journal_.*\.(xlsx|xls|xlsm|csv)$/', $normalized)) {
            return 'journal_posting_source';
        }
        if (preg_match('/(^|\/)lampiran\.json$/', $normalized)) {
            return 'attachment_metadata';
        }
        if (preg_match('/(^|\/)(pembelian|penjualan) 2022 page\d+\.json$/', $normalized)) {
            return 'operational_detail_json';
        }
        if (str_contains($filename, 'chartofaccounts') || str_contains($filename, 'chart_of_accounts')) {
            return 'coa_control';
        }
        if (str_contains($filename, 'general_ledger')) {
            return 'general_ledger_control';
        }
        if (str_contains($filename, 'buku besar')) {
            return 'book_reference';
        }
        if (str_contains($normalized, '/penjualan/') && str_contains($filename, 'sales_list') && $extension === 'xlsx') {
            return 'sales_operational_source';
        }
        if (str_contains($normalized, '/pembelian/') && str_contains($filename, 'purchases_list') && $extension === 'xlsx') {
            return 'purchase_operational_source';
        }
        if (str_contains($normalized, '/biaya/') && str_contains($filename, 'expensedetails') && $extension === 'xlsx') {
            return 'expense_detail_source';
        }
        if (str_contains($normalized, '/biaya/') && str_contains($filename, 'expense_list') && $extension === 'xlsx') {
            return 'expense_operational_source';
        }
        if ($extension === 'pdf') {
            return 'pdf_reference';
        }

        return 'unclassified';
    }

    private function periodFromName(string $path): array
    {
        preg_match_all('/(?<!\d)(\d{2}-\d{2}-\d{4})(?!\d)/', basename($path), $matches);
        $dates = [];
        foreach ($matches[1] ?? [] as $value) {
            $date = DateTimeImmutable::createFromFormat('!d-m-Y', $value);
            if ($date !== false && $date->format('d-m-Y') === $value) {
                $dates[] = $date->format('Y-m-d');
            }
        }

        if ($dates !== []) {
            sort($dates);

            return ['start' => $dates[0], 'end' => $dates[count($dates) - 1]];
        }

        if (preg_match('/(?<!\d)(20\d{2})(?!\d)/', basename($path), $yearMatch)) {
            return ['start' => $yearMatch[1].'-01-01', 'end' => $yearMatch[1].'-12-31'];
        }

        return ['start' => null, 'end' => null];
    }
}
