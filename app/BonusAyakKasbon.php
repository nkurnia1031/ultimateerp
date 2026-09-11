<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class BonusAyakKasbon extends Model
{
    protected $table = 'bonus_ayak_kasbon';

    protected $guarded = ['id'];

    protected $casts = [
        'tanggal' => 'date',
        'jumlah_kasbon' => 'decimal:2',
    ];

    public function business()
    {
        return $this->belongsTo(Business::class);
    }

    public function driver()
    {
        return $this->belongsTo(User::class, 'driver_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function settlements()
    {
        return $this->belongsToMany(\Modules\AssetManagement\Entities\HeavyEquipmentDriverSettlement::class, 'heavy_equipment_settlement_kasbons', 'kasbon_id', 'settlement_id')
            ->withPivot('business_id')
            ->withTimestamps();
    }
}
