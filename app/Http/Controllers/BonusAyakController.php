<?php

namespace App\Http\Controllers;

use App\BonusAyak;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

use Maatwebsite\Excel\Facades\Excel;
use App\Exports\BonusAyakExport;
use App\Imports\TripImport;
use Barryvdh\DomPDF\Facade\Pdf;

class BonusAyakController extends Controller
{
   public function index(Request $request)
{
    // Handle import Excel
    if ($request->isMethod('post') && $request->import === 'excel' && $request->hasFile('file')) {
        $request->validate(['file' => 'required|file|mimes:xlsx,csv,xls']);
        Excel::import(new \App\Imports\TripImport, $request->file('file'));
        return back()->with('success', 'Import trip berhasil');
    }

    // Filter
    $from = $request->input('from');
    $to   = $request->input('to');
    $nama = $request->input('nama');

    $q = \App\BonusAyak::query();
    if ($from) $q->whereDate('tanggal', '>=', $from);
    if ($to)   $q->whereDate('tanggal', '<=', $to);
    if ($nama) $q->where('nama_operator', 'like', "%$nama%");
    if (!$from && !$to) {
        $q->whereMonth('tanggal', now()->month)
          ->whereYear('tanggal', now()->year);
    }

    $data = $q->orderBy('tanggal')->get();
    $totalBulanIni = $data->sum('bonus');

    // Handle export Excel
    if ($request->export === 'excel') {
        return Excel::download(new \App\Exports\BonusAyakExport($from, $to), 'bonus_ayak.xlsx');
    }

    // Handle export PDF
    if ($request->export === 'pdf') {
        $total = $totalBulanIni;
        return \Barryvdh\DomPDF\Facade\Pdf::loadView('bonus_ayak.pdf', compact('data','total'))
                  ->setPaper('a4','portrait')
                  ->download('rekap_bonus_ayak.pdf');
    }

    return view('bonus_ayak.index', compact('data','totalBulanIni','from','to','nama'));
}

    public function store(Request $request)
    {
        $validated = $request->validate([
            'tanggal'       => ['required','date'],
            'nama_operator' => ['required','string','max:100'],
            'unit'          => ['required','string','max:50'],
            'trip'          => ['required','integer','min:0'],
        ]);

        $bonus = $this->hitungBonus($validated['trip']);

        BonusAyak::create([
            'tanggal'       => $validated['tanggal'],
            'nama_operator' => $validated['nama_operator'],
            'unit'          => $validated['unit'],
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
        $from = $request->input('from');
        $to   = $request->input('to');

        $q = BonusAyak::query();
        if ($from) $q->whereDate('tanggal','>=',$from);
        if ($to)   $q->whereDate('tanggal','<=',$to);
        if (!$from && !$to) {
            $q->whereMonth('tanggal', now()->month)
              ->whereYear('tanggal', now()->year);
        }
        $data = $q->orderBy('tanggal')->get();
        $total = $data->sum('bonus');

        $pdf = Pdf::loadView('bonus_ayak.pdf', compact('data','total'))
                  ->setPaper('a4','portrait');

        return $pdf->download('rekap_bonus_ayak.pdf');
    }

    private function hitungBonus(int $trip): int
    {
        if ($trip < 50) return 0;
        return 50000 + max(0, $trip - 50) * 2000;
    }
}
