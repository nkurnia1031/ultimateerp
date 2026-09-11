@extends('layouts.app')

@section('title', 'Invoice Project Trip')

@section('content')
@includeIf('assetmanagement::layouts.nav')
@include('layouts.partials.fleet-datatables')
<section class="content-header"><h1>Invoice Project dari Trip</h1></section>
<section class="content"><form method="POST" action="{{ route('asset.fleet.trips.project-invoice') }}">@csrf
    @foreach($trips as $trip)<input type="hidden" name="trip_ids[]" value="{{ $trip->id }}">@endforeach
    <div class="box box-primary"><div class="box-body">
        <p>Trip terpilih: <strong>{{ $trips->pluck('trip_no')->implode(', ') }}</strong></p>
        <div class="row">
            <div class="col-md-4 form-group"><label>Judul Invoice</label><input type="text" name="pjt_title" class="form-control" value="Angkutan {{ optional($trips->first()->project)->name }}"></div>
            <div class="col-md-4 form-group"><label>Skema Invoice</label><select name="invoice_scheme_id" class="form-control">@foreach($invoiceSchemes as $id => $name)<option value="{{ $id }}" @selected($defaultScheme && $defaultScheme->id == $id)>{{ $name }}</option>@endforeach</select></div>
            <div class="col-md-4 form-group"><label>Lokasi *</label><select name="location_id" class="form-control" required><option value="">Pilih lokasi</option>@foreach($locations as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach</select></div>
        </div>
        <div class="row"><div class="col-md-4 form-group"><label>Tanggal Invoice</label><input type="date" name="transaction_date" value="{{ now()->format('Y-m-d') }}" class="form-control" required></div><div class="col-md-4 form-group"><label>Status</label><select name="status" class="form-control"><option value="draft">Draft</option><option value="final">Final</option></select></div></div>
        <p class="text-muted">Ton tagihan adalah jumlah yang dipakai untuk invoice. Nilai akhir sudah memperhitungkan penyesuaian klaim dan potongan pada SPB.</p>
        <div class="table-responsive fleet-datatable-wrap"><table id="fleet-project-invoice-trips-table" class="table table-bordered fleet-datatable"><thead><tr><th>Trip</th><th>Rute</th><th>Ton Aktual</th><th>Ton Tagihan</th><th>Harga/Ton</th><th>Nilai Akhir</th></tr></thead><tbody>@foreach($trips as $trip)<tr><td>{{ $trip->trip_no }}</td><td>{{ $trip->route->name }}</td><td>{{ number_format($trip->actual_tons, 4, ',', '.') }}</td><td>{{ number_format($trip->billable_tons ?: $trip->actual_tons, 4, ',', '.') }}</td><td>@format_currency($trip->snapshot_sale_rate_per_ton)</td><td>@format_currency($trip->revenue_total)</td></tr>@endforeach</tbody></table></div>
        <button class="btn btn-primary">Buat Invoice Project</button> <a href="{{ route('asset.fleet.trips.index') }}" class="btn btn-default">Batal</a>
    </div></div>
</form></section>
@push('scripts')
<script>
$(function () {
    initFleetDataTable('#fleet-project-invoice-trips-table', { order: [[0, 'asc']], columnDefs: [{ targets: [1, 2, 3, 4, 5], orderable: false }] });
});
</script>
@endpush
@endsection
