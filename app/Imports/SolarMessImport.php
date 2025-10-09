<?php

namespace App\Imports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class SolarMessImport implements ToCollection, WithHeadingRow
{
    public function collection(Collection $rows)
    {
        foreach ($rows as $row) {
            if (empty($row['tanggal'])) continue;

            $tanggal = $this->parseDate($row['tanggal']);

            DB::table('solar_mess')->insert([
                'tanggal'        => $tanggal,
                'qty_in'         => $row['in'] ?? 0,
                'qty_out'        => $row['out'] ?? 0,
                'current_stock'  => $row['current'] ?? 0,
                'keterangan'     => $row['keterangan'] ?? '',
                'unit'           => $row['unit'] ?? '',
                'operator'       => $row['operator'] ?? '',
                'customer_id'    => $row['customer_id'] ?? null,
                'lokasi'         => $row['lokasi'] ?? '',
                'created_at'     => now(),
                'updated_at'     => now(),
            ]);
        }
    }

    private function parseDate($value)
    {
        if (is_numeric($value)) {
            return Carbon::createFromTimestampUTC(($value - 25569) * 86400)->format('Y-m-d');
        }

        $value = trim($value);
        $value = str_replace(['.', '-', '\\'], '/', $value);

        try {
            return Carbon::createFromFormat('d/m/Y', $value)->format('Y-m-d');
        } catch (\Exception $e) {
            try {
                return Carbon::parse($value)->format('Y-m-d');
            } catch (\Exception $e2) {
                return now()->format('Y-m-d');
            }
        }
    }
}
