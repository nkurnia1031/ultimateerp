<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BanPemasangan extends Model
{
    protected $table = 'ban_pemasangan';
    protected $fillable = [
        'ban_id_baru','ban_id_lama','vehicle_number','posisi_ban',
        'tanggal_pasang','tanggal_cabut','km_awal','km_akhir',
        'kondisi_cabut','foto_ban','keterangan'
    ];

    public function banBaru()
    {
        return $this->belongsTo(Ban::class, 'ban_id_baru');
    }

    public function banLama()
    {
        return $this->belongsTo(Ban::class, 'ban_id_lama');
    }
}
