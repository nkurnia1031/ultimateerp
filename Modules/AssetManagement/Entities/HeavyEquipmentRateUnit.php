<?php

namespace Modules\AssetManagement\Entities;

use Illuminate\Database\Eloquent\Model;

class HeavyEquipmentRateUnit extends Model
{
    protected $table = 'heavy_equipment_rate_units';
    protected $guarded = ['id'];

    public function rate()
    {
        return $this->belongsTo(HeavyEquipmentRouteRate::class, 'route_rate_id');
    }

    public function asset()
    {
        return $this->belongsTo(Asset::class, 'asset_id');
    }
}
