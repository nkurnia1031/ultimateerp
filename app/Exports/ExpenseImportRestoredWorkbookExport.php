<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;

class ExpenseImportRestoredWorkbookExport implements FromArray, WithHeadings, WithTitle
{
    private array $headings = [];

    private array $rows = [];

    public function __construct(array $rawRows)
    {
        foreach ($rawRows as $row) {
            if (! is_array($row)) {
                continue;
            }

            foreach ($row as $heading => $value) {
                $heading = (string) $heading;
                if (! in_array($heading, $this->headings, true)) {
                    $this->headings[] = $heading;
                }
                $row[$heading] = is_scalar($value) || $value === null
                    ? $value
                    : json_encode($value, JSON_UNESCAPED_UNICODE);
            }

            $this->rows[] = $row;
        }

        if ($this->headings === []) {
            $this->headings = [
                'LOCATION', 'CATEGORY', 'SUB-CATEGORY', 'DESCRIPTION', 'REFERENCE NO',
                'TAGS', 'EXPENSE DATE', 'EXPENSE FOR', 'EXPENSE FOR CONTACT ID',
                'ATTACH DOCUMENT', 'APPLICABLE TAX', 'EXPENSE NOTE', 'TOTAL AMOUNT',
                'PAID AMOUNT', 'PAID ON DATE', 'PAYMENT METHOD', 'PAYMENT ACCOUNT', 'PAYMENT NOTE',
            ];
        }
    }

    public function array(): array
    {
        return array_map(function (array $row): array {
            return array_map(fn (string $heading) => $row[$heading] ?? null, $this->headings);
        }, $this->rows);
    }

    public function headings(): array
    {
        return $this->headings;
    }

    public function title(): string
    {
        return 'Expense Import';
    }
}
