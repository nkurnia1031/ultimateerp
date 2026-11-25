<?php

namespace App\Http\Controllers;

use App\Http\Requests\BanRequest;
use App\Models\Ban;
use App\Models\BanPemasangan;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class BanController extends Controller
{
    public function index(Request $request)
    {
        $q = $request->query('q');

        $banBaru = Ban::when($q, fn($query) => $query->where('kode_ban','like',"%{$q}%")
                                                  ->orWhere('merk','like',"%{$q}%"))
            ->where('status','baru')
            ->orderBy('created_at','desc')
            ->get();

        $banTerpasang = Ban::when($q, fn($query) => $query->where('kode_ban','like',"%{$q}%")
                                                        ->orWhere('merk','like',"%{$q}%"))
            ->where('status','terpasang')
            ->orderBy('created_at','desc')
            ->get();

        $posisi_ban = ['Depan Kiri','Depan Kanan','Belakang Kiri','Belakang Kanan'];

        return view('ban.index', compact('banBaru','banTerpasang','posisi_ban'));
    }

public function store(Request $request)
{
    $validated = $request->validate([
        'kode_ban' => 'required',
        'merk' => 'required',
        'ukuran' => 'required',
    ]);

    $ban = new Ban();
    $ban->kode_ban = $validated['kode_ban'];
    $ban->merk = $validated['merk'];
    $ban->ukuran = $validated['ukuran'];
    $ban->save();

    return redirect()->route('ban.index')->with('success', 'Ban berhasil disimpan.');
}


    public function destroy(Ban $ban)
    {
        if ($ban->gambar) Storage::disk('public')->delete($ban->gambar);
        $ban->delete();

        return redirect()->route('ban.index')->with('success','Ban berhasil dihapus.');
    }

    public function storePemasangan(Request $request)
    {
        $data = $request->validate([
            'ban_id_baru' => 'required|exists:bans,id',
            'ban_id_lama' => 'nullable|exists:bans,id',
            'vehicle_number' => 'nullable|string|max:50',
            'posisi_ban' => 'nullable|string|max:50',
            'tanggal_pasang' => 'nullable|date',
            'tanggal_cabut' => 'nullable|date',
            'km_awal' => 'nullable|integer',
            'km_akhir' => 'nullable|integer',
            'kondisi_cabut' => 'nullable|string|max:50',
            'foto_ban' => 'nullable|image|max:15000',
            'keterangan' => 'nullable|string'
        ]);

        if ($request->hasFile('foto_ban')) {
            $data['foto_ban'] = $request->file('foto_ban')->store('ban_images','public');
        }

        BanPemasangan::create($data);

        // Update status ban baru jadi terpasang
        $ban = Ban::find($data['ban_id_baru']);
        $ban->status = 'terpasang';
        $ban->save();

        return redirect()->route('ban.index')->with('success','Pemasangan ban berhasil disimpan.');
    }
}
