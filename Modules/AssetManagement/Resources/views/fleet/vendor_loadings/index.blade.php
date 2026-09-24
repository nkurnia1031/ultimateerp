@extends('layouts.app')

@section('title', 'Loading Vendor')

@section('content')
@includeIf('assetmanagement::layouts.nav')
@include('layouts.partials.fleet-datatables')
<section class="content-header">
    <div class="pull-right">
        <a href="{{ route('asset.fleet.vendor-loadings.import.form') }}" class="btn btn-warning"><i class="fas fa-file-import"></i> Import Loading Vendor</a>
        <a href="{{ route('asset.fleet.vendor-loadings.create') }}" class="btn btn-primary"><i class="fas fa-plus"></i> Tambah Dokumen</a>
    </div>
    <h1><i class="fas fa-truck-loading"></i> Loading Vendor</h1>
    <p class="text-muted">Satu dokumen mewakili satu sheet/workbook; buka dokumen untuk melihat dan mengelola tiket angkutan.</p>
</section>
<section class="content">
    <div id="vendor-loading-feedback" class="alert" role="alert" hidden></div>
    <div class="box box-primary">
        <div class="box-header with-border"><h3 class="box-title">Filter Dokumen</h3><button type="button" id="vendor-loading-print" class="btn btn-default pull-right"><i class="fas fa-print"></i> Cetak Hasil</button></div>
        <div class="box-body">
            <form id="vendor-loading-filter" class="row">
                <div class="col-md-2 form-group"><label>Dari tanggal tiket</label><input type="date" name="start_date" class="form-control"></div>
                <div class="col-md-2 form-group"><label>Sampai tanggal tiket</label><input type="date" name="end_date" class="form-control"></div>
                <div class="col-md-2 form-group"><label>Bendera / Supplier</label><input type="search" name="supplier_search" class="form-control" placeholder="Cari nama supplier"></div>
                <div class="col-md-2 form-group"><label>Rute Dokumen</label><select name="route_id" class="form-control"><option value="">Semua rute</option>@foreach($routes as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach</select></div>
                <div class="col-md-2 form-group" style="padding-top:25px"><button type="submit" class="btn btn-default">Terapkan</button> <button type="button" id="vendor-loading-filter-reset" class="btn btn-link">Reset</button></div>
            </form>
        </div>
    </div>
    <div class="box box-solid" id="vendor-loading-summary-box">
        <div class="box-header with-border"><h3 class="box-title">Subtotal dan Total Hasil Filter</h3><small class="text-muted pull-right">Ringkasan mengikuti filter dan pencarian tabel.</small></div>
        <div class="box-body table-responsive">
            <table class="table table-bordered table-striped vendor-loading-summary-table">
                <thead><tr><th>Bendera / Supplier</th><th class="text-right">Dokumen</th><th class="text-right">Tiket</th><th class="text-right">Gross</th><th class="text-right">Tare</th><th class="text-right">Nett</th><th class="text-right">Ton tagihan</th><th class="text-right">Subtotal nilai (Rp)</th></tr></thead>
                <tbody id="vendor-loading-summary-body"><tr><td colspan="8" class="text-muted">Memuat ringkasan...</td></tr></tbody>
                <tfoot id="vendor-loading-summary-foot"></tfoot>
            </table>
        </div>
    </div>
    <div class="box box-solid">
        <div class="box-header with-border"><h3 class="box-title">Dokumen Loading Vendor per Sheet</h3></div>
        <div class="box-body table-responsive fleet-datatable-wrap">
            <table id="vendor-loadings-table" class="table table-bordered table-striped fleet-datatable" style="width:100%">
                <thead><tr><th>No. Dokumen</th><th>Sheet sumber</th><th>Bendera / Supplier</th><th>Customer</th><th>Barang / Kapal / Tujuan</th><th class="text-right">Tiket</th><th>Gross / Tare / Nett</th><th class="text-right">Subtotal nilai (Rp)</th><th>Aksi</th></tr></thead>
            </table>
        </div>
    </div>
</section>
<style>
    #vendor-loadings-table td { vertical-align: middle; }
    #vendor-loadings-table .btn { margin: 2px 0; white-space: nowrap; }
    .vendor-loading-summary-table th, .vendor-loading-summary-table td { vertical-align: middle !important; }
    .vendor-loading-summary-table .summary-total { font-weight: 700; background: #eaf1f5; }
    @media (max-width: 767px) { #vendor-loading-summary-box .box-header small { float: none !important; display: block; margin-top: 6px; } }
</style>
@push('scripts')
<script>
$(function () {
    var $feedback = $('#vendor-loading-feedback'), $summaryBody = $('#vendor-loading-summary-body'), $summaryFoot = $('#vendor-loading-summary-foot');
    function number(value, decimals) { return Number(value || 0).toLocaleString('id-ID', { minimumFractionDigits: decimals, maximumFractionDigits: decimals }); }
    function money(value) { return 'Rp ' + number(value, 0); }
    function renderSummary(summary) {
        var groups = summary.groups || [], totals = summary.totals || {};
        $summaryBody.html(groups.length ? groups.map(function (group) {
            return '<tr><td>'+ $('<div>').text(group.supplier_name || 'Tanpa Bendera').html() +'</td><td class="text-right">'+number(group.document_count, 0)+'</td><td class="text-right">'+number(group.record_count, 0)+'</td><td class="text-right">'+number(group.gross_tons, 2)+'</td><td class="text-right">'+number(group.tare_tons, 2)+'</td><td class="text-right">'+number(group.net_tons, 2)+'</td><td class="text-right">'+number(group.billable_tons, 2)+'</td><td class="text-right">'+money(group.vendor_total_amount)+'</td></tr>';
        }).join('') : '<tr><td colspan="8" class="text-muted">Tidak ada hasil untuk diringkas.</td></tr>');
        $summaryFoot.html('<tr class="summary-total"><th>TOTAL</th><th class="text-right">'+number(totals.document_count || 0, 0)+'</th><th class="text-right">'+number(totals.record_count, 0)+'</th><th class="text-right">'+number(totals.gross_tons, 2)+'</th><th class="text-right">'+number(totals.tare_tons, 2)+'</th><th class="text-right">'+number(totals.net_tons, 2)+'</th><th class="text-right">'+number(totals.billable_tons, 2)+'</th><th class="text-right">'+money(totals.vendor_total_amount)+'</th></tr>');
    }
    var table = $('#vendor-loadings-table').DataTable($.extend(true, {}, {
        processing: true, serverSide: true, responsive: false, scrollX: true, autoWidth: false,
        ajax: {
            url: @json(route('asset.fleet.vendor-loadings.data')),
            data: function (d) { $('#vendor-loading-filter').serializeArray().forEach(function (item) { d[item.name] = item.value; }); },
            dataSrc: function (response) { renderSummary(response.summary || {}); return response.data || []; },
            error: function (xhr) { var body = xhr.responseJSON || {}; feedback(false, body.msg || body.message || 'Daftar Loading Vendor gagal dimuat.'); }
        },
        order: [[0, 'desc']], pageLength: 25,
        language: { processing: 'Memuat data...', search: 'Cari:', searchPlaceholder: 'Dokumen, sheet, tiket, supplier, customer, barang...', lengthMenu: 'Tampilkan _MENU_ dokumen', info: 'Menampilkan _START_–_END_ dari _TOTAL_ dokumen', infoEmpty: 'Belum ada dokumen', infoFiltered: '(disaring dari _MAX_ dokumen)', zeroRecords: 'Dokumen tidak ditemukan', emptyTable: 'Belum ada Loading Vendor', paginate: { first: 'Awal', last: 'Akhir', next: 'Berikutnya', previous: 'Sebelumnya' } },
        columns: [
            { data: 'document_no', name: 'document_no' }, { data: 'source_sheet', name: 'source_sheet' }, { data: 'supplier', name: 'supplier_name_snapshot' },
            { data: 'customer', name: 'customer_name_snapshot' }, { data: 'manifest', name: 'goods_name', orderable: false },
            { data: 'line_count', name: 'created_at', className: 'text-right' }, { data: 'tons', name: 'created_at', orderable: false },
            { data: 'amount', name: 'created_at', className: 'text-right' }, { data: 'actions', orderable: false, searchable: false }
        ],
        columnDefs: [{ targets: [0, 2, 8], className: 'all' }]
    }));
    function feedback(success, text, level) {
        var message = text || 'Aksi tidak dapat diselesaikan.';
        var type = level || (success ? 'success' : 'error');
        if (window.toastr && typeof window.toastr[type] === 'function') { window.toastr[type](message); return; }
        $feedback.removeAttr('hidden').removeClass('alert-success alert-danger alert-warning').addClass(type === 'success' ? 'alert-success' : (type === 'warning' ? 'alert-warning' : 'alert-danger')).text(message);
    }
    function post(url, values) {
        $.ajax({ url: url, type: 'POST', data: $.extend({ _token: $('meta[name="csrf-token"]').attr('content') }, values || {}), headers: { Accept: 'application/json' } })
            .done(function (response) { feedback(true, response.msg || 'Perubahan berhasil.'); table.ajax.reload(null, false); })
            .fail(function (xhr) { var body = xhr.responseJSON || {}; feedback(false, body.msg || body.message || 'Aksi tidak dapat diselesaikan.'); });
    }
    $('#vendor-loading-filter').on('submit', function (event) { event.preventDefault(); table.ajax.reload(); });
    $('#vendor-loading-filter-reset').on('click', function () { $('#vendor-loading-filter')[0].reset(); table.ajax.reload(); });
    $('#vendor-loading-print').on('click', function () {
        var params = {}; $('#vendor-loading-filter').serializeArray().forEach(function (item) { params[item.name] = item.value; });
        params.search = table.search(); window.open(@json(route('asset.fleet.vendor-loadings.print')) + '?' + $.param(params), '_blank', 'noopener');
    });
    $('#vendor-loadings-table').on('click', '.vendor-loading-delete', function () {
        var button = $(this);
        if (window.confirm('Hapus dokumen ' + (button.data('loading-no') || '') + ' beserta seluruh line?')) post(button.data('url'), { _method: 'DELETE' });
    });
});
</script>
@endpush
@endsection
