@extends('layouts.app')

@section('title', 'Import SPB Angkutan')

@section('content')
@includeIf('assetmanagement::layouts.nav')
@include('layouts.partials.fleet-datatables')
<section class="content-header"><h1><i class="fas fa-file-import"></i> Import SPB Angkutan</h1></section>
<section class="content">
    @if (session('status'))
        @php
            $status = session('status');
        @endphp
        <div class="alert {{ !empty($status['success']) ? 'alert-success' : 'alert-danger' }}">{{ $status['msg'] ?? '' }}</div>
    @endif
    <div class="box box-primary"><div class="box-body">
        <p class="text-muted">Gunakan workbook rekap loading atau rekonsiliasi cangkang. Sistem menyimpan sheet dan nomor baris sumber, lalu mencocokkan unit dan sopir sebelum membuat SPB.</p>
        <form method="POST" action="{{ route('asset.fleet.trips.import.preview') }}" enctype="multipart/form-data" class="form-inline">
            @csrf
            <input type="file" name="file" class="form-control" accept=".xlsx,.xls,.csv" required>
            <button class="btn btn-primary">Preview Workbook</button>
            <a href="{{ route('asset.fleet.trips.index') }}" class="btn btn-default">Kembali</a>
        </form>
    </div></div>

    @isset($preview)
        <div class="box box-info"><div class="box-header with-border"><h3 class="box-title">Preview Import</h3></div><div class="box-body">
            @php
                $previewRows = collect($preview['rows'] ?? []);
                $logicalDuplicateRows = $previewRows->filter(fn ($row) => collect($row['row_errors'] ?? [])->contains(fn ($error) => str_contains($error, 'Duplikat logis')))->count();
                $sourceDuplicateRows = $previewRows->filter(fn ($row) => collect($row['row_errors'] ?? [])->contains(fn ($error) => str_contains($error, 'fingerprint sumber')))->count();
            @endphp
            <div class="row">
                <div class="col-md-3"><strong>Sheet:</strong> {{ $preview['summary']['sheets'] }}</div>
                <div class="col-md-3"><strong>Baris:</strong> {{ $preview['summary']['source_rows'] }}</div>
                <div class="col-md-3"><strong>Siap:</strong> {{ $preview['summary']['ready_rows'] }}</div>
                <div class="col-md-3"><strong>Bermasalah:</strong> {{ $preview['summary']['error_rows'] }}</div>
            </div>
            @if ($logicalDuplicateRows || $sourceDuplicateRows)
                <div class="alert alert-warning mt-3">
                    <strong>Duplikat terdeteksi:</strong>
                    {{ $logicalDuplicateRows }} baris duplikat antar-sheet dan {{ $sourceDuplicateRows }} baris fingerprint yang sudah pernah diimpor.
                    Baris tersebut tidak akan dibuat menjadi SPB baru.
                </div>
            @endif
            @if (!empty($preview['errors']))
                <div class="alert alert-warning mt-3"><strong>Catatan:</strong><ul>@foreach(array_slice($preview['errors'], 0, 15) as $error)<li>{{ $error }}</li>@endforeach</ul></div>
            @endif
            <form method="POST" id="spb_import_confirm" action="{{ route('asset.fleet.trips.import.confirm') }}">
                @csrf
                <input type="hidden" name="token" value="{{ $token }}">
                <div class="form-group"><label>Rute default untuk baris tanpa asal/tujuan</label><select name="default_route_id" class="form-control"><option value="">Jangan isi otomatis</option>@foreach($routes as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach</select></div>
                <div class="table-responsive fleet-datatable-wrap"><table id="fleet-spb-import-preview-table" class="table table-bordered table-striped table-condensed fleet-datatable"><thead><tr><th>Sumber</th><th>Tiket</th><th>Unit</th><th>Sopir</th><th>Tanggal</th><th>Ton</th><th>Tarif</th><th>Status</th><th>Catatan</th></tr></thead><tbody>
                    @foreach(array_slice($preview['rows'], 0, 100) as $row)
                        <tr><td>{{ $row['source_sheet'] }}:{{ $row['source_row'] }}</td><td>{{ $row['ticket_no'] ?: '-' }}</td><td>{{ $row['asset_label'] ?: '-' }}</td><td>{{ $row['driver_label'] ?: '-' }}</td><td>{{ $row['trip_date'] ?: '-' }}</td><td>{{ $row['actual_tons'] ?: '-' }}</td><td>{{ $row['sale_rate_per_ton'] ?: '-' }}</td><td>{!! empty($row['row_errors']) ? '<span class="label label-success">Siap</span>' : '<span class="label label-warning">Perlu koreksi</span>' !!}</td><td>@if(!empty($row['row_errors']))@php($rowErrorId = 'fleet-spb-import-error-'.$row['source_row'])<button type="button" class="fleet-table-detail-toggle" aria-expanded="false" aria-controls="{{ $rowErrorId }}">Lihat detail</button><div id="{{ $rowErrorId }}" class="fleet-table-detail" hidden>{{ implode(' ', $row['row_errors']) }}</div>@else<span class="text-muted">Tidak ada catatan.</span>@endif</td></tr>
                    @endforeach
                </tbody></table></div>
                <button type="submit" class="btn btn-success" {{ $preview['summary']['ready_rows'] === 0 ? 'disabled' : '' }}>Import Baris Siap</button>
            </form>
        </div></div>
    @endisset
</section>
@push('scripts')
<script>
$(function () {
    initFleetDataTable('#fleet-spb-import-preview-table', {
        pageLength: 25,
        order: [[0, 'asc']],
        columnDefs: [{ targets: [7, 8], orderable: false }]
    });
});
</script>
@endpush
@endsection
