<?php

namespace App\Utils;

use App\TagTransaction;

class TagTransactionUtil extends Util
{
    /**
     * Updates tax amount of a tax group
     *
     * @param  int  $group_tax_id
     * @return void
     */
    public function updateTagTransaction($transaction_id, $tag_ids)
    {
        TagTransaction::where('transaction_id', $transaction_id)->delete();
        if (!empty($tag_ids)) {
            if (!is_array($tag_ids)) {
                $tag_ids = [$tag_ids];
            }
            foreach ($tag_ids as $tag_id) {
                TagTransaction::create([
                    'transaction_id' => $transaction_id,
                    'tag_id' => $tag_id
                ]);
            }
        }
    }
}
