@extends('layouts.app')

@section('title', 'Import Loading Vendor')

@section('content')
@includeIf('assetmanagement::layouts.nav')
@include('layouts.partials.fleet-datatables')
<section class="content-header"><a href="{{ route('asset.fleet.vendor-loadings.index') }}" class="btn btn-default pull-right">Kembali</a><h1><i class="fas fa-file-import"></i> Import Loading Vendor</h1><p class="text-muted">Unit dan sopir vendor tidak dicocokkan ke Asset/User. Bendera/Supplier tetap perlu dipilih atau dibuat sebelum import.</p></section>
<section class="content">
    <div id="vendor-import-feedback" class="alert" role="alert" hidden></div>
    <div class="box box-primary"><div class="box-header with-border"><h3 class="box-title">Upload Workbook</h3></div><div class="box-body">
        <form id="vendor-import-upload" method="POST" action="{{ route('asset.fleet.vendor-loadings.import.preview') }}" enctype="multipart/form-data">
            @csrf
            <div class="form-group"><label for="vendor-import-file">File Loading Vendor</label><input id="vendor-import-file" type="file" name="file" class="form-control" accept=".xlsx,.xls,.csv" required><small class="text-muted">Format XLSX, XLS, atau CSV. Data belum disimpan saat preview.</small></div>
            <button class="btn btn-primary" type="submit"><i class="fas fa-search"></i> Upload &amp; Preview</button>
            <a href="{{ route('asset.fleet.vendor-loadings.index') }}" class="btn btn-default">Batal</a>
        </form>
    </div></div>
    <div id="vendor-import-preview-box" class="box box-info" hidden><div class="box-header with-border"><h3 class="box-title">Preview Import</h3></div><div class="box-body">
        <div id="vendor-import-summary" class="row"></div>
        <div class="alert alert-info"><strong>Perhatian:</strong> baris supplier yang belum cocok diberi warna kuning. Pilih supplier dari daftar atau centang buat supplier minimal. Setelah itu klik konfirmasi.</div>
        <div class="table-responsive fleet-datatable-wrap"><table id="vendor-import-preview-table" class="table table-bordered table-striped fleet-datatable" style="width:100%"><thead><tr><th>Sumber</th><th>Tiket</th><th>Bendera / Supplier</th><th>Unit Vendor</th><th>Sopir Vendor</th><th>Tanggal</th><th>Ton</th><th>Tarif</th><th>Status</th><th>Pilihan Supplier</th><th>Catatan</th></tr></thead><tbody></tbody></table></div>
        <button type="button" id="vendor-import-confirm" class="btn btn-success"><i class="fas fa-check"></i> Konfirmasi Import Baris Siap</button>
        <a href="{{ route('asset.fleet.vendor-loadings.import.form') }}" class="btn btn-default">Batalkan Preview</a>
    </div></div>
</section>
@push('styles')
<style>
    #vendor-import-preview-table tbody tr.vendor-import-row-error { background: #fff4f0; }
    #vendor-import-preview-table tbody tr.vendor-import-row-unmatched { background: #fffbea; }
    #vendor-import-preview-table .vendor-import-error { display: block; color: #a94442; font-size: 12px; max-width: 280px; white-space: normal; }
    #vendor-import-preview-table .vendor-import-select { min-width: 220px; }
    #vendor-import-summary .vendor-import-stat { padding: 10px 14px; border: 1px solid #dce7ea; border-radius: 4px; margin-bottom: 12px; background: #f8fbfc; }
    .vendor-import-create-label { display: block; margin-top: 6px; font-weight: normal; font-size: 12px; }
</style>
@endpush
@push('scripts')
<script>
$(function () {
    var token = null, preview = null, table = null, supplierSelections = {}, newSupplierRows = {};
    var $feedback = $('#vendor-import-feedback');
    function feedback(success, message) { $feedback.removeAttr('hidden').removeClass('alert-success alert-danger').addClass(success ? 'alert-success' : 'alert-danger').text(message || 'Terjadi kesalahan.'); }
    function escapeHtml(value) { return $('<div>').text(value == null ? '' : String(value)).html(); }
    function statusText(status) { return { matched: 'Supplier cocok', unmatched: 'Supplier belum cocok', ambiguous: 'Supplier ambigu', selected: 'Supplier dipilih', created: 'Supplier baru akan dibuat' }[status] || 'Perlu diperiksa'; }
    function initSupplierSelects() {
        $('.vendor-import-select').each(function () {
            var $select = $(this);
            if ($select.data('select2')) return;
            $select.select2({ width: '100%', placeholder: 'Pilih supplier', allowClear: true, ajax: { url: @json(route('asset.fleet.vendor-loadings.suppliers')), dataType: 'json', delay: 250, data: function (params) { return { term: params.term || '' }; }, processResults: function (data) { return data; } } }).on('change', function () {
                var key = $select.data('key'), value = $select.val(), $row = $select.closest('tr'), $status = $row.find('.vendor-import-status'), $notes = $row.find('.vendor-import-notes');
                if (value) {
                    supplierSelections[key] = value;
                    delete newSupplierRows[key];
                    $select.closest('td').find('.vendor-import-new-supplier').prop('checked', false);
                    $row.removeClass('vendor-import-row-unmatched');
                    $status.removeClass('label-warning label-info').addClass('label-success').text('Supplier dipilih');
                    $notes.removeClass('vendor-import-error').addClass('text-muted').text('Supplier sudah dipilih dan siap diimpor.');
                } else {
                    delete supplierSelections[key];
                }
            });
        });
    }
    function renderPreview(data) {
        preview = data; supplierSelections = {}; newSupplierRows = {};
        var summary = data.summary || {};
        $('#vendor-import-summary').html('<div class="col-md-3"><div class="vendor-import-stat"><strong>Sheet</strong><br>'+summary.sheets+'</div></div><div class="col-md-3"><div class="vendor-import-stat"><strong>Baris sumber</strong><br>'+summary.source_rows+'</div></div><div class="col-md-3"><div class="vendor-import-stat"><strong>Siap awal</strong><br>'+summary.ready_rows+'</div></div><div class="col-md-3"><div class="vendor-import-stat"><strong>Perlu diperiksa</strong><br>'+summary.error_rows+'</div></div>');
        var rows = (data.rows || []).map(function (row) {
            var key = row.row_key, errors = (row.row_errors || []).concat(row.row_warnings || []), hasSupplier = row.supplier_match_status === 'matched';
            if (hasSupplier) supplierSelections[key] = row.supplier_id;
            var option = hasSupplier ? '<option value="'+escapeHtml(row.supplier_id)+'" selected>'+escapeHtml(row.supplier_label || 'Supplier cocok')+'</option>' : '<option value="">Pilih supplier</option>';
            var supplierControl = '<select class="form-control vendor-import-select" data-key="'+escapeHtml(key)+'">'+option+'</select><label class="vendor-import-create-label"><input type="checkbox" class="vendor-import-new-supplier" data-key="'+escapeHtml(key)+'" '+(!row.supplier_label ? 'disabled' : '')+'> Buat supplier baru dari label ini</label>';
            var status = row.supplier_match_status === 'matched' && !(row.row_errors || []).length ? '<span class="label label-success vendor-import-status">Siap</span>' : '<span class="label label-warning vendor-import-status">'+statusText(row.supplier_match_status)+'</span>';
            return '<tr data-vendor-import-key="'+escapeHtml(key)+'" class="'+((row.row_errors || []).length ? 'vendor-import-row-error ' : '')+(row.supplier_match_status === 'unmatched' || row.supplier_match_status === 'ambiguous' ? 'vendor-import-row-unmatched' : '')+'"><td>'+escapeHtml(row.source_sheet)+':'+escapeHtml(row.source_row)+'</td><td>'+escapeHtml(row.ticket_no || '-')+'</td><td>'+escapeHtml(row.supplier_label || '-')+'</td><td>'+escapeHtml(row.vendor_unit_name || '-')+'</td><td>'+escapeHtml(row.vendor_driver_name || '-')+'</td><td>'+escapeHtml(row.loading_date || '-')+'</td><td>'+escapeHtml(row.billable_tons || row.net_tons || '-')+'</td><td>'+escapeHtml(row.vendor_rate_per_ton || '-')+'</td><td>'+status+'</td><td>'+supplierControl+'</td><td>'+(errors.length ? '<span class="vendor-import-error vendor-import-notes">'+escapeHtml(errors.join(' '))+'</span>' : '<span class="text-muted vendor-import-notes">Tidak ada catatan.</span>')+'</td></tr>';
        }).join('');
        if (table) { table.destroy(); table = null; }
        $('#vendor-import-preview-table tbody').html(rows);
        if ($.fn.DataTable) table = initFleetDataTable('#vendor-import-preview-table', { pageLength: 25, order: [[0, 'asc']], columnDefs: [{ targets: [8, 9, 10], orderable: false }] });
        initSupplierSelects(); $('#vendor-import-preview-box').removeAttr('hidden');
    }
    $('#vendor-import-upload').on('submit', function (event) {
        event.preventDefault(); var form = this, button = $(form).find('[type="submit"]'); button.prop('disabled', true);
        $.ajax({ url: form.action, type: 'POST', data: new FormData(form), processData: false, contentType: false, headers: { Accept: 'application/json' } }).done(function (response) { token = response.token; feedback(true, response.msg); renderPreview(response.preview); }).fail(function (xhr) { var body = xhr.responseJSON || {}; feedback(false, body.msg || body.message || 'File belum dapat dipreview.'); }).always(function () { button.prop('disabled', false); });
    });
    $('#vendor-import-preview-table').on('change', '.vendor-import-new-supplier', function () {
        var key = $(this).data('key'), $row = $(this).closest('tr'), $status = $row.find('.vendor-import-status'), $notes = $row.find('.vendor-import-notes');
        if (this.checked) {
            newSupplierRows[key] = true;
            delete supplierSelections[key];
            $row.removeClass('vendor-import-row-unmatched');
            $status.removeClass('label-warning label-danger').addClass('label-info').text('Supplier baru akan dibuat');
            $notes.removeClass('vendor-import-error').addClass('text-muted').text('Supplier minimal akan dibuat saat konfirmasi import.');
            $row.find('.vendor-import-select').val(null).trigger('change').prop('disabled', true);
        } else {
            delete newSupplierRows[key];
            $row.addClass('vendor-import-row-unmatched');
            $status.removeClass('label-info label-success').addClass('label-warning').text('Supplier belum cocok');
            $notes.removeClass('text-muted').addClass('vendor-import-error').text('Bendera/Supplier belum ditemukan. Pilih supplier atau centang buat supplier baru.');
            $row.find('.vendor-import-select').prop('disabled', false);
        }
    });
    $('#vendor-import-confirm').on('click', function () {
        if (!token) return; var button = this; button.disabled = true;
        $.ajax({ url: @json(route('asset.fleet.vendor-loadings.import.confirm')), type: 'POST', data: { _token: $('meta[name="csrf-token"]').attr('content'), token: token, supplier_selections: JSON.stringify(supplierSelections), new_supplier_rows: JSON.stringify(newSupplierRows) }, headers: { Accept: 'application/json' } }).done(function (response) { feedback(true, response.msg); if (response.errors && response.errors.length) feedback(true, response.msg+' '+response.errors.slice(0, 3).join(' | ')); window.setTimeout(function () { window.location.href = response.redirect; }, 600); }).fail(function (xhr) { var body = xhr.responseJSON || {}; feedback(false, (body.msg || body.message || 'Import belum berhasil.') + (body.errors ? ' '+body.errors.slice(0, 3).join(' | ') : '')); button.disabled = false; });
    });
});
</script>
@endpush
@endsection
