<?php

namespace Modules\Accounting\Entities;

use Illuminate\Database\Eloquent\Model;

class AccountingAccTransMapping extends Model
{
    protected $fillable = [];

    public function source_record()
    {
        return $this->morphTo(__FUNCTION__, 'source_record_type', 'source_record_id');
    }
}
