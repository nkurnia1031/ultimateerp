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

    public function loadingDocument()
    {
        return $this->belongsTo(HeavyEquipmentVendorLoadingDocument::class, 'loading_document_id');
    }

    public function project()
    {
        return $this->belongsTo(\Modules\Project\Entities\Project::class, 'project_id');
    }

    public function creator()
    {
        return $this->belongsTo(\App\User::class, 'created_by');
    }

    public function getBusinessAttribute()
    {
        return $this->loadingDocument?->business;
    }

    public function getSupplierAttribute()
    {
        return $this->loadingDocument?->supplier;
    }

    public function getRouteAttribute()
    {
        return $this->loadingDocument?->route;
    }

    public function getCustomerAttribute()
    {
        return $this->loadingDocument?->customer;
    }

    public function getExpenseTransactionAttribute()
    {
        return $this->loadingDocument?->expenseTransaction;
    }

    public function getSupplierIdAttribute()
    {
        return $this->loadingDocument?->supplier_id;
    }

    public function getSupplierNameSnapshotAttribute()
    {
        return $this->loadingDocument?->supplier_name_snapshot;
    }

    public function getCustomerIdAttribute()
    {
        return $this->loadingDocument?->customer_id;
    }

    public function getGoodsNameAttribute()
    {
        return $this->loadingDocument?->goods_name;
    }

    public function getGoodsTypeAttribute()
    {
        return $this->loadingDocument?->goods_type;
    }

    public function getVesselNameAttribute()
    {
        return $this->loadingDocument?->vessel_name;
    }

    public function getDestinationAttribute()
    {
        return $this->loadingDocument?->destination;
    }

    public function getRouteIdAttribute()
    {
        return $this->loadingDocument?->route_id;
    }

    public function getRouteNameSnapshotAttribute()
    {
        return $this->loadingDocument?->route_name_snapshot;
    }

    public function getExpenseStatusAttribute()
    {
        return $this->loadingDocument?->expense_status ?: 'not_linked';
    }

    public function getExpenseTransactionIdAttribute()
    {
        return $this->loadingDocument?->expense_transaction_id;
    }

    public function getSourceWorkbookAttribute()
    {
        return $this->loadingDocument?->source_workbook;
    }

    public function getSourceSheetAttribute()
    {
        return $this->loadingDocument?->source_sheet;
    }

    public function getSupplierLabelAttribute(): string
    {
        return $this->loadingDocument?->supplier_label ?: '-';
    }

    public function getRouteLabelAttribute(): string
    {
        return $this->loadingDocument?->route_label ?: '-';
    }
}
