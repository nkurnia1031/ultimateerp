<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Facades\Excel;
use App\Imports\SolarStockImport;
use App\Imports\SolarPoolImport;
use App\Imports\SolarMessImport;
use DateTime;
use DatePeriod;
use DateInterval;

class SolarController extends Controller
{
public function index(Request $request)
{
    $date1 = new DateTime($request->get('date1', date('Y-m-01')));
    $date2 = new DateTime($request->get('date2', date('Y-m-d')));

    // 🔹 Buat daftar bulan dari range tanggal
    $months = [];
    $current = clone $date1;
    while ($current <= $date2) {
        $months[] = $current->format('Y-m');
        $current->modify('+1 month');
    }

    // 🔹 Tentukan halaman sekarang (default halaman 1)
    $page = (int) $request->get('page', 1);
    $currentMonth = $months[$page - 1] ?? $months[0];

    // 🔹 Tentukan awal dan akhir bulan aktif
    $monthStart = $currentMonth . '-01';
    $monthEnd   = date('Y-m-t', strtotime($monthStart)); // tanggal terakhir bulan itu

    // 🔹 Stok awal Stock (tetap seperti sebelumnya)
    $stok_awal_row = DB::table('solar_stock')
        ->where('tanggal', '<', $date1->format('Y-m-d'))
        ->orderBy('tanggal', 'desc')
        ->orderBy('id', 'desc')
        ->first();
    $stok_awal_actual  = $stok_awal_row->stock_actual  ?? 0;
    $stok_awal_meteran = $stok_awal_row->stock_meteran ?? 0;

    // 🔹 Ambil data Stock sesuai range
    $solar_stock = DB::table('solar_stock')
        ->whereBetween('tanggal', [$date1->format('Y-m-d'), $date2->format('Y-m-d')])
        ->orderBy('tanggal', 'ASC')
        ->get();

   $lastDatePrevMonth = date('Y-m-t', strtotime('-1 month', strtotime($monthStart)));

$stok_awal_pool_row = DB::table('solar_pool')
    ->where('tanggal', '<=', $lastDatePrevMonth)
    ->orderBy('tanggal', 'desc')
    ->orderBy('id', 'desc')
    ->first();

$stok_awal_pool = $stok_awal_pool_row->current_stock ?? 0;
    // 🔹 Ambil data Pool bulan ini (per halaman)
    $solar_pool = DB::table('solar_pool')
        ->whereBetween('tanggal', [$monthStart, $monthEnd])
        ->orderBy('tanggal', 'ASC')
        ->get();

$stok_awal_mess_row = DB::table('solar_mess')
    ->where('tanggal', '<=', $lastDatePrevMonth)
    ->orderBy('tanggal', 'desc')
    ->orderBy('id', 'desc')
    ->first();

$stok_awal_mess = $stok_awal_mess_row->current_stock ?? 0;

    // 🔹 Ambil data Mess bulan ini (per halaman)
    $solar_mess = DB::table('solar_mess')
        ->whereBetween('tanggal', [$monthStart, $monthEnd])
        ->orderBy('tanggal', 'ASC')
        ->get();

    // 🔹 Customer
    $customers = DB::table('contacts')
        ->where('type', 'customer')
        ->select('id', DB::raw('COALESCE(supplier_business_name, name) as display_name'))
        ->orderBy('display_name', 'asc')
        ->get();

    // 🔹 Hitung total bulan untuk pagination
    $totalPages = count($months);

    return view('solar.index', compact(
        'date1',
        'date2',
        'solar_stock',
        'solar_pool',
        'solar_mess',
        'stok_awal_actual',
        'stok_awal_meteran',
        'stok_awal_pool',
        'stok_awal_mess',
        'customers',
        'page',
        'totalPages',
        'currentMonth'
    ));
}


    public function saveStock(Request $request)
    {
        foreach ($request->tanggal as $i => $tgl) {
            $data = [
                'tanggal' => $tgl,
                'stock_in' => $request->stock_in[$i] ?? 0,
                'out_actual' => $request->out_actual[$i] ?? 0,
                'stock_actual' => $request->stock_actual[$i] ?? 0,
                'out_meteran' => $request->out_meteran[$i] ?? 0,
                'stock_meteran' => $request->stock_meteran[$i] ?? 0,
                'keterangan' => $request->keterangan[$i] ?? '',
                'updated_at' => now()
            ];
            if (!empty($request->id[$i])) {
                DB::table('solar_stock')->where('id', $request->id[$i])->update($data);
            } else {
                $data['created_at'] = now();
                DB::table('solar_stock')->insert($data);
            }
        }
        return back()->with('success', 'Data Stock berhasil disimpan');
    }

   public function savePool(Request $request)
{
    foreach ($request->tanggal as $i => $tgl) {
        $data = [
            'tanggal'       => $tgl,
            'qty_in'        => $request->qty_in[$i] ?? 0,
            'qty_out'       => $request->qty_out[$i] ?? 0,
            'current_stock' => $request->current_stock[$i] ?? 0,
            'keterangan'    => $request->keterangan[$i] ?? '',
            'unit'          => $request->unit[$i] ?? '',
            'operator'      => $request->operator[$i] ?? '',
            'customer_id'   => $request->customer_id[$i] ?? null, // ✅ ambil nilainya, bukan string
            'updated_at'    => now(),
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
            'tanggal'       => $tgl,
            'qty_in'        => $request->qty_in[$i] ?? 0,
            'qty_out'       => $request->qty_out[$i] ?? 0,
            'current_stock' => $request->current_stock[$i] ?? 0,
            'keterangan'    => $request->keterangan[$i] ?? '',
            'unit'          => $request->unit[$i] ?? '',
            'operator'      => $request->operator[$i] ?? '',
            'customer_id'   => $request->customer_id[$i] ?? null, // ✅ ambil nilai asli
            'lokasi'        => $request->lokasi[$i] ?? null,       // ✅ ambil nilai asli
            'updated_at'    => now(),
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

public function importPool(Request $request)
{
    $request->validate([
        'file' => 'required|mimes:xlsx,xls,csv'
    ]);

    try {
        Excel::import(new SolarPoolImport, $request->file('file'));
        return back()->with([
            'status' => ['alert' => 'success', 'msg' => 'Data Solar Pool berhasil diimport!']
        ]);
    } catch (\Exception $e) {
        return back()->with([
            'status' => ['alert' => 'danger', 'msg' => 'Import Solar Pool gagal: ' . $e->getMessage()]
        ]);
    }
}

// =============================
// Import untuk Solar Mess
// =============================
public function importMess(Request $request)
{
    $request->validate([
        'file' => 'required|mimes:xlsx,xls,csv'
    ]);

    try {
        Excel::import(new SolarMessImport, $request->file('file'));
        return back()->with([
            'status' => ['alert' => 'success', 'msg' => 'Data Solar Mess berhasil diimport!']
        ]);
    } catch (\Exception $e) {
        return back()->with([
            'status' => ['alert' => 'danger', 'msg' => 'Import Solar Mess gagal: ' . $e->getMessage()]
        ]);
    }
}

}

