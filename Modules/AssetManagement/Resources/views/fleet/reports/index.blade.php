@extends('layouts.app')

@section('title', 'Rekap Alat dan Sopir')

@section('content')
@includeIf('assetmanagement::layouts.nav')
@include('layouts.partials.fleet-datatables')
<section class="content-header"><a href="{{ route('asset.fleet.reports.export', request()->query()) }}" class="btn btn-success pull-right"><i class="fa fa-file-excel"></i> Export Rekap</a><h1><i class="fas fa-chart-line"></i> Rekap Alat &amp; Sopir</h1></section>
<section class="content">
    <div class="box box-solid"><div class="box-body"><form method="GET" class="row"><div class="col-md-2"><label>Dari</label><input type="date" name="start_date" value="{{ request('start_date') }}" class="form-control"></div><div class="col-md-2"><label>Sampai</label><input type="date" name="end_date" value="{{ request('end_date') }}" class="form-control"></div><div class="col-md-3"><label>Unit</label><select name="asset_id" class="form-control"><option value="">Semua</option>@foreach($assets as $id => $name)<option value="{{ $id }}" @selected(request('asset_id') == $id)>{{ $name }}</option>@endforeach</select></div><div class="col-md-2"><label>Sopir</label><select name="driver_id" class="form-control"><option value="">Semua</option>@foreach($drivers as $id => $name)<option value="{{ $id }}" @selected(request('driver_id') == $id)>{{ $name }}</option>@endforeach</select></div><div class="col-md-3"><label>Project</label><select name="project_id" class="form-control"><option value="">Semua</option>@foreach($projects as $id => $name)<option value="{{ $id }}" @selected(request('project_id') == $id)>{{ $name }}</option>@endforeach</select></div><div class="col-md-12" style="margin-top:10px"><button class="btn btn-default">Filter</button></div></form></div></div>
    <div class="row">@foreach([['Trip', $summary['trip_count']], ['Ton', number_format($summary['tons'], 2, ',', '.')], ['Pendapatan', number_format($summary['revenue'], 0, ',', '.')], ['Upah Sopir', number_format($summary['driver_pay'], 0, ',', '.')], ['Bonus Ayak', number_format($summary['bonus_ayak'], 0, ',', '.')], ['Kontribusi setelah Bonus', number_format($summary['contribution'], 0, ',', '.')]] as $card)<div class="col-md-2"><div class="small-box bg-{{ $loop->last && $summary['contribution'] < 0 ? 'red' : 'aqua' }}"><div class="inner"><h3>{{ $card[1] }}</h3><p>{{ $card[0] }}</p></div></div></div>@endforeach</div>
    <div class="box box-primary"><div class="box-header with-border"><h3 class="box-title">Per Unit Alat</h3></div><div class="box-body table-responsive fleet-datatable-wrap"><table id="fleet-report-assets-table" class="table table-bordered fleet-datatable"><thead><tr><th>Unit</th><th>Trip</th><th>Ton</th><th>Pendapatan</th><th>Upah Sopir</th><th>Bonus Ayak</th><th>Kontribusi setelah Bonus</th></tr></thead><tbody>@forelse($byAsset as $assetId => $item)<tr><td><a href="{{ route('asset.fleet.trips.asset-ledger', ['assetId' => $assetId]) }}">{{ $item['label'] }}</a></td><td>{{ $item['trips'] }}</td><td>{{ number_format($item['tons'], 2, ',', '.') }}</td><td>@format_currency($item['revenue'])</td><td>@format_currency($item['driver_pay'])</td><td>@format_currency($item['bonus_ayak'])</td><td>@format_currency($item['contribution'])</td></tr>@empty<tr><td colspan="7" class="text-center">Belum ada data.</td></tr>@endforelse</tbody></table></div></div>
    <div class="box box-solid"><div class="box-header with-border"><h3 class="box-title">Per Sopir</h3></div><div class="box-body table-responsive fleet-datatable-wrap"><table id="fleet-report-drivers-table" class="table table-bordered fleet-datatable"><thead><tr><th>Sopir</th><th>Trip</th><th>Ton</th><th>Upah Trip</th><th>Bonus Ayak</th><th>Kontribusi setelah Bonus</th></tr></thead><tbody>@forelse($byDriver as $item)<tr><td>{{ $item['label'] }}</td><td>{{ $item['trips'] }}</td><td>{{ number_format($item['tons'], 2, ',', '.') }}</td><td>@format_currency($item['driver_pay'])</td><td>@format_currency($item['bonus_ayak'])</td><td>@format_currency($item['contribution'])</td></tr>@empty<tr><td colspan="6" class="text-center">Belum ada data.</td></tr>@endforelse</tbody></table></div></div>
</section>
@push('scripts')
<script>
$(function () {
    initFleetDataTable('#fleet-report-assets-table', { order: [[0, 'asc']], columnDefs: [{ targets: [1, 2, 3, 4, 5, 6], orderable: false }] });
    initFleetDataTable('#fleet-report-drivers-table', { order: [[0, 'asc']], columnDefs: [{ targets: [1, 2, 3, 4, 5], orderable: false }] });
});
</script>
@endpush
@endsection
