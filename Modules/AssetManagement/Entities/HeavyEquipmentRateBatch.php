<?php

namespace Modules\AssetManagement\Entities;

use Illuminate\Database\Eloquent\Model;

class HeavyEquipmentRateBatch extends Model
{
    protected $table = 'heavy_equipment_rate_batches';
    protected $guarded = ['id'];
    protected $casts = ['effective_from' => 'date', 'effective_to' => 'date', 'imported_at' => 'datetime'];

    public function importHistory()
    {
        return $this->belongsTo(\App\UserImportBatch::class, 'user_import_batch_id');
    }

    public function rates()
    {
        return $this->hasMany(HeavyEquipmentRouteRate::class, 'batch_id');
    }

    public function uploader()
    {
        return $this->belongsTo(\App\User::class, 'uploaded_by');
    }
}
