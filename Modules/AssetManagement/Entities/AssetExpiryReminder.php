<?php

namespace Modules\AssetManagement\Entities;

use App\Business;
use Illuminate\Database\Eloquent\Model;

class AssetExpiryReminder extends Model
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
        'expiry_date' => 'date',
        'email_start_date' => 'date',
        'daily_start_date' => 'date',
        'daily_end_date' => 'date',
        'last_email_sent_on' => 'date',
        'is_active' => 'boolean',
    ];

    public function asset()
    {
        return $this->belongsTo(Asset::class, 'asset_id');
    }

    public function business()
    {
        return $this->belongsTo(Business::class, 'business_id');
    }

    public function items()
    {
        return $this->hasMany(AssetExpiryReminderItem::class, 'asset_expiry_reminder_id');
    }
}
