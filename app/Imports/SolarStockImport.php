<?php

namespace App\Imports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class SolarStockImport implements ToCollection, WithHeadingRow
{
    public function collection(Collection $rows)
    {
        foreach ($rows as $row) {
            if (empty($row['tanggal'])) continue;

            $tanggal = $this->parseDate($row['tanggal']);

            DB::table('solar_stock')->insert([
                'tanggal'       => $tanggal,
                'stock_in'      => $row['in'] ?? 0,
                'out_actual'    => $row['out_act'] ?? 0,
                'stock_actual'  => $row['stock_act'] ?? 0,
                'out_meteran'   => $row['out_meteran'] ?? 0,
                'stock_meteran' => $row['stock_meteran'] ?? 0,
                'keterangan'    => $row['keterangan'] ?? '',
                'created_at'    => now(),
                'updated_at'    => now(),
            ]);
        }
    }

    private function parseDate($value)
    {
        // ✅ Jika tanggal adalah angka (serial Excel)
        if (is_numeric($value)) {
            // Konversi dari serial Excel (mulai dari 1900-01-01)
            return Carbon::createFromTimestampUTC(($value - 25569) * 86400)->format('Y-m-d');
        }

        // ✅ Jika berupa teks seperti "10/09/2025" atau "1/11/2023"
        $value = trim($value);

        // Pastikan separator seragam
        $value = str_replace(['.', '-', '\\'], '/', $value);

        // Coba parse dengan format d/m/Y terlebih dahulu
        try {
            return Carbon::createFromFormat('d/m/Y', $value)->format('Y-m-d');
        } catch (\Exception $e) {
            // Jika gagal, coba format lain (misal Y-m-d)
            try {
                return Carbon::parse($value)->format('Y-m-d');
            } catch (\Exception $e2) {
                // Jika tetap gagal, fallback ke tanggal hari ini
                return now()->format('Y-m-d');
            }
        }
    }
}
