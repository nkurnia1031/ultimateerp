<?php

namespace Modules\AssetManagement\Entities;

use Illuminate\Database\Eloquent\Model;

class HeavyEquipmentRouteRate extends Model
{
    protected $table = 'heavy_equipment_route_rates';
    protected $guarded = ['id'];
    protected $casts = [
        'effective_from' => 'date',
        'effective_to' => 'date',
        'source_modified_at' => 'datetime',
    ];

    public function route()
    {
        return $this->belongsTo(HeavyEquipmentRoute::class, 'route_id');
    }

    public function batch()
    {
        return $this->belongsTo(HeavyEquipmentRateBatch::class, 'batch_id');
    }

    public function units()
    {
        return $this->hasMany(HeavyEquipmentRateUnit::class, 'route_rate_id');
    }

    public function trips()
    {
        return $this->hasMany(HeavyEquipmentTrip::class, 'route_rate_id');
    }

    public function sourceModifier()
    {
        return $this->belongsTo(\App\User::class, 'source_modified_by');
    }
}
