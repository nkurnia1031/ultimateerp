<?php

namespace App\Imports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class SolarPoolImport implements ToCollection, WithHeadingRow
{
    public function collection(Collection $rows)
    {
        foreach ($rows as $row) {
            if (empty($row['tanggal'])) continue;

            $tanggal = $this->parseDate($row['tanggal']);

            DB::table('solar_pool')->insert([
                'tanggal'        => $tanggal,
                'qty_in'         => $row['in'] ?? 0,
                'qty_out'        => $row['out'] ?? 0,
                'current_stock'  => $row['current'] ?? 0,
                'keterangan'     => $row['keterangan'] ?? '',
                'unit'           => $row['unit'] ?? '',
                'operator'       => $row['operator'] ?? '',
                'customer_id'    => $row['customer_id'] ?? null,
                'created_at'     => now(),
                'updated_at'     => now(),
            ]);
        }
    }

    private function parseDate($value)
    {
        // ✅ Jika tanggal adalah angka (serial Excel)
        if (is_numeric($value)) {
            return Carbon::createFromTimestampUTC(($value - 25569) * 86400)->format('Y-m-d');
        }

        // ✅ Jika berupa teks seperti "10/09/2025" atau "1/11/2023"
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
