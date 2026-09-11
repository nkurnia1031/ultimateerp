@extends('layouts.app')

@php
    $isEdit = isset($trip);
    $value = fn ($key, $default = null) => old($key, $isEdit ? $trip->{$key} : (request($key) ?: $default));
    $dateValue = fn ($key, $default = null) => old($key, $isEdit && $trip->{$key} ? $trip->{$key}->format('Y-m-d') : $default);
@endphp

@section('title', $isEdit ? 'Edit SPB Angkutan' : 'Buat SPB Angkutan')

@section('content')
@includeIf('assetmanagement::layouts.nav')
<section class="content-header"><h1>{{ $isEdit ? 'Edit SPB Angkutan '.$trip->trip_no : 'Buat SPB Angkutan' }}</h1></section>
<section class="content">
    @if (session('status'))
        @php
            $status = session('status');
        @endphp
        <div class="alert {{ !empty($status['success']) ? 'alert-success' : 'alert-danger' }}" role="alert">{{ $status['msg'] ?? '' }}</div>
    @endif
    @if ($errors->any())
        <div class="alert alert-danger"><ul class="mb-0">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
    @endif
    <form method="POST" action="{{ $isEdit ? route('asset.fleet.trips.update', $trip->id) : route('asset.fleet.trips.store') }}">
        @csrf
        @if($isEdit) @method('PUT') @endif
        @if(!$isEdit && request('return_context'))
            <input type="hidden" name="return_context" value="{{ request('return_context') }}">
            <input type="hidden" name="return_id" value="{{ request('return_id') }}">
        @endif
        <div class="box box-primary"><div class="box-body">
            <div class="row">
                <div class="col-md-3 form-group"><label>Nomor Tiket</label><input type="text" name="ticket_no" value="{{ $value('ticket_no') }}" class="form-control"></div>
                <div class="col-md-3 form-group"><label>Tanggal SPB *</label><input type="date" name="trip_date" value="{{ $dateValue('trip_date', now()->format('Y-m-d')) }}" class="form-control" required></div>
                <div class="col-md-3 form-group"><label>Tanggal Muat</label><input type="date" name="load_date" value="{{ $dateValue('load_date', now()->format('Y-m-d')) }}" class="form-control"></div>
                <div class="col-md-3 form-group"><label>Tanggal Bongkar</label><input type="date" name="unload_date" value="{{ $dateValue('unload_date') }}" class="form-control"></div>
            </div>
            <div class="row">
                <div class="col-md-3 form-group"><label>Rute *</label><select name="route_id" class="form-control" required><option value="">Pilih rute</option>@foreach($routes as $id => $name)<option value="{{ $id }}" @selected($value('route_id') == $id)>{{ $name }}</option>@endforeach</select></div>
                <div class="col-md-3 form-group"><label>Unit/kendaraan *</label><select name="asset_id" class="form-control" required><option value="">Pilih unit</option>@foreach($assets as $id => $name)<option value="{{ $id }}" @selected($value('asset_id') == $id)>{{ $name }}</option>@endforeach</select></div>
                <div class="col-md-3 form-group"><label>Sopir *</label><select name="driver_id" class="form-control" required><option value="">Pilih sopir</option>@foreach($drivers as $id => $name)<option value="{{ $id }}" @selected($value('driver_id') == $id)>{{ $name }}</option>@endforeach</select></div>
                <div class="col-md-3 form-group"><label>Tarif override/Ton</label><input type="number" step="0.01" min="0" name="sale_rate_per_ton" value="{{ $value('sale_rate_per_ton') }}" class="form-control"><small class="text-muted">Kosongkan untuk memakai master tarif.</small></div>
            </div>
            <div class="row">
                <div class="col-md-2 form-group"><label>Gross (Ton)</label><input type="number" step="0.0001" min="0" name="gross_tons" id="gross_tons" value="{{ $value('gross_tons') }}" class="form-control"></div>
                <div class="col-md-2 form-group"><label>Tare (Ton)</label><input type="number" step="0.0001" min="0" name="tare_tons" id="tare_tons" value="{{ $value('tare_tons') }}" class="form-control"></div>
                <div class="col-md-2 form-group"><label>Netto (Ton) *</label><input type="number" step="0.0001" min="0" name="actual_tons" id="actual_tons" value="{{ $value('actual_tons') }}" class="form-control" required></div>
                <div class="col-md-2 form-group"><label>Diterima (Ton)</label><input type="number" step="0.0001" min="0" name="received_tons" value="{{ $value('received_tons') }}" class="form-control"></div>
                <div class="col-md-2 form-group"><label>Ton Tagihan</label><input type="number" step="0.0001" min="0" name="billable_tons" value="{{ $value('billable_tons') }}" class="form-control"></div>
                <div class="col-md-2 form-group"><label>Potongan Lain</label><input type="number" step="0.01" min="0" name="other_deduction_total" value="{{ $value('other_deduction_total', 0) }}" class="form-control"></div>
            </div>
            <div class="row">
                <div class="col-md-3 form-group"><label>Selisih (Kg)</label><input type="number" step="0.01" name="variance_kg" value="{{ $value('variance_kg', 0) }}" class="form-control"></div>
                <div class="col-md-3 form-group"><label>Toleransi (Kg)</label><input type="number" step="0.01" min="0" name="tolerance_kg" value="{{ $value('tolerance_kg', 0) }}" class="form-control"></div>
                <div class="col-md-3 form-group"><label>Tarif Klaim/Kg</label><input type="number" step="0.01" min="0" name="claim_rate_per_kg" value="{{ $value('claim_rate_per_kg', 0) }}" class="form-control"></div>
                <div class="col-md-3 form-group"><label>Alasan override</label><input type="text" name="rate_override_reason" value="{{ $value('rate_override_reason') }}" class="form-control"><small class="text-muted">Wajib jika tarif master tidak tersedia.</small></div>
            </div>
            <div class="row">
                <div class="col-md-4 form-group"><label>Project</label><select name="project_id" class="form-control"><option value="">Tidak terkait Project</option>@foreach($projects as $id => $name)<option value="{{ $id }}" @selected($value('project_id') == $id)>{{ $name }}</option>@endforeach</select></div>
                <div class="col-md-4 form-group"><label>Customer</label><select name="customer_id" class="form-control"><option value="">Ikuti customer Project</option>@foreach($customers as $id => $name)<option value="{{ $id }}" @selected($value('customer_id') == $id)>{{ $name }}</option>@endforeach</select></div>
                <div class="col-md-4 form-group"><label>Catatan</label><textarea name="notes" class="form-control" rows="2">{{ $value('notes') }}</textarea></div>
            </div>
            <button class="btn btn-primary">{{ $isEdit ? 'Simpan Perubahan' : 'Simpan SPB' }}</button>
            <a href="{{ route('asset.fleet.trips.index') }}" class="btn btn-default">Kembali</a>
            <a href="{{ route('asset.fleet.trips.import.form') }}" class="btn btn-warning pull-right">Import Excel SPB</a>
        </div></div>
    </form>
</section>
@push('scripts')
<script>
    $('#gross_tons, #tare_tons').on('input', function () {
        const gross = parseFloat($('#gross_tons').val()) || 0;
        const tare = parseFloat($('#tare_tons').val()) || 0;
        if (gross > 0 && tare >= 0 && gross >= tare && !$('#actual_tons').data('manual')) {
            $('#actual_tons').val((gross - tare).toFixed(4));
        }
    });
    $('#actual_tons').on('input', function () { $(this).data('manual', true); });
</script>
@endpush
@endsection
