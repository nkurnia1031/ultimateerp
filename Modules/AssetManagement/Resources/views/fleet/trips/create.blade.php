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
        <div class="box box-primary spb-form-box">
            <div class="box-header with-border">
                <h3 class="box-title">Data perjalanan</h3>
                <p class="text-muted">Kelompokkan jadwal, armada, tonase, dan nilai dalam urutan pengisian.</p>
            </div>
            <div class="box-body">
                <fieldset class="spb-form-section">
                    <legend>Identitas dan jadwal</legend>
                    <div class="spb-form-grid spb-form-grid--four">
                        <div class="spb-form-field"><label for="spb-ticket-no">Nomor tiket</label><input id="spb-ticket-no" type="text" name="ticket_no" value="{{ $value('ticket_no') }}" class="form-control"></div>
                        <div class="spb-form-field"><label for="spb-trip-date">Tanggal SPB *</label><input id="spb-trip-date" type="date" name="trip_date" value="{{ $dateValue('trip_date', now()->format('Y-m-d')) }}" class="form-control" required></div>
                        <div class="spb-form-field"><label for="spb-load-date">Tanggal muat</label><input id="spb-load-date" type="date" name="load_date" value="{{ $dateValue('load_date', now()->format('Y-m-d')) }}" class="form-control"></div>
                        <div class="spb-form-field"><label for="spb-unload-date">Tanggal bongkar</label><input id="spb-unload-date" type="date" name="unload_date" value="{{ $dateValue('unload_date') }}" class="form-control"></div>
                    </div>
                </fieldset>

                <fieldset class="spb-form-section">
                    <legend>Rute dan armada</legend>
                    <div class="spb-form-grid spb-form-grid--three">
                        <div class="spb-form-field"><label for="spb-route">Rute *</label><select id="spb-route" name="route_id" class="form-control" required><option value="">Pilih rute</option>@foreach($routes as $id => $name)<option value="{{ $id }}" @selected($value('route_id') == $id)>{{ $name }}</option>@endforeach</select></div>
                        <div class="spb-form-field"><label for="spb-asset">Unit / kendaraan *</label><select id="spb-asset" name="asset_id" class="form-control" required><option value="">Pilih unit</option>@foreach($assets as $id => $name)<option value="{{ $id }}" @selected($value('asset_id') == $id)>{{ $name }}</option>@endforeach</select></div>
                        <div class="spb-form-field"><label for="spb-driver">Sopir *</label><select id="spb-driver" name="driver_id" class="form-control" required><option value="">Pilih sopir</option>@foreach($drivers as $id => $name)<option value="{{ $id }}" @selected($value('driver_id') == $id)>{{ $name }}</option>@endforeach</select></div>
                    </div>
                </fieldset>

                <fieldset class="spb-form-section">
                    <legend>Tonase</legend>
                    <div class="spb-form-grid spb-form-grid--three">
                        <div class="spb-form-field"><label for="gross_tons">Gross (ton)</label><input type="number" step="0.0001" min="0" name="gross_tons" id="gross_tons" value="{{ $value('gross_tons') }}" class="form-control"></div>
                        <div class="spb-form-field"><label for="tare_tons">Tare (ton)</label><input type="number" step="0.0001" min="0" name="tare_tons" id="tare_tons" value="{{ $value('tare_tons') }}" class="form-control"></div>
                        <div class="spb-form-field"><label for="actual_tons">Netto (ton) *</label><input type="number" step="0.0001" min="0" name="actual_tons" id="actual_tons" value="{{ $value('actual_tons') }}" class="form-control" required></div>
                        <div class="spb-form-field"><label for="spb-received-tons">Tonase diterima</label><input id="spb-received-tons" type="number" step="0.0001" min="0" name="received_tons" value="{{ $value('received_tons') }}" class="form-control"></div>
                        <div class="spb-form-field"><label for="spb-billable-tons">Tonase tagihan</label><input id="spb-billable-tons" type="number" step="0.0001" min="0" name="billable_tons" value="{{ $value('billable_tons') }}" class="form-control"></div>
                    </div>
                </fieldset>

                <fieldset class="spb-form-section">
                    <legend>Tarif dan potongan</legend>
                    <div class="spb-form-grid spb-form-grid--three">
                        <div class="spb-form-field"><label for="spb-sale-rate">Tarif override per ton</label><input id="spb-sale-rate" type="number" step="0.01" min="0" name="sale_rate_per_ton" value="{{ $value('sale_rate_per_ton') }}" class="form-control"><small class="text-muted">Kosongkan untuk memakai master tarif.</small></div>
                        <div class="spb-form-field"><label for="spb-other-deduction">Potongan lain</label><input id="spb-other-deduction" type="number" step="0.01" min="0" name="other_deduction_total" value="{{ $value('other_deduction_total', 0) }}" class="form-control"></div>
                    </div>
                </fieldset>

                <fieldset class="spb-form-section">
                    <legend>Selisih dan klaim</legend>
                    <div class="spb-form-grid spb-form-grid--four">
                        <div class="spb-form-field"><label for="spb-variance">Selisih (kg)</label><input id="spb-variance" type="number" step="0.01" name="variance_kg" value="{{ $value('variance_kg', 0) }}" class="form-control"></div>
                        <div class="spb-form-field"><label for="spb-tolerance">Toleransi (kg)</label><input id="spb-tolerance" type="number" step="0.01" min="0" name="tolerance_kg" value="{{ $value('tolerance_kg', 0) }}" class="form-control"></div>
                        <div class="spb-form-field"><label for="spb-claim-rate">Tarif klaim per kg</label><input id="spb-claim-rate" type="number" step="0.01" min="0" name="claim_rate_per_kg" value="{{ $value('claim_rate_per_kg', 0) }}" class="form-control"></div>
                        <div class="spb-form-field"><label for="spb-override-reason">Alasan override</label><input id="spb-override-reason" type="text" name="rate_override_reason" value="{{ $value('rate_override_reason') }}" class="form-control"><small class="text-muted">Wajib jika tarif master tidak tersedia.</small></div>
                    </div>
                </fieldset>

                <fieldset class="spb-form-section">
                    <legend>Relasi dan catatan</legend>
                    <div class="spb-form-grid spb-form-grid--two">
                        <div class="spb-form-field"><label for="spb-project">Project</label><select id="spb-project" name="project_id" class="form-control"><option value="">Tidak terkait project</option>@foreach($projects as $id => $name)<option value="{{ $id }}" @selected($value('project_id') == $id)>{{ $name }}</option>@endforeach</select></div>
                        <div class="spb-form-field"><label for="spb-customer">Customer</label><select id="spb-customer" name="customer_id" class="form-control"><option value="">Ikuti customer project</option>@foreach($customers as $id => $name)<option value="{{ $id }}" @selected($value('customer_id') == $id)>{{ $name }}</option>@endforeach</select></div>
                        <div class="spb-form-field spb-form-field--full"><label for="spb-notes">Catatan</label><textarea id="spb-notes" name="notes" class="form-control" rows="3">{{ $value('notes') }}</textarea></div>
                    </div>
                </fieldset>
            </div>
            <div class="box-footer spb-form-actions">
                <button class="btn btn-primary">{{ $isEdit ? 'Simpan Perubahan' : 'Simpan SPB' }}</button>
                <a href="{{ route('asset.fleet.trips.index') }}" class="btn btn-default">Kembali</a>
                <a href="{{ route('asset.fleet.trips.import.form') }}" class="btn btn-warning spb-import-action">Import Excel SPB</a>
            </div>
        </div>
    </form>
</section>
<style>
    .spb-form-box .box-header p { margin: 6px 0 0; }
    .spb-form-section { min-width: 0; margin: 0; padding: 20px 0 2px; border: 0; border-top: 1px solid #e8edf1; }
    .spb-form-section:first-child { padding-top: 0; border-top: 0; }
    .spb-form-section legend { width: auto; margin: 0 0 14px; padding: 0; border: 0; color: #34495e; font-size: 16px; font-weight: 600; }
    .spb-form-grid { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); column-gap: 18px; row-gap: 0; }
    .spb-form-grid--four { grid-template-columns: repeat(4, minmax(0, 1fr)); }
    .spb-form-grid--two { grid-template-columns: repeat(2, minmax(0, 1fr)); }
    .spb-form-field { min-width: 0; margin-bottom: 16px; }
    .spb-form-field label { display: block; margin-bottom: 6px; font-weight: 600; }
    .spb-form-field .form-control { width: 100%; min-height: 38px; }
    .spb-form-field textarea.form-control { min-height: 86px; }
    .spb-form-field--full { grid-column: 1 / -1; }
    .spb-form-actions { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; }
    .spb-import-action { margin-left: auto; }
    @media (max-width: 991px) { .spb-form-grid--four { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
    @media (max-width: 575px) {
        .spb-form-grid, .spb-form-grid--four, .spb-form-grid--two { grid-template-columns: minmax(0, 1fr); }
        .spb-form-field--full { grid-column: auto; }
        .spb-import-action { margin-left: 0; }
    }
</style>
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
