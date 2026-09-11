@extends('layouts.app')

@section('title', $title)

@section('content')
@includeIf('assetmanagement::layouts.nav')
@include('layouts.partials.fleet-datatables')
<section class="content-header">
    <h1>{{ $title }}</h1>
</section>
<section class="content">
    <div class="box box-primary">
        <div class="box-header with-border">
            <h3 class="box-title">{{ $description }}</h3>
        </div>
        <form method="GET" action="{{ $submitRoute }}">
            <div class="box-body">
                @if($project)
                    <div class="alert alert-info">
                        Project: <strong>{{ $project->name }}</strong>
                        @if($project->customer) — Customer: {{ $project->customer->name }} @endif
                    </div>
                @endif
                @if($trips->isEmpty())
                    <div class="alert alert-warning mb-0">Belum ada SPB yang siap dipilih.</div>
                @else
                    <div class="table-responsive fleet-datatable-wrap">
                        <table id="fleet-billing-chooser-table" class="table table-bordered table-striped fleet-datatable">
                            <thead>
                                <tr>
                                    <th class="text-center" style="width: 40px"><input type="checkbox" id="select-all-trips"></th>
                                    <th>SPB</th>
                                    <th>Tanggal</th>
                                    <th>Rute</th>
                                    <th>Unit / Sopir</th>
                                    <th class="text-end">Ton Tagihan</th>
                                    <th class="text-end">Pendapatan</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($trips as $trip)
                                    <tr>
                                        <td class="text-center"><input type="checkbox" class="trip-checkbox" name="trip_ids[]" value="{{ $trip->id }}"></td>
                                        <td><a href="{{ route('asset.fleet.trips.show', $trip->id) }}">{{ $trip->trip_no }}</a></td>
                                        <td>{{ optional($trip->trip_date)->format('d-m-Y') }}</td>
                                        <td>{{ optional($trip->route)->name ?: '-' }}</td>
                                        <td>{{ optional($trip->asset)->name ?: '-' }}<br><small>{{ optional($trip->driver)->user_full_name ?: '-' }}</small></td>
                                        <td class="text-end">{{ number_format((float) ($trip->billable_tons ?: $trip->actual_tons), 4, ',', '.') }}</td>
                                        <td class="text-end">@format_currency($trip->revenue_total)</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>
            <div class="box-footer">
                <button type="submit" class="btn btn-primary" @disabled($trips->isEmpty())>Lanjutkan</button>
                <a href="{{ $backUrl }}" class="btn btn-default">Kembali</a>
            </div>
        </form>
    </div>
</section>
@push('scripts')
<script>
$(function () {
    var chooserTable = initFleetDataTable('#fleet-billing-chooser-table', {
        order: [[2, 'desc']],
        columnDefs: [{ targets: [0, 5, 6], orderable: false }]
    });
    var selectedTrips = new Set();

    function syncChooserChecks() {
        if (!chooserTable) return;
        $(chooserTable.rows({ page: 'current' }).nodes()).find('.trip-checkbox').each(function () {
            this.checked = selectedTrips.has(String(this.value));
        });
        var pageChecks = $(chooserTable.rows({ page: 'current' }).nodes()).find('.trip-checkbox');
        $('#select-all-trips').prop('checked', pageChecks.length > 0 && pageChecks.filter(':checked').length === pageChecks.length);
    }

    $('#fleet-billing-chooser-table').on('change', '.trip-checkbox', function () {
        var id = String(this.value);
        if (this.checked) selectedTrips.add(id); else selectedTrips.delete(id);
        syncChooserChecks();
    });

    $('#select-all-trips').on('change', function () {
        if (!chooserTable) return;
        $(chooserTable.rows({ page: 'current' }).nodes()).find('.trip-checkbox').each(function () {
            this.checked = $('#select-all-trips').is(':checked');
            var id = String(this.value);
            if (this.checked) selectedTrips.add(id); else selectedTrips.delete(id);
        });
    });

    if (chooserTable) chooserTable.on('draw', syncChooserChecks);
    $('#fleet-billing-chooser-table').closest('form').on('submit', function () {
        var form = this;
        $(form).find('.fleet-selected-trip').remove();
        $('#fleet-billing-chooser-table .trip-checkbox').prop('disabled', true);
        selectedTrips.forEach(function (id) {
            $('<input>', { type: 'hidden', name: 'trip_ids[]', value: id, class: 'fleet-selected-trip' }).appendTo(form);
        });
    });
    syncChooserChecks();
});
</script>
@endpush
@endsection
