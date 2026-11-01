<?php

namespace Modules\Accounting\Services;

use DateTimeImmutable;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

class LegacyOperationalEvidenceReader
{
    private const MONEY_FIELDS = [
        'total' => ['total', 'total invoice', 'jumlah', 'original amount', 'total akhir', 'total transaksi'],
        'tax' => ['total pajak', 'tax', 'tax amount', 'pajak'],
        'base' => ['jumlah kena pajak', 'gross taxable amount', 'taxable amount', 'subtotal', 'total sebelum pajak'],
        'line_total' => ['jumlah per baris', 'total per line', 'line total'],
        'line_base' => ['jumlah kena pajak per baris', 'taxable amount per line', 'line base'],
        'line_tax' => ['jumlah pajak', 'line tax amount', 'tax amount per line'],
        'unit_price' => ['harga per unit', 'unit price', 'price per unit'],
    ];

    public function read(array $inventoryFiles, string $root, ?int $year = null): array
    {
        $documents = [];
        $fileSummaries = [];
        foreach ($inventoryFiles as $file) {
            if (! empty($file['duplicate_of'])) {
                continue;
            }

            $classification = (string) ($file['classification'] ?? '');
            if (! in_array($classification, [
                'sales_operational_source',
                'purchase_operational_source',
                'expense_operational_source',
                'expense_detail_source',
                'operational_detail_json',
            ], true)) {
                continue;
            }

            $absolutePath = rtrim($root, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR
                .str_replace('/', DIRECTORY_SEPARATOR, (string) $file['path']);
            try {
                $fileDocuments = $classification === 'operational_detail_json'
                    ? $this->readJson($absolutePath, $file, $year)
                    : $this->readWorkbook($absolutePath, $file, $year);
                $error = null;
            } catch (\Throwable $exception) {
                $fileDocuments = [];
                $error = $exception->getMessage();
            }
            $documents = array_merge($documents, $fileDocuments);
            $fileSummaries[] = [
                'path' => (string) $file['path'],
                'sha256' => (string) $file['sha256'],
                'classification' => $classification,
                'documents' => count($fileDocuments),
                'error' => $error,
            ];
        }

        return $this->mergeDocuments($documents, $fileSummaries);
    }

    public function mergeDocuments(array $documents, array $fileSummaries = []): array
    {
        $groups = [];
        foreach ($documents as $document) {
            if (! is_array($document)) {
                continue;
            }
            $key = $this->documentKey($document);
            if ($key === null) {
                continue;
            }
            $groups[$key][] = $document;
        }

        $merged = [];
        $conflictCount = 0;
        $duplicateCount = 0;
        foreach ($groups as $key => $candidates) {
            $result = $this->mergeCandidates($candidates);
            $result['identity'] = $key;
            if ($result['status'] === 'conflict') {
                $conflictCount++;
            }
            $duplicateCount += max(0, count($candidates) - 1);
            $merged[$key] = $result;
        }

        return [
            'documents' => $merged,
            'document_count' => count($merged),
            'source_record_count' => count($documents),
            'duplicate_source_count' => $duplicateCount,
            'conflict_count' => $conflictCount,
            'file_summaries' => $fileSummaries,
        ];
    }

    private function readWorkbook(string $path, array $file, ?int $year): array
    {
        $reader = IOFactory::createReaderForFile($path);
        $reader->setReadDataOnly(true);
        $workbook = $reader->load($path);
        try {
            $sheet = $workbook->getSheet(0);
            $lastRow = $sheet->getHighestDataRow();
            $headerRow = $this->findHeaderRow($sheet, min(20, $lastRow));
            if ($headerRow === null) {
                return [];
            }

            $header = $this->headerMap($sheet, $headerRow);
            $sourceType = $this->sourceTypeForClassification((string) $file['classification']);
            $result = [];
            $sectionCategory = null;
            for ($rowNumber = $headerRow + 1; $rowNumber <= $lastRow; $rowNumber++) {
                $row = $sheet->rangeToArray('A'.$rowNumber.':'.$sheet->getHighestDataColumn().$rowNumber, null, true, false, false)[0] ?? [];
                if ($row === []) {
                    continue;
                }
                $dateValue = $this->value($row, $header['date'] ?? null);
                $date = $this->parseDate($dateValue);
                if ($date === null && $file['classification'] === 'expense_detail_source') {
                    $heading = trim((string) ($row[$header['date'] ?? 0] ?? ''));
                    if ($heading !== '') {
                        $sectionCategory = $heading;
                    }
                    continue;
                }

                $documentNumber = trim((string) $this->value($row, $header['number'] ?? null));
                if ($date === null || $documentNumber === '') {
                    continue;
                }
                if ($year !== null && (int) substr($date, 0, 4) !== $year) {
                    continue;
                }

                $category = trim((string) $this->value($row, $header['category'] ?? null));
                if ($category === '' && $file['classification'] === 'expense_detail_source') {
                    $category = (string) ($sectionCategory ?? '');
                }
                $document = $this->newDocument(
                    $sourceType,
                    $documentNumber,
                    $date,
                    (string) ($file['path'] ?? ''),
                    (string) ($file['sha256'] ?? ''),
                    $rowNumber
                );
                $document['contact_name'] = trim((string) $this->value($row, $header['contact'] ?? null));
                $document['description'] = trim((string) $this->value($row, $header['description'] ?? null));
                $document['category_name'] = $category;
                $document['total'] = $file['classification'] === 'expense_detail_source'
                    ? null
                    : $this->number($this->value($row, $header['total'] ?? null));
                $document['tax'] = $this->number($this->value($row, $header['tax'] ?? null));
                $document['base'] = $file['classification'] === 'expense_detail_source'
                    ? null
                    : $this->number($this->value($row, $header['base'] ?? null));

                $productName = trim((string) $this->value($row, $header['product'] ?? null));
                if ($productName !== '') {
                    $document['lines'][] = $this->lineFromWorkbookRow($row, $header, $productName, $rowNumber);
                }
                if ($file['classification'] === 'expense_detail_source' && $category !== '') {
                    $amount = $this->number($this->value($row, $header['total'] ?? null));
                    if ($amount === null || $amount <= 0) {
                        $amount = $this->number($this->value($row, $header['line_total'] ?? null));
                    }
                    if ($amount !== null && $amount > 0) {
                        $document['expense_lines'][] = [
                            'category_name' => $category,
                            'description' => $document['description'],
                            'amount' => $amount,
                        ];
                    }
                }
                $result[] = $document;
            }

            return $this->groupFileRows($result);
        } finally {
            $workbook->disconnectWorksheets();
            unset($workbook);
        }
    }

    private function readJson(string $path, array $file, ?int $year): array
    {
        $contents = file_get_contents($path);
        $decoded = is_string($contents) ? json_decode($contents, true) : null;
        if (! is_array($decoded)) {
            throw new \RuntimeException('JSON operational tidak valid: '.(string) ($file['path'] ?? basename($path)));
        }

        $sourceType = preg_match('/^pembelian/i', basename($path)) === 1 ? 'Purchase Invoice' : 'Sales Invoice';
        $rootKey = $sourceType === 'Purchase Invoice' ? 'purchases_lists' : 'sales_lists';
        $records = $decoded[$rootKey]['transactions'] ?? [];
        $result = [];
        foreach ((array) $records as $recordIndex => $record) {
            if (! is_array($record)) {
                continue;
            }
            $date = $this->parseDate($record['transaction_date'] ?? null);
            $documentNumber = trim((string) ($record['transaction_no'] ?? ''));
            if ($date === null || $documentNumber === '' || ($year !== null && (int) substr($date, 0, 4) !== $year)) {
                continue;
            }

            $document = $this->newDocument(
                $sourceType,
                $documentNumber,
                $date,
                (string) ($file['path'] ?? ''),
                (string) ($file['sha256'] ?? ''),
                (int) $recordIndex + 1
            );
            $legacyRecordId = is_scalar($record['id'] ?? null) ? trim((string) $record['id']) : '';
            if ($legacyRecordId !== '') {
                $document['legacy_record_id'] = $legacyRecordId;
                $document['provenance'][0]['legacy_record_id'] = $legacyRecordId;
            }
            $document['contact_name'] = trim((string) ($record['person']['name'] ?? ''));
            $document['description'] = trim((string) ($record['description'] ?? $record['memo'] ?? ''));
            $document['total'] = $this->number($record['original_amount'] ?? $record['total_invoice'] ?? null);
            $document['tax'] = $this->number($record['tax_amount'] ?? null);
            $document['base'] = $this->number($record['gross_taxable_amount'] ?? null);
            $productName = trim((string) ($record['product']['name'] ?? ''));
            $quantity = $this->parseQuantity($record['quantity_unit'] ?? null);
            $unitPrice = $this->number($record['unit_price'] ?? null);
            $lineBase = $this->number($record['taxable_amount_per_line'] ?? $record['total_per_line'] ?? null);
            if ($lineBase === null && $quantity !== null && $unitPrice !== null) {
                $lineBase = round($quantity * $unitPrice, 4);
            }
            if ($productName !== '' && $quantity !== null && $quantity > 0 && $unitPrice !== null && $unitPrice >= 0) {
                $document['lines'][] = [
                    'product_name' => $productName,
                    'product_code' => trim((string) ($record['product_code'] ?? '')),
                    'quantity' => $quantity,
                    'unit_price' => $lineBase !== null && $quantity > 0 ? round($lineBase / $quantity, 4) : $unitPrice,
                    'line_base' => $lineBase,
                    'tax' => $this->number($record['line_tax_amount'] ?? null),
                    'discount_percent' => $this->number($record['discount_line_rate'] ?? 0) ?: 0,
                    'description' => trim((string) ($record['description'] ?? '')),
                    'source_row' => (int) $recordIndex + 1,
                ];
            }
            $result[] = $document;
        }

        return $this->groupFileRows($result);
    }

    private function findHeaderRow($sheet, int $maxRow): ?int
    {
        for ($row = 1; $row <= $maxRow; $row++) {
            $cells = $sheet->rangeToArray('A'.$row.':'.$sheet->getHighestDataColumn().$row, null, true, false, false)[0] ?? [];
            $normalized = array_map(fn ($value) => $this->normalizeHeader((string) $value), $cells);
            $hasNumber = count(array_intersect($normalized, ['nomor', 'nomor transaksi', 'transaction number', 'transaction no', 'number'])) > 0;
            $hasDate = count(array_intersect($normalized, ['tanggal', 'tanggal transaksi', 'date', 'transaction date', 'kategori tanggal'])) > 0;
            if ($hasNumber && $hasDate) {
                return $row;
            }
        }

        return null;
    }

    private function headerMap($sheet, int $headerRow): array
    {
        $cells = $sheet->rangeToArray('A'.$headerRow.':'.$sheet->getHighestDataColumn().$headerRow, null, true, false, false)[0] ?? [];
        $normalized = array_map(fn ($value) => $this->normalizeHeader((string) $value), $cells);
        $aliases = [
            'date' => ['tanggal', 'tanggal transaksi', 'date', 'transaction date', 'kategori tanggal'],
            'number' => ['nomor transaksi', 'transaction number', 'transaction no', 'nomor', 'number'],
            'contact' => ['nama panggilan', 'customer', 'supplier', 'contact', 'person'],
            'description' => ['deskripsi', 'description', 'keterangan', 'memo'],
            'category' => ['kategori', 'category'],
            'product' => ['nama produk', 'product', 'product name'],
            'product_code' => ['kode produk', 'product code', 'sku'],
            'quantity' => ['kuantitas', 'quantity', 'qty'],
            'unit_price' => self::MONEY_FIELDS['unit_price'],
            'line_total' => self::MONEY_FIELDS['line_total'],
            'line_base' => self::MONEY_FIELDS['line_base'],
            'line_tax' => self::MONEY_FIELDS['line_tax'],
            'discount' => ['diskon per baris', 'discount line rate', 'discount percent'],
            'total' => self::MONEY_FIELDS['total'],
            'tax' => self::MONEY_FIELDS['tax'],
            'base' => self::MONEY_FIELDS['base'],
        ];
        $map = [];
        foreach ($aliases as $field => $names) {
            foreach ($normalized as $index => $name) {
                if (in_array($name, $names, true)) {
                    $map[$field] = $index;
                    break;
                }
            }
        }

        return $map;
    }

    private function lineFromWorkbookRow(array $row, array $header, string $productName, int $rowNumber): array
    {
        $quantity = $this->parseQuantity($this->value($row, $header['quantity'] ?? null));
        $unitPrice = $this->number($this->value($row, $header['unit_price'] ?? null));
        $lineBase = $this->number($this->value($row, $header['line_base'] ?? null));
        if ($lineBase === null) {
            $lineBase = $this->number($this->value($row, $header['line_total'] ?? null));
        }
        if ($lineBase === null && $quantity !== null && $unitPrice !== null) {
            $lineBase = round($quantity * $unitPrice, 4);
        }
        $discount = $this->number($this->value($row, $header['discount'] ?? null)) ?: 0.0;

        return [
            'product_name' => $productName,
            'product_code' => trim((string) $this->value($row, $header['product_code'] ?? null)),
            'quantity' => $quantity,
            'unit_price' => $lineBase !== null && $quantity !== null && $quantity > 0
                ? round($lineBase / $quantity, 4)
                : $unitPrice,
            'line_base' => $lineBase,
            'tax' => $this->number($this->value($row, $header['line_tax'] ?? null)),
            'discount_percent' => $discount,
            'description' => trim((string) $this->value($row, $header['description'] ?? null)),
            'source_row' => $rowNumber,
        ];
    }

    private function groupFileRows(array $rows): array
    {
        $groups = [];
        foreach ($rows as $row) {
            $key = $this->documentKey($row);
            if ($key === null) {
                continue;
            }
            if (! isset($groups[$key])) {
                $groups[$key] = $row;
                continue;
            }

            $group = &$groups[$key];
            foreach (['contact_name', 'description', 'category_name', 'total', 'tax', 'base'] as $field) {
                if (($group[$field] ?? null) === null || ($group[$field] ?? '') === '') {
                    $group[$field] = $row[$field] ?? null;
                }
            }
            $group['lines'] = array_merge((array) ($group['lines'] ?? []), (array) ($row['lines'] ?? []));
            $group['expense_lines'] = array_merge((array) ($group['expense_lines'] ?? []), (array) ($row['expense_lines'] ?? []));
            $group['provenance'] = array_merge((array) $group['provenance'], (array) $row['provenance']);
            $currentLegacyId = trim((string) ($group['legacy_record_id'] ?? ''));
            $rowLegacyId = trim((string) ($row['legacy_record_id'] ?? ''));
            if ($currentLegacyId !== '' && $rowLegacyId !== '' && $currentLegacyId !== $rowLegacyId) {
                $group['legacy_record_id_conflict'] = true;
            } elseif ($currentLegacyId === '' && $rowLegacyId !== '') {
                $group['legacy_record_id'] = $rowLegacyId;
            }
            unset($group);
        }

        return array_values($groups);
    }

    private function mergeCandidates(array $candidates): array
    {
        usort($candidates, function (array $left, array $right) {
            $score = function (array $candidate): int {
                $lines = (array) ($candidate['lines'] ?? []);
                $codedLines = count(array_filter($lines, fn (array $line) => trim((string) ($line['product_code'] ?? '')) !== ''));

                return count($lines) * 10 + $codedLines + count((array) ($candidate['expense_lines'] ?? []));
            };

            return $score($right) <=> $score($left);
        });
        $selected = $candidates[0];
        $allSources = [];
        $lineCandidates = [];
        $lineLookup = [];
        $lineCodes = [];
        $differentDetailSources = 0;
        $summaryValues = ['total' => [], 'tax' => [], 'base' => []];
        $expenseLines = [];

        foreach ($candidates as $candidate) {
            $allSources = array_merge($allSources, (array) ($candidate['provenance'] ?? []));
            foreach ($summaryValues as $field => $_) {
                if (is_numeric($candidate[$field] ?? null)) {
                    $summaryValues[$field][] = (float) $candidate[$field];
                }
            }
            $candidateLineFingerprints = [];
            foreach ((array) ($candidate['lines'] ?? []) as $line) {
                $lineFingerprint = $this->lineFingerprint($line);
                $candidateLineFingerprints[$lineFingerprint] = ($candidateLineFingerprints[$lineFingerprint] ?? 0) + 1;
                $lineLookup[$lineFingerprint] = $lineLookup[$lineFingerprint] ?? $line;
                $productCode = $this->normalizeIdentityText($line['product_code'] ?? '');
                if ($productCode !== '') {
                    $lineCodes[$lineFingerprint][$productCode] = true;
                }
            }
            if ($candidateLineFingerprints !== []) {
                ksort($candidateLineFingerprints);
                $lineCandidates[] = $candidateLineFingerprints;
                $differentDetailSources++;
            }
            foreach ((array) ($candidate['expense_lines'] ?? []) as $line) {
                $expenseLines[$this->expenseLineFingerprint($line)] = $line;
            }
        }

        $legacyRecordIds = array_values(array_unique(array_filter(array_map(
            fn (array $candidate) => trim((string) ($candidate['legacy_record_id'] ?? '')),
            $candidates
        ))));
        $legacyRecordIdConflict = count(array_filter(
            $candidates,
            fn (array $candidate) => ! empty($candidate['legacy_record_id_conflict'])
        )) > 0;
        if (count($legacyRecordIds) > 1 || $legacyRecordIdConflict) {
            return $this->conflictedDocument($selected, $candidates, 'ID transaksi legacy berbeda untuk nomor dan tanggal sumber yang sama.');
        }
        if ($legacyRecordIds !== []) {
            $selected['legacy_record_id'] = $legacyRecordIds[0];
        }

        foreach ($summaryValues as $field => $values) {
            if ($values !== [] && max($values) - min($values) > 0.01) {
                return $this->conflictedDocument($selected, $candidates, 'Nominal '.$field.' berbeda antar file operational.');
            }
            if (($selected[$field] ?? null) === null && $values !== []) {
                $selected[$field] = $values[0];
            }
        }

        foreach (['contact_name', 'description', 'category_name'] as $field) {
            if (trim((string) ($selected[$field] ?? '')) !== '') {
                continue;
            }
            foreach ($candidates as $candidate) {
                if (trim((string) ($candidate[$field] ?? '')) !== '') {
                    $selected[$field] = $candidate[$field];
                    break;
                }
            }
        }

        foreach ($lineCodes as $codes) {
            if (count($codes) > 1) {
                return $this->conflictedDocument($selected, $candidates, 'SKU berbeda untuk detail produk dengan nama, jumlah, dan harga yang sama.');
            }
        }

        if ($differentDetailSources > 1) {
            $referenceCounts = $lineCandidates[0] ?? [];
            $sameLineSet = count(array_filter($lineCandidates, fn (array $counts) => $counts !== $referenceCounts)) === 0;
            if (! $sameLineSet) {
                $maximumCounts = [];
                foreach ($lineCandidates as $counts) {
                    foreach ($counts as $fingerprint => $count) {
                        $maximumCounts[$fingerprint] = max($maximumCounts[$fingerprint] ?? 0, $count);
                    }
                }
                $candidateBases = array_filter(array_map(fn (array $candidate) => $candidate['base'] ?? null, $candidates), 'is_numeric');
                $combinedBase = 0.0;
                $combinedLines = [];
                foreach ($maximumCounts as $fingerprint => $count) {
                    $line = $lineLookup[$fingerprint];
                    $combinedBase += (float) ($line['line_base'] ?? 0) * $count;
                    for ($copy = 0; $copy < $count; $copy++) {
                        $combinedLines[] = $line;
                    }
                }
                if ($candidateBases !== [] && abs($combinedBase - (float) reset($candidateBases)) <= 0.01) {
                    $selected['lines'] = $combinedLines;
                } else {
                    return $this->conflictedDocument($selected, $candidates, 'Detail item berbeda dan tidak dapat digabungkan secara pasti.');
                }
            }
        }

        $selected['provenance'] = $this->uniqueProvenance($allSources);
        $selected['expense_lines'] = array_values($expenseLines);
        $selected['status'] = 'matched';
        $selected['source_count'] = count($candidates);

        return $selected;
    }

    private function conflictedDocument(array $selected, array $candidates, string $reason): array
    {
        $selected['status'] = 'conflict';
        $selected['review_reason'] = $reason;
        $selected['source_count'] = count($candidates);
        $selected['provenance'] = $this->uniqueProvenance(array_merge(...array_map(
            fn (array $candidate) => (array) ($candidate['provenance'] ?? []),
            $candidates
        )));

        return $selected;
    }

    private function newDocument(string $type, string $number, string $date, string $path, string $sha, int $row): array
    {
        $document = [
            'source_type' => $type,
            'source_number' => $number,
            'date' => $date,
            'contact_name' => '',
            'description' => '',
            'category_name' => '',
            'total' => null,
            'tax' => null,
            'base' => null,
            'lines' => [],
            'expense_lines' => [],
            'provenance' => [],
        ];
        $document['provenance'][] = [
            'path' => $path,
            'sha256' => $sha,
            'row' => $row,
            'source_identity' => hash('sha256', implode('|', [
                $type,
                $this->normalizeIdentityText($number),
                $date,
            ])),
        ];

        return $document;
    }

    private function lineFingerprint(array $line): string
    {
        $identity = [
            $this->normalizeIdentityText($line['product_name'] ?? ''),
            number_format((float) ($line['quantity'] ?? 0), 4, '.', ''),
            number_format((float) ($line['line_base'] ?? 0), 4, '.', ''),
            number_format((float) ($line['discount_percent'] ?? 0), 4, '.', ''),
        ];

        return hash('sha256', json_encode($identity));
    }

    private function expenseLineFingerprint(array $line): string
    {
        return hash('sha256', json_encode([
            $this->normalizeIdentityText($line['category_name'] ?? ''),
            $this->normalizeIdentityText($line['description'] ?? ''),
            number_format((float) ($line['amount'] ?? 0), 4, '.', ''),
        ]));
    }

    private function uniqueProvenance(array $provenance): array
    {
        $unique = [];
        $seen = [];
        foreach ($provenance as $source) {
            if (! is_array($source)) {
                continue;
            }
            $identity = implode('|', [
                (string) ($source['path'] ?? ''),
                (string) ($source['row'] ?? ''),
                (string) ($source['source_identity'] ?? ''),
            ]);
            if (isset($seen[$identity])) {
                continue;
            }
            $seen[$identity] = true;
            $unique[] = $source;
        }

        return $unique;
    }

    private function documentKey(array $document): ?string
    {
        $type = trim((string) ($document['source_type'] ?? ''));
        $number = $this->normalizeIdentityText($document['source_number'] ?? '');
        $date = trim((string) ($document['date'] ?? ''));
        if ($type === '' || $number === '' || preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
            return null;
        }

        return implode('|', [$type, $number, $date]);
    }

    private function sourceTypeForClassification(string $classification): string
    {
        if (str_starts_with($classification, 'sales_')) {
            return 'Sales Invoice';
        }
        if (str_starts_with($classification, 'purchase_')) {
            return 'Purchase Invoice';
        }

        return 'Expense';
    }

    private function normalizeHeader(string $header): string
    {
        $header = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $header) ?: $header;
        $header = strtolower(trim($header));

        return trim(preg_replace('/[^a-z0-9]+/', ' ', $header));
    }

    private function normalizeIdentityText($value): string
    {
        $value = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', (string) $value) ?: (string) $value;

        return strtoupper(trim(preg_replace('/\s+/', ' ', $value)));
    }

    private function value(array $row, ?int $index)
    {
        return $index !== null ? ($row[$index] ?? null) : null;
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
        if (is_numeric($value)) {
            return (float) $value;
        }
        if (! is_string($value)) {
            return null;
        }

        $value = trim(str_ireplace(['rp', 'idr', ' '], '', $value));
        $value = preg_replace('/[^0-9,\.\-]/', '', $value);
        if ($value === '' || $value === '-') {
            return null;
        }
        $comma = strrpos($value, ',');
        $dot = strrpos($value, '.');
        if ($comma !== false && $dot !== false) {
            if ($comma > $dot) {
                $value = str_replace('.', '', $value);
                $value = str_replace(',', '.', $value);
            } else {
                $value = str_replace(',', '', $value);
            }
        } elseif ($comma !== false) {
            $decimals = strlen($value) - $comma - 1;
            $value = $decimals > 0 && $decimals <= 2
                ? str_replace(',', '.', $value)
                : str_replace(',', '', $value);
        }

        return is_numeric($value) ? (float) $value : null;
    }

    private function parseQuantity($value): ?float
    {
        if (is_numeric($value)) {
            return (float) $value;
        }
        if (! is_string($value) || preg_match('/-?\d+(?:[.,]\d+)?/', $value, $matches) !== 1) {
            return null;
        }

        return (float) str_replace(',', '.', $matches[0]);
    }

}
