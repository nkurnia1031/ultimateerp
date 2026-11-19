@extends('layouts.app')

@section('title', 'Trip Angkutan')

@section('content')
@includeIf('assetmanagement::layouts.nav')
@include('layouts.partials.fleet-datatables')
<section class="content-header">
    <a href="{{ route('asset.fleet.trips.create') }}" class="btn btn-primary pull-right"><i class="fa fa-plus"></i> Buat Trip</a>
    <a href="{{ route('asset.fleet.trips.import.form') }}" class="btn btn-warning pull-right" style="margin-right:8px"><i class="fa fa-file-import"></i> Import SPB</a>
    <h1><i class="fas fa-truck-moving"></i> SPB Angkutan</h1>
</section>
<section class="content">
    @if (session('status'))
        @php
            $status = session('status');
        @endphp
        <div class="alert {{ !empty($status['success']) ? 'alert-success' : 'alert-danger' }}" role="alert">{{ $status['msg'] ?? '' }}</div>
    @endif
    <div class="box box-solid">
        <div class="box-body">
            <form method="GET" class="row">
                <div class="col-md-2"><label>Dari</label><input type="date" name="start_date" value="{{ request('start_date') }}" class="form-control"></div>
                <div class="col-md-2"><label>Sampai</label><input type="date" name="end_date" value="{{ request('end_date') }}" class="form-control"></div>
                <div class="col-md-2"><label>Project</label><select name="project_id" class="form-control"><option value="">Semua</option>@foreach($projects as $id => $name)<option value="{{ $id }}" @selected(request('project_id') == $id)>{{ $name }}</option>@endforeach</select></div>
                <div class="col-md-2"><label>Unit</label><select name="asset_id" class="form-control"><option value="">Semua</option>@foreach($assets as $id => $name)<option value="{{ $id }}" @selected(request('asset_id') == $id)>{{ $name }}</option>@endforeach</select></div>
                <div class="col-md-2"><label>Sopir</label><select name="driver_id" class="form-control"><option value="">Semua</option>@foreach($drivers as $id => $name)<option value="{{ $id }}" @selected(request('driver_id') == $id)>{{ $name }}</option>@endforeach</select></div>
                <div class="col-md-2"><label>Status</label><select name="status" class="form-control"><option value="">Semua</option>@foreach($statuses as $id => $name)<option value="{{ $id }}" @selected(request('status') == $id)>{{ $name }}</option>@endforeach</select></div>
                <div class="col-md-2"><label>No. Tiket</label><input type="text" name="ticket_no" value="{{ request('ticket_no') }}" class="form-control"></div>
                <div class="col-md-12" style="margin-top:10px"><button class="btn btn-default">Filter</button> <a href="{{ route('asset.fleet.trips.index') }}" class="btn btn-link">Reset</a></div>
            </form>
        </div>
    </div>

    <form method="GET" id="trip_billing_form" action="{{ route('asset.fleet.trips.project-invoice.form') }}"></form>
    <div class="box box-primary">
            <div class="box-header with-border">
                <h3 class="box-title">Daftar Trip</h3>
                <div class="box-tools pull-right">
                    <button type="submit" form="trip_billing_form" class="btn btn-xs btn-success">Invoice Project dari yang dipilih</button>
                    <button type="submit" form="trip_billing_form" class="btn btn-xs btn-info" formaction="{{ route('asset.fleet.trips.sell.form') }}">Sell dari yang dipilih</button>
                </div>
            </div>
            <div class="box-body table-responsive fleet-datatable-wrap">
                <table id="fleet-trips-table" class="table table-bordered table-striped fleet-datatable">
                    <thead><tr><th><input type="checkbox" id="select-all-trip-checks" aria-label="Pilih semua SPB di halaman ini"></th><th>No. SPB / Tiket</th><th>Tanggal</th><th>Rute</th><th>Unit / Sopir</th><th>Project</th><th>Ton</th><th>Pendapatan</th><th>Upah</th><th>Kontribusi</th><th>Status</th><th>Aksi</th></tr></thead>
                    <tbody>
                    @forelse($trips as $trip)
                        <tr>
                            <td>@if($trip->status === 'verified' && $trip->invoice_status === 'unbilled')<input type="checkbox" form="trip_billing_form" class="trip-check" name="trip_ids[]" value="{{ $trip->id }}">@endif</td>
                            <td><a href="{{ route('asset.fleet.trips.show', $trip->id) }}">{{ $trip->trip_no }}</a><br><small>{{ $trip->ticket_no ?: '-' }}</small></td>
                            <td>{{ optional($trip->trip_date)->format('d-m-Y') }}</td><td>{{ $trip->route->name }}</td>
                            <td>{{ optional($trip->asset)->name ?: '-' }}<br><small>{{ optional($trip->driver)->user_full_name }}</small></td>
                            <td>{{ optional($trip->project)->name ?: '-' }}</td><td>{{ number_format($trip->billable_tons ?: $trip->actual_tons, 2, ',', '.') }}</td>
                            <td>@format_currency($trip->revenue_total)</td><td>@format_currency($trip->driver_pay_total)</td><td>@format_currency($trip->gross_contribution)</td>
                            <td>{{ $statuses[$trip->status] ?? $trip->status }}<br><small>{{ $trip->invoice_status }}</small></td>
                            <td>
                                @if($canManageTrips && $trip->canBeChanged())
                                    <a href="{{ route('asset.fleet.trips.edit', $trip->id) }}" class="btn btn-xs btn-warning">Edit</a>
                                    <form method="POST" action="{{ route('asset.fleet.trips.destroy', $trip->id) }}" style="display:inline-block" onsubmit="return confirm('Hapus SPB ini?')">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="btn btn-xs btn-danger">Hapus</button>
                                    </form>
                                @endif
                                @if($trip->status !== 'verified' && $trip->status !== 'cancelled')
                                    @php $next = $trip->status === 'completed' ? 'verified' : 'completed'; @endphp
                                    <form method="POST" action="{{ route('asset.fleet.trips.status', $trip->id) }}">@csrf<input type="hidden" name="status" value="{{ $next }}"><button type="submit" class="btn btn-xs btn-warning">{{ $next === 'verified' ? 'Verify' : 'Selesaikan' }}</button></form>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="12" class="text-center">Belum ada trip.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>
</section>
@push('scripts')
<script>
$(function () {
    var tripTable = initFleetDataTable('#fleet-trips-table', {
        order: [[2, 'desc']],
        columnDefs: [{ targets: [0, 10, 11], orderable: false }]
    });
    var selectedTrips = new Set();

    function syncTripChecks() {
        if (!tripTable) return;
        $(tripTable.rows({ page: 'current' }).nodes()).find('.trip-check').each(function () {
            this.checked = selectedTrips.has(String(this.value));
        });
        var pageChecks = $(tripTable.rows({ page: 'current' }).nodes()).find('.trip-check');
        $('#select-all-trip-checks').prop('checked', pageChecks.length > 0 && pageChecks.filter(':checked').length === pageChecks.length);
    }

    $('#fleet-trips-table').on('change', '.trip-check', function () {
        var id = String(this.value);
        if (this.checked) selectedTrips.add(id); else selectedTrips.delete(id);
        syncTripChecks();
    });

    $('#select-all-trip-checks').on('change', function () {
        if (!tripTable) return;
        $(tripTable.rows({ page: 'current' }).nodes()).find('.trip-check').each(function () {
            this.checked = $('#select-all-trip-checks').is(':checked');
            var id = String(this.value);
            if (this.checked) selectedTrips.add(id); else selectedTrips.delete(id);
        });
    });

    if (tripTable) tripTable.on('draw', syncTripChecks);
    $('#trip_billing_form').on('submit', function () {
        var form = this;
        $(form).find('.fleet-selected-trip').remove();
        $('#fleet-trips-table .trip-check').prop('disabled', true);
        selectedTrips.forEach(function (id) {
            $('<input>', { type: 'hidden', name: 'trip_ids[]', value: id, class: 'fleet-selected-trip' }).appendTo(form);
        });
    });
    syncTripChecks();
});
</script>
@endpush
@endsection
