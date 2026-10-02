@extends('layouts.app')

@section('title', 'Laporan Harian Solar')

@section('css')
<style>
    .solar-report { max-width:900px; margin:20px auto; background:#f0ffff; border:1px solid #b7dfe1; padding:24px; }
    .solar-report h2 { margin:0 0 6px; padding:14px; text-align:center; background:#d9ead3; font-weight:700; }
    .solar-report .report-date { text-align:center; margin-bottom:20px; }
    .solar-report table { width:100%; border-collapse:collapse; background:#e7f5f5; }
    .solar-report th,.solar-report td { border:1px solid #b7dfe1; padding:16px; font-size:16px; }
    .solar-report th { width:65%; }
    .solar-report td { text-align:right; font-weight:700; font-size:21px; }
    .solar-report .report-actions { margin-bottom:15px; }
    @media print {
        .content-header,.main-header,.main-sidebar,.control-sidebar,.report-actions,.main-footer { display:none !important; }
        .content-wrapper,.wrapper { margin:0 !important; min-height:0 !important; background:#fff !important; }
        .content { padding:0 !important; }
        .solar-report { max-width:none; margin:0; border:0; padding:0; }
        .solar-report h2 { -webkit-print-color-adjust:exact; print-color-adjust:exact; }
        .solar-report table,.solar-report th,.solar-report td { -webkit-print-color-adjust:exact; print-color-adjust:exact; }
    }
</style>
@endsection

@section('content')
<section class="content-header"><h1>Laporan Harian Solar</h1></section>
<section class="content">
    <div class="solar-report">
        <div class="report-actions">
            <a href="{{ route('solar.index', ['report_date' => $reportDate]) }}" class="btn btn-default"><i class="fa fa-arrow-left"></i> Kembali</a>
            <button type="button" class="btn btn-primary pull-right" onclick="window.print()"><i class="fa fa-print"></i> Cetak</button>
        </div>
        <h2>LAPORAN MINYAK</h2>
        <div class="report-date">{{ \Carbon\Carbon::parse($reportDate)->locale('id')->translatedFormat('d/m/Y') }}</div>
        <table>
            <tbody>
                <tr><th>Stock aktual di tangki</th><td>{{ number_format($balances['stock']['actual'] ?? 0, 2, ',', '.') }} L</td></tr>
                <tr><th>Stock berdasarkan meteran</th><td>{{ number_format($balances['stock']['meter'] ?? 0, 2, ',', '.') }} L</td></tr>
                <tr><th>Standby di Mess Jatim</th><td>{{ number_format($balances['mess']['quantity'] ?? 0, 2, ',', '.') }} Gln</td></tr>
                <tr><th>Standby di Pool</th><td>{{ number_format($balances['pool']['quantity'] ?? 0, 2, ',', '.') }} Gln</td></tr>
            </tbody>
        </table>
    </div>
</section>
@endsection
