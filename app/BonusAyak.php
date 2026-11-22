<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class BonusAyak extends Model
{
    protected $table = 'bonus_ayak';

    protected $fillable = [
        'business_id', 'project_id', 'tanggal', 'nama_operator', 'driver_id', 'unit', 'asset_id', 'trip', 'bonus', 'created_by', 'source_fingerprint', 'user_import_batch_id'
    ];

    protected $casts = [
        'tanggal' => 'date',
    ];

    // Accessor tampilan rupiah cepat
    public function getBonusFormatAttribute(): string {
        return number_format($this->bonus, 0, ',', '.');
    }

    public function business()
    {
        return $this->belongsTo(Business::class);
    }

    public function driver()
    {
        return $this->belongsTo(User::class, 'driver_id');
    }

    public function asset()
    {
        return $this->belongsTo(\Modules\AssetManagement\Entities\Asset::class, 'asset_id');
    }

    public function project()
    {
        return $this->belongsTo(\Modules\Project\Entities\Project::class, 'project_id');
    }

    public function settlements()
    {
        return $this->belongsToMany(\Modules\AssetManagement\Entities\HeavyEquipmentDriverSettlement::class, 'heavy_equipment_settlement_bonus_ayak', 'bonus_ayak_id', 'settlement_id')
            ->withPivot('business_id')
            ->withTimestamps();
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function userImportBatch()
    {
        return $this->belongsTo(UserImportBatch::class);
    }
}
