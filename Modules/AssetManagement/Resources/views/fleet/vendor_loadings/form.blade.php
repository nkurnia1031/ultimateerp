@extends('layouts.app')

@php
    $isEdit = isset($loading);
    $value = fn ($key, $default = null) => old($key, $isEdit ? $loading->{$key} : (request($key) ?: $default));
    $dateValue = fn ($key, $default = null) => old($key, $isEdit && $loading->{$key} ? $loading->{$key}->format('Y-m-d') : $default);
@endphp

@section('title', $isEdit ? 'Edit Loading Vendor' : 'Tambah Loading Vendor')

@section('content')
@includeIf('assetmanagement::layouts.nav')
<section class="content-header"><a href="{{ route('asset.fleet.vendor-loadings.index') }}" class="btn btn-default pull-right">Kembali</a><h1><i class="fas fa-truck-loading"></i> {{ $isEdit ? 'Edit Loading Vendor '.$loading->loading_no : 'Tambah Loading Vendor' }}</h1><p class="text-muted">Unit dan sopir vendor disimpan sebagai teks perjalanan, bukan sebagai Asset atau User internal.</p></section>
<section class="content">
    <div id="vendor-loading-form-feedback" class="alert" role="alert" hidden></div>
    @if ($errors->any())<div class="alert alert-danger"><ul>@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
    <form id="vendor-loading-form" method="POST" action="{{ $isEdit ? route('asset.fleet.vendor-loadings.update', $loading->id) : route('asset.fleet.vendor-loadings.store') }}">
        @csrf
        @if($isEdit)<input type="hidden" name="_method" value="PUT">@endif
        <div class="box box-primary"><div class="box-header with-border"><h3 class="box-title">Informasi Loading</h3></div><div class="box-body">
            <div class="row">
                <div class="col-md-3 form-group"><label>Nomor Loading</label><input name="loading_no" class="form-control" value="{{ $value('loading_no') }}" placeholder="Otomatis jika kosong"></div>
                <div class="col-md-3 form-group"><label>Nomor Tiket</label><input name="ticket_no" class="form-control" value="{{ $value('ticket_no') }}"></div>
                <div class="col-md-3 form-group"><label>Tanggal Loading *</label><input type="date" name="loading_date" class="form-control" value="{{ $dateValue('loading_date', now()->format('Y-m-d')) }}" required></div>
                <div class="col-md-3 form-group"><label>Tanggal Muat</label><input type="date" name="load_date" class="form-control" value="{{ $dateValue('load_date', now()->format('Y-m-d')) }}"></div>
            </div>
            <div class="row">
                <div class="col-md-3 form-group"><label>Tanggal Bongkar</label><input type="date" name="unload_date" class="form-control" value="{{ $dateValue('unload_date') }}"></div>
                @php
                    $selectedSupplierId = $value('supplier_id');
                    $selectedSupplier = $isEdit && $loading->supplier_id == $selectedSupplierId ? $loading->supplier : null;
                    $selectedSupplierLabel = $selectedSupplier ? \App\Contact::supplierDisplayName($selectedSupplier, true) : null;
                @endphp
                <div class="col-md-3 form-group"><label>Bendera / Supplier</label><select name="supplier_id" id="vendor_supplier_id" class="form-control"><option value="">Belum dipilih</option>@if($selectedSupplierId && $selectedSupplierLabel)<option value="{{ $selectedSupplierId }}" selected>{{ $selectedSupplierLabel }}</option>@endif</select><small class="text-muted">Cari supplier dari business aktif. Wajib sebelum verifikasi.</small>@if(auth()->user()->can('superadmin') || auth()->user()->can('supplier.create'))<button type="button" class="btn btn-link btn-xs vendor-add-supplier" data-toggle="modal" data-target="#vendor-minimal-supplier-modal"><i class="fas fa-plus"></i> Tambah supplier minimal</button>@endif</div>
                <div class="col-md-3 form-group"><label>Rute</label><select name="route_id" class="form-control"><option value="">Tidak terkait rute master</option>@foreach($routes as $id => $name)<option value="{{ $id }}" @selected($value('route_id') == $id)>{{ $name }}</option>@endforeach</select></div>
                <div class="col-md-3 form-group"><label>Project</label><select name="project_id" class="form-control"><option value="">Tidak terkait Project</option>@foreach($projects as $id => $name)<option value="{{ $id }}" @selected($value('project_id') == $id)>{{ $name }}</option>@endforeach</select></div>
            </div>
            <div class="row">
                <div class="col-md-3 form-group"><label>Customer</label><select name="customer_id" class="form-control"><option value="">Tidak terkait Customer</option>@foreach($customers as $id => $name)<option value="{{ $id }}" @selected($value('customer_id') == $id)>{{ $name }}</option>@endforeach</select></div>
                @php
                    $unitOptions = $vendorUnits->values();
                    $driverOptions = $vendorDrivers->values();
                    if ($isEdit && $loading->vendor_unit_name && ! $unitOptions->contains($loading->vendor_unit_name)) {
                        $unitOptions->prepend($loading->vendor_unit_name);
                    }
                    if ($isEdit && $loading->vendor_driver_name && ! $driverOptions->contains($loading->vendor_driver_name)) {
                        $driverOptions->prepend($loading->vendor_driver_name);
                    }
                @endphp
                <div class="col-md-3 form-group"><label>Unit Vendor</label><select name="vendor_unit_name" class="form-control vendor-tag-select" data-placeholder="Ketik unit vendor">@foreach($unitOptions as $unit)<option value="{{ $unit }}" @selected($value('vendor_unit_name') == $unit)>{{ $unit }}</option>@endforeach</select><small class="text-muted">Ketik nama unit lalu tekan Enter.</small></div>
                <div class="col-md-3 form-group"><label>Sopir Vendor</label><select name="vendor_driver_name" class="form-control vendor-tag-select" data-placeholder="Ketik nama sopir vendor">@foreach($driverOptions as $driver)<option value="{{ $driver }}" @selected($value('vendor_driver_name') == $driver)>{{ $driver }}</option>@endforeach</select><small class="text-muted">Ketik nama sopir lalu tekan Enter.</small></div>
                <div class="col-md-3 form-group"><label>Kode Unit / Kode Sopir</label><div class="row"><div class="col-xs-6"><input name="vendor_unit_code" class="form-control" placeholder="Kode unit" value="{{ $value('vendor_unit_code') }}"></div><div class="col-xs-6"><input name="vendor_driver_code" class="form-control" placeholder="Kode sopir" value="{{ $value('vendor_driver_code') }}"></div></div></div>
            </div>
        </div></div>
        <div class="box box-info"><div class="box-header with-border"><h3 class="box-title">Tonase dan Nilai Biaya Vendor</h3></div><div class="box-body">
            <div class="row">
                <div class="col-md-2 form-group"><label>Gross (Ton)</label><input type="number" step="0.0001" min="0" id="vendor_gross_tons" name="gross_tons" class="form-control" value="{{ $value('gross_tons') }}"></div>
                <div class="col-md-2 form-group"><label>Tare (Ton)</label><input type="number" step="0.0001" min="0" id="vendor_tare_tons" name="tare_tons" class="form-control" value="{{ $value('tare_tons') }}"></div>
                <div class="col-md-2 form-group"><label>Netto (Ton)</label><input type="number" step="0.0001" min="0" id="vendor_net_tons" name="net_tons" class="form-control" value="{{ $value('net_tons') }}"><small class="text-muted">Otomatis dari Gross - Tare.</small></div>
                <div class="col-md-2 form-group"><label>Diterima (Ton)</label><input type="number" step="0.0001" min="0" name="received_tons" class="form-control" value="{{ $value('received_tons') }}"></div>
                <div class="col-md-2 form-group"><label>Tonase Tagihan</label><input type="number" step="0.0001" min="0" id="vendor_billable_tons" name="billable_tons" class="form-control" value="{{ $value('billable_tons') }}"></div>
                <div class="col-md-2 form-group"><label>Tarif Vendor/Ton</label><input type="number" step="0.01" min="0" id="vendor_rate_per_ton" name="vendor_rate_per_ton" class="form-control" value="{{ $value('vendor_rate_per_ton') }}"></div>
            </div>
            <div class="row">
                <div class="col-md-3 form-group"><label>Penyesuaian Klaim</label><input type="number" step="0.01" name="claim_adjustment" id="vendor_claim_adjustment" class="form-control" value="{{ $value('claim_adjustment', 0) }}"></div>
                <div class="col-md-3 form-group"><label>Potongan Lain</label><input type="number" step="0.01" min="0" name="other_deduction" id="vendor_other_deduction" class="form-control" value="{{ $value('other_deduction', 0) }}"></div>
                <div class="col-md-3 form-group"><label>Nilai Dasar</label><input type="text" id="vendor_base_amount_preview" class="form-control" readonly></div>
                <div class="col-md-3 form-group"><label>Nilai Akhir Vendor</label><input type="text" id="vendor_total_amount_preview" class="form-control" readonly></div>
            </div>
            <div class="form-group"><label>Catatan</label><textarea name="notes" class="form-control" rows="3">{{ $value('notes') }}</textarea></div>
            <div class="alert alert-info"><strong>Catatan:</strong> Loading Vendor akan tersimpan sebagai biaya vendor. Belum dibuat menjadi Expense, invoice, atau payroll otomatis.</div>
            <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> {{ $isEdit ? 'Simpan Perubahan' : 'Simpan Loading Vendor' }}</button>
            <a href="{{ route('asset.fleet.vendor-loadings.index') }}" class="btn btn-default">Batal</a>
        </div></div>
    </form>
    @if(auth()->user()->can('superadmin') || auth()->user()->can('supplier.create'))
    <div class="modal fade" id="vendor-minimal-supplier-modal" tabindex="-1" role="dialog" aria-labelledby="vendor-minimal-supplier-title">
        <div class="modal-dialog" role="document"><div class="modal-content">
            <div class="modal-header"><button type="button" class="close" data-dismiss="modal" aria-label="Tutup"><span aria-hidden="true">&times;</span></button><h4 class="modal-title" id="vendor-minimal-supplier-title">Tambah Supplier Minimal</h4></div>
            <div class="modal-body"><div id="vendor-minimal-supplier-feedback" class="alert" hidden></div><label for="vendor-minimal-supplier-name">Nama supplier / bendera</label><input type="text" id="vendor-minimal-supplier-name" class="form-control" maxlength="191" placeholder="Contoh: PT Vendor Angkutan"><small class="text-muted">Data minimal ini dapat dilengkapi nanti dari menu Contacts.</small></div>
            <div class="modal-footer"><button type="button" class="btn btn-default" data-dismiss="modal">Batal</button><button type="button" class="btn btn-primary" id="vendor-minimal-supplier-save">Simpan Supplier</button></div>
        </div></div>
    </div>
    @endif
</section>
@push('styles')
<style>
    .select2-container { width: 100% !important; }
    #vendor_base_amount_preview, #vendor_total_amount_preview { font-weight: 600; background: #f7fafb; }
</style>
@endpush
@push('scripts')
<script>
$(function () {
    if ($.fn.select2) {
        $('#vendor_supplier_id').select2({
            width: '100%',
            placeholder: 'Pilih supplier',
            allowClear: true,
            ajax: {
                url: @json(route('asset.fleet.vendor-loadings.suppliers')),
                dataType: 'json',
                delay: 250,
                data: function (params) { return { term: params.term || '' }; },
                processResults: function (data) { return data; },
                cache: true
            },
            minimumInputLength: 0
        });
        $('.vendor-tag-select').select2({
            tags: true,
            width: '100%',
            placeholder: function () { return $(this).data('placeholder') || 'Ketik lalu tekan Enter'; },
            allowClear: true,
            minimumResultsForSearch: 0,
            createTag: function (params) {
                var term = $.trim(params.term || '');
                return term ? { id: term, text: term, newTag: true } : null;
            }
        });
    }
    function money(value) { return 'Rp ' + (Number(value) || 0).toLocaleString('id-ID', { maximumFractionDigits: 0 }); }
    function calculateVendor() {
        var gross = parseFloat($('#vendor_gross_tons').val()) || 0, tare = parseFloat($('#vendor_tare_tons').val()) || 0;
        if (gross > 0 && tare >= 0 && gross >= tare && !$('#vendor_net_tons').data('manual')) $('#vendor_net_tons').val((gross - tare).toFixed(4));
        var net = parseFloat($('#vendor_net_tons').val()) || 0, received = parseFloat($('[name="received_tons"]').val()) || 0;
        if (!parseFloat($('#vendor_billable_tons').val())) $('#vendor_billable_tons').val((received > 0 ? received : net).toFixed(4));
        var billable = parseFloat($('#vendor_billable_tons').val()) || 0, rate = parseFloat($('#vendor_rate_per_ton').val());
        var base = Number.isFinite(rate) ? billable * rate : 0;
        var total = base - (parseFloat($('#vendor_other_deduction').val()) || 0) + (parseFloat($('#vendor_claim_adjustment').val()) || 0);
        $('#vendor_base_amount_preview').val(rate ? money(base) : '-'); $('#vendor_total_amount_preview').val(rate ? money(total) : '-');
    }
    $('#vendor_gross_tons, #vendor_tare_tons').on('input', calculateVendor);
    $('#vendor_net_tons').on('input', function () { $(this).data('manual', true); calculateVendor(); });
    $('#vendor_billable_tons, #vendor_rate_per_ton, #vendor_claim_adjustment, #vendor_other_deduction, [name="received_tons"]').on('input', calculateVendor);
    calculateVendor();
    $('#vendor-loading-form').on('submit', function (event) {
        event.preventDefault();
        var $form = $(this), $feedback = $('#vendor-loading-form-feedback'), $button = $form.find('[type="submit"]');
        $button.prop('disabled', true);
        $.ajax({ url: $form.attr('action'), type: 'POST', data: $form.serialize(), headers: { Accept: 'application/json' } })
            .done(function (response) { $feedback.removeAttr('hidden').removeClass('alert-danger').addClass('alert-success').text(response.msg || 'Tersimpan.'); window.location.href = response.redirect || @json(route('asset.fleet.vendor-loadings.index')); })
            .fail(function (xhr) { var body = xhr.responseJSON || {}; $feedback.removeAttr('hidden').removeClass('alert-success').addClass('alert-danger').text(body.msg || body.message || 'Data belum tersimpan.'); $button.prop('disabled', false); });
    });
    $('#vendor-minimal-supplier-save').on('click', function () {
        var $button = $(this), $name = $('#vendor-minimal-supplier-name'), $modalFeedback = $('#vendor-minimal-supplier-feedback'), name = $.trim($name.val() || '');
        if (!name) { $modalFeedback.removeAttr('hidden').removeClass('alert-success').addClass('alert-danger').text('Nama supplier wajib diisi.'); return; }
        $button.prop('disabled', true);
        $.ajax({ url: @json(route('asset.fleet.vendor-loadings.suppliers.minimal')), type: 'POST', data: { _token: $('meta[name="csrf-token"]').attr('content'), name: name }, headers: { Accept: 'application/json' } })
            .done(function (response) {
                var supplier = response.supplier;
                if (supplier) {
                    var option = new Option(supplier.text, supplier.id, true, true);
                    $('#vendor_supplier_id').append(option).trigger('change');
                }
                $('#vendor-minimal-supplier-modal').modal('hide');
                $name.val('');
                $modalFeedback.attr('hidden', true);
            })
            .fail(function (xhr) { var body = xhr.responseJSON || {}; $modalFeedback.removeAttr('hidden').removeClass('alert-success').addClass('alert-danger').text(body.msg || body.message || 'Supplier belum dapat dibuat.'); })
            .always(function () { $button.prop('disabled', false); });
    });
});
</script>
@endpush
@endsection
