<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class TransactionSellLineTag extends Model
{
    /**
     * The attributes that aren't mass assignable.
     *
     * @var array
     */
    protected $guarded = ['id'];

    protected $table = 'transaction_sell_line_tags';

    public $timestamps = false;

    protected $fillable = [
        'transaction_sell_line_id',
        'tag_id',
    ];
}
