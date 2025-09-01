<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class BonusAyak extends Model
{
    protected $table = 'bonus_ayak';

    protected $fillable = [
        'tanggal','nama_operator','unit','trip','bonus'
    ];

    protected $casts = [
        'tanggal' => 'date',
    ];

    // Accessor tampilan rupiah cepat
    public function getBonusFormatAttribute(): string {
        return number_format($this->bonus, 0, ',', '.');
    }
}
