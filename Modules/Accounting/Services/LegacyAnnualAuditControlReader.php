<?php

namespace Modules\Accounting\Services;

use DateTimeImmutable;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\IReadFilter;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use RuntimeException;

class LegacyAnnualAuditControlReader
{
    private const CHUNK_SIZE = 10000;

    public function read(array $files, string $root): array
    {
        $result = [
            'general_ledger' => [],
            'book_references' => [],
            'errors' => [],
        ];

        foreach ($files as $file) {
            if (! empty($file['duplicate_of'])) {
                continue;
            }
            $classification = (string) ($file['classification'] ?? '');
            if (! in_array($classification, ['general_ledger_control', 'book_reference'], true)) {
                continue;
            }

            $relativePath = (string) ($file['path'] ?? '');
            $path = rtrim($root, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR
                .str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
            try {
                if ($classification === 'general_ledger_control') {
                    $result['general_ledger'] = array_merge(
                        $result['general_ledger'],
                        $this->readGeneralLedger($path, $relativePath)
                    );
                } else {
                    $result['book_references'] = array_merge(
                        $result['book_references'],
                        $this->readBookReference($path, $relativePath)
                    );
                }
            } catch (\Throwable $exception) {
                $result['errors'][] = [
                    'path' => $relativePath,
                    'classification' => $classification,
                    'message' => $exception->getMessage(),
                ];
            }
        }

        return $result;
    }

    private function readGeneralLedger(string $path, string $relativePath): array
    {
        $parsed = [];
        $this->readWorkbookRows($path, 8, function (array $rows, string $sheetName) use ($relativePath, &$parsed): array {
            $parsed[$sheetName] ??= ['header' => null, 'account' => null, 'totals' => []];
            $state = &$parsed[$sheetName];

            foreach ($rows as [$rowNumber, $cells]) {
                if ($state['header'] === null) {
                    $state['header'] = $this->findHeader($cells, true);
                    if ($state['header'] === null) {
                        continue;
                    }
                    continue;
                }

                $header = $state['header'];
                $date = $this->parseDate($this->cell($cells, $header['date'] ?? 0));
                if ($date === null) {
                    $label = trim((string) ($cells[0] ?? ''));
                    $parsedAccount = $this->parseAccountHeader($label);
                    if ($parsedAccount !== null) {
                        $state['account'] = $parsedAccount;
                    }
                    continue;
                }
                if ($state['account'] === null) {
                    continue;
                }

                $year = (int) substr($date, 0, 4);
                $debit = $this->number($this->cell($cells, $header['debit'] ?? null)) ?? 0.0;
                $credit = $this->number($this->cell($cells, $header['credit'] ?? null)) ?? 0.0;
                $balance = $this->number($this->cell($cells, $header['balance'] ?? null));
                $description = $this->rowText($cells);
                $isOpening = preg_match('/\b(opening\s+balance|saldo\s+awal)\b/i', $description) === 1;
                $key = $state['account']['key'].'|'.$year;
                if (! isset($state['totals'][$key])) {
                    $state['totals'][$key] = [
                        'year' => $year,
                        'account_key' => $state['account']['key'],
                        'account_no' => $state['account']['account_no'],
                        'account_name' => $state['account']['account_name'],
                        'opening_balance' => null,
                        'debit' => 0.0,
                        'credit' => 0.0,
                        'closing_balance' => null,
                        'source_file' => $relativePath,
                        'source_sheet' => $sheetName,
                        'source_rows' => [],
                    ];
                }
                $state['totals'][$key]['source_rows'][] = $rowNumber;
                if ($isOpening) {
                    $state['totals'][$key]['opening_balance'] = $balance ?? ($debit - $credit);
                } else {
                    $state['totals'][$key]['debit'] += $debit;
                    $state['totals'][$key]['credit'] += $credit;
                }
                if ($balance !== null) {
                    $state['totals'][$key]['closing_balance'] = $balance;
                }
            }

            return [];
        }, fn (string $sheetName, int $index): bool => $index === 0);

        $result = [];
        foreach ($parsed as $state) {
            $result = array_merge($result, array_values($state['totals']));
        }

        return $result;
    }

    private function readBookReference(string $path, string $relativePath): array
    {
        $parsed = [];
        $this->readWorkbookRows($path, 7, function (array $rows, string $sheetName) use ($relativePath, &$parsed): array {
            $parsed[$sheetName] ??= [
                'header' => null,
                'year_from_sheet' => $this->yearFromText($sheetName) ?? $this->yearFromText(basename($relativePath)),
                'totals' => [],
            ];
            $state = &$parsed[$sheetName];

            foreach ($rows as [$rowNumber, $cells]) {
                if ($state['header'] === null) {
                    $state['header'] = $this->findHeader($cells, false);
                    if ($state['header'] === null) {
                        continue;
                    }
                    continue;
                }

                $header = $state['header'];
                $date = $this->parseDate($this->cell($cells, $header['date'] ?? 0));
                if ($date === null) {
                    continue;
                }
                $year = (int) substr($date, 0, 4);
                if ($state['year_from_sheet'] !== null && $year !== $state['year_from_sheet']) {
                    continue;
                }

                $debit = $this->number($this->cell($cells, $header['debit'] ?? null)) ?? 0.0;
                $credit = $this->number($this->cell($cells, $header['credit'] ?? null)) ?? 0.0;
                $balance = $this->number($this->cell($cells, $header['balance'] ?? null));
                $description = $this->rowText($cells);
                $isOpening = preg_match('/\b(opening\s+balance|saldo\s+awal)\b/i', $description) === 1;
                if (! isset($state['totals'][$year])) {
                    $state['totals'][$year] = [
                        'year' => $year,
                        'opening_balance' => null,
                        'debit' => 0.0,
                        'credit' => 0.0,
                        'closing_balance' => null,
                        'source_file' => $relativePath,
                        'source_sheet' => $sheetName,
                        'source_rows' => [],
                    ];
                }
                $state['totals'][$year]['source_rows'][] = $rowNumber;
                if ($isOpening) {
                    $state['totals'][$year]['opening_balance'] = $balance ?? ($debit - $credit);
                } else {
                    $state['totals'][$year]['debit'] += $debit;
                    $state['totals'][$year]['credit'] += $credit;
                }
                if ($balance !== null) {
                    $state['totals'][$year]['closing_balance'] = $balance;
                }
            }

            return [];
        }, function (string $sheetName, int $index): bool {
            $normalized = $this->normalize($sheetName);

            return str_contains($normalized, 'buku besar') || $normalized === 'done';
        });

        $result = [];
        foreach ($parsed as $state) {
            $result = array_merge($result, array_values($state['totals']));
        }

        return $result;
    }

    /**
     * Load large control workbooks in bounded row windows so auditing does not
     * keep the entire workbook object graph in PHP memory.
     */
    private function readWorkbookRows(string $path, int $maxColumns, callable $consume, callable $selectSheet): array
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new RuntimeException('File kontrol tidak dapat dibaca.');
        }

        $reader = IOFactory::createReaderForFile($path);
        $reader->setReadDataOnly(true);
        $worksheetInfo = $this->selectedWorksheetInfo($path, $selectSheet);
        $result = [];

        foreach ($worksheetInfo as $info) {
            $sheetName = (string) ($info['worksheetName'] ?? '');
            $lastRow = max(1, (int) ($info['totalRows'] ?? 1));
            $reader->setLoadSheetsOnly([$sheetName]);
            for ($start = 1; $start <= $lastRow; $start += self::CHUNK_SIZE) {
                $end = min($lastRow, $start + self::CHUNK_SIZE - 1);
                $sheetRows = [];
                $reader->setReadFilter(new class($sheetName, $start, $end, $maxColumns) implements IReadFilter {
                    private string $sheetName;
                    private int $start;
                    private int $end;
                    private int $maxColumns;

                    public function __construct(string $sheetName, int $start, int $end, int $maxColumns)
                    {
                        $this->sheetName = $sheetName;
                        $this->start = $start;
                        $this->end = $end;
                        $this->maxColumns = $maxColumns;
                    }

                    public function readCell($columnAddress, $row, $worksheetName = ''): bool
                    {
                        if ($worksheetName !== $this->sheetName || $row < $this->start || $row > $this->end) {
                            return false;
                        }

                        return \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($columnAddress) <= $this->maxColumns;
                    }
                });
                $workbook = $reader->load($path);
                try {
                    $sheet = $workbook->getSheetByName($sheetName);
                    if ($sheet === null) {
                        continue;
                    }
                    $lastColumn = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($maxColumns);
                    for ($row = $start; $row <= $end; $row++) {
                        $cells = $sheet->rangeToArray('A'.$row.':'.$lastColumn.$row, null, true, false, false)[0] ?? [];
                        $sheetRows[] = [$row, $cells];
                    }
                } finally {
                    $workbook->disconnectWorksheets();
                    unset($workbook);
                }
                $consume($sheetRows, $sheetName);
                if (function_exists('gc_collect_cycles')) {
                    gc_collect_cycles();
                }
            }
        }

        return $result;
    }

    /**
     * Read row counts only for sheets the audit consumes. PhpSpreadsheet's
     * listWorksheetInfo() scans every cell in every sheet; legacy workbooks can
     * contain irrelevant formatted sheets claiming more than a million rows.
     * The workbook relationships and each worksheet's dimension are small
     * metadata entries, so inspect those before opening selected sheets in
     * bounded row windows.
     */
    private function selectedWorksheetInfo(string $path, callable $selectSheet): array
    {
        $zip = new \ZipArchive();
        if ($zip->open($path) !== true) {
            throw new RuntimeException('File kontrol bukan workbook Excel yang dapat dibaca.');
        }

        try {
            $rootRelationships = $this->readXmlEntry($zip, '_rels/.rels');
            $workbookPath = null;
            foreach ($rootRelationships->xpath('//*[local-name()="Relationship"]') ?: [] as $relationship) {
                $attributes = $relationship->attributes();
                if (str_ends_with((string) ($attributes['Type'] ?? ''), '/officeDocument')) {
                    $workbookPath = $this->normalizeZipPath((string) ($attributes['Target'] ?? ''), '');
                    break;
                }
            }
            if ($workbookPath === null || $workbookPath === '') {
                throw new RuntimeException('Metadata workbook Excel tidak ditemukan.');
            }

            $workbookDirectory = dirname($workbookPath);
            $relationshipPath = ($workbookDirectory === '.' ? '' : $workbookDirectory.'/')
                .'_rels/'.basename($workbookPath).'.rels';
            $workbookRelationships = $this->readXmlEntry($zip, $relationshipPath);
            $targets = [];
            foreach ($workbookRelationships->xpath('//*[local-name()="Relationship"]') ?: [] as $relationship) {
                $attributes = $relationship->attributes();
                $targets[(string) ($attributes['Id'] ?? '')] = (string) ($attributes['Target'] ?? '');
            }

            $workbook = $this->readXmlEntry($zip, $workbookPath);
            $worksheetInfo = [];
            $sheetIndex = 0;
            foreach ($workbook->xpath('//*[local-name()="sheet"]') ?: [] as $sheet) {
                $attributes = $sheet->attributes();
                $sheetName = (string) ($attributes['name'] ?? '');
                if (! $selectSheet($sheetName, $sheetIndex)) {
                    $sheetIndex++;
                    continue;
                }

                $relationshipAttributes = $sheet->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships');
                $relationshipId = (string) ($relationshipAttributes['id'] ?? '');
                $target = $targets[$relationshipId] ?? '';
                if ($target === '') {
                    throw new RuntimeException('Relasi sheet "'.$sheetName.'" tidak ditemukan.');
                }

                $worksheetPath = $this->normalizeZipPath($target, $workbookDirectory);
                $worksheetInfo[] = [
                    'worksheetName' => $sheetName,
                    'totalRows' => $this->worksheetLastRow($zip, $worksheetPath),
                ];
                if ($worksheetInfo[array_key_last($worksheetInfo)]['totalRows'] <= 1) {
                    array_pop($worksheetInfo);
                }
                $sheetIndex++;
            }

            return $worksheetInfo;
        } finally {
            $zip->close();
        }
    }

    private function readXmlEntry(\ZipArchive $zip, string $path): \SimpleXMLElement
    {
        $contents = $zip->getFromName($path);
        if ($contents === false) {
            throw new RuntimeException('Metadata workbook tidak dapat dibaca.');
        }

        $xml = simplexml_load_string($contents, \SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOBLANKS);
        if (! $xml instanceof \SimpleXMLElement) {
            throw new RuntimeException('Format metadata workbook tidak valid.');
        }

        return $xml;
    }

    private function normalizeZipPath(string $target, string $baseDirectory): string
    {
        $path = str_starts_with($target, '/')
            ? ltrim($target, '/')
            : (($baseDirectory !== '' && $baseDirectory !== '.' ? $baseDirectory.'/' : '').$target);
        $segments = [];
        foreach (explode('/', str_replace('\\', '/', $path)) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                array_pop($segments);
                continue;
            }
            $segments[] = $segment;
        }

        return implode('/', $segments);
    }

    private function worksheetLastRow(\ZipArchive $zip, string $worksheetPath): int
    {
        $stream = $zip->getStream($worksheetPath);
        if ($stream === false) {
            throw new RuntimeException('Sheet workbook tidak dapat dibaca.');
        }

        try {
            $prefix = fread($stream, 65536);
        } finally {
            fclose($stream);
        }

        $dimensionLastRow = null;
        if (preg_match('/<(?:[A-Za-z0-9_]+:)?dimension\b[^>]*\bref=["\']([^"\']+)["\']/', $prefix, $matches) === 1
            && preg_match('/(?:^|:)[A-Z]{1,3}(\d+)$/i', $matches[1], $rowMatches) === 1) {
            $dimensionLastRow = max(1, (int) $rowMatches[1]);
            // Some workbooks extend a sheet's dimension to Excel's row limit
            // because of formatting. Avoid turning that formatting into a
            // million-row audit window; find the last row with actual values.
            if ($dimensionLastRow <= 100000) {
                return $dimensionLastRow;
            }
        }

        // The dimension element is optional in OOXML. For absent or suspicious
        // dimensions, inspect only this selected sheet and find the last row
        // with a date-column value. These legacy control formats store dates in
        // column A; stray values in other columns can extend the dimension to
        // Excel's row limit without adding an auditable transaction.
        $contents = $zip->getFromName($worksheetPath);
        if ($contents === false) {
            throw new RuntimeException('Sheet workbook tidak dapat dibaca.');
        }
        $xml = new \XMLReader();
        if (! $xml->XML($contents, null, LIBXML_NONET | LIBXML_COMPACT)) {
            throw new RuntimeException('Konten sheet workbook tidak valid.');
        }

        $lastDataRow = 0;
        $currentRow = 0;
        $rowHasDate = false;
        $insideDateCell = false;
        $valueDepth = null;
        $textDepth = null;
        try {
            while ($xml->read()) {
                if ($xml->nodeType === \XMLReader::ELEMENT) {
                    if ($xml->localName === 'row') {
                        $currentRow = (int) ($xml->getAttribute('r') ?? 0);
                    } elseif ($xml->localName === 'c') {
                        $insideDateCell = preg_match('/^A\d+$/i', (string) $xml->getAttribute('r')) === 1;
                        $valueDepth = null;
                        $textDepth = null;
                    } elseif ($insideDateCell && $xml->localName === 'f') {
                        $rowHasDate = true;
                    } elseif ($insideDateCell && $xml->localName === 'v' && ! $xml->isEmptyElement) {
                        $valueDepth = $xml->depth;
                    } elseif ($insideDateCell && $xml->localName === 't' && ! $xml->isEmptyElement) {
                        $textDepth = $xml->depth;
                    }
                } elseif (($xml->nodeType === \XMLReader::TEXT || $xml->nodeType === \XMLReader::CDATA)
                    && trim($xml->value) !== ''
                    && ($valueDepth !== null || $textDepth !== null)) {
                    $rowHasDate = true;
                } elseif ($xml->nodeType === \XMLReader::END_ELEMENT) {
                    if ($xml->localName === 'v' && $valueDepth === $xml->depth) {
                        $valueDepth = null;
                    } elseif ($xml->localName === 't' && $textDepth === $xml->depth) {
                        $textDepth = null;
                    } elseif ($xml->localName === 'c') {
                        $insideDateCell = false;
                    } elseif ($xml->localName === 'row' && $rowHasDate) {
                        $lastDataRow = max($lastDataRow, $currentRow);
                        $rowHasDate = false;
                    }
                }
            }
        } finally {
            $xml->close();
        }

        return max(1, $lastDataRow);
    }

    private function findHeader(array $cells, bool $generalLedger): ?array
    {
        $normalized = array_map(fn ($cell) => $this->normalize((string) $cell), $cells);
        $find = function (array $aliases) use ($normalized): ?int {
            foreach ($normalized as $index => $value) {
                foreach ($aliases as $alias) {
                    if ($value === $alias || str_contains($value, $alias)) {
                        return $index;
                    }
                }
            }

            return null;
        };

        $date = $find(['tanggal', 'date']);
        $debit = $find(['debit']);
        $credit = $find(['kredit', 'credit']);
        $balance = $find(['saldo akhir', 'saldo']);
        if ($date === null || $debit === null || $credit === null || $balance === null) {
            return null;
        }
        if ($generalLedger && ! $find(['transaksi', 'keterangan'])) {
            return null;
        }

        return compact('date', 'debit', 'credit', 'balance');
    }

    private function parseAccountHeader(string $label): ?array
    {
        if (preg_match('/^\s*\(?\s*([0-9][0-9A-Za-z.-]*)\s*\)?\s*(.*?)\s*$/u', $label, $matches) !== 1) {
            return null;
        }
        $accountNo = trim($matches[1]);
        $accountName = trim($matches[2]);
        if ($accountName === '') {
            return null;
        }

        return [
            'key' => $accountNo.'|'.mb_strtolower($accountName),
            'account_no' => $accountNo,
            'account_name' => $accountName,
        ];
    }

    private function parseDate($value): ?string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }
        if (is_numeric($value) && (float) $value > 10000 && (float) $value < 100000) {
            try {
                return ExcelDate::excelToDateTimeObject((float) $value)->format('Y-m-d');
            } catch (\Throwable $exception) {
                return null;
            }
        }

        $value = trim((string) $value);
        foreach (['!d/m/Y', '!d-m-Y', '!Y-m-d', '!m/d/Y'] as $format) {
            $date = DateTimeImmutable::createFromFormat($format, $value);
            if ($date !== false && $date->format(substr($format, 1)) === $value) {
                return $date->format('Y-m-d');
            }
        }

        return null;
    }

    private function number($value): ?float
    {
        if (is_int($value) || is_float($value)) {
            return is_finite((float) $value) ? (float) $value : null;
        }
        if (! is_string($value) || trim($value) === '') {
            return null;
        }
        $value = trim(str_replace(["\xc2\xa0", ' '], '', $value));
        $value = preg_replace('/[^0-9,().-]/u', '', $value);
        if ($value === '' || $value === '-') {
            return null;
        }
        $negative = str_starts_with($value, '(') && str_ends_with($value, ')');
        $value = trim($value, '()');
        if (str_contains($value, ',') && str_contains($value, '.')) {
            if (strrpos($value, ',') > strrpos($value, '.')) {
                $value = str_replace('.', '', $value);
                $value = str_replace(',', '.', $value);
            } else {
                $value = str_replace(',', '', $value);
            }
        } elseif (str_contains($value, ',')) {
            $parts = explode(',', $value);
            $value = count($parts) === 2 && strlen(end($parts)) <= 2
                ? implode('.', $parts)
                : implode('', $parts);
        } elseif (preg_match('/^\d{1,3}(?:\.\d{3})+$/', $value) === 1) {
            $value = str_replace('.', '', $value);
        }
        if (! is_numeric($value)) {
            return null;
        }

        $number = (float) $value;

        return is_finite($number) ? ($negative ? -$number : $number) : null;
    }

    private function cell(array $cells, ?int $index)
    {
        return $index === null ? null : ($cells[$index] ?? null);
    }

    private function rowText(array $cells): string
    {
        return implode(' ', array_filter(array_map(
            fn ($value) => trim((string) $value),
            $cells
        ), fn (string $value): bool => $value !== ''));
    }

    private function yearFromText(string $value): ?int
    {
        return preg_match('/(?<!\d)(20\d{2})(?!\d)/', $value, $matches) === 1
            ? (int) $matches[1]
            : null;
    }

    private function normalize(string $value): string
    {
        $value = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value) ?: $value;

        return trim(strtolower(preg_replace('/[^a-z0-9]+/i', ' ', $value)));
    }
}
