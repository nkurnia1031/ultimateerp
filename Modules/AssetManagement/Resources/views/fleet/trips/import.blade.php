@extends('layouts.app')

@section('title', 'Import SPB Angkutan')

@section('content')
@includeIf('assetmanagement::layouts.nav')
@include('layouts.partials.fleet-datatables')
<section class="content-header"><h1><i class="fas fa-file-import"></i> Import SPB Angkutan</h1></section>
<section class="content spb-import-page">
    @if (session('status'))
        @php($status = session('status'))
        <div class="alert {{ !empty($status['success']) ? 'alert-success' : 'alert-danger' }}">{{ $status['msg'] ?? '' }}</div>
    @endif

    <div class="box box-primary">
        <div class="box-header with-border"><h3 class="box-title">Pilih workbook</h3></div>
        <div class="box-body">
            <p class="text-muted">Unggah workbook rekap loading atau rekonsiliasi cangkang. Periksa seluruh baris, lalu koreksi langsung dari preview sebelum import.</p>
            <form method="POST" action="{{ route('asset.fleet.trips.import.preview') }}" enctype="multipart/form-data" class="spb-import-upload-form">
                @csrf
                <div class="form-group">
                    <label for="spb-import-file">File Excel atau CSV</label>
                    <input id="spb-import-file" type="file" name="file" class="form-control" accept=".xlsx,.xls,.csv" required>
                </div>
                <button class="btn btn-primary" type="submit"><i class="fas fa-search"></i> Preview Workbook</button>
                <a href="{{ route('asset.fleet.trips.index') }}" class="btn btn-default">Kembali</a>
            </form>
        </div>
    </div>

    @if (!empty($preview))
        <div class="box box-info" id="spb-import-preview">
            <div class="box-header with-border"><h3 class="box-title">Preview Import</h3></div>
            <div class="box-body">
                <div class="spb-import-summary" aria-live="polite">
                    <div class="spb-import-summary-card"><span>Sheet</span><strong id="spb-summary-sheets">{{ $preview['summary']['sheets'] }}</strong></div>
                    <div class="spb-import-summary-card"><span>Baris sumber</span><strong id="spb-summary-source">{{ $preview['summary']['source_rows'] }}</strong></div>
                    <div class="spb-import-summary-card spb-import-summary-ready"><span>Siap diimpor</span><strong id="spb-summary-ready">{{ $preview['summary']['ready_rows'] }}</strong></div>
                    <div class="spb-import-summary-card spb-import-summary-error"><span>Perlu koreksi</span><strong id="spb-summary-errors">{{ $preview['summary']['error_rows'] }}</strong></div>
                </div>

                @if (!empty($preview['errors']))
                    <div class="alert alert-warning" id="spb-import-error-summary">
                        <strong>Baris yang memerlukan perhatian</strong>
                        <ul id="spb-import-error-list">@foreach(array_slice($preview['errors'], 0, 15) as $error)<li>{{ $error }}</li>@endforeach</ul>
                        @if (count($preview['errors']) > 15)<p class="text-muted">Daftar lengkap tersedia melalui kolom Catatan pada tiap baris.</p>@endif
                    </div>
                @else
                    <div class="alert alert-success" id="spb-import-error-summary">Semua baris valid dan siap diimpor.</div>
                @endif

                <form method="POST" id="spb_import_confirm" action="{{ route('asset.fleet.trips.import.confirm') }}">
                    @csrf
                    <input type="hidden" name="token" value="{{ $token }}">
                    <div class="form-group spb-default-route">
                        <label for="spb-default-route">Rute default</label>
                        <select id="spb-default-route" name="default_route_id" class="form-control">
                            <option value="">Jangan isi otomatis</option>
                            @foreach($routes as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach
                        </select>
                        <p class="help-block">Dipakai hanya untuk baris yang belum memiliki nama rute.</p>
                    </div>

                    <div class="table-responsive fleet-datatable-wrap">
                        <table id="fleet-spb-import-preview-table" class="table table-bordered table-striped table-condensed fleet-datatable" style="width:100%">
                            <thead><tr><th>Sumber</th><th>Tiket</th><th>Unit</th><th>Sopir</th><th>Tanggal</th><th>Ton aktual</th><th>Tarif/ton</th><th>Status</th><th>Catatan</th><th>Aksi</th></tr></thead>
                            <tbody></tbody>
                        </table>
                    </div>
                    <button id="spb-import-submit" type="submit" class="btn btn-success" {{ $preview['summary']['ready_rows'] === 0 ? 'disabled' : '' }}>
                        <i class="fas fa-check"></i> Import <span id="spb-import-submit-count">{{ $preview['summary']['ready_rows'] }}</span> baris siap
                    </button>
                </form>
            </div>
        </div>

        <div class="modal fade spb-import-modal" id="spb-import-edit-modal" tabindex="-1" role="dialog" aria-labelledby="spb-import-edit-title" aria-hidden="true">
            <div class="modal-dialog modal-lg" role="document">
                <div class="modal-content">
                    <form id="spb-import-edit-form" method="POST" data-url="{{ route('asset.fleet.trips.import.rows.update', ['rowIndex' => '__ROW_INDEX__']) }}">
                        @csrf
                        <input type="hidden" name="token" value="{{ $token }}">
                        <input type="hidden" name="asset_label" id="spb-edit-asset-label">
                        <input type="hidden" name="driver_label" id="spb-edit-driver-label">
                        <div class="modal-header">
                            <button type="button" class="close" data-dismiss="modal" aria-label="Tutup"><span aria-hidden="true">&times;</span></button>
                            <h4 class="modal-title" id="spb-import-edit-title">Edit baris preview</h4>
                            <p class="text-muted spb-edit-source" id="spb-edit-source"></p>
                        </div>
                        <div class="modal-body">
                            <div class="alert alert-danger" id="spb-edit-form-errors" role="alert" hidden></div>
                            <div class="alert alert-warning" id="spb-edit-row-errors" role="status" hidden></div>
                            <div class="alert alert-info">Perubahan Unit atau Sopir ikut diterapkan ke semua baris yang memiliki nama sumber sama. Baris yang terkena perubahan akan ditampilkan setelah disimpan.</div>

                            <div class="spb-edit-section">
                                <h4>Unit dan Sopir</h4>
                                <div class="row">
                                    <div class="col-sm-6 spb-field-group" data-field="asset_id">
                                        <label for="spb-edit-asset-mode">Unit</label>
                                        <select id="spb-edit-asset-mode" name="asset_mode" class="form-control spb-mode-select">
                                            <option value="existing">Pilih unit dari Assets</option>
                                            <option value="label">Gunakan nama workbook</option>
                                            @if (!empty($canCreateAsset))<option value="new">Tambah unit baru</option>@endif
                                        </select>
                                        <div class="spb-existing-panel" id="spb-asset-existing-panel">
                                            <select id="spb-edit-asset-id" name="asset_id" class="form-control spb-select2">
                                                <option value="">Pilih unit</option>
                                                @foreach($assets as $asset)<option value="{{ $asset['id'] }}" data-name="{{ $asset['name'] }}">{{ $asset['label'] }}</option>@endforeach
                                            </select>
                                        </div>
                                        <div class="spb-label-panel" id="spb-asset-label-panel" hidden>
                                            <input type="text" id="spb-edit-asset-label-editor" class="form-control" placeholder="Nama unit dari workbook">
                                            <p class="help-block">Nama akan dicocokkan kembali dengan Assets saat validasi.</p>
                                        </div>
                                        @if (!empty($canCreateAsset))
                                            <div class="spb-new-panel" id="spb-asset-new-panel" hidden>
                                                <div class="row">
                                                    <div class="col-sm-6 form-group"><label for="spb-asset-new-name">Nama unit *</label><input id="spb-asset-new-name" name="asset_new_name" class="form-control" maxlength="255"></div>
                                                    <div class="col-sm-6 form-group"><label for="spb-asset-new-code">Kode aset</label><input id="spb-asset-new-code" name="asset_new_code" class="form-control" maxlength="255" placeholder="Otomatis jika kosong"></div>
                                                    <div class="col-sm-6 form-group"><label for="spb-asset-new-category">Kategori</label><select id="spb-asset-new-category" name="asset_new_category_id" class="form-control"><option value="">Tanpa kategori</option>@foreach($assetCategories as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach</select></div>
                                                    <div class="col-sm-6 form-group"><label for="spb-asset-new-location">Lokasi</label><select id="spb-asset-new-location" name="asset_new_location_id" class="form-control"><option value="">Tanpa lokasi</option>@foreach($businessLocations as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach</select></div>
                                                    <div class="col-sm-6 form-group"><label for="spb-asset-new-model">Model</label><input id="spb-asset-new-model" name="asset_new_model" class="form-control" maxlength="255"></div>
                                                    <div class="col-sm-6 form-group"><label for="spb-asset-new-serial">Nomor seri</label><input id="spb-asset-new-serial" name="asset_new_serial_no" class="form-control" maxlength="255"></div>
                                                    <div class="col-sm-6 form-group"><label for="spb-asset-new-quantity">Jumlah *</label><input id="spb-asset-new-quantity" name="asset_new_quantity" type="number" min="1" step="0.0001" value="1" class="form-control"></div>
                                                    <div class="col-sm-6 form-group"><label for="spb-asset-new-price">Harga satuan *</label><input id="spb-asset-new-price" name="asset_new_unit_price" type="number" min="0" step="0.01" value="0" class="form-control"></div>
                                                    <div class="col-sm-6 form-group"><label for="spb-asset-new-depreciation">Penyusutan</label><input id="spb-asset-new-depreciation" name="asset_new_depreciation" type="number" min="0" step="0.01" class="form-control"></div>
                                                    <div class="col-sm-6 form-group"><label for="spb-asset-new-purchase-date">Tanggal pembelian</label><input id="spb-asset-new-purchase-date" name="asset_new_purchase_date" type="date" class="form-control"></div>
                                                    <div class="col-sm-6 form-group"><label for="spb-asset-new-purchase-type">Jenis kepemilikan</label><select id="spb-asset-new-purchase-type" name="asset_new_purchase_type" class="form-control"><option value="owned">Milik sendiri</option><option value="rented">Disewa</option><option value="leased">Sewa guna</option></select></div>
                                                    <div class="col-sm-12 form-group"><label for="spb-asset-new-description">Keterangan aset</label><textarea id="spb-asset-new-description" name="asset_new_description" class="form-control" rows="2"></textarea></div>
                                                </div>
                                            </div>
                                        @endif
                                        <div class="spb-field-error" aria-live="polite"></div>
                                    </div>
                                    <div class="col-sm-6 spb-field-group" data-field="driver_id">
                                        <label for="spb-edit-driver-mode">Sopir</label>
                                        <select id="spb-edit-driver-mode" name="driver_mode" class="form-control spb-mode-select">
                                            <option value="existing">Pilih User yang sudah ada</option>
                                            <option value="label">Gunakan nama workbook</option>
                                            @if (!empty($canCreateDriver))<option value="new">Tambah User sopir</option>@endif
                                        </select>
                                        <div class="spb-existing-panel" id="spb-driver-existing-panel">
                                            <select id="spb-edit-driver-id" name="driver_id" class="form-control spb-select2">
                                                <option value="">Pilih sopir</option>
                                                @foreach($drivers as $driver)<option value="{{ $driver['id'] }}" data-name="{{ $driver['name'] }}">{{ $driver['label'] }}</option>@endforeach
                                            </select>
                                        </div>
                                        <div class="spb-label-panel" id="spb-driver-label-panel" hidden>
                                            <input type="text" id="spb-edit-driver-label-editor" class="form-control" placeholder="Nama sopir dari workbook">
                                            <p class="help-block">Nama akan dicocokkan kembali dengan Users saat validasi.</p>
                                        </div>
                                        @if (!empty($canCreateDriver))
                                            <div class="spb-new-panel" id="spb-driver-new-panel" hidden>
                                                <label for="spb-driver-new-name">Nama sopir *</label>
                                                <input id="spb-driver-new-name" name="driver_new_name" class="form-control" maxlength="255">
                                                <p class="help-block">User ini dibuat tanpa username, kata sandi, atau akses login.</p>
                                            </div>
                                        @endif
                                        <div class="spb-field-error" aria-live="polite"></div>
                                    </div>
                                </div>
                            </div>

                            <div class="spb-edit-section">
                                <h4>Detail tiket dan rute</h4>
                                <div class="row">
                                    <div class="col-sm-4 spb-field-group" data-field="ticket_no"><label for="spb-edit-ticket">Nomor tiket</label><input id="spb-edit-ticket" name="ticket_no" class="form-control" maxlength="100"><div class="spb-field-error"></div></div>
                                    <div class="col-sm-4 spb-field-group" data-field="trip_date"><label for="spb-edit-trip-date">Tanggal trip *</label><input id="spb-edit-trip-date" name="trip_date" type="date" class="form-control"><div class="spb-field-error"></div></div>
                                    <div class="col-sm-4 spb-field-group" data-field="load_date"><label for="spb-edit-load-date">Tanggal muat</label><input id="spb-edit-load-date" name="load_date" type="date" class="form-control"><div class="spb-field-error"></div></div>
                                    <div class="col-sm-4 spb-field-group" data-field="unload_date"><label for="spb-edit-unload-date">Tanggal bongkar</label><input id="spb-edit-unload-date" name="unload_date" type="date" class="form-control"><div class="spb-field-error"></div></div>
                                    <div class="col-sm-4 spb-field-group" data-field="origin"><label for="spb-edit-origin">Asal</label><input id="spb-edit-origin" name="origin" class="form-control" maxlength="255"><div class="spb-field-error"></div></div>
                                    <div class="col-sm-4 spb-field-group" data-field="destination"><label for="spb-edit-destination">Tujuan</label><input id="spb-edit-destination" name="destination" class="form-control" maxlength="255"><div class="spb-field-error"></div></div>
                                    <div class="col-sm-12 spb-field-group" data-field="route_name"><label for="spb-edit-route">Rute</label><input id="spb-edit-route" name="route_name" class="form-control" list="spb-route-options" maxlength="255"><datalist id="spb-route-options">@foreach($routes as $id => $name)<option value="{{ $name }}">@endforeach</datalist><div class="spb-field-error"></div></div>
                                </div>
                            </div>

                            <div class="spb-edit-section">
                                <h4>Tonase dan tarif</h4>
                                <div class="row">
                                    <div class="col-sm-4 spb-field-group" data-field="gross_tons"><label for="spb-edit-gross">Tonase bruto</label><input id="spb-edit-gross" name="gross_tons" type="number" step="0.001" min="0" class="form-control"><div class="spb-field-error"></div></div>
                                    <div class="col-sm-4 spb-field-group" data-field="tare_tons"><label for="spb-edit-tare">Tonase tara</label><input id="spb-edit-tare" name="tare_tons" type="number" step="0.001" min="0" class="form-control"><div class="spb-field-error"></div></div>
                                    <div class="col-sm-4 spb-field-group" data-field="actual_tons"><label for="spb-edit-actual">Tonase aktual *</label><input id="spb-edit-actual" name="actual_tons" type="number" step="0.001" min="0" class="form-control"><div class="spb-field-error"></div></div>
                                    <div class="col-sm-4 spb-field-group" data-field="received_tons"><label for="spb-edit-received">Tonase diterima</label><input id="spb-edit-received" name="received_tons" type="number" step="0.001" min="0" class="form-control"><div class="spb-field-error"></div></div>
                                    <div class="col-sm-4 spb-field-group" data-field="billable_tons"><label for="spb-edit-billable">Tonase tagih</label><input id="spb-edit-billable" name="billable_tons" type="number" step="0.001" min="0" class="form-control"><div class="spb-field-error"></div></div>
                                    <div class="col-sm-4 spb-field-group" data-field="sale_rate_per_ton"><label for="spb-edit-rate">Tarif per ton</label><input id="spb-edit-rate" name="sale_rate_per_ton" type="number" step="0.01" min="0" class="form-control"><div class="spb-field-error"></div></div>
                                    <div class="col-sm-4 spb-field-group" data-field="variance_kg"><label for="spb-edit-variance">Selisih kg</label><input id="spb-edit-variance" name="variance_kg" type="number" step="0.01" class="form-control"><div class="spb-field-error"></div></div>
                                    <div class="col-sm-4 spb-field-group" data-field="tolerance_kg"><label for="spb-edit-tolerance">Toleransi kg</label><input id="spb-edit-tolerance" name="tolerance_kg" type="number" step="0.01" min="0" class="form-control"><div class="spb-field-error"></div></div>
                                    <div class="col-sm-4 spb-field-group" data-field="claim_rate_per_kg"><label for="spb-edit-claim-rate">Tarif klaim per kg</label><input id="spb-edit-claim-rate" name="claim_rate_per_kg" type="number" step="0.01" min="0" class="form-control"><div class="spb-field-error"></div></div>
                                    <div class="col-sm-4 spb-field-group" data-field="other_deduction_total"><label for="spb-edit-deduction">Potongan lain</label><input id="spb-edit-deduction" name="other_deduction_total" type="number" step="0.01" min="0" class="form-control"><div class="spb-field-error"></div></div>
                                    <div class="col-sm-8 spb-field-group" data-field="rate_override_reason"><label for="spb-edit-rate-reason">Alasan tarif</label><input id="spb-edit-rate-reason" name="rate_override_reason" class="form-control" maxlength="1000"><div class="spb-field-error"></div></div>
                                </div>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-default" data-dismiss="modal">Batal</button>
                            <button type="submit" class="btn btn-primary" id="spb-edit-save"><i class="fas fa-save"></i> Simpan koreksi</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    @endif
</section>

<style>
    .spb-import-page .spb-import-upload-form { display: flex; align-items: flex-end; flex-wrap: wrap; gap: 10px; }
    .spb-import-page .spb-import-upload-form .form-group { flex: 1 1 320px; margin: 0; }
    .spb-import-summary { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 12px; margin-bottom: 18px; }
    .spb-import-summary-card { padding: 13px 16px; border: 1px solid #d9e1e7; border-radius: 5px; background: #f8fafb; }
    .spb-import-summary-card span { display: block; color: #536574; font-size: 13px; }
    .spb-import-summary-card strong { display: block; margin-top: 4px; color: #263845; font-size: 24px; }
    .spb-import-summary-ready { border-color: #b8dec5; background: #f0faf3; }
    .spb-import-summary-error { border-color: #edc8c8; background: #fff6f5; }
    .spb-default-route { max-width: 520px; }
    .spb-import-modal .modal-dialog { width: min(960px, calc(100% - 24px)); margin: 12px auto; }
    .spb-import-modal .modal-content { max-height: calc(100vh - 24px); display: flex; flex-direction: column; }
    .spb-import-modal .modal-body { overflow-y: auto; }
    .spb-import-modal .modal-header { flex: 0 0 auto; }
    .spb-import-modal .modal-footer { flex: 0 0 auto; }
    .spb-import-modal .spb-edit-section { margin-bottom: 20px; padding-bottom: 14px; border-bottom: 1px solid #e6ebef; }
    .spb-import-modal .spb-edit-section h4 { margin: 0 0 14px; font-weight: 600; }
    .spb-import-modal .spb-field-group { margin-bottom: 14px; }
    .spb-import-modal .spb-existing-panel, .spb-import-modal .spb-label-panel, .spb-import-modal .spb-new-panel { margin-top: 10px; }
    .spb-import-modal .spb-new-panel { padding: 12px; border: 1px solid #dce5e9; border-radius: 4px; background: #f8fafb; }
    .spb-import-modal .spb-field-group.has-error input,
    .spb-import-modal .spb-field-group.has-error select,
    .spb-import-modal .spb-field-group.has-error textarea { border-color: #c9302c; box-shadow: 0 0 0 2px rgba(201, 48, 44, .12); }
    .spb-import-modal .spb-field-error { color: #a94442; font-size: 12px; margin-top: 4px; }
    .spb-import-modal .select2-container { width: 100% !important; }
    .spb-edit-source { margin: 5px 24px 0 0; overflow-wrap: anywhere; }
    #fleet-spb-import-preview-table td { overflow-wrap: anywhere; }
    @media (max-width: 767px) {
        .spb-import-summary { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        .spb-import-page .spb-import-upload-form { display: block; }
        .spb-import-page .spb-import-upload-form .form-group { margin-bottom: 12px; }
        .spb-import-page .spb-import-upload-form button,
        .spb-import-page .spb-import-upload-form a { margin: 0 5px 5px 0; }
        .spb-import-modal .modal-dialog { width: calc(100% - 16px); margin: 8px; }
        .spb-import-modal .modal-content { max-height: calc(100vh - 16px); }
    }
</style>

@push('scripts')
@if (!empty($preview))
<script>
$(function () {
    var previewRows = @json(array_values($preview['rows'] ?? []));
    previewRows.forEach(function (row, index) { row.preview_index = index; });
    var $table = $('#fleet-spb-import-preview-table');
    var $modal = $('#spb-import-edit-modal');
    var $editForm = $('#spb-import-edit-form');
    var activeRowIndex = null;

    function escapeHtml(value) {
        return $('<div>').text(value == null ? '' : String(value)).html();
    }

    function rowStatus(row) {
        return row.row_errors && row.row_errors.length
            ? '<span class="label label-warning">Perlu koreksi</span>'
            : '<span class="label label-success">Siap</span>';
    }

    function rowErrors(row) {
        if (!row.row_errors || !row.row_errors.length) return '<span class="text-muted">Tidak ada catatan.</span>';
        return '<ul class="spb-row-errors">' + row.row_errors.map(function (error) { return '<li>' + escapeHtml(error) + '</li>'; }).join('') + '</ul>';
    }

    var dataTable = initFleetDataTable('#fleet-spb-import-preview-table', {
        data: previewRows,
        pageLength: 25,
        order: [[0, 'asc']],
        columns: [
            { data: null, render: function (data, type, row) { return type === 'display' ? escapeHtml(row.source_sheet) + ':' + escapeHtml(row.source_row) : String(row.source_sheet || '') + ':' + String(row.source_row || ''); } },
            { data: 'ticket_no', defaultContent: '-', render: function (value, type) { return type === 'display' ? escapeHtml(value || '-') : (value || ''); } },
            { data: 'asset_label', defaultContent: '-', render: function (value, type) { return type === 'display' ? escapeHtml(value || '-') : (value || ''); } },
            { data: 'driver_label', defaultContent: '-', render: function (value, type) { return type === 'display' ? escapeHtml(value || '-') : (value || ''); } },
            { data: 'trip_date', defaultContent: '-', render: function (value, type) { return type === 'display' ? escapeHtml(value || '-') : (value || ''); } },
            { data: 'actual_tons', defaultContent: '-', render: function (value, type) { return type === 'display' ? escapeHtml(value == null || value === '' ? '-' : value) : (value || ''); } },
            { data: 'sale_rate_per_ton', defaultContent: '-', render: function (value, type) { return type === 'display' ? escapeHtml(value == null || value === '' ? '-' : value) : (value || ''); } },
            { data: null, orderable: false, render: function (data, type, row) { return type === 'display' ? rowStatus(row) : ((row.row_errors || []).length ? 'Perlu koreksi' : 'Siap'); } },
            { data: null, orderable: false, render: function (data, type, row) { return type === 'display' ? rowErrors(row) : ((row.row_errors || []).join(' ')); } },
            { data: null, orderable: false, searchable: false, render: function (data, type, row) { return type === 'display' ? '<button type="button" class="btn btn-xs btn-primary spb-edit-row" data-row-index="' + Number(row.preview_index) + '"><i class="fas fa-edit"></i> Edit</button>' : ''; } }
        ],
        columnDefs: [{ targets: [7, 8, 9], orderable: false }]
    });

    function setField(name, value) {
        $editForm.find('[name="' + name + '"]').not('[type="hidden"][name="token"]').val(value == null ? '' : value);
    }

    function clearFieldErrors() {
        $editForm.find('.has-error').removeClass('has-error');
        $editForm.find('.spb-field-error').empty();
        $('#spb-edit-form-errors').prop('hidden', true).empty();
        $('#spb-edit-row-errors').prop('hidden', true).empty();
    }

    function showFieldErrors(errors) {
        clearFieldErrors();
        var messages = [];
        Object.keys(errors || {}).forEach(function (field) {
            (errors[field] || []).forEach(function (message) {
                messages.push(message);
                var groupField = field === 'asset_mode' ? 'asset_id' : (field === 'driver_mode' ? 'driver_id' : field);
                var $group = $editForm.find('.spb-field-group[data-field="' + groupField + '"]').first();
                if ($group.length) {
                    $group.addClass('has-error');
                    $group.find('.spb-field-error').first().append($('<div>').text(message));
                } else if (field !== 'row') {
                    var $input = $editForm.find('[name="' + field + '"]').first();
                    var $inputGroup = $input.closest('.form-group');
                    if ($inputGroup.length) {
                        $inputGroup.addClass('has-error');
                        var $fieldError = $inputGroup.find('.spb-field-error');
                        if (!$fieldError.length) $fieldError = $('<div class="spb-field-error">').appendTo($inputGroup);
                        $fieldError.append($('<div>').text(message));
                    }
                }
            });
        });
        if (messages.length) {
            $('#spb-edit-form-errors').text(messages.join(' ')).prop('hidden', false);
        }
    }

    function markRowErrors(row) {
        var rowOnlyErrors = (row.row_error_fields && row.row_error_fields.row) || [];
        if (rowOnlyErrors.length) {
            $('#spb-edit-row-errors').text(rowOnlyErrors.join(' ')).prop('hidden', false);
        }
        Object.keys(row.row_error_fields || {}).forEach(function (field) {
            if (field === 'row') return;
            var groupField = field === 'asset_label' ? 'asset_id' : (field === 'driver_label' ? 'driver_id' : field);
            var $group = $editForm.find('.spb-field-group[data-field="' + groupField + '"]').first();
            if (!$group.length) return;
            $group.addClass('has-error');
            (row.row_error_fields[field] || []).forEach(function (message) {
                $group.find('.spb-field-error').first().append($('<div>').text(message));
            });
        });
    }

    function syncModePanels(kind) {
        var mode = $('#spb-edit-' + kind + '-mode').val();
        $('#spb-' + kind + '-existing-panel').prop('hidden', mode !== 'existing').find(':input').prop('disabled', mode !== 'existing');
        $('#spb-' + kind + '-label-panel').prop('hidden', mode !== 'label').find(':input').prop('disabled', mode !== 'label');
        $('#spb-' + kind + '-new-panel').prop('hidden', mode !== 'new').find(':input').prop('disabled', mode !== 'new');
    }

    function fillRelation(kind, row) {
        var isAsset = kind === 'asset';
        var idField = isAsset ? 'asset_id' : 'driver_id';
        var labelField = isAsset ? 'asset_label' : 'driver_label';
        var $select = $('#spb-edit-' + kind + '-id');
        var id = row[idField] == null ? '' : String(row[idField]);
        var found = id !== '' && $select.find('option[value="' + id.replace(/"/g, '\\"') + '"]').length > 0;
        $('#spb-edit-' + kind + '-mode').val(found ? 'existing' : 'label');
        $select.val(found ? id : '').trigger('change.select2');
        $('#spb-edit-' + kind + '-label-editor').val(row[labelField] || '');
        $('#spb-edit-' + kind + '-new-name').val(row[labelField] || '');
        $('#spb-edit-' + labelField).val(row[labelField] || '');
        syncModePanels(kind);
    }

    function openEditor(index) {
        var row = previewRows[index];
        if (!row) return;
        activeRowIndex = index;
        $editForm[0].reset();
        clearFieldErrors();
        $editForm.find('input[name="token"]').val(@json($token));
        $('#spb-edit-title').text('Edit baris preview');
        $('#spb-import-edit-title').text('Edit baris preview');
        $('#spb-edit-source').text((row.source_sheet || '') + ' · baris ' + (row.source_row || ''));

        var fields = ['ticket_no', 'trip_date', 'load_date', 'unload_date', 'gross_tons', 'tare_tons', 'actual_tons', 'received_tons', 'billable_tons', 'sale_rate_per_ton', 'origin', 'destination', 'route_name', 'variance_kg', 'tolerance_kg', 'claim_rate_per_kg', 'other_deduction_total', 'rate_override_reason'];
        fields.forEach(function (field) { setField(field, row[field]); });
        fillRelation('asset', row);
        fillRelation('driver', row);
        $('#spb-asset-new-code, #spb-asset-new-model, #spb-asset-new-serial, #spb-asset-new-category, #spb-asset-new-location, #spb-asset-new-purchase-date, #spb-asset-new-depreciation, #spb-asset-new-description').val('');
        $('#spb-asset-new-quantity').val(1);
        $('#spb-asset-new-price').val(0);
        $('#spb-asset-new-purchase-type').val('owned');
        markRowErrors(row);
        $modal.modal('show');
    }

    function renderUpdatedRows(changes) {
        (changes || []).forEach(function (change) {
            var row = change.row;
            row.preview_index = Number(change.index);
            previewRows[Number(change.index)] = row;
            if (row.asset_id && !$('#spb-edit-asset-id option[value="' + String(row.asset_id).replace(/"/g, '\\"') + '"]').length) {
                $('<option>').val(row.asset_id).attr('data-name', row.asset_label || '').text(row.asset_label || '').appendTo('#spb-edit-asset-id');
            }
            if (row.driver_id && !$('#spb-edit-driver-id option[value="' + String(row.driver_id).replace(/"/g, '\\"') + '"]').length) {
                $('<option>').val(row.driver_id).attr('data-name', row.driver_label || '').text(row.driver_label || '').appendTo('#spb-edit-driver-id');
            }
            if (!dataTable) return;
            var match = dataTable.row(function (index, current) { return Number(current.preview_index) === Number(change.index); });
            if (match.length) match.data(row);
        });
        if (dataTable) dataTable.draw(false);
    }

    function updateSummary(summary, errors) {
        $('#spb-summary-sheets').text(summary.sheets || 0);
        $('#spb-summary-source').text(summary.source_rows || 0);
        $('#spb-summary-ready, #spb-import-submit-count').text(summary.ready_rows || 0);
        $('#spb-summary-errors').text(summary.error_rows || 0);
        $('#spb-import-submit').prop('disabled', Number(summary.ready_rows || 0) < 1);
        var $box = $('#spb-import-error-summary').removeClass('alert-warning alert-success').empty();
        if (errors && errors.length) {
            $box.addClass('alert-warning').append($('<strong>').text('Baris yang memerlukan perhatian'));
            var $list = $('<ul>');
            errors.slice(0, 15).forEach(function (error) { $list.append($('<li>').text(error)); });
            $box.append($list);
            if (errors.length > 15) $box.append($('<p class="text-muted">').text('Daftar lengkap tersedia melalui kolom Catatan pada tiap baris.'));
        } else {
            $box.addClass('alert-success').text('Semua baris valid dan siap diimpor.');
        }
    }

    $table.on('click', '.spb-edit-row', function () { openEditor(Number($(this).data('row-index'))); });
    $('.spb-mode-select').on('change', function () { syncModePanels($(this).attr('id').indexOf('asset') !== -1 ? 'asset' : 'driver'); });
    $('#spb-edit-asset-id').on('change', function () {
        var name = $(this).find(':selected').data('name') || '';
        $('#spb-edit-asset-label').val(name);
        if (name) $('#spb-edit-asset-label-editor, #spb-asset-new-name').val(name);
    });
    $('#spb-edit-driver-id').on('change', function () {
        var name = $(this).find(':selected').data('name') || '';
        $('#spb-edit-driver-label').val(name);
        if (name) $('#spb-edit-driver-label-editor, #spb-driver-new-name').val(name);
    });
    $('#spb-edit-asset-label-editor, #spb-asset-new-name').on('input change', function () { $('#spb-edit-asset-label').val($(this).val()); });
    $('#spb-edit-driver-label-editor, #spb-driver-new-name').on('input change', function () { $('#spb-edit-driver-label').val($(this).val()); });

    $modal.find('.spb-select2').select2({ width: '100%', placeholder: 'Cari nama atau kode', allowClear: true, dropdownParent: $modal });

    $editForm.on('submit', function (event) {
        event.preventDefault();
        if (activeRowIndex === null) return;
        clearFieldErrors();
        var assetMode = $('#spb-edit-asset-mode').val();
        var driverMode = $('#spb-edit-driver-mode').val();
        if (assetMode === 'existing') $('#spb-edit-asset-label').val($('#spb-edit-asset-id option:selected').data('name') || '');
        if (assetMode === 'label') $('#spb-edit-asset-label').val($('#spb-edit-asset-label-editor').val());
        if (assetMode === 'new') $('#spb-edit-asset-label').val($('#spb-asset-new-name').val());
        if (driverMode === 'existing') $('#spb-edit-driver-label').val($('#spb-edit-driver-id option:selected').data('name') || '');
        if (driverMode === 'label') $('#spb-edit-driver-label').val($('#spb-edit-driver-label-editor').val());
        if (driverMode === 'new') $('#spb-edit-driver-label').val($('#spb-driver-new-name').val());

        var $button = $('#spb-edit-save').prop('disabled', true).text('Menyimpan…');
        var url = $editForm.data('url').replace('__ROW_INDEX__', activeRowIndex);
        $.ajax({ url: url, method: 'POST', data: $editForm.serialize(), dataType: 'json' })
            .done(function (response) {
                renderUpdatedRows(response.updated_rows || []);
                updateSummary(response.summary || {}, response.errors || []);
                $modal.modal('hide');
                $('<div class="alert alert-success spb-edit-save-notice" role="status">').text(response.message || 'Koreksi tersimpan.').insertBefore('#spb-import-error-summary');
                window.setTimeout(function () { $('.spb-edit-save-notice').fadeOut(200, function () { $(this).remove(); }); }, 5000);
            })
            .fail(function (xhr) {
                var response = xhr.responseJSON || {};
                if (response.errors) showFieldErrors(response.errors);
                else $('#spb-edit-form-errors').text(response.message || 'Koreksi gagal disimpan. Silakan coba lagi.').prop('hidden', false);
            })
            .always(function () { $button.prop('disabled', false).html('<i class="fas fa-save"></i> Simpan koreksi'); });
    });
});
</script>
@endif
@endpush
@endsection
