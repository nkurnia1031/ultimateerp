<?php

namespace Modules\AssetManagement\Entities;

use Illuminate\Database\Eloquent\Model;

class HeavyEquipmentVendorLoadingImportBatch extends Model
{
    protected $table = 'heavy_equipment_vendor_loading_import_batches';
    protected $guarded = ['id'];
}
