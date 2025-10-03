<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Facades\Excel;
use App\Imports\SolarStockImport;

class SolarController extends Controller
{
    public function index(Request $request)
{
    $date1 = $request->get('date1', date('Y-m-01'));
    $date2 = $request->get('date2', date('Y-m-d'));

    $solar_stock = DB::table('solar_stock')
        ->whereBetween('tanggal', [$date1, $date2])
        ->orderBy('tanggal', 'ASC')
        ->get();

    $solar_pool = DB::table('solar_pool')
        ->whereBetween('tanggal', [$date1, $date2])
        ->orderBy('tanggal', 'ASC')
        ->get();

    $solar_mess = DB::table('solar_mess')
        ->whereBetween('tanggal', [$date1, $date2])
        ->orderBy('tanggal', 'ASC')
        ->get();

    // ✅ Ambil stok awal terakhir yang tersimpan
    $stok_awal = DB::table('solar_stock')
        ->select('stock_awal_actual', 'stock_awal_meteran')
        ->whereNotNull('stock_awal_actual')
        ->orWhereNotNull('stock_awal_meteran')
        ->orderBy('id', 'desc')
        ->first();

    $stok_awal_act = $stok_awal->stock_awal_actual ?? 0;
    $stok_awal_meteran = $stok_awal->stock_awal_meteran ?? 0;

    return view('solar.index', compact(
        'date1',
        'date2',
        'solar_stock',
        'solar_pool',
        'solar_mess',
        'stok_awal_act',
        'stok_awal_meteran'
    ));
}

public function saveStock(Request $request)
{
    // ✅ Ambil stok awal otomatis
    $stok_awal = DB::table('solar_stock')
        ->select('stock_awal_actual', 'stock_awal_meteran')
        ->whereNotNull('stock_awal_actual')
        ->orWhereNotNull('stock_awal_meteran')
        ->orderBy('id', 'desc')
        ->first();

    $previous_stock_actual = $stok_awal->stock_awal_actual ?? 0;
    $previous_stock_meteran = $stok_awal->stock_awal_meteran ?? 0;

    foreach ($request->tanggal as $i => $tgl) {
        $stock_in = (float) ($request->stock_in[$i] ?? 0);
        $out_actual = (float) ($request->out_actual[$i] ?? 0);
        $out_meteran = (float) ($request->out_meteran[$i] ?? 0);
        $keterangan = $request->keterangan[$i] ?? '';

        // ✅ Hitung otomatis berdasarkan stok awal terakhir
        $stock_actual = $previous_stock_actual + $stock_in - $out_actual;
        $stock_meteran = $previous_stock_meteran + $stock_in - $out_meteran;

        $data = [
            'tanggal' => $tgl,
            'stock_in' => $stock_in,
            'out_actual' => $out_actual,
            'stock_actual' => $stock_actual,
            'out_meteran' => $out_meteran,
            'stock_meteran' => $stock_meteran,
            'keterangan' => $keterangan,
            'updated_at' => now()
        ];

        if (!empty($request->id[$i])) {
            DB::table('solar_stock')->where('id', $request->id[$i])->update($data);
        } else {
            $data['created_at'] = now();
            DB::table('solar_stock')->insert($data);
        }

        // Simpan stok terakhir untuk baris berikutnya
        $previous_stock_actual = $stock_actual;
        $previous_stock_meteran = $stock_meteran;
    }

    return back()->with([
        'status' => ['alert' => 'success', 'msg' => 'Data Stock berhasil disimpan dengan stok awal otomatis!']
    ]);
}



    public function savePool(Request $request)
    {
        foreach ($request->tanggal as $i => $tgl) {
            $data = [
                'tanggal' => $tgl,
                'qty_in' => $request->qty_in[$i] ?? 0,
                'qty_out' => $request->qty_out[$i] ?? 0,
                'current_stock' => $request->current_stock[$i] ?? 0,
                'keterangan' => $request->keterangan[$i] ?? '',
                'unit' => $request->unit[$i] ?? '',
                'operator' => $request->operator[$i] ?? '',
                'updated_at' => now()
            ];
            if (!empty($request->id[$i])) {
                DB::table('solar_pool')->where('id', $request->id[$i])->update($data);
            } else {
                $data['created_at'] = now();
                DB::table('solar_pool')->insert($data);
            }
        }
        return back()->with('success', 'Data Pool berhasil disimpan');
    }

    public function saveMess(Request $request)
    {
        foreach ($request->tanggal as $i => $tgl) {
            $data = [
                'tanggal' => $tgl,
                'qty_in' => $request->qty_in[$i] ?? 0,
                'qty_out' => $request->qty_out[$i] ?? 0,
                'current_stock' => $request->current_stock[$i] ?? 0,
                'keterangan' => $request->keterangan[$i] ?? '',
                'unit' => $request->unit[$i] ?? '',
                'operator' => $request->operator[$i] ?? '',
                'updated_at' => now()
            ];
            if (!empty($request->id[$i])) {
                DB::table('solar_mess')->where('id', $request->id[$i])->update($data);
            } else {
                $data['created_at'] = now();
                DB::table('solar_mess')->insert($data);
            }
        }
        return back()->with('success', 'Data Mess berhasil disimpan');
    }

    public function deleteStock($id)
    {
        DB::table('solar_stock')->where('id', $id)->delete();
        return back()->with('success', 'Data Stock dihapus');
    }

    public function deletePool($id)
    {
        DB::table('solar_pool')->where('id', $id)->delete();
        return back()->with('success', 'Data Pool dihapus');
    }

    public function deleteMess($id)
    {
        DB::table('solar_mess')->where('id', $id)->delete();
        return back()->with('success', 'Data Mess dihapus');
    }

public function importStock(Request $request)
{
    $request->validate([
        'file' => 'required|mimes:xlsx,xls,csv'
    ]);

    try {
        Excel::import(new SolarStockImport, $request->file('file'));
        return back()->with([
            'status' => ['alert' => 'success', 'msg' => 'Data solar stock berhasil diimport!']
        ]);
    } catch (\Exception $e) {
        return back()->with([
            'status' => ['alert' => 'danger', 'msg' => 'Import gagal: ' . $e->getMessage()]
        ]);
    }
}

public function saveStockAwal(Request $request)
{
    $stock_awal_actual = (float) $request->input('stock_awal_actual');
    $stock_awal_meteran = (float) $request->input('stock_awal_meteran');

    // Cek apakah stok awal sudah pernah disimpan
    $existing = DB::table('solar_stock')
        ->whereNotNull('stock_awal_actual')
        ->orWhereNotNull('stock_awal_meteran')
        ->first();

    if (!$existing) {
        // Simpan hanya sekali
        DB::table('solar_stock')->insert([
            'tanggal' => now()->format('Y-m-d'),
            'stock_awal_actual' => $stock_awal_actual,
            'stock_awal_meteran' => $stock_awal_meteran,
            'keterangan' => 'Stok awal solar',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    } else {
        // Update nilai stok awal yang sudah ada
        DB::table('solar_stock')->where('id', $existing->id)->update([
            'stock_awal_actual' => $stock_awal_actual,
            'stock_awal_meteran' => $stock_awal_meteran,
            'updated_at' => now(),
        ]);
    }

    return redirect()->back()->with([
        'status' => ['alert' => 'success', 'msg' => 'Stok awal berhasil disimpan!']
    ]);
}



}
