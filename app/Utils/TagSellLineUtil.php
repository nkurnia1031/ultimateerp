<?php

namespace App\Utils;

use App\Transaction;
use App\TransactionSellLine;
use Illuminate\Support\Facades\DB;

class TagSellLineUtil extends Util
{
    /**
     * Sync sell line tags for a sell transaction.
     *
     * Main lines receive the explicit request tags. Child combo/modifier lines
     * automatically inherit their parent's tags.
     *
     * @param  \App\Transaction  $transaction
     * @param  array  $products
     * @return void
     */
    public function syncTransactionSellLineTags(Transaction $transaction, array $products)
    {
        if ($transaction->type !== 'sell') {
            return;
        }

        $all_line_ids = TransactionSellLine::where('transaction_id', $transaction->id)
            ->pluck('id')
            ->all();

        if (empty($all_line_ids)) {
            return;
        }

        DB::table('transaction_sell_line_tags')
            ->whereIn('transaction_sell_line_id', $all_line_ids)
            ->delete();

        $existing_main_line_ids = collect($products)
            ->pluck('transaction_sell_lines_id')
            ->filter()
            ->map(function ($id) {
                return (int) $id;
            })
            ->values()
            ->all();

        $new_products_count = collect($products)
            ->filter(function ($product) {
                return empty($product['transaction_sell_lines_id']);
            })
            ->count();

        $new_main_lines = TransactionSellLine::where('transaction_id', $transaction->id)
            ->whereNull('parent_sell_line_id')
            ->when(! empty($existing_main_line_ids), function ($query) use ($existing_main_line_ids) {
                $query->whereNotIn('id', $existing_main_line_ids);
            })
            ->orderBy('id')
            ->get(['id']);

        if ($new_main_lines->count() < $new_products_count) {
            throw new \RuntimeException('Unable to map new sell lines while syncing sell line tags.');
        }

        $mapped_main_line_ids = [];
        $new_line_index = 0;

        foreach ($products as $row_index => $product) {
            if (! empty($product['transaction_sell_lines_id'])) {
                $mapped_main_line_ids[$row_index] = (int) $product['transaction_sell_lines_id'];
            } else {
                $mapped_main_line_ids[$row_index] = (int) $new_main_lines[$new_line_index]->id;
                $new_line_index++;
            }
        }

        $child_lines = TransactionSellLine::whereIn('parent_sell_line_id', array_values($mapped_main_line_ids))
            ->get(['id', 'parent_sell_line_id'])
            ->groupBy('parent_sell_line_id');

        $insert_rows = [];

        foreach ($products as $row_index => $product) {
            $main_line_id = $mapped_main_line_ids[$row_index] ?? null;
            if (empty($main_line_id)) {
                continue;
            }

            $tag_ids = array_values(array_unique(array_filter(array_map('intval', (array) ($product['tag_ids'] ?? [])))));

            foreach ($tag_ids as $tag_id) {
                $insert_rows[] = [
                    'transaction_sell_line_id' => $main_line_id,
                    'tag_id' => $tag_id,
                ];
            }

            foreach ($child_lines->get($main_line_id, collect()) as $child_line) {
                foreach ($tag_ids as $tag_id) {
                    $insert_rows[] = [
                        'transaction_sell_line_id' => (int) $child_line->id,
                        'tag_id' => $tag_id,
                    ];
                }
            }
        }

        if (! empty($insert_rows)) {
            DB::table('transaction_sell_line_tags')->insert($insert_rows);
        }
    }

    /**
     * Attach selected tag metadata to editable sell lines.
     *
     * @param  \Illuminate\Support\Collection|array  $sell_lines
     * @return void
     */
    public function hydrateEditableSellLineTags($sell_lines)
    {
        foreach ($sell_lines as $sell_line) {
            $tags = ! empty($sell_line->tags) ? $sell_line->tags : collect();
            $sell_line->tag_ids = $tags->pluck('id')->all();
            $sell_line->tag_options = $tags->pluck('name', 'id')->toArray();
        }
    }

    /**
     * Get unique transaction tags derived from sell lines.
     *
     * @param  \App\Transaction|int  $transaction
     * @return \Illuminate\Support\Collection
     */
    public function getTransactionSellTags($transaction)
    {
        $transaction_id = is_object($transaction) ? $transaction->id : $transaction;

        return DB::table('transaction_sell_lines as tsl')
            ->join('transaction_sell_line_tags as tslt', 'tslt.transaction_sell_line_id', '=', 'tsl.id')
            ->join('tags', 'tags.id', '=', 'tslt.tag_id')
            ->where('tsl.transaction_id', $transaction_id)
            ->select('tags.id', 'tags.name')
            ->distinct()
            ->orderBy('tags.name')
            ->get();
    }
}
