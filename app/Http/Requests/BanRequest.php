<?php


namespace App\Http\Requests;


use Illuminate\Foundation\Http\FormRequest;


class BanRequest extends FormRequest
{
public function authorize() { return true; }


public function rules()
{
$id = $this->route('ban') ? $this->route('ban')->id ?? null : null;


return [
'kode_ban' => 'required|string|max:100|unique:bans,kode_ban'.($id? ",$id" : ''),
'merk' => 'nullable|string|max:100',
'ukuran' => 'nullable|string|max:50',
'status' => 'required|in:baru,terpasang,rusak,dibuang',
'tanggal_masuk' => 'nullable|date',
'gambar' => 'nullable|image|max:2048',
'keterangan' => 'nullable|string',
];
}
}