<?php

namespace Modules\Crm\Entities;

use App\Business;
use App\User;
use Illuminate\Database\Eloquent\Model;

class SuratKontrakTemplate extends Model
{
    protected $table = 'surat_kontrak_templates';

    protected $guarded = ['id'];

    protected $casts = [
        'components' => 'array',
        'is_active' => 'boolean',
        'is_default' => 'boolean',
    ];

    public function business()
    {
        return $this->belongsTo(Business::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function scopeForBusiness($query, $business_id)
    {
        return $query->where('business_id', $business_id);
    }
}
