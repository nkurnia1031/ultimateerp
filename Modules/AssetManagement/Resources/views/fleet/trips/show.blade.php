@extends('layouts.app')

@section('title', 'Detail SPB ' . $trip->trip_no)

@section('content')
@includeIf('assetmanagement::layouts.nav')
<section class="content-header"><a href="{{ route('asset.fleet.trips.index') }}" class="btn btn-default pull-right">Kembali</a>@if($canManageTrips && $trip->canBeChanged())<a href="{{ route('asset.fleet.trips.edit', $trip->id) }}" class="btn btn-warning pull-right" style="margin-right:8px">Edit</a>@endif<h1>SPB {{ $trip->trip_no }}</h1></section>
<section class="content">
    <div class="box box-primary"><div class="box-body">
        <div class="row">
            <div class="col-md-3"><strong>Tanggal SPB</strong><br>{{ optional($trip->trip_date)->format('d-m-Y') }}</div>
            <div class="col-md-3"><strong>No. Tiket</strong><br>{{ $trip->ticket_no ?: '-' }}</div>
            <div class="col-md-3"><strong>Rute</strong><br>{{ optional($trip->route)->name ?: '-' }}</div>
            <div class="col-md-3"><strong>Unit / Sopir</strong><br>{{ optional($trip->asset)->name ?: '-' }} / {{ optional($trip->driver)->user_full_name ?: '-' }}</div>
        </div><hr>
        <div class="row">
            <div class="col-md-2"><strong>Gross</strong><br>{{ $trip->gross_tons !== null ? number_format($trip->gross_tons, 4, ',', '.') : '-' }}</div>
            <div class="col-md-2"><strong>Tare</strong><br>{{ $trip->tare_tons !== null ? number_format($trip->tare_tons, 4, ',', '.') : '-' }}</div>
            <div class="col-md-2"><strong>Netto</strong><br>{{ number_format($trip->actual_tons, 4, ',', '.') }}</div>
            <div class="col-md-2"><strong>Diterima</strong><br>{{ $trip->received_tons !== null ? number_format($trip->received_tons, 4, ',', '.') : '-' }}</div>
            <div class="col-md-2"><strong>Tagihan</strong><br>{{ number_format($trip->billable_tons ?: $trip->actual_tons, 4, ',', '.') }}</div>
            <div class="col-md-2"><strong>Harga/Ton</strong><br>@if($trip->snapshot_sale_rate_per_ton !== null) @format_currency($trip->snapshot_sale_rate_per_ton) @else Kosong @endif</div>
        </div><hr>
        <div class="row">
            <div class="col-md-3"><strong>Nilai dasar</strong><br>@format_currency($trip->base_revenue_total)</div>
            <div class="col-md-3"><strong>Penyesuaian klaim</strong><br>@format_currency($trip->claim_adjustment_total)</div>
            <div class="col-md-3"><strong>Nilai akhir</strong><br>@format_currency($trip->revenue_total)</div>
        </div><hr>
        <div class="row">
            <div class="col-md-3"><strong>Supir/Ton</strong><br>@format_currency($trip->snapshot_driver_rate_per_ton)</div>
            <div class="col-md-3"><strong>Uang jalan</strong><br>@format_currency($trip->snapshot_travel_allowance)</div>
            <div class="col-md-3"><strong>Total upah</strong><br>@format_currency($trip->driver_pay_total)</div>
            <div class="col-md-3"><strong>Status</strong><br>{{ strtoupper($trip->status) }}</div>
        </div>
        @if($trip->rate_override_reason)<hr><p><strong>Override:</strong> {{ $trip->rate_override_reason }}</p>@endif
        @if($trip->billing)<hr><p class="text-success">Sudah ditagihkan ke transaksi #{{ $trip->billing->transaction_id }} ({{ $trip->billing->billing_type }}).</p>@endif
        @if($trip->settlements->isNotEmpty())<p class="text-success">Sudah masuk settlement {{ $trip->settlements->pluck('settlement_no')->implode(', ') }}.</p>@endif
    </div></div>
</section>
@endsection
