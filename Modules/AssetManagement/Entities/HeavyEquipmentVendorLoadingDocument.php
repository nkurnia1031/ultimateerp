<?php

namespace Modules\AssetManagement\Entities;

use Illuminate\Database\Eloquent\Model;

class HeavyEquipmentVendorLoadingDocument extends Model
{
    protected $table = 'heavy_equipment_vendor_loading_documents';
    protected $guarded = ['id'];

    public function business()
    {
        return $this->belongsTo(\App\Business::class);
    }

    public function lines()
    {
        return $this->hasMany(HeavyEquipmentVendorLoading::class, 'loading_document_id')->orderBy('id');
    }

    public function supplier()
    {
        return $this->belongsTo(\App\Contact::class, 'supplier_id');
    }

    public function customer()
    {
        return $this->belongsTo(\App\Contact::class, 'customer_id');
    }

    public function route()
    {
        return $this->belongsTo(HeavyEquipmentRoute::class, 'route_id');
    }

    public function expenseTransaction()
    {
        return $this->belongsTo(\App\Transaction::class, 'expense_transaction_id');
    }

    public function creator()
    {
        return $this->belongsTo(\App\User::class, 'created_by');
    }

    public function importBatch()
    {
        return $this->belongsTo(HeavyEquipmentVendorLoadingImportBatch::class, 'import_batch_id');
    }

    public function getSupplierLabelAttribute(): string
    {
        return $this->supplier
            ? \App\Contact::supplierDisplayName($this->supplier, false)
            : (trim((string) $this->supplier_name_snapshot) ?: '-');
    }

    public function getCustomerLabelAttribute(): string
    {
        return $this->customer?->name ?: (trim((string) $this->customer_name_snapshot) ?: '-');
    }

    public function getRouteLabelAttribute(): string
    {
        return $this->route?->name ?: (trim((string) $this->route_name_snapshot) ?: '-');
    }

}
