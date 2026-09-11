<?php

namespace App\Exports;

use App\BonusAyak;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;

class BonusAyakExport implements FromCollection, WithHeadings
{
    public function __construct(private ?string $from, private ?string $to, private ?int $businessId = null) {}

    public function collection()
    {
        $q = BonusAyak::query();
        if ($this->businessId) $q->where('business_id', $this->businessId);
        if ($this->from) $q->whereDate('tanggal','>=',$this->from);
        if ($this->to)   $q->whereDate('tanggal','<=',$this->to);
        if (!$this->from && !$this->to) {
            $q->whereMonth('tanggal', now()->month)->whereYear('tanggal', now()->year);
        }

        return $q->orderBy('tanggal')->get(['tanggal','nama_operator','unit','trip','bonus']);
    }

    public function headings(): array
    {
        return ['Tanggal','Nama Operator','Unit','Trip','Bonus'];
    }
}
