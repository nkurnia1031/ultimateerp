<?php

namespace App\Console\Commands;

use App\Services\SolarLedgerService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class BackfillSolarBusiness extends Command
{
    protected $signature = 'solar:backfill-legacy {business_id : ID business pemilik data Solar lama} {--dry-run : Tampilkan rencana tanpa mengubah data}';

    protected $description = 'Mengikat data Solar legacy ke business dan menginferensi saldo awal secara aman.';

    public function handle(SolarLedgerService $ledgerService): int
    {
        $businessId = (int) $this->argument('business_id');
        if ($businessId < 1 || ! DB::table('business')->where('id', $businessId)->exists()) {
            $this->error('Business ID tidak ditemukan.');

            return self::FAILURE;
        }

        if (! Schema::hasTable('solar_opening_balances')) {
            $this->error('Jalankan migration terlebih dahulu.');

            return self::FAILURE;
        }

        $counts = [];
        foreach (SolarLedgerService::LEDGERS as $ledger) {
            $counts[$ledger] = DB::table($ledgerService->table($ledger))->whereNull('business_id')->count();
        }

        $this->table(['Ledger', 'Baris legacy'], collect($counts)->map(fn ($count, $ledger) => [$ledger, $count])->values());
        if ($this->option('dry-run')) {
            $this->info('Dry-run selesai; tidak ada data yang diubah.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($businessId, $ledgerService, $counts) {
            foreach (SolarLedgerService::LEDGERS as $ledger) {
                if ($counts[$ledger] === 0) {
                    continue;
                }

                $table = $ledgerService->table($ledger);
                $first = DB::table($table)->whereNull('business_id')->orderBy('tanggal')->orderBy('id')->first();
                if ($first && ! $ledgerService->openingBalance($businessId, $ledger)) {
                    if ($ledger === 'stock') {
                        $values = [
                            'actual' => (float) $first->stock_actual - (float) $first->stock_in + (float) $first->out_actual,
                            'meter' => (float) $first->stock_meteran - (float) $first->stock_in + (float) $first->out_meteran,
                        ];
                    } else {
                        $values = [
                            'quantity' => (float) $first->current_stock - (float) $first->qty_in + (float) $first->qty_out,
                        ];
                    }
                    $ledgerService->setOpeningBalance($businessId, $ledger, $values, (string) $first->tanggal);
                }

                DB::table($table)->whereNull('business_id')->update([
                    'business_id' => $businessId,
                    'source' => 'legacy',
                    'updated_at' => now(),
                ]);
                $ledgerService->recalculate($businessId, $ledger);
            }
        });

        $this->info('Data Solar legacy berhasil diikat ke business '.$businessId.'.');

        return self::SUCCESS;
    }
}
