<?php

namespace App\Http\Controllers;

use App\BonusAyak;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Facades\Excel;
use App\Exports\BonusAyakExport;
use App\Imports\TripImport;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon; 

class BonusAyakController extends Controller
{
    public function index(Request $request)
    {
        // Handle import Excel
        if ($request->isMethod('post') && $request->import === 'excel' && $request->hasFile('file')) {
            $request->validate(['file' => 'required|file|mimes:xlsx,csv,xls']);
            Excel::import(new TripImport, $request->file('file'));
            return back()->with('success', 'Import trip berhasil');
        }

        // Filter
        $from = $request->input('from');
        $to   = $request->input('to');
        $nama = $request->input('nama');

        $q = BonusAyak::query();

        if ($from) $q->whereDate('tanggal', '>=', $from);
        if ($to)   $q->whereDate('tanggal', '<=', $to);
        if ($nama) $q->where('nama_operator', 'like', "%$nama%");
        if (!$from && !$to) {
            $q->whereMonth('tanggal', now()->month)
              ->whereYear('tanggal', now()->year);
        }

        $data = $q->orderBy('tanggal')->get();
        $totalBulanIni = $data->sum('bonus');
        $total_trip  = $data->sum('trip');   // total semua trip
        $grand_total = $data->sum('bonus');  // total semua bonus

        // ✅ Ambil hanya user dengan role Driver / Operator
        $users = \App\User::whereHas('roles', function($q) {
            $q->where('name', 'like', 'Driver%')
              ->orWhere('name', 'like', 'Operator%');
        })->with('roles')->get();

        // ✅ Ambil unit langsung dari tabel assets
        $assets = DB::table('assets')->get();

        // Handle export Excel
        if ($request->export === 'excel') {
            return Excel::download(new BonusAyakExport($from, $to), 'bonus_ayak.xlsx');
        }

        return view('bonus_ayak.index', compact(
            'data',
            'totalBulanIni',
            'from',
            'to',
            'nama',
            'users',
            'assets', // 👈 kirim ke view
            'total_trip',    // tambahan
            'grand_total'    // tambahan
        ));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'tanggal'       => ['required','date'],
            'nama_operator' => ['required','string','max:100'],
            'unit'          => ['required','string','max:100'], // langsung string (name dari assets)
            'trip'          => ['required','integer','min:0'],
        ]);

        $bonus = $this->hitungBonus($validated['trip']);

        BonusAyak::create([
            'tanggal'       => $validated['tanggal'],
            'nama_operator' => $validated['nama_operator'],
            'unit'          => $validated['unit'], // langsung simpan nama unit
            'trip'          => $validated['trip'],
            'bonus'         => $bonus,
        ]);

        return back()->with('success','Data berhasil disimpan');
    }

    public function destroy(BonusAyak $bonusAyak)
    {
        $bonusAyak->delete();
        return back()->with('success','Data dihapus');
    }

    public function importExcel(Request $request)
    {
        $request->validate([
            'file' => ['required','file','mimes:xlsx,csv,xls']
        ]);
        Excel::import(new TripImport, $request->file('file'));
        return back()->with('success','Import trip berhasil');
    }

    public function exportExcel(Request $request)
    {
        $from = $request->input('from');
        $to   = $request->input('to');
        return Excel::download(new BonusAyakExport($from,$to), 'bonus_ayak.xlsx');
    }

public function exportPdf(Request $request)
{
    // FIX UTAMA ADA DI SINI ↓↓↓
    $from = $request->input('from')
         ?: $request->input('export_from')
         ?: $request->input('filter_from')
         ?: null;

    $to   = $request->input('to')
         ?: $request->input('export_to')
         ?: $request->input('filter_to')
         ?: null;
    // FIX UTAMA ADA DI ATAS ↑↑↑

    if (!$from && !$to) {
        $from = now()->startOfMonth()->toDateString();
        $to   = now()->endOfMonth()->toDateString();
    } elseif ($from && !$to) {
        $to = $from;
    } elseif (!$from && $to) {
        $from = $to;
    }

    $q = DB::table('bonus_ayak')
        ->select(
            'nama_operator',
            DB::raw('SUM(trip) as total_trip'),
            DB::raw('SUM(bonus) as total_bonus')
        )
        ->whereBetween('tanggal', [$from, $to]);

    $data = $q->groupBy('nama_operator')
              ->orderBy('nama_operator')
              ->get();

    $total_trip  = $data->sum('total_trip');
    $grand_total = $data->sum('total_bonus');

    $kasbonData = DB::table('bonus_ayak_kasbon')
        ->select('nama_operator', DB::raw('SUM(jumlah_kasbon) as total_kasbon'))
        ->whereBetween('tanggal', [$from, $to])
        ->groupBy('nama_operator')
        ->pluck('total_kasbon', 'nama_operator')
        ->toArray();

    $displayFrom = Carbon::parse($from)->format('d/m/Y');
    $displayTo   = Carbon::parse($to)->format('d/m/Y');

    $pdf = Pdf::loadView('bonus_ayak.pdf', [
        'data' => $data,
        'displayFrom' => $displayFrom,
        'displayTo' => $displayTo,
        'total_trip' => $total_trip,
        'grand_total' => $grand_total,
        'kasbonData' => $kasbonData,
        'from' => $from,
        'to' => $to
    ]);

    return $pdf->setPaper('a4','portrait')->download('rekap_bonus_ayak.pdf');
}


    private function hitungBonus(int $trip): int
    {
        if ($trip < 50) return 0;
        return 50000 + max(0, $trip - 50) * 2000;
    }
    
    public function edit(BonusAyak $bonusAyak)
{
    // ✅ Ambil hanya user dengan role Driver / Operator
    $users = \App\User::whereHas('roles', function($q) {
        $q->where('name', 'like', 'Driver%')
          ->orWhere('name', 'like', 'Operator%');
    })->with('roles')->get();

    // ✅ Ambil unit langsung dari tabel assets
    $assets = DB::table('assets')->get();

    return view('bonus_ayak.edit', [
        'bonus'  => $bonusAyak,
        'users'  => $users,
        'assets' => $assets,
    ]);
}

public function update(Request $request, BonusAyak $bonusAyak)
{
    $validated = $request->validate([
        'tanggal'       => ['required','date'],
        'nama_operator' => ['required','string','max:100'],
        'unit'          => ['required','string','max:100'],
        'trip'          => ['required','integer','min:0'],
    ]);

    $bonus = $this->hitungBonus($validated['trip']);

    $bonusAyak->update([
        'tanggal'       => $validated['tanggal'],
        'nama_operator' => $validated['nama_operator'],
        'unit'          => $validated['unit'],
        'trip'          => $validated['trip'],
        'bonus'         => $bonus,
    ]);

    return redirect()->route('bonus-ayak.index')->with('success', 'Data berhasil diupdate');
}

public function kasbonForm()
{
    $users = \App\User::all();
    $kasbon = \DB::table('bonus_ayak_kasbon')->orderBy('tanggal', 'desc')->get();

    return view('bonus_ayak.kasbon_form', compact('users', 'kasbon'));
}

public function kasbonStore(Request $request)
{
    $request->validate([
        'tanggal' => 'required|date',
        'nama_operator' => 'required|string',
        'jumlah_kasbon' => 'required|numeric|min:0',
    ]);

    \DB::table('bonus_ayak_kasbon')->insert([
        'tanggal' => $request->tanggal,
        'nama_operator' => $request->nama_operator,
        'jumlah_kasbon' => $request->jumlah_kasbon,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return redirect()->route('bonus-ayak.kasbon-form')->with('success', 'Kasbon berhasil disimpan!');
}

public function kasbonDelete($id)
{
    \DB::table('bonus_ayak_kasbon')->where('id', $id)->delete();
    return redirect()->route('bonus-ayak.kasbon-form')->with('success', 'Kasbon berhasil dihapus!');
}


}

