<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class SolarLedgerService
{
    public const LEDGERS = ['stock', 'pool', 'mess'];

    private const TABLES = [
        'stock' => 'solar_stock',
        'pool' => 'solar_pool',
        'mess' => 'solar_mess',
    ];

    public function table(string $ledger): string
    {
        if (! isset(self::TABLES[$ledger])) {
            throw new InvalidArgumentException('Jenis ledger Solar tidak dikenal.');
        }

        return self::TABLES[$ledger];
    }

    public function recalculate(int $businessId, string $ledger): void
    {
        $table = $this->table($ledger);
        $opening = $this->openingBalance($businessId, $ledger);
        $rows = DB::table($table)
            ->where('business_id', $businessId)
            ->orderBy('tanggal')
            ->orderBy('id')
            ->get();

        if ($ledger === 'stock') {
            $actual = (float) ($opening->actual_balance ?? 0);
            $meter = (float) ($opening->meter_balance ?? 0);
            $updates = [];

            foreach ($rows as $row) {
                $actual += (float) $row->stock_in - (float) $row->out_actual;
                $meter += (float) $row->stock_in - (float) $row->out_meteran;
                $payload = (array) $row;
                $payload['stock_actual'] = $actual;
                $payload['stock_meteran'] = $meter;
                $payload['updated_at'] = now();
                $updates[] = $payload;
            }
            foreach (array_chunk($updates, 500) as $chunk) {
                DB::table($table)->upsert(
                    $chunk,
                    ['id'],
                    ['stock_actual', 'stock_meteran', 'updated_at']
                );
            }

            return;
        }

        $balance = (float) ($opening->quantity_balance ?? 0);
        $updates = [];
        foreach ($rows as $row) {
            $balance += (float) $row->qty_in - (float) $row->qty_out;
            $payload = (array) $row;
            $payload['current_stock'] = $balance;
            $payload['updated_at'] = now();
            $updates[] = $payload;
        }
        foreach (array_chunk($updates, 500) as $chunk) {
            DB::table($table)->upsert($chunk, ['id'], ['current_stock', 'updated_at']);
        }
    }

    public function openingBalance(int $businessId, string $ledger): ?object
    {
        return DB::table('solar_opening_balances')
            ->where('business_id', $businessId)
            ->where('ledger', $ledger)
            ->first();
    }

    public function setOpeningBalance(int $businessId, string $ledger, array $values, string $effectiveDate): void
    {
        $payload = [
            'business_id' => $businessId,
            'ledger' => $ledger,
            'effective_date' => $effectiveDate,
            'actual_balance' => $ledger === 'stock' ? (float) ($values['actual'] ?? 0) : null,
            'meter_balance' => $ledger === 'stock' ? (float) ($values['meter'] ?? 0) : null,
            'quantity_balance' => $ledger === 'stock' ? null : (float) ($values['quantity'] ?? 0),
            'updated_at' => now(),
        ];

        $query = DB::table('solar_opening_balances')
            ->where('business_id', $businessId)
            ->where('ledger', $ledger);
        if ($query->exists()) {
            $query->update($payload);
        } else {
            DB::table('solar_opening_balances')->insert($payload + ['created_at' => now()]);
        }
    }

    public function balancesBefore(int $businessId, string $date): array
    {
        $result = [];
        foreach (self::LEDGERS as $ledger) {
            $opening = $this->openingBalance($businessId, $ledger);
            $query = DB::table($this->table($ledger))
                ->where('business_id', $businessId)
                ->where('tanggal', '<', $date)
                ->orderByDesc('tanggal')
                ->orderByDesc('id')
                ->first();

            if ($ledger === 'stock') {
                $result[$ledger] = [
                    'actual' => (float) ($query->stock_actual ?? $opening->actual_balance ?? 0),
                    'meter' => (float) ($query->stock_meteran ?? $opening->meter_balance ?? 0),
                ];
            } else {
                $result[$ledger] = [
                    'quantity' => (float) ($query->current_stock ?? $opening->quantity_balance ?? 0),
                ];
            }
        }

        return $result;
    }

    public function currentBalances(int $businessId): array
    {
        return $this->balancesBefore($businessId, Carbon::tomorrow()->format('Y-m-d'));
    }

    public function rowHash(string $ledger, array $row): string
    {
        $keys = $ledger === 'stock'
            ? ['tanggal', 'stock_in', 'out_actual', 'out_meteran', 'keterangan']
            : ['tanggal', 'qty_in', 'qty_out', 'keterangan', 'unit', 'operator', 'customer_id', 'nama_kapal', 'lokasi'];

        $normalized = ['ledger' => $ledger];
        foreach ($keys as $key) {
            $value = $row[$key] ?? null;
            if ($key === 'customer_id') {
                $value = $value === null || $value === '' ? null : (int) $value;
            } elseif (is_numeric($value)) {
                $value = number_format((float) $value, 4, '.', '');
            } elseif (is_string($value)) {
                $value = mb_strtolower(trim(preg_replace('/\s+/', ' ', $value)));
            }
            $normalized[$key] = $value === '' ? null : $value;
        }

        return hash('sha256', json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION));
    }

    public function reconciliation(int $businessId, array $parsed): array
    {
        $items = [];
        $onlyExcel = [];
        $summary = [
            'match' => 0,
            'only_excel' => 0,
            'only_app' => 0,
            'different' => 0,
            'error' => count($parsed['errors'] ?? []),
        ];

        foreach ($parsed['errors'] ?? [] as $error) {
            $items[] = [
                'ledger' => $error['ledger'] ?? '-',
                'row' => $error['row'] ?? null,
                'date' => null,
                'status' => 'error',
                'differences' => [$error['message']],
                'excel' => null,
                'app' => null,
            ];
        }

        foreach (self::LEDGERS as $ledger) {
            $excelRows = array_values($parsed['rows'][$ledger] ?? []);
            $this->compareOpeningBalance(
                $businessId,
                $ledger,
                $parsed['opening'][$ledger] ?? null,
                $summary,
                $items
            );
            $dates = array_values(array_filter(array_column($excelRows, 'tanggal')));
            if (isset($parsed['opening'][$ledger]['date'])) {
                $dates[] = $parsed['opening'][$ledger]['date'];
            }
            if ($dates === []) {
                $appRows = [];
            } else {
                sort($dates);
                $appRows = DB::table($this->table($ledger))
                    ->where('business_id', $businessId)
                    ->whereBetween('tanggal', [$dates[0], $dates[count($dates) - 1]])
                    ->orderBy('tanggal')
                    ->orderBy('id')
                    ->get()
                    ->map(fn ($row) => $this->databaseRow($ledger, $row))
                    ->values()
                    ->all();
            }

            $deduplicatedExcel = [];
            $seenExcel = [];
            foreach ($excelRows as $row) {
                $hash = $this->rowHash($ledger, $row);
                if (isset($seenExcel[$hash])) {
                    $summary['error']++;
                    $items[] = [
                        'ledger' => $ledger,
                        'row' => $row['source_row'] ?? null,
                        'date' => $row['tanggal'] ?? null,
                        'status' => 'error',
                        'differences' => ['Duplikat dari baris Excel '.$seenExcel[$hash].'. Baris ini tidak akan diimpor.'],
                        'excel' => $row,
                        'app' => null,
                    ];
                    continue;
                }
                $seenExcel[$hash] = $row['source_row'] ?? '-';
                $deduplicatedExcel[] = $row;
            }
            $excelRows = $deduplicatedExcel;

            $appBuckets = [];
            foreach ($appRows as $index => $row) {
                $appBuckets[$this->rowHash($ledger, $row)][] = $index;
            }
            $usedApp = [];
            $remainingExcel = [];

            foreach ($excelRows as $row) {
                $hash = $this->rowHash($ledger, $row);
                $appIndex = null;
                while (! empty($appBuckets[$hash])) {
                    $candidate = array_shift($appBuckets[$hash]);
                    if (! isset($usedApp[$candidate])) {
                        $appIndex = $candidate;
                        break;
                    }
                }

                if ($appIndex === null) {
                    $remainingExcel[] = $row;
                    continue;
                }

                $usedApp[$appIndex] = true;
                $differences = $this->balanceDifferences($ledger, $row, $appRows[$appIndex]);
                $status = $differences === [] ? 'match' : 'different';
                $summary[$status]++;
                $items[] = $this->comparisonItem($ledger, $row, $appRows[$appIndex], $status, $differences);
            }

            $remainingApp = [];
            foreach ($appRows as $index => $row) {
                if (! isset($usedApp[$index])) {
                    $remainingApp[] = $row;
                }
            }

            foreach ($remainingExcel as $excelIndex => $excelRow) {
                $pairIndex = $this->findSameDate($remainingApp, (string) $excelRow['tanggal']);
                if ($pairIndex !== null) {
                    $appRow = $remainingApp[$pairIndex];
                    unset($remainingApp[$pairIndex]);
                    $remainingApp = array_values($remainingApp);
                    $differences = $this->allDifferences($ledger, $excelRow, $appRow);
                    $summary['different']++;
                    $items[] = $this->comparisonItem($ledger, $excelRow, $appRow, 'different', $differences);
                    unset($remainingExcel[$excelIndex]);
                }
            }

            foreach ($remainingExcel as $row) {
                $summary['only_excel']++;
                $onlyExcel[$ledger][] = $row;
                $items[] = $this->comparisonItem($ledger, $row, null, 'only_excel', ['Baris belum ada di aplikasi.']);
            }

            foreach ($remainingApp as $row) {
                $summary['only_app']++;
                $items[] = $this->comparisonItem($ledger, null, $row, 'only_app', ['Baris tidak ditemukan di Excel.']);
            }
        }

        return compact('summary', 'items', 'onlyExcel') + [
            'opening' => $parsed['opening'] ?? [],
        ];
    }

    private function databaseRow(string $ledger, object $row): array
    {
        $values = (array) $row;
        $values['tanggal'] = (string) $row->tanggal;
        if ($ledger === 'stock') {
            $values['excel_stock_actual'] = (float) $row->stock_actual;
            $values['excel_stock_meteran'] = (float) $row->stock_meteran;
        } else {
            $values['excel_current_stock'] = (float) $row->current_stock;
        }

        return $values;
    }

    private function balanceDifferences(string $ledger, array $excel, array $app): array
    {
        $fields = $ledger === 'stock'
            ? ['excel_stock_actual', 'excel_stock_meteran']
            : ['excel_current_stock'];
        $fields = array_values(array_filter($fields, fn ($field) => ($excel[$field] ?? null) !== null));

        return $this->fieldDifferences($fields, $excel, $app);
    }

    private function allDifferences(string $ledger, array $excel, array $app): array
    {
        $fields = $ledger === 'stock'
            ? ['stock_in', 'out_actual', 'excel_stock_actual', 'out_meteran', 'excel_stock_meteran', 'keterangan']
            : ['qty_in', 'qty_out', 'excel_current_stock', 'keterangan', 'unit', 'operator', 'customer_id', 'nama_kapal', 'lokasi'];
        $fields = array_values(array_filter(
            $fields,
            fn ($field) => ! str_starts_with($field, 'excel_') || ($excel[$field] ?? null) !== null
        ));

        return $this->fieldDifferences($fields, $excel, $app);
    }

    private function compareOpeningBalance(
        int $businessId,
        string $ledger,
        ?array $excelOpening,
        array &$summary,
        array &$items
    ): void {
        if (! $excelOpening) {
            return;
        }

        $table = $this->table($ledger);
        $hasRows = DB::table($table)->where('business_id', $businessId)->exists();
        $storedOpening = $this->openingBalance($businessId, $ledger);
        if (! $hasRows && ! $storedOpening) {
            $summary['only_excel']++;
            $items[] = [
                'ledger' => $ledger,
                'row' => $excelOpening['source_row'] ?? null,
                'date' => $excelOpening['date'],
                'status' => 'only_excel',
                'differences' => ['Saldo awal belum ada di aplikasi dan akan dibuat saat import dikonfirmasi.'],
                'excel' => $excelOpening,
                'app' => null,
            ];

            return;
        }

        $previous = DB::table($table)
            ->where('business_id', $businessId)
            ->where('tanggal', '<', $excelOpening['date'])
            ->orderByDesc('tanggal')
            ->orderByDesc('id')
            ->first();
        $differences = [];
        if ($ledger === 'stock') {
            $appActual = (float) ($previous->stock_actual ?? $storedOpening->actual_balance ?? 0);
            $appMeter = (float) ($previous->stock_meteran ?? $storedOpening->meter_balance ?? 0);
            if (abs((float) $excelOpening['actual'] - $appActual) >= 0.0001) {
                $differences[] = "Saldo awal actual: Excel \"{$excelOpening['actual']}\", aplikasi \"{$appActual}\"";
            }
            if (abs((float) $excelOpening['meter'] - $appMeter) >= 0.0001) {
                $differences[] = "Saldo awal meteran: Excel \"{$excelOpening['meter']}\", aplikasi \"{$appMeter}\"";
            }
        } else {
            $appQuantity = (float) ($previous->current_stock ?? $storedOpening->quantity_balance ?? 0);
            if (abs((float) $excelOpening['quantity'] - $appQuantity) >= 0.0001) {
                $differences[] = "Saldo awal: Excel \"{$excelOpening['quantity']}\", aplikasi \"{$appQuantity}\"";
            }
        }

        $status = $differences === [] ? 'match' : 'different';
        $summary[$status]++;
        $items[] = [
            'ledger' => $ledger,
            'row' => $excelOpening['source_row'] ?? null,
            'date' => $excelOpening['date'],
            'status' => $status,
            'differences' => $differences,
            'excel' => $excelOpening,
            'app' => null,
        ];
    }

    private function fieldDifferences(array $fields, array $excel, array $app): array
    {
        $differences = [];
        foreach ($fields as $field) {
            $excelValue = $excel[$field] ?? null;
            $appValue = $app[$field] ?? null;
            $same = is_numeric($excelValue) && is_numeric($appValue)
                ? abs((float) $excelValue - (float) $appValue) < 0.0001
                : trim((string) $excelValue) === trim((string) $appValue);
            if (! $same) {
                $differences[] = sprintf('%s: Excel "%s", aplikasi "%s"', $field, $excelValue, $appValue);
            }
        }

        return $differences;
    }

    private function findSameDate(array $rows, string $date): ?int
    {
        foreach ($rows as $index => $row) {
            if ((string) ($row['tanggal'] ?? '') === $date) {
                return $index;
            }
        }

        return null;
    }

    private function comparisonItem(
        string $ledger,
        ?array $excel,
        ?array $app,
        string $status,
        array $differences
    ): array {
        return [
            'ledger' => $ledger,
            'row' => $excel['source_row'] ?? null,
            'date' => $excel['tanggal'] ?? $app['tanggal'] ?? null,
            'status' => $status,
            'differences' => $differences,
            'excel' => $excel,
            'app' => $app,
        ];
    }
}
