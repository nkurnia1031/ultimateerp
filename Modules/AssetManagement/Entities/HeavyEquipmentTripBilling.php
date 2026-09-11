<?php

namespace Modules\AssetManagement\Entities;

use Illuminate\Database\Eloquent\Model;

class HeavyEquipmentTripBilling extends Model
{
    protected $table = 'heavy_equipment_trip_billings';
    protected $guarded = ['id'];

    public function trip()
    {
        return $this->belongsTo(HeavyEquipmentTrip::class, 'trip_id');
    }

    public function transaction()
    {
        return $this->belongsTo(\App\Transaction::class, 'transaction_id');
    }
}
