<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Rekap Loading Vendor</title>
    <style>
        * { box-sizing: border-box; }
        body { margin: 18px; color: #17212b; font: 11px Arial, Helvetica, sans-serif; }
        .toolbar { margin: 0 0 16px; text-align: right; }
        .toolbar button { border: 1px solid #526b79; border-radius: 3px; background: #234a64; color: #fff; padding: 8px 14px; cursor: pointer; }
        .surat-kontrak-letterhead { display: grid; grid-template-columns: minmax(72px, 1fr) minmax(0, 2.8fr) minmax(72px, 1fr); grid-auto-rows: auto; column-gap: 8px; align-items: stretch; margin-bottom: 10px; }
        .surat-kontrak-letterhead .skt-header-component { box-sizing: border-box; padding: 0 6px; }
        .surat-kontrak-letterhead .skt-header-text { line-height: 1.15; white-space: nowrap; }
        .surat-kontrak-letterhead .skt-header-logo { display: flex; align-items: center; }
        .surat-kontrak-letterhead img { max-width: 100%; object-fit: contain; }
        .surat-kontrak-letterhead .skt-rich-text ol, .surat-kontrak-letterhead .skt-rich-text ul { padding-left: 24px; }
        .surat-kontrak-letterhead .skt-rich-text p { margin: 0 0 8px; }
        .surat-kontrak-letterhead .skt-header-component hr { border-top-width: 2px !important; }
        .document-section { page-break-after: always; margin-bottom: 28px; }
        .document-section:last-of-type { page-break-after: auto; }
        .report-title { margin: 7px 0 3px; text-align: center; font-size: 17px; text-transform: uppercase; }
        .report-subtitle { margin: 0 0 12px; text-align: center; }
        .document-meta { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 5px 15px; border: 1px solid #afbdc4; background: #f4f7f8; padding: 8px 10px; margin-bottom: 9px; }
        .document-meta strong { color: #234a64; }
        .filters { margin: 0 0 12px; color: #46575f; }
        table { width: 100%; border-collapse: collapse; }
        .report-table { table-layout: fixed; font-size: 8.5px; }
        th, td { border: 1px solid #71838b; padding: 4px; vertical-align: middle; overflow-wrap: anywhere; }
        .report-table thead th { text-align: center; background: #dce7ec; }
        .report-table .subtotal-row th, .report-table .subtotal-row td { background: #f4f7f8; font-weight: bold; }
        .report-table tfoot th, .report-table tfoot td { background: #dce7ec; font-weight: bold; }
        .numeric { text-align: right; white-space: nowrap; }
        .center { text-align: center; }
        .signature-area { display: grid; grid-template-columns: repeat(3, 1fr); gap: 35px; margin: 20px 0 0; page-break-inside: avoid; }
        .signature { min-height: 80px; text-align: center; }
        .signature .line { margin-top: 56px; border-top: 1px solid #26343b; padding-top: 4px; }
        .total-overall { margin: 18px 0; page-break-inside: avoid; }
        .printed-at { margin-top: 12px; text-align: right; font-size: 9px; }
        @page { size: A3 landscape; margin: 9mm; }
        @media print { body { margin: 0; } .toolbar { display: none !important; } .report-table thead { display: table-header-group; } tr { page-break-inside: avoid; } .surat-kontrak-letterhead { grid-template-columns: minmax(72px, 1fr) minmax(0, 2.8fr) minmax(72px, 1fr); grid-auto-rows: auto; column-gap: 8px; } .surat-kontrak-letterhead .skt-header-component { padding: 0 6px; } }
        @media screen and (max-width: 900px) { body { margin: 10px; } .report-wrap { overflow-x: auto; } .report-table { min-width: 1400px; } .document-meta { grid-template-columns: repeat(2, minmax(0, 1fr)); } .signature-area { gap: 8px; } .surat-kontrak-letterhead { grid-template-columns: minmax(60px, 1fr) minmax(0, 2.8fr) minmax(60px, 1fr); column-gap: 4px; } }
    </style>
</head>
<body>
<div class="toolbar"><button type="button" onclick="window.print()">Cetak</button> <button type="button" onclick="window.close()">Tutup</button></div>
@forelse($documents as $document)
    @php
        $lines = $document->lines;
        $subtotal = [
            'count' => $lines->count(),
            'gross' => $lines->sum(fn ($line) => (float) $line->gross_tons),
            'tare' => $lines->sum(fn ($line) => (float) $line->tare_tons),
            'net' => $lines->sum(fn ($line) => (float) $line->net_tons),
            'billable' => $lines->sum(fn ($line) => (float) ($line->billable_tons ?: $line->net_tons)),
            'amount' => $lines->sum(fn ($line) => (float) $line->vendor_total_amount),
        ];
    @endphp
    <section class="document-section">
        @include('crm::surat_kontrak.partials.letterhead', ['template' => $template, 'rendered_components' => $renderedComponents])
        <h2 class="report-title">Rekap Loading Vendor</h2>
        <p class="report-subtitle">{{ $business->name ?? '' }}</p>
        <div class="document-meta">
            <span><strong>No. Dokumen:</strong> {{ $document->document_no }}</span><span><strong>Sheet:</strong> {{ $document->source_sheet ?: 'Manual' }}</span>
            <span><strong>Bendera:</strong> {{ $document->supplier_label }}</span><span><strong>Customer:</strong> {{ $document->customer_label }}</span>
            <span><strong>Barang:</strong> {{ $document->goods_name ?: '-' }}{{ $document->goods_type ? ' / '.$document->goods_type : '' }}</span>
            <span><strong>Kapal:</strong> {{ $document->vessel_name ?: '-' }}</span><span><strong>Tujuan:</strong> {{ $document->destination ?: '-' }}</span><span><strong>Rute:</strong> {{ $document->route_label }}</span>
        </div>
        @if($loop->first)
            <p class="filters"><strong>Filter cetak:</strong> {{ $request->input('start_date') ?: 'Semua tanggal' }} — {{ $request->input('end_date') ?: 'Semua tanggal' }}@if($request->filled('search')) · Pencarian: {{ $request->input('search') }}@endif</p>
        @endif
        <div class="report-wrap"><table class="report-table">
            <thead><tr><th style="width:3%">No</th><th style="width:7%">Tanggal</th><th style="width:9%">No. Tiket</th><th style="width:9%">No. Polisi / Unit</th><th style="width:8%">Gross</th><th style="width:8%">Tare</th><th style="width:8%">Nett</th><th style="width:8%">Ton Tagihan</th><th style="width:8%">Harga/Ton (Rp)</th><th style="width:10%">Total (Rp)</th><th>Keterangan / Nama Sopir</th></tr></thead>
            <tbody>
            @foreach($lines as $index => $line)
                <tr><td class="center">{{ $index + 1 }}</td><td class="center">{{ optional($line->loading_date)->format('d/m/Y') ?: '-' }}</td><td>{{ $line->ticket_no ?: '-' }}</td><td>{{ $line->vendor_unit_name ?: '-' }}</td>
                    <td class="numeric">{{ $line->gross_tons === null ? '-' : number_format((float) $line->gross_tons, 4, ',', '.') }}</td><td class="numeric">{{ $line->tare_tons === null ? '-' : number_format((float) $line->tare_tons, 4, ',', '.') }}</td><td class="numeric">{{ $line->net_tons === null ? '-' : number_format((float) $line->net_tons, 4, ',', '.') }}</td>
                    <td class="numeric">{{ $line->billable_tons === null ? '-' : number_format((float) $line->billable_tons, 4, ',', '.') }}</td><td class="numeric">{{ $line->vendor_rate_per_ton === null ? '-' : 'Rp '.number_format((float) $line->vendor_rate_per_ton, 0, ',', '.') }}</td><td class="numeric">{{ $line->vendor_total_amount === null ? '-' : 'Rp '.number_format((float) $line->vendor_total_amount, 0, ',', '.') }}</td><td>{{ $line->vendor_driver_name ?: '-' }}</td></tr>
            @endforeach
            </tbody>
            <tfoot><tr class="subtotal-row"><th colspan="4" class="numeric">SUBTOTAL {{ $document->source_sheet ?: $document->document_no }} ({{ number_format($subtotal['count'], 0, ',', '.') }} tiket)</th><th class="numeric">{{ number_format($subtotal['gross'], 4, ',', '.') }}</th><th class="numeric">{{ number_format($subtotal['tare'], 4, ',', '.') }}</th><th class="numeric">{{ number_format($subtotal['net'], 4, ',', '.') }}</th><th class="numeric">{{ number_format($subtotal['billable'], 4, ',', '.') }}</th><th></th><th class="numeric">Rp {{ number_format($subtotal['amount'], 0, ',', '.') }}</th><th></th></tr></tfoot>
        </table></div>
        <div class="signature-area"><div class="signature">Dibuat oleh<div class="line">(........................)</div></div><div class="signature">Diperiksa oleh<div class="line">(........................)</div></div><div class="signature">Disetujui oleh<div class="line">(........................)</div></div></div>
    </section>
@empty
    <p>Tidak ada data untuk filter yang dipilih.</p>
@endforelse
<div class="total-overall">
    <table class="report-table"><thead><tr><th colspan="4">TOTAL KESELURUHAN ({{ number_format($summary['totals']['record_count'], 0, ',', '.') }} tiket)</th><th>Gross</th><th>Tare</th><th>Nett</th><th>Ton tagihan</th><th></th><th>Nilai total (Rp)</th><th colspan="2"></th></tr></thead><tbody><tr><th colspan="4" class="numeric">Semua sheet/dokumen terfilter</th><td class="numeric">{{ number_format($summary['totals']['gross_tons'], 4, ',', '.') }}</td><td class="numeric">{{ number_format($summary['totals']['tare_tons'], 4, ',', '.') }}</td><td class="numeric">{{ number_format($summary['totals']['net_tons'], 4, ',', '.') }}</td><td class="numeric">{{ number_format($summary['totals']['billable_tons'], 4, ',', '.') }}</td><td></td><td class="numeric">Rp {{ number_format($summary['totals']['vendor_total_amount'], 0, ',', '.') }}</td><td colspan="2"></td></tr></tbody></table>
</div>
<div class="printed-at">Dicetak {{ now()->format('d/m/Y H:i') }}</div>
</body>
</html>
