@extends('layouts.app')

@php
    $isEdit = isset($loading);
    $isDocumentLine = isset($loadingDocument);
    $value = fn ($key, $default = null) => old($key, $isEdit ? $loading->{$key} : (request($key) ?: $default));
    $dateValue = fn ($key, $default = null) => old($key, $isEdit && $loading->{$key} ? $loading->{$key}->format('Y-m-d') : $default);
@endphp

@section('title', $isEdit ? 'Edit Loading Vendor' : ($isDocumentLine ? 'Tambah Tiket Angkutan' : 'Tambah Loading Vendor'))

@section('content')
@includeIf('assetmanagement::layouts.nav')
<section class="content-header"><a href="{{ $isDocumentLine ? route('asset.fleet.vendor-loadings.documents.show', $loadingDocument->id) : route('asset.fleet.vendor-loadings.index') }}" class="btn btn-default pull-right">Kembali</a><h1><i class="fas fa-truck-loading"></i> {{ $isEdit ? 'Edit Loading Vendor '.$loading->loading_no : ($isDocumentLine ? 'Tambah Tiket ke '.$loadingDocument->document_no : 'Tambah Dokumen Loading Vendor') }}</h1><p class="text-muted">Unit dan sopir vendor disimpan sebagai teks perjalanan, bukan sebagai Asset atau User internal.</p></section>
<section class="content">
    <div id="vendor-loading-form-feedback" class="alert" role="alert" hidden></div>
    @if ($errors->any())<div class="alert alert-danger"><ul>@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
    @if($isDocumentLine)<div class="alert alert-info"><strong>Dokumen:</strong> {{ $loadingDocument->document_no }} · <strong>Sheet:</strong> {{ $loadingDocument->source_sheet ?: 'Manual' }} · <strong>Bendera:</strong> {{ $loadingDocument->supplier_label }} · <strong>Customer:</strong> {{ $loadingDocument->customer_label }}. Identitas ini mengikuti header dokumen.</div>@endif
    <form id="vendor-loading-form" method="POST" action="{{ $isEdit ? route('asset.fleet.vendor-loadings.update', $loading->id) : ($isDocumentLine ? route('asset.fleet.vendor-loadings.documents.lines.store', $loadingDocument->id) : route('asset.fleet.vendor-loadings.store')) }}">
        @csrf
        @if($isEdit)<input type="hidden" name="_method" value="PUT">@endif
        <div class="box box-primary vendor-form-box">
            <div class="box-header with-border">
                <h3 class="box-title">Data {{ $isDocumentLine ? 'tiket angkutan' : 'loading vendor' }}</h3>
                <p class="text-muted">Isi identitas perjalanan terlebih dahulu, lalu lengkapi unit, tonase, dan nilai vendor.</p>
            </div>
            <div class="box-body">
                <fieldset class="vendor-form-section">
                    <legend>Jadwal dan nomor tiket</legend>
                    <div class="vendor-form-grid vendor-form-grid--four">
                        @unless($isDocumentLine)<div class="vendor-form-field"><label for="vendor-loading-no">Nomor loading</label><input id="vendor-loading-no" name="loading_no" class="form-control" value="{{ $value('loading_no') }}" placeholder="Otomatis jika kosong"></div>@endunless
                        <div class="vendor-form-field"><label for="vendor-ticket-no">Nomor tiket</label><input id="vendor-ticket-no" name="ticket_no" class="form-control" value="{{ $value('ticket_no') }}"></div>
                        <div class="vendor-form-field"><label for="vendor-loading-date">Tanggal loading *</label><input id="vendor-loading-date" type="date" name="loading_date" class="form-control" value="{{ $dateValue('loading_date', now()->format('Y-m-d')) }}" required></div>
                        <div class="vendor-form-field"><label for="vendor-load-date">Tanggal muat</label><input id="vendor-load-date" type="date" name="load_date" class="form-control" value="{{ $dateValue('load_date') }}"></div>
                        <div class="vendor-form-field"><label for="vendor-unload-date">Tanggal bongkar</label><input id="vendor-unload-date" type="date" name="unload_date" class="form-control" value="{{ $dateValue('unload_date') }}"></div>
                    </div>
                </fieldset>

                @unless($isDocumentLine)
                <fieldset class="vendor-form-section">
                    <legend>Barang dan tujuan</legend>
                    <div class="vendor-form-grid vendor-form-grid--four">
                        <div class="vendor-form-field"><label for="vendor-goods-name">Nama barang</label><input id="vendor-goods-name" name="goods_name" class="form-control" maxlength="191" value="{{ $value('goods_name') }}" placeholder="Contoh: Loading Cangkang"></div>
                        <div class="vendor-form-field"><label for="vendor-goods-type">Jenis barang</label><input id="vendor-goods-type" name="goods_type" class="form-control" maxlength="191" value="{{ $value('goods_type') }}" placeholder="Opsional"></div>
                        <div class="vendor-form-field"><label for="vendor-vessel-name">Nama kapal</label><input id="vendor-vessel-name" name="vessel_name" class="form-control" maxlength="191" value="{{ $value('vessel_name') }}" placeholder="Contoh: MV. Bright King"></div>
                        <div class="vendor-form-field"><label for="vendor-destination">Tujuan</label><input id="vendor-destination" name="destination" class="form-control" maxlength="191" value="{{ $value('destination') }}" placeholder="Contoh: Pelabuhan Pelindo"></div>
                    </div>
                </fieldset>
                @endunless

                <fieldset class="vendor-form-section">
                    <legend>Pihak dan relasi</legend>
                    <div class="vendor-form-grid vendor-form-grid--three">
                        @unless($isDocumentLine)
                        @php
                            $selectedSupplierId = $value('supplier_id');
                            $selectedSupplier = $isEdit && $loading->supplier_id == $selectedSupplierId ? $loading->supplier : null;
                            $selectedSupplierLabel = $selectedSupplier ? \App\Contact::supplierDisplayName($selectedSupplier, true) : null;
                        @endphp
                        <div class="vendor-form-field"><label for="vendor_supplier_id">Bendera / supplier</label><select name="supplier_id" id="vendor_supplier_id" class="form-control"><option value="">Belum dipilih</option>@if($selectedSupplierId && $selectedSupplierLabel)<option value="{{ $selectedSupplierId }}" selected>{{ $selectedSupplierLabel }}</option>@endif</select><small class="text-muted">Cari supplier dari business aktif.</small>@if(auth()->user()->can('superadmin') || auth()->user()->can('supplier.create'))<button type="button" class="btn btn-link btn-xs vendor-add-supplier" data-toggle="modal" data-target="#vendor-minimal-supplier-modal"><i class="fas fa-plus"></i> Tambah supplier minimal</button>@endif</div>
                        <div class="vendor-form-field"><label for="vendor-customer">Customer</label><select id="vendor-customer" name="customer_id" class="form-control"><option value="">Tidak terkait customer</option>@foreach($customers as $id => $name)<option value="{{ $id }}" @selected($value('customer_id') == $id)>{{ $name }}</option>@endforeach</select></div>
                        @endunless
                        <div class="vendor-form-field"><label for="vendor-project">Project</label><select id="vendor-project" name="project_id" class="form-control"><option value="">Tidak terkait project</option>@foreach($projects as $id => $name)<option value="{{ $id }}" @selected($value('project_id') == $id)>{{ $name }}</option>@endforeach</select></div>
                    </div>
                </fieldset>

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
                <fieldset class="vendor-form-section">
                    <legend>Unit dan sopir vendor</legend>
                    <div class="vendor-form-grid vendor-form-grid--two">
                        <div class="vendor-form-field"><label for="vendor-unit-name">Nama unit vendor</label><select id="vendor-unit-name" name="vendor_unit_name" class="form-control vendor-tag-select" data-placeholder="Ketik unit vendor">@foreach($unitOptions as $unit)<option value="{{ $unit }}" @selected($value('vendor_unit_name') == $unit)>{{ $unit }}</option>@endforeach</select><small class="text-muted">Ketik nama unit lalu tekan Enter.</small></div>
                        <div class="vendor-form-field"><label for="vendor-driver-name">Nama sopir vendor</label><select id="vendor-driver-name" name="vendor_driver_name" class="form-control vendor-tag-select" data-placeholder="Ketik nama sopir vendor">@foreach($driverOptions as $driver)<option value="{{ $driver }}" @selected($value('vendor_driver_name') == $driver)>{{ $driver }}</option>@endforeach</select><small class="text-muted">Ketik nama sopir lalu tekan Enter.</small></div>
                        <div class="vendor-form-field"><label for="vendor-unit-code">Kode unit</label><input id="vendor-unit-code" name="vendor_unit_code" class="form-control" placeholder="Kode unit" value="{{ $value('vendor_unit_code') }}"></div>
                        <div class="vendor-form-field"><label for="vendor-driver-code">Kode sopir</label><input id="vendor-driver-code" name="vendor_driver_code" class="form-control" placeholder="Kode sopir" value="{{ $value('vendor_driver_code') }}"></div>
                    </div>
                </fieldset>

                <fieldset class="vendor-form-section">
                    <legend>Tonase perjalanan</legend>
                    <div class="vendor-form-grid vendor-form-grid--three">
                        <div class="vendor-form-field"><label for="vendor_gross_tons">Gross (ton)</label><input type="number" step="0.0001" min="0" id="vendor_gross_tons" name="gross_tons" class="form-control" value="{{ $value('gross_tons') }}"></div>
                        <div class="vendor-form-field"><label for="vendor_tare_tons">Tare (ton)</label><input type="number" step="0.0001" min="0" id="vendor_tare_tons" name="tare_tons" class="form-control" value="{{ $value('tare_tons') }}"></div>
                        <div class="vendor-form-field"><label for="vendor_net_tons">Netto (ton)</label><input type="number" step="0.0001" min="0" id="vendor_net_tons" name="net_tons" class="form-control" value="{{ $value('net_tons') }}" readonly><small class="text-muted">Dihitung dari gross dikurangi tare.</small></div>
                        <div class="vendor-form-field"><label for="vendor-received-tons">Tonase diterima</label><input id="vendor-received-tons" type="number" step="0.0001" min="0" name="received_tons" class="form-control" value="{{ $value('received_tons') }}"></div>
                        <div class="vendor-form-field"><label for="vendor_billable_tons">Tonase tagihan</label><input type="number" step="0.0001" min="0" id="vendor_billable_tons" name="billable_tons" class="form-control" value="{{ $value('billable_tons') }}"></div>
                    </div>
                </fieldset>

                <fieldset class="vendor-form-section">
                    <legend>Tarif dan nilai biaya</legend>
                    <div class="vendor-form-grid vendor-form-grid--three">
                        <div class="vendor-form-field"><label for="vendor_rate_per_ton">Tarif vendor per ton (Rp)</label><input type="number" step="0.01" min="0" id="vendor_rate_per_ton" name="vendor_rate_per_ton" class="form-control" value="{{ $value('vendor_rate_per_ton') }}"></div>
                        <div class="vendor-form-field"><label for="vendor_claim_adjustment">Penyesuaian klaim (Rp)</label><input type="number" step="0.01" name="claim_adjustment" id="vendor_claim_adjustment" class="form-control" value="{{ $value('claim_adjustment', 0) }}"></div>
                        <div class="vendor-form-field"><label for="vendor_other_deduction">Potongan lain (Rp)</label><input type="number" step="0.01" min="0" name="other_deduction" id="vendor_other_deduction" class="form-control" value="{{ $value('other_deduction', 0) }}"></div>
                        <div class="vendor-form-field"><label for="vendor_base_amount_preview">Nilai dasar (Rp)</label><input type="text" id="vendor_base_amount_preview" class="form-control" readonly></div>
                        <div class="vendor-form-field"><label for="vendor_total_amount_preview">Nilai akhir vendor (Rp)</label><input type="text" id="vendor_total_amount_preview" class="form-control" readonly></div>
                    </div>
                    <p class="text-muted vendor-form-hint">Nilai dihitung dari tonase tagihan, tarif, potongan, dan klaim.</p>
                </fieldset>

                <fieldset class="vendor-form-section">
                    <legend>Catatan</legend>
                    <div class="vendor-form-field"><label for="vendor-notes">Keterangan tambahan</label><textarea id="vendor-notes" name="notes" class="form-control" rows="3">{{ $value('notes') }}</textarea></div>
                </fieldset>
            </div>
            <div class="box-footer vendor-form-actions">
                <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> {{ $isEdit ? 'Simpan Perubahan' : ($isDocumentLine ? 'Tambahkan Tiket' : 'Simpan Loading Vendor') }}</button>
                <a href="{{ $isDocumentLine ? route('asset.fleet.vendor-loadings.documents.show', $loadingDocument->id) : route('asset.fleet.vendor-loadings.index') }}" class="btn btn-default">Batal</a>
            </div>
        </div>
    </form>
    @if(!$isDocumentLine && (auth()->user()->can('superadmin') || auth()->user()->can('supplier.create')))
    <div class="modal fade" id="vendor-minimal-supplier-modal" tabindex="-1" role="dialog" aria-labelledby="vendor-minimal-supplier-title">
        <div class="modal-dialog" role="document"><div class="modal-content">
            <div class="modal-header"><button type="button" class="close" data-dismiss="modal" aria-label="Tutup"><span aria-hidden="true">&times;</span></button><h4 class="modal-title" id="vendor-minimal-supplier-title">Tambah Supplier Minimal</h4></div>
            <div class="modal-body"><div id="vendor-minimal-supplier-feedback" class="alert" hidden></div><label for="vendor-minimal-supplier-name">Nama supplier / bendera</label><input type="text" id="vendor-minimal-supplier-name" class="form-control" maxlength="191" placeholder="Contoh: PT Vendor Angkutan"><small class="text-muted">Data minimal ini dapat dilengkapi nanti dari menu Contacts.</small></div>
            <div class="modal-footer"><button type="button" class="btn btn-default" data-dismiss="modal">Batal</button><button type="button" class="btn btn-primary" id="vendor-minimal-supplier-save">Simpan Supplier</button></div>
        </div></div>
    </div>
    @endif
</section>
<style>
    .select2-container { width: 100% !important; }
    .vendor-form-box .box-header p { margin: 6px 0 0; }
    .vendor-form-section { min-width: 0; margin: 0; padding: 20px 0 2px; border: 0; border-top: 1px solid #e8edf1; }
    .vendor-form-section:first-child { padding-top: 0; border-top: 0; }
    .vendor-form-section legend { width: auto; margin: 0 0 14px; padding: 0; border: 0; color: #34495e; font-size: 16px; font-weight: 600; }
    .vendor-form-grid { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); column-gap: 18px; row-gap: 0; }
    .vendor-form-grid--four { grid-template-columns: repeat(4, minmax(0, 1fr)); }
    .vendor-form-grid--two { grid-template-columns: repeat(2, minmax(0, 1fr)); }
    .vendor-form-field { min-width: 0; margin-bottom: 16px; }
    .vendor-form-field label { display: block; margin-bottom: 6px; font-weight: 600; }
    .vendor-form-field .form-control { width: 100%; min-height: 38px; }
    .vendor-form-field textarea.form-control { min-height: 86px; }
    #vendor_base_amount_preview, #vendor_total_amount_preview { font-weight: 600; background: #f7fafb; }
    .vendor-form-hint { margin: -2px 0 8px; }
    .vendor-form-actions { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; }
    @media (max-width: 991px) { .vendor-form-grid--four { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
    @media (max-width: 575px) { .vendor-form-grid, .vendor-form-grid--four, .vendor-form-grid--two { grid-template-columns: minmax(0, 1fr); } }
</style>
@push('scripts')
<script>
$(function () {
    function notify(success, text, $fallback) {
        var message = text || 'Aksi tidak dapat diselesaikan.';
        if (window.toastr && typeof window.toastr[success ? 'success' : 'error'] === 'function') { window.toastr[success ? 'success' : 'error'](message); return; }
        if ($fallback && $fallback.length) $fallback.removeAttr('hidden').removeClass('alert-success alert-danger').addClass(success ? 'alert-success' : 'alert-danger').text(message);
    }
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
            .done(function (response) { notify(true, response.msg || 'Line angkutan tersimpan.', $feedback); window.setTimeout(function () { window.location.href = response.redirect || @json(route('asset.fleet.vendor-loadings.index')); }, 500); })
            .fail(function (xhr) { var body = xhr.responseJSON || {}; notify(false, body.msg || body.message || 'Data belum tersimpan.', $feedback); $button.prop('disabled', false); });
    });
    $('#vendor-minimal-supplier-save').on('click', function () {
        var $button = $(this), $name = $('#vendor-minimal-supplier-name'), $modalFeedback = $('#vendor-minimal-supplier-feedback'), name = $.trim($name.val() || '');
        if (!name) { notify(false, 'Nama supplier wajib diisi.', $modalFeedback); return; }
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
                notify(true, 'Supplier berhasil dibuat.');
            })
            .fail(function (xhr) { var body = xhr.responseJSON || {}; notify(false, body.msg || body.message || 'Supplier belum dapat dibuat.', $modalFeedback); })
            .always(function () { $button.prop('disabled', false); });
    });
});
</script>
@endpush
@endsection
