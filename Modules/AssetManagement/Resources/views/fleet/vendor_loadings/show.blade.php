@extends('layouts.app')

@section('title', 'Loading Vendor '.$loading->loading_no)

@section('content')
@includeIf('assetmanagement::layouts.nav')
<section class="content-header">
    <div class="pull-right">
        <a href="{{ route('asset.fleet.vendor-loadings.index') }}" class="btn btn-default">Kembali</a>
        @if(!in_array($loading->status, ['verified', 'cancelled'], true) && !$loading->expense_transaction_id)<a href="{{ route('asset.fleet.vendor-loadings.edit', $loading->id) }}" class="btn btn-warning">Edit</a>@endif
    </div>
    <h1>Loading Vendor {{ $loading->loading_no }}</h1>
</section>
<section class="content">
    <div id="vendor-loading-show-feedback" class="alert" role="alert" hidden></div>
    <div class="box box-primary"><div class="box-header with-border"><h3 class="box-title">Ringkasan Loading Vendor</h3></div><div class="box-body">
        <div class="row">
            <div class="col-md-3"><strong>Nomor Tiket</strong><p>{{ $loading->ticket_no ?: '-' }}</p></div>
            <div class="col-md-3"><strong>Tanggal Loading</strong><p>{{ optional($loading->loading_date)->format('d/m/Y') ?: '-' }}</p></div>
            <div class="col-md-3"><strong>Status</strong><p><span class="label label-primary">{{ ['draft' => 'Draft', 'loading' => 'Loading', 'in_transit' => 'Dalam Perjalanan', 'completed' => 'Selesai', 'verified' => 'Terverifikasi', 'cancelled' => 'Dibatalkan'][$loading->status] ?? $loading->status }}</span></p></div>
            <div class="col-md-3"><strong>Status Expense</strong><p>{{ $loading->expense_status }} @if($loading->expense_transaction_id)(#{{ $loading->expense_transaction_id }})@endif</p></div>
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
    </div></div>
    <div class="box box-info"><div class="box-header with-border"><h3 class="box-title">Tonase dan Nilai Vendor</h3></div><div class="box-body">
        <div class="row">
            <div class="col-md-2"><strong>Gross</strong><p>{{ number_format((float) $loading->gross_tons, 4, ',', '.') }} Ton</p></div>
            <div class="col-md-2"><strong>Tare</strong><p>{{ number_format((float) $loading->tare_tons, 4, ',', '.') }} Ton</p></div>
            <div class="col-md-2"><strong>Netto</strong><p>{{ number_format((float) $loading->net_tons, 4, ',', '.') }} Ton</p></div>
            <div class="col-md-2"><strong>Tagihan</strong><p>{{ number_format((float) $loading->billable_tons, 4, ',', '.') }} Ton</p></div>
            <div class="col-md-2"><strong>Tarif/Ton</strong><p>{{ $loading->vendor_rate_per_ton === null ? '-' : number_format((float) $loading->vendor_rate_per_ton, 0, ',', '.') }}</p></div>
            <div class="col-md-2"><strong>Nilai Akhir</strong><p><strong>{{ $loading->vendor_total_amount === null ? '-' : 'Rp '.number_format((float) $loading->vendor_total_amount, 0, ',', '.') }}</strong></p></div>
        </div>
        <p><strong>Catatan:</strong> {{ $loading->notes ?: '-' }}</p>
        @if(!in_array($loading->status, ['verified', 'cancelled'], true))
            <div class="vendor-loading-status-actions">
                @php
                    $nextStatus = ['draft' => 'loading', 'loading' => 'in_transit', 'in_transit' => 'completed', 'completed' => 'verified'][$loading->status] ?? null;
                @endphp
                @if($nextStatus)<button type="button" class="btn btn-success vendor-loading-show-status" data-status="{{ $nextStatus }}" data-url="{{ route('asset.fleet.vendor-loadings.status', $loading->id) }}">{{ $nextStatus === 'verified' ? 'Verifikasi Loading Vendor' : 'Ubah ke '.(['loading' => 'Loading', 'in_transit' => 'Dalam Perjalanan', 'completed' => 'Selesai'][$nextStatus] ?? $nextStatus) }}</button>@endif
                <button type="button" class="btn btn-danger vendor-loading-show-cancel" data-url="{{ route('asset.fleet.vendor-loadings.status', $loading->id) }}">Batalkan</button>
            </div>
        @endif
    </div></div>
</section>
@push('scripts')
<script>
$(function () {
    function sendStatus(url, status) {
        $.ajax({ url: url, type: 'POST', data: { _token: $('meta[name="csrf-token"]').attr('content'), status: status }, headers: { Accept: 'application/json' } }).done(function (response) { window.location.reload(); }).fail(function (xhr) { var body = xhr.responseJSON || {}; $('#vendor-loading-show-feedback').removeAttr('hidden').addClass('alert-danger').text(body.msg || body.message || 'Status tidak dapat diubah.'); });
    }
    $('.vendor-loading-show-status').on('click', function () { if (window.confirm('Ubah status Loading Vendor ini?')) sendStatus($(this).data('url'), $(this).data('status')); });
    $('.vendor-loading-show-cancel').on('click', function () { if (window.confirm('Batalkan Loading Vendor ini?')) sendStatus($(this).data('url'), 'cancelled'); });
});
</script>
@endpush
@endsection
