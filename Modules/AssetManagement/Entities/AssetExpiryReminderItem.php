<?php

namespace Modules\AssetManagement\Entities;

use Illuminate\Database\Eloquent\Model;
use Modules\Essentials\Entities\Reminder;

class AssetExpiryReminderItem extends Model
{
    /**
     * The attributes that aren't mass assignable.
     *
     * @var array
     */
    protected $guarded = ['id'];

    /**
     * The attributes that should be cast.
     *
     * @var array
     */
    protected $casts = [
        'reminder_date' => 'date',
    ];

    public function expiryReminder()
    {
        return $this->belongsTo(AssetExpiryReminder::class, 'asset_expiry_reminder_id');
    }

    public function reminder()
    {
        return $this->belongsTo(Reminder::class, 'reminder_id');
    }
}
