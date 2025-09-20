<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class TagTransaction extends Model
{
    /**
     * The attributes that aren't mass assignable.
     *
     * @var array
     */
    protected $guarded = ['id'];
    protected $table = 'transaction_tags';
    public $timestamps = false;

    protected $fillable = [
        'tag_id',
        'transaction_id',
    ];
}
