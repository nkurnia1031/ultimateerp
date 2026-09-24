@extends('layouts.app')

@section('title', 'Loading Vendor '.$loading->loading_no)

@section('content')
@includeIf('assetmanagement::layouts.nav')
<section class="content-header">
    <div class="pull-right">
        <a href="{{ route('asset.fleet.vendor-loadings.index') }}" class="btn btn-default">Kembali</a>
        @if($canManage)<a href="{{ route('asset.fleet.vendor-loadings.edit', $loading->id) }}" class="btn btn-warning">Edit</a>@endif
    </div>
    <h1>Loading Vendor {{ $loading->loading_no }}</h1>
</section>
<section class="content">
    <div id="vendor-loading-show-feedback" class="alert" role="alert" hidden></div>
    <div class="box box-primary"><div class="box-header with-border"><h3 class="box-title">Ringkasan Loading Vendor</h3></div><div class="box-body">
        <div class="row">
            <div class="col-md-3"><strong>Nomor Tiket</strong><p>{{ $loading->ticket_no ?: '-' }}</p></div>
            <div class="col-md-3"><strong>Tanggal Loading</strong><p>{{ optional($loading->loading_date)->format('d/m/Y') ?: '-' }}</p></div>
        </div>
        <div class="row">
            <div class="col-md-3"><strong>Bendera / Supplier</strong><p>{{ $loading->supplier_label }}</p></div>
            <div class="col-md-3"><strong>Unit Vendor</strong><p>{{ $loading->vendor_unit_name ?: '-' }}</p></div>
            <div class="col-md-3"><strong>Sopir Vendor</strong><p>{{ $loading->vendor_driver_name ?: '-' }}</p></div>
            <div class="col-md-3"><strong>Rute</strong><p>{{ $loading->route_label }}</p></div>
        </div>
        <div class="row">
            <div class="col-md-3"><strong>Project</strong><p>{{ optional($loading->project)->name ?: '-' }}</p></div>
            <div class="col-md-3"><strong>Customer</strong><p>{{ optional($loading->customer)->name ?: '-' }}</p></div>
            <div class="col-md-3"><strong>Tanggal Muat</strong><p>{{ optional($loading->load_date)->format('d/m/Y') ?: '-' }}</p></div>
            <div class="col-md-3"><strong>Tanggal Bongkar</strong><p>{{ optional($loading->unload_date)->format('d/m/Y') ?: '-' }}</p></div>
        </div>
        <div class="row">
            <div class="col-md-3"><strong>Nama Barang</strong><p>{{ $loading->goods_name ?: '-' }}</p></div>
            <div class="col-md-3"><strong>Jenis Barang</strong><p>{{ $loading->goods_type ?: '-' }}</p></div>
            <div class="col-md-3"><strong>Nama Kapal</strong><p>{{ $loading->vessel_name ?: '-' }}</p></div>
            <div class="col-md-3"><strong>Tujuan</strong><p>{{ $loading->destination ?: '-' }}</p></div>
        </div>
    </div></div>
    <div class="box box-info"><div class="box-header with-border"><h3 class="box-title">Tonase dan Nilai Vendor</h3></div><div class="box-body">
        <div class="row">
            <div class="col-md-2"><strong>Gross</strong><p>{{ number_format((float) $loading->gross_tons, 4, ',', '.') }} Ton</p></div>
            <div class="col-md-2"><strong>Tare</strong><p>{{ number_format((float) $loading->tare_tons, 4, ',', '.') }} Ton</p></div>
            <div class="col-md-2"><strong>Netto</strong><p>{{ number_format((float) $loading->net_tons, 4, ',', '.') }} Ton</p></div>
            <div class="col-md-2"><strong>Tagihan</strong><p>{{ number_format((float) $loading->billable_tons, 4, ',', '.') }} Ton</p></div>
            <div class="col-md-2"><strong>Tarif/Ton (Rp)</strong><p>{{ $loading->vendor_rate_per_ton === null ? '-' : 'Rp '.number_format((float) $loading->vendor_rate_per_ton, 0, ',', '.') }}</p></div>
            <div class="col-md-2"><strong>Nilai Akhir (Rp)</strong><p><strong>{{ $loading->vendor_total_amount === null ? '-' : 'Rp '.number_format((float) $loading->vendor_total_amount, 0, ',', '.') }}</strong></p></div>
        </div>
        <p><strong>Catatan:</strong> {{ $loading->notes ?: '-' }}</p>
    </div></div>
</section>
@endsection
