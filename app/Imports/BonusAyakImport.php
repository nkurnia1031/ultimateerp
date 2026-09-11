<?php

namespace App\Imports;

use App\BonusAyak;
use App\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Modules\AssetManagement\Entities\Asset;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

class BonusAyakImport implements ToCollection, WithHeadingRow
{
    public function __construct(private int $businessId, private int $userId)
    {
    }

    public function collection(Collection $rows): void
    {
        $result = $this->parse($rows);

        if ($result['errors'] !== []) {
            throw new \InvalidArgumentException(implode(' ', $result['errors']));
        }

        foreach ($result['entries'] as $entry) {
            unset($entry['source_row']);
            BonusAyak::create($entry);
        }
    }

    /**
     * Parse workbook rows without writing anything. The preview flow uses this
     * method, while collection() keeps the legacy direct-import contract.
     */
    public function parse(Collection $rows): array
    {
        $errors = [];
        $entries = [];

        foreach ($rows as $index => $row) {
            $line = $index + 2;
            $operator = trim((string) $this->first($row, ['nama_operator', 'operator', 'sopir', 'driver']));
            $unit = trim((string) $this->first($row, ['unit', 'alat', 'kendaraan']));
            $trip = $this->number($this->first($row, ['trip', 'jumlah_trip', 'jumlah']));
            $date = $this->date($this->first($row, ['tanggal', 'date']));

            if ($date === null || $operator === '' || $unit === '' || $trip === null || $trip < 0) {
                $errors[] = "Baris {$line}: tanggal, operator, unit, dan trip wajib valid.";
                continue;
            }

            $driver = $this->findDriver($operator);
            if (! $driver) {
                $errors[] = "Baris {$line}: operator '{$operator}' belum cocok secara unik dengan Users pada business aktif.";
                continue;
            }

            $asset = $this->findAsset($unit);
            if (! $asset) {
                $errors[] = "Baris {$line}: unit '{$unit}' belum cocok secara unik dengan Assets pada business aktif.";
                continue;
            }

            $trip = (int) $trip;
            $entries[] = [
                'source_row' => $line,
                'business_id' => $this->businessId,
                'tanggal' => $date,
                'nama_operator' => $driver->user_full_name,
                'driver_id' => $driver->id,
                'unit' => $asset->name,
                'asset_id' => $asset->id,
                'trip' => $trip,
                'bonus' => $this->bonus($trip),
                'created_by' => $this->userId,
                'source_fingerprint' => $this->fingerprint($date, $driver->id, $asset->id, $trip),
            ];
        }

        return ['entries' => $entries, 'errors' => $errors];
    }

    private function findDriver(string $label): ?User
    {
        $needle = $this->normalize($label);
        if ($needle === '') {
            return null;
        }

        $matches = User::where('business_id', $this->businessId)->where('user_type', 'user')->get()->filter(function (User $user) use ($needle) {
            return $this->personMatches($needle, $user->user_full_name)
                || $this->personMatches($needle, $user->username);
        });

        return $matches->count() === 1 ? $matches->first() : null;
    }

    private function findAsset(string $label): ?Asset
    {
        $needle = strtolower($label);

        $matches = Asset::where('business_id', $this->businessId)
            ->where(function ($query) use ($needle) {
                $query->whereRaw('LOWER(name) = ?', [$needle])
                    ->orWhereRaw('LOWER(asset_code) = ?', [$needle]);
            })->get();

        return $matches->count() === 1 ? $matches->first() : null;
    }

    private function first(Collection|array $row, array $keys)
    {
        foreach ($keys as $key) {
            $value = $row instanceof Collection ? $row->get($key) : ($row[$key] ?? null);
            if ($value !== null && $value !== '') {
                return $value;
            }
        }

        return null;
    }

    private function date($value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            if (is_numeric($value)) {
                return ExcelDate::excelToDateTimeObject((float) $value)->format('Y-m-d');
            }

            return Carbon::parse($value)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }

    private function number($value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_numeric($value)) {
            return (float) $value;
        }

        $cleaned = str_replace(',', '.', preg_replace('/[^0-9,.-]/', '', (string) $value));

        return $cleaned !== '' && is_numeric($cleaned) ? (float) $cleaned : null;
    }

    private function bonus(int $trip): int
    {
        return $trip < 50 ? 0 : 50000 + max(0, $trip - 50) * 2000;
    }

    private function normalize(?string $value): string
    {
        return strtolower(trim((string) preg_replace('/\s+/', ' ', $value ?? '')));
    }

    private function personMatches(string $needle, ?string $candidate): bool
    {
        $needle = $this->normalize(preg_replace('/^(mr|mrs|miss|ms)\s+/i', '', $needle));
        $candidate = $this->normalize(preg_replace('/^(mr|mrs|miss|ms)\s+/i', '', (string) $candidate));
        if ($needle === '' || $candidate === '') {
            return false;
        }
        if ($needle === $candidate) {
            return true;
        }

        $needleTokens = array_filter(explode(' ', $needle));
        $candidateTokens = array_filter(explode(' ', $candidate));

        return count($needleTokens) > 0 && count(array_intersect($needleTokens, $candidateTokens)) === count($needleTokens);
    }

    private function fingerprint(string $date, int $driverId, int $assetId, int $trip): string
    {
        return hash('sha256', implode('|', [$this->businessId, $date, $driverId, $assetId, $trip]));
    }
}
