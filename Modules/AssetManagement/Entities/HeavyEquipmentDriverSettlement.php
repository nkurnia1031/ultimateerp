<?php

namespace Modules\AssetManagement\Entities;

use Illuminate\Database\Eloquent\Model;

class HeavyEquipmentDriverSettlement extends Model
{
    protected $table = 'heavy_equipment_driver_settlements';
    protected $guarded = ['id'];
    protected $casts = ['period_start' => 'date', 'period_end' => 'date'];

    public function driver()
    {
        return $this->belongsTo(\App\User::class, 'driver_id');
    }

    public function trips()
    {
        return $this->belongsToMany(HeavyEquipmentTrip::class, 'heavy_equipment_settlement_trips', 'settlement_id', 'trip_id')->withTimestamps();
    }

    public function payrollTransaction()
    {
        return $this->belongsTo(\App\Transaction::class, 'payroll_transaction_id');
    }

    public function bonusAyaks()
    {
        return $this->belongsToMany(\App\BonusAyak::class, 'heavy_equipment_settlement_bonus_ayak', 'settlement_id', 'bonus_ayak_id')
            ->withPivot('business_id')
            ->withTimestamps();
    }

    public function kasbons()
    {
        return $this->belongsToMany(\App\BonusAyakKasbon::class, 'heavy_equipment_settlement_kasbons', 'settlement_id', 'kasbon_id')
            ->withPivot('business_id')
            ->withTimestamps();
    }
}
