@extends('layouts.app')

@section('title', 'Import Loading Vendor')

@section('content')
@includeIf('assetmanagement::layouts.nav')
@include('layouts.partials.fleet-datatables')
<section class="content-header"><a href="{{ route('asset.fleet.vendor-loadings.index') }}" class="btn btn-default pull-right">Kembali</a><h1><i class="fas fa-file-import"></i> Import Loading Vendor</h1><p class="text-muted">Workbook multi-sheet dapat diimpor langsung. Nama bendera dibaca dari sel kuning; kolom Keterangan menjadi nama sopir.</p></section>
<section class="content">
    <div id="vendor-import-feedback" class="alert" role="alert" hidden></div>
    <div class="box box-primary"><div class="box-header with-border"><h3 class="box-title">Upload Workbook</h3></div><div class="box-body">
        <form id="vendor-import-upload" method="POST" action="{{ route('asset.fleet.vendor-loadings.import.preview') }}" enctype="multipart/form-data">
            @csrf
            <div class="form-group"><label for="vendor-import-file">File Loading Vendor</label><input id="vendor-import-file" type="file" name="file" class="form-control" accept=".xlsx,.xls,.csv" required><small class="text-muted">Format XLSX, XLS, atau CSV. Semua sheet data akan dibaca; data belum disimpan saat preview.</small></div>
            <button class="btn btn-primary" type="submit"><i class="fas fa-search"></i> Upload &amp; Preview</button>
            <a href="{{ route('asset.fleet.vendor-loadings.import.template') }}" class="btn btn-success"><i class="fas fa-download"></i> Download Template XLSX</a>
            <a href="{{ route('asset.fleet.vendor-loadings.index') }}" class="btn btn-default">Batal</a>
        </form>
    </div></div>
    <div id="vendor-import-preview-box" class="box box-info" hidden><div class="box-header with-border"><h3 class="box-title">Preview Import</h3></div><div class="box-body">
        <div id="vendor-import-summary" class="row"></div>
        <div class="alert alert-info"><strong>Perhatian:</strong> bendera yang belum cocok diberi warna kuning. Pilih Supplier yang benar atau centang pembuatan Supplier minimal. Customer pada header workbook akan dicocokkan atau dibuat sekali jika diperlukan.</div>
        <div id="vendor-import-missing-date-box" class="alert alert-warning" hidden><label for="vendor-import-missing-date">Tanggal untuk baris yang tanggalnya kosong</label><div class="row"><div class="col-md-3"><input id="vendor-import-missing-date" type="date" class="form-control"></div><div class="col-md-9"><p class="help-block">Tanggal ini hanya diterapkan pada baris tanpa tanggal dari workbook. Tanggal yang sudah terisi tidak berubah.</p></div></div></div>
        <div class="table-responsive fleet-datatable-wrap"><table id="vendor-import-preview-table" class="table table-bordered table-striped fleet-datatable" style="width:100%"><thead><tr><th>Sumber</th><th>Tiket</th><th>Bendera / Supplier</th><th>Customer</th><th>Barang / Kapal / Tujuan</th><th>Unit</th><th>Sopir</th><th>Tanggal</th><th>Gross / Tare / Nett</th><th>Tarif / Total (Rp)</th><th>Status</th><th>Pilihan Supplier</th><th>Catatan</th></tr></thead><tbody></tbody></table></div>
        <button type="button" id="vendor-import-confirm" class="btn btn-success" disabled><i class="fas fa-check"></i> Konfirmasi Import Baris Siap</button>
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
    function money(value) { return value == null || value === '' ? '-' : 'Rp ' + Number(value || 0).toLocaleString('id-ID', { maximumFractionDigits: 0 }); }
    function statusText(status) { return { matched: 'Supplier cocok', unmatched: 'Supplier belum cocok', ambiguous: 'Supplier ambigu', selected: 'Supplier dipilih', created: 'Supplier baru akan dibuat' }[status] || 'Perlu diperiksa'; }
    function isSupplierError(message) { return String(message || '').toLowerCase().indexOf('bendera/supplier') !== -1; }
    function supplierIsReady(row, key) { return !!(supplierSelections[key] || newSupplierRows[key] || row.supplier_match_status === 'matched'); }
    function rowMessages(row, key) {
        var messages = (row.row_errors || []).filter(function (message) { return !isSupplierError(message); }).concat(row.row_warnings || []);
        if (!supplierIsReady(row, key)) messages.push('Bendera/Supplier belum dipilih.');
        if (row.date_missing && !$('#vendor-import-missing-date').val()) messages.push('Isi tanggal bersama di atas untuk baris ini.');
        return messages;
    }
    function refreshPreviewState() {
        if (!preview) return;
        var date = $('#vendor-import-missing-date').val(), ready = 0, issues = 0;
        (preview.rows || []).forEach(function (row) {
            var key = row.row_key, fatal = (row.row_errors || []).filter(function (message) { return !isSupplierError(message); }).length > 0;
            var rowReady = !fatal && supplierIsReady(row, key) && (!row.date_missing || !!date);
            if (rowReady) ready++; else issues++;
        });
        $('#vendor-import-ready-count').text(ready);
        $('#vendor-import-issue-count').text(issues);
        var requiresDate = Number((preview.summary || {}).date_missing_rows || 0) > 0 && !date;
        $('#vendor-import-confirm').prop('disabled', ready === 0 || requiresDate);
        $('#vendor-import-preview-table tbody tr').each(function () {
            var $row = $(this), key = $row.attr('data-vendor-import-key'), source = (preview.rows || []).find(function (item) { return item.row_key === key; });
            if (!source) return;
            var messages = rowMessages(source, key), fatal = (source.row_errors || []).filter(function (message) { return !isSupplierError(message); }).length > 0;
            var status = $row.find('.vendor-import-status');
            if (fatal) status.removeClass('label-success label-info label-warning').addClass('label-danger').text('Perlu diperiksa');
            else if (source.date_missing && !date) status.removeClass('label-success label-danger label-info').addClass('label-warning').text('Isi tanggal');
            else if (!supplierIsReady(source, key)) status.removeClass('label-success label-danger label-info').addClass('label-warning').text('Pilih supplier');
            else status.removeClass('label-danger label-warning label-info').addClass('label-success').text('Siap');
            $row.toggleClass('vendor-import-row-error', fatal).toggleClass('vendor-import-row-unmatched', !fatal && (!supplierIsReady(source, key) || (source.date_missing && !date)));
            $row.find('.vendor-import-notes')
                .toggleClass('vendor-import-error', messages.length > 0)
                .toggleClass('text-muted', messages.length === 0)
                .html(messages.length ? window.importHistoryMessageCell(messages.join('\n'), 'display') : 'Tidak ada catatan.');
            $row.find('.vendor-import-date').text(source.date_missing ? (date || 'Perlu diisi') : (source.loading_date || '-'));
        });
    }
    function initSupplierSelects() {
        $('.vendor-import-select').each(function () {
            var $select = $(this);
            if ($select.data('select2')) return;
            $select.select2({ width: '100%', placeholder: 'Pilih supplier', allowClear: true, ajax: { url: @json(route('asset.fleet.vendor-loadings.suppliers')), dataType: 'json', delay: 250, data: function (params) { return { term: params.term || '' }; }, processResults: function (data) { return data; } } }).on('change', function () {
                var key = $select.data('key'), value = $select.val(), $row = $select.closest('tr');
                if (value) {
                    supplierSelections[key] = value;
                    delete newSupplierRows[key];
                    $select.closest('td').find('.vendor-import-new-supplier').prop('checked', false);
                } else {
                    delete supplierSelections[key];
                }
                refreshPreviewState();
            });
        });
    }
    function renderPreview(data) {
        preview = data; supplierSelections = {}; newSupplierRows = {};
        var summary = data.summary || {};
        $('#vendor-import-summary').html('<div class="col-md-2"><div class="vendor-import-stat"><strong>Sheet data</strong><br>'+summary.sheets+'</div></div><div class="col-md-2"><div class="vendor-import-stat"><strong>Baris tiket</strong><br>'+summary.source_rows+'</div></div><div class="col-md-2"><div class="vendor-import-stat"><strong>Siap</strong><br><span id="vendor-import-ready-count">0</span></div></div><div class="col-md-2"><div class="vendor-import-stat"><strong>Perlu diperiksa</strong><br><span id="vendor-import-issue-count">0</span></div></div><div class="col-md-2"><div class="vendor-import-stat"><strong>Tanggal kosong</strong><br>'+summary.date_missing_rows+'</div></div>');
        if (Number(summary.date_missing_rows) > 0) $('#vendor-import-missing-date-box').removeAttr('hidden'); else $('#vendor-import-missing-date-box').attr('hidden', true);
        $('#vendor-import-missing-date').val('');
        var rows = (data.rows || []).map(function (row) {
            var key = row.row_key, errors = (row.row_errors || []).concat(row.row_warnings || []), hasSupplier = row.supplier_match_status === 'matched';
            if (hasSupplier) supplierSelections[key] = row.supplier_id;
            var option = hasSupplier ? '<option value="'+escapeHtml(row.supplier_id)+'" selected>'+escapeHtml(row.supplier_label || 'Supplier cocok')+'</option>' : '<option value="">Pilih supplier</option>';
            var supplierControl = '<select class="form-control vendor-import-select" data-key="'+escapeHtml(key)+'">'+option+'</select><label class="vendor-import-create-label"><input type="checkbox" class="vendor-import-new-supplier" data-key="'+escapeHtml(key)+'" '+(!row.supplier_label ? 'disabled' : '')+'> Buat supplier baru dari label ini</label>';
            var customer = row.customer_label || '-';
            if (row.customer_match_status === 'will_create') customer += ' (akan dibuat)';
            if (row.customer_match_status === 'permission_required') customer += ' (perlu izin customer.create)';
            var manifest = ['Barang: '+escapeHtml(row.goods_name || '-'), 'Jenis: '+escapeHtml(row.goods_type || '-'), 'Kapal: '+escapeHtml(row.vessel_name || '-'), 'Tujuan: '+escapeHtml(row.destination || '-')].join('<br>');
            var status = '<span class="label label-warning vendor-import-status">'+statusText(row.supplier_match_status)+'</span>';
            var tonnage = 'G '+(row.gross_tons == null ? '-' : row.gross_tons)+' / T '+(row.tare_tons == null ? '-' : row.tare_tons)+' / N '+(row.net_tons == null ? '-' : row.net_tons);
            var amount = 'Tarif: '+money(row.vendor_rate_per_ton)+' / Total: '+money(row.vendor_total_amount);
            return '<tr data-vendor-import-key="'+escapeHtml(key)+'"><td>'+escapeHtml(row.source_sheet)+':'+escapeHtml(row.source_row)+'</td><td>'+escapeHtml(row.ticket_no || '-')+'</td><td>'+escapeHtml(row.supplier_label || '-')+'</td><td>'+escapeHtml(customer)+'</td><td>'+manifest+'</td><td>'+escapeHtml(row.vendor_unit_name || '-')+'</td><td>'+escapeHtml(row.vendor_driver_name || '-')+'</td><td class="vendor-import-date">'+escapeHtml(row.date_missing ? 'Perlu diisi' : (row.loading_date || '-'))+'</td><td>'+escapeHtml(tonnage)+'</td><td>'+escapeHtml(amount)+'</td><td>'+status+'</td><td>'+supplierControl+'</td><td><span class="vendor-import-notes">'+window.importHistoryMessageCell(errors.join('\n'), 'display')+'</span></td></tr>';
        }).join('');
        if (table) { table.destroy(); table = null; }
        $('#vendor-import-preview-table tbody').html(rows);
        if ($.fn.DataTable) {
            table = initFleetDataTable('#vendor-import-preview-table', { pageLength: 25, order: [[0, 'asc']], columnDefs: [{ targets: [10, 11, 12], orderable: false }] });
            table.on('draw.dt', refreshPreviewState);
        }
        initSupplierSelects(); $('#vendor-import-preview-box').removeAttr('hidden'); refreshPreviewState();
    }
    $('#vendor-import-upload').on('submit', function (event) {
        event.preventDefault(); var form = this, button = $(form).find('[type="submit"]'); button.prop('disabled', true);
        $.ajax({ url: form.action, type: 'POST', data: new FormData(form), processData: false, contentType: false, headers: { Accept: 'application/json' } }).done(function (response) { token = response.token; feedback(true, response.msg); renderPreview(response.preview); }).fail(function (xhr) { var body = xhr.responseJSON || {}; feedback(false, body.msg || body.message || 'File belum dapat dipreview.'); }).always(function () { button.prop('disabled', false); });
    });
    $('#vendor-import-preview-table').on('change', '.vendor-import-new-supplier', function () {
        var key = $(this).data('key'), $row = $(this).closest('tr');
        if (this.checked) {
            newSupplierRows[key] = true;
            delete supplierSelections[key];
            $row.find('.vendor-import-select').val(null).trigger('change').prop('disabled', true);
        } else {
            delete newSupplierRows[key];
            $row.find('.vendor-import-select').prop('disabled', false);
        }
        refreshPreviewState();
    });
    $('#vendor-import-missing-date').on('change input', refreshPreviewState);
    $('#vendor-import-confirm').on('click', function () {
        if (!token) return; var button = this; button.disabled = true;
        $.ajax({ url: @json(route('asset.fleet.vendor-loadings.import.confirm')), type: 'POST', data: { _token: $('meta[name="csrf-token"]').attr('content'), token: token, supplier_selections: JSON.stringify(supplierSelections), new_supplier_rows: JSON.stringify(newSupplierRows), missing_date: $('#vendor-import-missing-date').val() }, headers: { Accept: 'application/json' } }).done(function (response) { feedback(true, response.msg); if (response.errors && response.errors.length) feedback(true, response.msg+' '+response.errors.slice(0, 3).join(' | ')); window.setTimeout(function () { window.location.href = response.redirect; }, 600); }).fail(function (xhr) { var body = xhr.responseJSON || {}; feedback(false, (body.msg || body.message || 'Import belum berhasil.') + (body.errors ? ' '+body.errors.slice(0, 3).join(' | ') : '')); button.disabled = false; });
    });
});
</script>
@endpush
@endsection
