<?php

namespace App\Console\Commands;

use App\Services\SolarLedgerService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class BackfillSolarSourceKeys extends Command
{
    protected $signature = 'solar:backfill-source-keys {--business= : Batasi ke business ID tertentu}';

    protected $description = 'Membuat source key stabil untuk transaksi Solar legacy.';

    public function handle(SolarLedgerService $ledgerService): int
    {
        $business = $this->option('business');
        $updated = 0;
        $ambiguous = 0;

        foreach (SolarLedgerService::LEDGERS as $ledger) {
            $table = $ledgerService->table($ledger);
            $query = DB::table($table)->whereNull('source_key')->orderBy('business_id')->orderBy('source_row')->orderBy('id');
            if ($business !== null) {
                $query->where('business_id', (int) $business);
            }

            $occurrences = [];
            foreach ($query->cursor() as $row) {
                $values = (array) $row;
                $hash = $row->source_hash ?: $ledgerService->rowHash($ledger, $values);
                $sheet = mb_strtolower(trim((string) ($row->source_sheet ?: $ledger)));
                $scope = $row->business_id.'|'.$sheet.'|'.$hash;
                $occurrence = $occurrences[$scope] ?? 0;
                $occurrences[$scope] = $occurrence + 1;
                $sourceKey = hash('sha256', json_encode([
                    'ledger' => $ledger,
                    'sheet' => $sheet,
                    'hash' => $hash,
                    'occurrence' => $occurrence,
                ], JSON_UNESCAPED_UNICODE));

                $conflict = DB::table($table)
                    ->where('business_id', $row->business_id)
                    ->where('source_key', $sourceKey)
                    ->exists();
                if ($conflict) {
                    $ambiguous++;
                    $this->warn("{$ledger} #{$row->id}: source key sudah digunakan, dilewati.");
                    continue;
                }

                DB::table($table)->where('id', $row->id)->update([
                    'source_hash' => $hash,
                    'source_key' => $sourceKey,
                    'updated_at' => now(),
                ]);
                $updated++;
            }
        }

        $this->info("Source key dibuat: {$updated}; konflik/ambigu: {$ambiguous}.");

        return $ambiguous > 0 ? self::FAILURE : self::SUCCESS;
    }
}
