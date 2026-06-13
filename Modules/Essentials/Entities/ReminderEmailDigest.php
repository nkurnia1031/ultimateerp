<?php

namespace Modules\Essentials\Entities;

use Illuminate\Database\Eloquent\Model;

class ReminderEmailDigest extends Model
{
    /**
     * The attributes that aren't mass assignable.
     *
     * @var array
     */
    protected $guarded = ['id'];

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'reminder_email_digests';

    /**
     * The attributes that should be cast.
     *
     * @var array
     */
    protected $casts = [
        'send_date' => 'date',
        'sent_at' => 'datetime',
        'payload' => 'array',
    ];
}
