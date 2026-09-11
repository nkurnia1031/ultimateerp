<?php

namespace App\Imports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

class BonusAyakImportPreview implements ToCollection, WithHeadingRow
{
    public array $entries = [];
    public array $errors = [];

    public function __construct(private int $businessId, private int $userId)
    {
    }

    public function collection(Collection $rows): void
    {
        $parsed = (new BonusAyakImport($this->businessId, $this->userId))->parse($rows);
        $this->entries = array_merge($this->entries, $parsed['entries']);
        $this->errors = array_merge($this->errors, $parsed['errors']);
    }

    public function parseSheets(Collection $sheets): void
    {
        foreach ($sheets as $rows) {
            if ($rows instanceof Collection) {
                $this->collection($rows);
            }
        }
    }
}
