@extends('layouts.app')

@section('title', 'Loading Vendor')

@section('content')
@includeIf('assetmanagement::layouts.nav')
@include('layouts.partials.fleet-datatables')
<section class="content-header">
    <div class="pull-right">
        <a href="{{ route('asset.fleet.vendor-loadings.import.form') }}" class="btn btn-warning"><i class="fas fa-file-import"></i> Import Loading Vendor</a>
        <a href="{{ route('asset.fleet.vendor-loadings.create') }}" class="btn btn-primary"><i class="fas fa-plus"></i> Tambah Loading Vendor</a>
    </div>
    <h1><i class="fas fa-truck-loading"></i> Loading Vendor</h1>
    <p class="text-muted">Catat perjalanan dari unit dan sopir milik vendor. Data ini belum menjadi invoice atau Expense otomatis.</p>
</section>
<section class="content">
    <div id="vendor-loading-feedback" class="alert" role="alert" hidden></div>
    <div class="box box-primary">
        <div class="box-header with-border"><h3 class="box-title">Filter Loading Vendor</h3></div>
        <div class="box-body">
            <form id="vendor-loading-filter" class="row">
                <div class="col-md-2 form-group"><label>Dari</label><input type="date" name="start_date" class="form-control"></div>
                <div class="col-md-2 form-group"><label>Sampai</label><input type="date" name="end_date" class="form-control"></div>
                <div class="col-md-2 form-group"><label>Bendera / Supplier</label><input type="search" name="supplier_search" class="form-control" placeholder="Cari nama supplier"></div>
                <div class="col-md-2 form-group"><label>Status</label><select name="status" class="form-control"><option value="">Semua status</option><option value="draft">Draft</option><option value="loading">Loading</option><option value="in_transit">Dalam Perjalanan</option><option value="completed">Selesai</option><option value="verified">Terverifikasi</option><option value="cancelled">Dibatalkan</option></select></div>
                <div class="col-md-2 form-group"><label>Status Expense</label><select name="expense_status" class="form-control"><option value="">Semua</option><option value="not_linked">Belum dikaitkan</option><option value="draft">Draft Expense</option><option value="posted">Posted</option></select></div>
                <div class="col-md-2 form-group" style="padding-top:25px"><button type="submit" class="btn btn-default">Terapkan</button> <button type="button" id="vendor-loading-filter-reset" class="btn btn-link">Reset</button></div>
            </form>
        </div>
    </div>
    <div class="box box-solid">
        <div class="box-header with-border"><h3 class="box-title">Daftar Loading Vendor</h3></div>
        <div class="box-body table-responsive fleet-datatable-wrap">
            <table id="vendor-loadings-table" class="table table-bordered table-striped fleet-datatable" style="width:100%">
                <thead><tr><th>No. Loading / Tiket</th><th>Tanggal</th><th>Supplier</th><th>Unit Vendor</th><th>Sopir Vendor</th><th>Rute</th><th>Project</th><th>Ton</th><th>Nilai Vendor</th><th>Status</th><th>Aksi</th></tr></thead>
            </table>
        </div>
    </div>
</section>
@push('styles')
<style>
    #vendor-loadings-table td { vertical-align: middle; }
    #vendor-loadings-table .btn { margin: 2px 0; white-space: nowrap; }
    .vendor-loading-action-feedback { margin-top: 10px; }
</style>
@endpush
@push('scripts')
<script>
$(function () {
    var $feedback = $('#vendor-loading-feedback');
    var table = $('#vendor-loadings-table').DataTable($.extend(true, {}, {
        processing: true,
        serverSide: true,
        responsive: true,
        autoWidth: false,
        ajax: {
            url: @json(route('asset.fleet.vendor-loadings.data')),
            data: function (d) {
                $('#vendor-loading-filter').serializeArray().forEach(function (item) { d[item.name] = item.value; });
            }
        },
        order: [[1, 'desc']],
        pageLength: 25,
        language: {
            processing: 'Memuat data...', search: 'Cari:', searchPlaceholder: 'No tiket, supplier, unit, sopir...',
            lengthMenu: 'Tampilkan _MENU_ data', info: 'Menampilkan _START_–_END_ dari _TOTAL_ data', infoEmpty: 'Belum ada data',
            infoFiltered: '(disaring dari _MAX_ data)', zeroRecords: 'Data tidak ditemukan', emptyTable: 'Belum ada Loading Vendor',
            paginate: { first: 'Awal', last: 'Akhir', next: 'Berikutnya', previous: 'Sebelumnya' }
        },
        columns: [
            { data: 'loading_no', name: 'loading_no' }, { data: 'loading_date', name: 'loading_date' }, { data: 'supplier', name: 'supplier_name_snapshot' },
            { data: 'unit', name: 'vendor_unit_name' }, { data: 'driver', name: 'vendor_driver_name' }, { data: 'route', name: 'route_name_snapshot' },
            { data: 'project', name: 'project_id' }, { data: 'tons', name: 'billable_tons' }, { data: 'amount', name: 'vendor_total_amount' },
            { data: 'status', name: 'status' }, { data: 'actions', orderable: false, searchable: false }
        ],
        columnDefs: [{ targets: [0, 2, 3, 4, 5, 6, 9, 10], className: 'all' }]
    }));

    function showFeedback(success, message) {
        $feedback.removeAttr('hidden').removeClass('alert-success alert-danger').addClass(success ? 'alert-success' : 'alert-danger').text(message || 'Terjadi kesalahan.');
    }
    function ajaxAction(url, data, successMessage) {
        $.ajax({ url: url, type: 'POST', data: $.extend({ _token: $('meta[name="csrf-token"]').attr('content') }, data || {}), headers: { Accept: 'application/json' } })
            .done(function (response) { showFeedback(true, response.msg || successMessage); table.ajax.reload(null, false); })
            .fail(function (xhr) { var body = xhr.responseJSON || {}; showFeedback(false, body.msg || body.message || 'Aksi tidak dapat diselesaikan.'); });
    }
    $('#vendor-loading-filter').on('submit', function (event) { event.preventDefault(); table.ajax.reload(); });
    $('#vendor-loading-filter-reset').on('click', function () { $('#vendor-loading-filter')[0].reset(); table.ajax.reload(); });
    $('#vendor-loadings-table').on('click', '.vendor-loading-delete', function () {
        if (! window.confirm('Hapus Loading Vendor ini? Data draft akan dihapus permanen.')) return;
        ajaxAction($(this).data('url'), { _method: 'DELETE' }, 'Loading Vendor dihapus.');
    });
    $('#vendor-loadings-table').on('click', '.vendor-loading-status', function () {
        var button = $(this);
        var label = button.data('status') === 'verified' ? 'Verifikasi' : 'Lanjutkan status';
        if (! window.confirm(label + ' Loading Vendor ini?')) return;
        ajaxAction(button.data('url'), { status: button.data('status') }, 'Status diperbarui.');
    });
});
</script>
@endpush
@endsection
