<?php


namespace App\Models;


use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;


class Ban extends Model
{
use HasFactory;


protected $table = 'bans';


protected $fillable = [
'kode_ban','merk','ukuran','lokasi','status','tanggal_masuk','gambar','keterangan'
];


protected $casts = [
'tanggal_masuk' => 'date',
];


public function riwayat()
{
return $this->hasMany(BanRiwayat::class);
}
}