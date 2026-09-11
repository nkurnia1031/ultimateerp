<?php

namespace Modules\AssetManagement\Entities;

use Illuminate\Database\Eloquent\Model;

class HeavyEquipmentRoute extends Model
{
    protected $table = 'heavy_equipment_routes';
    protected $guarded = ['id'];
    protected $casts = ['is_active' => 'boolean'];

    public function rates()
    {
        return $this->hasMany(HeavyEquipmentRouteRate::class, 'route_id');
    }

    public function trips()
    {
        return $this->hasMany(HeavyEquipmentTrip::class, 'route_id');
    }
}
