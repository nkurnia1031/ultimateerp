@extends('layouts.app')

@section('title', 'Sell Trip Angkutan')

@section('content')
@includeIf('assetmanagement::layouts.nav')
@include('layouts.partials.fleet-datatables')
<section class="content-header"><h1>Sell dari Trip Angkutan</h1></section>
<section class="content"><form method="POST" action="{{ route('asset.fleet.trips.sell') }}">@csrf
    @foreach($trips as $trip)<input type="hidden" name="trip_ids[]" value="{{ $trip->id }}">@endforeach
    <div class="box box-primary"><div class="box-body">
        <p>Trip terpilih: <strong>{{ $trips->pluck('trip_no')->implode(', ') }}</strong></p>
        <div class="row">
            <div class="col-md-4 form-group"><label>Customer *</label><select name="customer_id" class="form-control" required><option value="">Pilih customer</option>@foreach($customers as $id => $name)<option value="{{ $id }}" @selected($trips->first()->customer_id == $id)>{{ $name }}</option>@endforeach</select></div>
            <div class="col-md-4 form-group"><label>Produk jasa angkutan *</label><select name="variation_id" class="form-control" required><option value="">Pilih produk jasa tanpa stok</option>@foreach($variations as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach</select><small class="text-muted">Buat/siapkan produk tipe jasa atau produk tanpa stok di modul Produk.</small></div>
            <div class="col-md-4 form-group"><label>Lokasi *</label><select name="location_id" class="form-control" required><option value="">Pilih lokasi</option>@foreach($locations as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach</select></div>
        </div>
        <div class="row"><div class="col-md-4 form-group"><label>Tanggal Sell</label><input type="date" name="transaction_date" value="{{ now()->format('Y-m-d') }}" class="form-control" required></div><div class="col-md-4 form-group"><label>Status</label><select name="status" class="form-control"><option value="draft">Draft</option><option value="final">Final</option></select></div></div>
        <div class="table-responsive fleet-datatable-wrap"><table id="fleet-sell-trips-table" class="table table-bordered fleet-datatable"><thead><tr><th>Trip</th><th>Rute</th><th>Ton</th><th>Harga/Ton</th><th>Total</th></tr></thead><tbody>@foreach($trips as $trip)<tr><td>{{ $trip->trip_no }}</td><td>{{ $trip->route->name }}</td><td>{{ $trip->actual_tons }}</td><td>@format_currency($trip->snapshot_sale_rate_per_ton)</td><td>@format_currency($trip->revenue_total)</td></tr>@endforeach</tbody></table></div>
        <button class="btn btn-primary">Buat Sell</button> <a href="{{ route('asset.fleet.trips.index') }}" class="btn btn-default">Batal</a>
    </div></div>
</form></section>
@push('scripts')
<script>
$(function () {
    initFleetDataTable('#fleet-sell-trips-table', { order: [[0, 'asc']], columnDefs: [{ targets: [1, 2, 3, 4], orderable: false }] });
});
</script>
@endpush
@endsection
