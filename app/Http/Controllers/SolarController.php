<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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

        return view('solar.index', compact('date1', 'date2', 'solar_stock', 'solar_pool', 'solar_mess'));
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
}
