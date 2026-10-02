@extends('layouts.app')

@section('title', 'Ledger Trip ' . $asset->name)

@section('content')
@includeIf('assetmanagement::layouts.nav')
@include('layouts.partials.fleet-datatables')
<section class="content-header">
    <a href="{{ route('asset.fleet.reports.index', ['asset_id' => $asset->id]) }}" class="btn btn-default pull-right">Rekap Semua Unit</a>
    <h1><i class="fas fa-truck-moving"></i> Ledger Trip Angkutan: {{ $asset->name }}</h1>
</section>
<section class="content">
    <div class="row">
        @foreach([['Trip', $summary['trip_count']], ['Ton', number_format($summary['tons'], 2, ',', '.')], ['Pendapatan', number_format($summary['revenue'], 0, ',', '.')], ['Upah Sopir', number_format($summary['driver_pay'], 0, ',', '.')], ['Bonus Ayak', number_format($summary['bonus_ayak'], 0, ',', '.')], ['Kontribusi setelah Bonus', number_format($summary['contribution'], 0, ',', '.')]] as $card)
            <div class="col-md-2"><div class="small-box bg-{{ $loop->last && $summary['contribution'] < 0 ? 'red' : 'aqua' }}"><div class="inner"><h3>{{ $card[1] }}</h3><p>{{ $card[0] }}</p></div></div></div>
        @endforeach
    </div>
    <div class="box box-primary"><div class="box-body table-responsive fleet-datatable-wrap">
        <table id="fleet-asset-ledger-trips-table" class="table table-bordered table-striped fleet-datatable">
            <thead><tr><th>Tanggal</th><th>Trip</th><th>Rute</th><th>Sopir</th><th>Project</th><th>Ton</th><th>Pendapatan</th><th>Upah</th><th>Kontribusi</th><th>Invoice</th><th>Settlement Payroll</th><th>Status</th></tr></thead>
            <tbody>
            @forelse($trips as $trip)
                <tr>
                    <td>{{ optional($trip->trip_date)->format('d-m-Y') }}</td>
                    <td><a href="{{ route('asset.fleet.trips.show', $trip->id) }}">{{ $trip->trip_no }}</a></td>
                    <td>{{ optional($trip->route)->name }}</td>
                    <td>{{ optional($trip->driver)->user_full_name }}</td>
                    <td>{{ optional($trip->project)->name ?: '-' }}</td>
                    <td>{{ number_format($trip->actual_tons, 2, ',', '.') }}</td>
                    <td>@format_currency($trip->revenue_total)</td>
                    <td>@format_currency($trip->driver_pay_total)</td>
                    <td>@format_currency($trip->gross_contribution)</td>
                    <td>{{ $trip->billing ? strtoupper($trip->billing->billing_type) : 'Belum' }}</td>
                    <td>{{ $trip->settlements->isNotEmpty() ? $trip->settlements->pluck('settlement_no')->implode(', ') : 'Belum' }}</td>
                    <td>{{ strtoupper($trip->status) }}</td>
                </tr>
            @empty
                <tr><td colspan="12" class="text-center">Belum ada trip untuk unit ini.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div></div>
    <div class="box box-warning"><div class="box-header with-border"><h3 class="box-title">Bonus Ayak unit ini</h3></div><div class="box-body"><p class="text-muted">Bonus dicatat per sopir dan unit, tanpa ditautkan ke SPB tertentu. Nilainya masuk ke ringkasan biaya unit dan tetap diproses melalui settlement payroll.</p><div class="table-responsive fleet-datatable-wrap"><table id="fleet-asset-ledger-bonus-table" class="table table-bordered fleet-datatable"><thead><tr><th>Tanggal</th><th>Sopir</th><th>Project</th><th>Trip</th><th>Bonus</th><th>Status Payroll</th></tr></thead><tbody>@forelse($assetBonusAyaks as $bonus)<tr><td>{{ optional($bonus->tanggal)->format('d-m-Y') }}</td><td>{{ optional($bonus->driver)->user_full_name ?: $bonus->nama_operator }}</td><td>{{ optional($bonus->project)->name ?: 'Tanpa Project' }}</td><td>{{ $bonus->trip }}</td><td>@format_currency($bonus->bonus)</td><td>{{ $bonus->settlements()->exists() ? 'Sudah settlement' : 'Belum settlement' }}</td></tr>@empty<tr><td colspan="6" class="text-center">Belum ada Bonus Ayak untuk unit ini.</td></tr>@endforelse</tbody></table></div></div></div>
</section>
@push('scripts')
<script>
$(function () {
    initFleetDataTable('#fleet-asset-ledger-trips-table', { order: [[0, 'desc']], columnDefs: [{ targets: [1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11], orderable: false }] });
    initFleetDataTable('#fleet-asset-ledger-bonus-table', { order: [[0, 'desc']], columnDefs: [{ targets: [1, 2, 3, 4, 5], orderable: false }] });
});
</script>
@endpush
@endsection
