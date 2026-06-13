<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class Tag extends Model
{
    /**
     * The attributes that aren't mass assignable.
     *
     * @var array
     */
    protected $guarded = ['id'];
    public function transactions()
    {
        return $this->belongsToMany(Transaction::class, 'transaction_tags', 'tag_id', 'transaction_id');
    }

    public function sellLines()
    {
        return $this->belongsToMany(TransactionSellLine::class, 'transaction_sell_line_tags', 'tag_id', 'transaction_sell_line_id');
    }
}
