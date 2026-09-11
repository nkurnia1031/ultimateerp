<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class ChangeLogRead extends Model
{
    protected $table = 'change_log_reads';

    protected $guarded = ['id'];

    protected $casts = [
        'read_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function business()
    {
        return $this->belongsTo(Business::class);
    }
}
