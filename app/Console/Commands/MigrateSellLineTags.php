<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class MigrateSellLineTags extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'tags:migrate-sell-line-tags';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Backfill sell transaction tags into transaction sell line tags and remove legacy sell transaction tags.';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        if (! Schema::hasTable('transaction_sell_line_tags')) {
            $this->error('Table transaction_sell_line_tags belum ada. Jalankan migration dulu.');

            return self::FAILURE;
        }

        $legacy_sell_tag_count = (int) DB::table('transaction_tags as tt')
            ->join('transactions as t', 't.id', '=', 'tt.transaction_id')
            ->where('t.type', 'sell')
            ->count();

        $this->info("Legacy sell transaction_tags ditemukan: {$legacy_sell_tag_count}");

        DB::beginTransaction();

        try {
            $inserted_main_rows = DB::affectingStatement("
                INSERT IGNORE INTO transaction_sell_line_tags (transaction_sell_line_id, tag_id)
                SELECT DISTINCT tsl.id, tt.tag_id
                FROM transaction_tags tt
                INNER JOIN transactions t
                    ON t.id = tt.transaction_id
                    AND t.type = 'sell'
                INNER JOIN transaction_sell_lines tsl
                    ON tsl.transaction_id = t.id
                    AND tsl.parent_sell_line_id IS NULL
            ");

            $inserted_child_rows = DB::affectingStatement("
                INSERT IGNORE INTO transaction_sell_line_tags (transaction_sell_line_id, tag_id)
                SELECT DISTINCT child.id, tt.tag_id
                FROM transaction_tags tt
                INNER JOIN transactions t
                    ON t.id = tt.transaction_id
                    AND t.type = 'sell'
                INNER JOIN transaction_sell_lines parent
                    ON parent.transaction_id = t.id
                    AND parent.parent_sell_line_id IS NULL
                INNER JOIN transaction_sell_lines child
                    ON child.parent_sell_line_id = parent.id
            ");

            $deleted_legacy_rows = DB::affectingStatement("
                DELETE tt
                FROM transaction_tags tt
                INNER JOIN transactions t
                    ON t.id = tt.transaction_id
                WHERE t.type = 'sell'
            ");

            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $sell_line_tag_count = (int) DB::table('transaction_sell_line_tags as tslt')
            ->join('transaction_sell_lines as tsl', 'tsl.id', '=', 'tslt.transaction_sell_line_id')
            ->join('transactions as t', 't.id', '=', 'tsl.transaction_id')
            ->where('t.type', 'sell')
            ->count();

        $remaining_legacy_rows = (int) DB::table('transaction_tags as tt')
            ->join('transactions as t', 't.id', '=', 'tt.transaction_id')
            ->where('t.type', 'sell')
            ->count();

        $this->info("Inserted main line rows : {$inserted_main_rows}");
        $this->info("Inserted child line rows: {$inserted_child_rows}");
        $this->info("Deleted legacy rows    : {$deleted_legacy_rows}");
        $this->info("Sell line tag rows now : {$sell_line_tag_count}");
        $this->info("Remaining legacy rows  : {$remaining_legacy_rows}");

        return self::SUCCESS;
    }
}
