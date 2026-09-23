<?php

namespace Modules\AssetManagement\Entities;

use Illuminate\Database\Eloquent\Model;

class HeavyEquipmentVendorLoading extends Model
{
    protected $table = 'heavy_equipment_vendor_loadings';
    protected $guarded = ['id'];

    protected $casts = [
        'loading_date' => 'date',
        'load_date' => 'date',
        'unload_date' => 'date',
        'verified_at' => 'datetime',
        'gross_tons' => 'decimal:4',
        'tare_tons' => 'decimal:4',
        'net_tons' => 'decimal:4',
        'received_tons' => 'decimal:4',
        'billable_tons' => 'decimal:4',
        'vendor_rate_per_ton' => 'decimal:4',
        'vendor_base_amount' => 'decimal:4',
        'claim_adjustment' => 'decimal:4',
        'other_deduction' => 'decimal:4',
        'vendor_total_amount' => 'decimal:4',
    ];

    public function business()
    {
        return $this->belongsTo(\App\Business::class);
    }

    public function supplier()
    {
        return $this->belongsTo(\App\Contact::class, 'supplier_id');
    }

    public function route()
    {
        return $this->belongsTo(HeavyEquipmentRoute::class, 'route_id');
    }

    public function project()
    {
        return $this->belongsTo(\Modules\Project\Entities\Project::class, 'project_id');
    }

    public function customer()
    {
        return $this->belongsTo(\App\Contact::class, 'customer_id');
    }

    public function expenseTransaction()
    {
        return $this->belongsTo(\App\Transaction::class, 'expense_transaction_id');
    }

    public function creator()
    {
        return $this->belongsTo(\App\User::class, 'created_by');
    }

    public function verifier()
    {
        return $this->belongsTo(\App\User::class, 'verified_by');
    }

    public function getSupplierLabelAttribute(): string
    {
        return $this->supplier
            ? \App\Contact::supplierDisplayName($this->supplier, false)
            : (trim((string) $this->supplier_name_snapshot) ?: '-');
    }

    public function getRouteLabelAttribute(): string
    {
        return $this->route?->name ?: (trim((string) $this->route_name_snapshot) ?: '-');
    }
}
