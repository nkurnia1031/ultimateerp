<?php

namespace Modules\AssetManagement\Entities;

use Illuminate\Database\Eloquent\Model;

class HeavyEquipmentTrip extends Model
{
    protected $table = 'heavy_equipment_trips';
    protected $guarded = ['id'];
    protected $casts = [
        'trip_date' => 'date',
        'load_date' => 'date',
        'unload_date' => 'date',
        'completed_at' => 'datetime',
        'verified_at' => 'datetime',
        'gross_tons' => 'decimal:4',
        'tare_tons' => 'decimal:4',
        'received_tons' => 'decimal:4',
        'billable_tons' => 'decimal:4',
        'variance_kg' => 'decimal:4',
        'tolerance_kg' => 'decimal:4',
        'claim_rate_per_kg' => 'decimal:4',
        'base_revenue_total' => 'decimal:4',
        'claim_adjustment_total' => 'decimal:4',
        'other_deduction_total' => 'decimal:4',
        'final_revenue_total' => 'decimal:4',
    ];

    public function canBeChanged(): bool
    {
        if (in_array($this->status, ['verified', 'cancelled'], true)
            || ($this->invoice_status ?: 'unbilled') !== 'unbilled'
            || ($this->payroll_status ?: 'unsettled') !== 'unsettled') {
            return false;
        }

        $hasBilling = $this->relationLoaded('billing')
            ? $this->getRelation('billing') !== null
            : $this->billing()->exists();
        $hasSettlement = $this->relationLoaded('settlements')
            ? $this->getRelation('settlements')->isNotEmpty()
            : (array_key_exists('settlements_count', $this->attributes)
                ? (int) $this->settlements_count > 0
                : $this->settlements()->exists());

        return ! $hasBilling && ! $hasSettlement;
    }

    public function route()
    {
        return $this->belongsTo(HeavyEquipmentRoute::class, 'route_id');
    }

    public function asset()
    {
        return $this->belongsTo(Asset::class, 'asset_id');
    }

    public function driver()
    {
        return $this->belongsTo(\App\User::class, 'driver_id');
    }

    public function project()
    {
        return $this->belongsTo(\Modules\Project\Entities\Project::class, 'project_id');
    }

    public function customer()
    {
        return $this->belongsTo(\App\Contact::class, 'customer_id');
    }

    public function rate()
    {
        return $this->belongsTo(HeavyEquipmentRouteRate::class, 'route_rate_id');
    }

    public function billing()
    {
        return $this->hasOne(HeavyEquipmentTripBilling::class, 'trip_id');
    }

    public function settlements()
    {
        return $this->belongsToMany(HeavyEquipmentDriverSettlement::class, 'heavy_equipment_settlement_trips', 'trip_id', 'settlement_id')->withTimestamps();
    }

}
