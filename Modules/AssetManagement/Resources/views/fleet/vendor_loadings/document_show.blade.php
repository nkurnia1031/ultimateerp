@extends('layouts.app')

@section('title', 'Dokumen Loading Vendor '.$document->document_no)

@section('content')
@includeIf('assetmanagement::layouts.nav')
@include('layouts.partials.fleet-datatables')
<section class="content-header">
    <div class="pull-right">
        <a href="{{ route('asset.fleet.vendor-loadings.index') }}" class="btn btn-default">Kembali ke daftar</a>
        <a href="{{ route('asset.fleet.vendor-loadings.documents.print', $document->id) }}" target="_blank" rel="noopener" class="btn btn-default"><i class="fas fa-print"></i> Cetak sheet</a>
        @if($canManage)<a href="{{ route('asset.fleet.vendor-loadings.documents.lines.create', $document->id) }}" class="btn btn-primary"><i class="fas fa-plus"></i> Tambah tiket</a>@endif
    </div>
    <h1><i class="fas fa-file-alt"></i> {{ $document->document_no }}</h1>
    <p class="text-muted">{{ $document->source_workbook ?: 'Input manual' }} · Sheet: {{ $document->source_sheet ?: 'Manual' }}</p>
</section>
<section class="content">
    <div id="vendor-document-feedback" class="alert" role="alert" hidden></div>
    <div class="row">
        <div class="col-lg-8">
            <div class="box box-primary">
                <div class="box-header with-border"><h3 class="box-title">Header Dokumen / Identitas Sheet</h3></div>
                <div class="box-body">
                    <form id="vendor-document-header-form" action="{{ route('asset.fleet.vendor-loadings.documents.header.update', $document->id) }}">
                        <div class="row">
                            <div class="col-md-6 form-group"><label>Bendera / Supplier *</label><select class="form-control" name="supplier_id" id="document-supplier" required><option value="">Pilih supplier</option>@if($document->supplier_id)<option selected value="{{ $document->supplier_id }}">{{ $document->supplier_label }}</option>@endif</select></div>
                            <div class="col-md-6 form-group"><label>Customer *</label><select class="form-control" name="customer_id" id="document-customer" required><option value="">Pilih customer</option>@if($document->customer_id)<option selected value="{{ $document->customer_id }}">{{ $document->customer_label }}</option>@endif</select></div>
                        </div>
                        <div class="row">
                            <div class="col-md-3 form-group"><label>Nama barang *</label><input name="goods_name" maxlength="191" class="form-control" value="{{ $document->goods_name }}" required></div>
                            <div class="col-md-3 form-group"><label>Jenis barang</label><input name="goods_type" maxlength="191" class="form-control" value="{{ $document->goods_type }}"></div>
                            <div class="col-md-3 form-group"><label>Nama kapal *</label><input name="vessel_name" maxlength="191" class="form-control" value="{{ $document->vessel_name }}" required></div>
                            <div class="col-md-3 form-group"><label>Tujuan *</label><input name="destination" maxlength="191" class="form-control" value="{{ $document->destination }}" required></div>
                        </div>
                        <div class="row"><div class="col-md-6 form-group"><label>Rute Dokumen</label><select name="route_id" class="form-control"><option value="">Tanpa rute master</option>@foreach($routes as $id => $name)<option value="{{ $id }}" @selected($document->route_id == $id)>{{ $name }}</option>@endforeach</select></div></div>
                        @if($canManage)<button type="submit" class="btn btn-primary">Simpan Header</button>@endif
                    </form>
                </div>
            </div>
        </div>
        <div class="col-lg-4">
            <div class="box box-info">
                <div class="box-header with-border"><h3 class="box-title">Ringkasan</h3></div>
                <div class="box-body">
                    <p><strong>Jumlah tiket:</strong> <span id="vendor-document-line-count">{{ number_format($document->lines->count(), 0, ',', '.') }}</span></p>
                    <div class="row text-center vendor-document-totals">
                        <div class="col-xs-4"><strong>Gross</strong><br>{{ number_format((float) $document->lines->sum('gross_tons'), 4, ',', '.') }}</div>
                        <div class="col-xs-4"><strong>Tare</strong><br>{{ number_format((float) $document->lines->sum('tare_tons'), 4, ',', '.') }}</div>
                        <div class="col-xs-4"><strong>Nett</strong><br>{{ number_format((float) $document->lines->sum('net_tons'), 4, ',', '.') }}</div>
                    </div>
                    <hr><p><strong>Total nilai (Rp):</strong> <span id="vendor-document-total-amount">Rp {{ number_format((float) $document->lines->sum('vendor_total_amount'), 0, ',', '.') }}</span></p>
                    @if($canManage)<button type="button" id="vendor-document-delete" class="btn btn-danger btn-block" data-url="{{ route('asset.fleet.vendor-loadings.documents.destroy', $document->id) }}">Hapus dokumen dan semua line</button>@endif
                </div>
            </div>
        </div>
    </div>

    <div class="box box-solid">
        <div class="box-header with-border"><h3 class="box-title">Line Angkutan / Tiket</h3><span id="vendor-document-list-count" class="text-muted pull-right">{{ $document->lines->count() }} tiket</span></div>
        <div class="box-body fleet-datatable-wrap">
            <table id="vendor-document-lines" class="table table-bordered table-striped vendor-document-lines fleet-datatable" style="width:100%">
                <thead><tr><th>No</th><th>No. Tiket</th><th>Tanggal</th><th>No. Polisi / Unit</th><th>Keterangan / Sopir</th><th>Gross</th><th>Tare</th><th>Nett</th><th>Ton tagihan</th><th>Harga / Ton (Rp)</th><th>Total (Rp)</th><th>Aksi</th></tr></thead>
                <tbody>
                @foreach($document->lines as $index => $line)
                    <tr data-vendor-line data-gross="{{ (float) $line->gross_tons }}" data-tare="{{ (float) $line->tare_tons }}" data-net="{{ (float) $line->net_tons }}" data-billable="{{ (float) ($line->billable_tons ?: $line->net_tons) }}" data-amount="{{ (float) $line->vendor_total_amount }}">
                        <td>{{ $index + 1 }}</td><td>{{ $line->ticket_no ?: $line->loading_no }}</td><td data-order="{{ optional($line->loading_date)->format('Y-m-d') }}">{{ optional($line->loading_date)->format('d/m/Y') ?: '-' }}</td><td>{{ $line->vendor_unit_name ?: '-' }}</td><td>{{ $line->vendor_driver_name ?: '-' }}</td>
                        <td class="text-right" data-order="{{ (float) $line->gross_tons }}">{{ number_format((float) $line->gross_tons, 4, ',', '.') }}</td><td class="text-right" data-order="{{ (float) $line->tare_tons }}">{{ number_format((float) $line->tare_tons, 4, ',', '.') }}</td><td class="text-right" data-order="{{ (float) $line->net_tons }}">{{ number_format((float) $line->net_tons, 4, ',', '.') }}</td><td class="text-right" data-order="{{ (float) ($line->billable_tons ?: $line->net_tons) }}">{{ number_format((float) ($line->billable_tons ?: $line->net_tons), 4, ',', '.') }}</td>
                        <td class="text-right" data-order="{{ (float) $line->vendor_rate_per_ton }}">{{ $line->vendor_rate_per_ton === null ? '-' : 'Rp '.number_format((float) $line->vendor_rate_per_ton, 0, ',', '.') }}</td><td class="text-right" data-order="{{ (float) $line->vendor_total_amount }}">{{ $line->vendor_total_amount === null ? '-' : 'Rp '.number_format((float) $line->vendor_total_amount, 0, ',', '.') }}</td>
                        <td class="vendor-line-actions">
                            @if($canManage)<a class="btn btn-xs btn-warning" href="{{ route('asset.fleet.vendor-loadings.edit', $line->id) }}">Edit</a> <button type="button" class="btn btn-xs btn-danger vendor-line-delete" data-url="{{ route('asset.fleet.vendor-loadings.destroy', $line->id) }}" data-label="{{ $line->ticket_no ?: $line->loading_no }}">Hapus</button>@endif
                        </td>
                    </tr>
                @endforeach
                </tbody>
                <tfoot><tr class="active"><th colspan="5" class="text-right">SUBTOTAL / TOTAL DOKUMEN</th><th id="vendor-subtotal-gross" class="text-right">{{ number_format((float) $document->lines->sum('gross_tons'), 4, ',', '.') }}</th><th id="vendor-subtotal-tare" class="text-right">{{ number_format((float) $document->lines->sum('tare_tons'), 4, ',', '.') }}</th><th id="vendor-subtotal-net" class="text-right">{{ number_format((float) $document->lines->sum('net_tons'), 4, ',', '.') }}</th><th id="vendor-subtotal-billable" class="text-right">{{ number_format((float) $document->lines->sum(fn ($line) => (float) ($line->billable_tons ?: $line->net_tons)), 4, ',', '.') }}</th><th></th><th id="vendor-subtotal-amount" class="text-right">Rp {{ number_format((float) $document->lines->sum('vendor_total_amount'), 0, ',', '.') }}</th><th></th></tr></tfoot>
            </table>
        </div>
    </div>
</section>
<style>
    .vendor-document-totals > div { padding: 8px 2px; border: 1px solid #e4eaed; }
    .vendor-line-actions { min-width: 160px; white-space: nowrap; }
    .vendor-line-actions .btn { margin: 2px; }
    @media (max-width: 767px) { .content-header .pull-right { float: none !important; margin-bottom: 10px; } }
</style>
@push('scripts')
<script>
$(function () {
    var feedback = $('#vendor-document-feedback');
    var lineTable = window.initFleetDataTable('#vendor-document-lines', {
        searching: true,
        paging: true,
        ordering: true,
        pageLength: 25,
        columnDefs: [
            { targets: [0, 11], orderable: false, searchable: false },
            { targets: 1, className: 'all dtr-control' },
            { targets: 11, className: 'all' }
        ],
        drawCallback: function () {
            var api = this.api();
            var page = api.page.info();
            api.column(0, { page: 'current' }).nodes().each(function (cell, index) {
                cell.textContent = page.start + index + 1;
            });
        },
        language: { emptyTable: 'Belum ada line angkutan pada dokumen ini.' }
    });
    function notify(success, text) {
        var message = text || 'Aksi gagal.';
        if (window.toastr && typeof window.toastr[success ? 'success' : 'error'] === 'function') { window.toastr[success ? 'success' : 'error'](message); return; }
        feedback.removeAttr('hidden').removeClass('alert-success alert-danger').addClass(success ? 'alert-success' : 'alert-danger').text(message);
    }
    function showError(xhr) { var body = xhr.responseJSON || {}; notify(false, body.msg || body.message || 'Aksi gagal.'); }
    function send(url, data, method) {
        return $.ajax({ url: url, type: method || 'POST', data: $.extend({ _token: $('meta[name="csrf-token"]').attr('content') }, data || {}), headers: { Accept: 'application/json' } });
    }
    function formatted(value, decimals) { return Number(value || 0).toLocaleString('id-ID', { minimumFractionDigits: decimals, maximumFractionDigits: decimals }); }
    function refreshLineTotals() {
        var totals = { gross: 0, tare: 0, net: 0, billable: 0, amount: 0 };
        var lines = lineTable ? lineTable.rows().nodes().to$().filter('[data-vendor-line]') : $('[data-vendor-line]');
        lines.each(function (index) {
            var row = $(this);
            totals.gross += Number(row.data('gross') || 0);
            totals.tare += Number(row.data('tare') || 0);
            totals.net += Number(row.data('net') || 0);
            totals.billable += Number(row.data('billable') || 0);
            totals.amount += Number(row.data('amount') || 0);
        });
        var lineCount = lineTable ? lineTable.rows().count() : lines.length;
        $('#vendor-subtotal-gross').text(formatted(totals.gross, 4));
        $('#vendor-subtotal-tare').text(formatted(totals.tare, 4));
        $('#vendor-subtotal-net').text(formatted(totals.net, 4));
        $('#vendor-subtotal-billable').text(formatted(totals.billable, 4));
        $('#vendor-subtotal-amount, #vendor-document-total-amount').text('Rp ' + formatted(totals.amount, 0));
        $('#vendor-document-line-count').text(formatted(lineCount, 0));
        $('#vendor-document-list-count').text(formatted(lineCount, 0) + ' tiket');
    }
    $('#document-supplier').select2({ width: '100%', placeholder: 'Pilih supplier', allowClear: true, ajax: { url: @json(route('asset.fleet.vendor-loadings.suppliers')), dataType: 'json', delay: 250, data: function (p) { return { term: p.term || '' }; }, processResults: function (data) { return data; } } });
    $('#document-customer').select2({ width: '100%', placeholder: 'Pilih customer', allowClear: true, ajax: { url: @json(route('asset.fleet.vendor-loadings.customers')), dataType: 'json', delay: 250, data: function (p) { return { term: p.term || '' }; }, processResults: function (data) { return data; } } });
    $('#vendor-document-header-form').on('submit', function (event) {
        event.preventDefault(); var form = this;
        var values = {}; $.each($(form).serializeArray(), function (_, field) { values[field.name] = field.value; });
        var button = $(form).find('[type="submit"]').prop('disabled', true);
        send(form.action, values, 'PUT').done(function (response) { notify(true, response.msg || 'Header diperbarui.'); }).fail(showError).always(function () { button.prop('disabled', false); });
    });
    $('#vendor-document-lines').on('click', '.vendor-line-delete', function () {
        var button = $(this), row = button.closest('tr');
        if (!window.confirm('Hapus tiket ' + button.data('label') + '?')) return;
        button.prop('disabled', true);
        send(button.data('url'), { _method: 'DELETE' }).done(function (response) {
            if (lineTable) {
                lineTable.row(row.get(0)).remove().draw(false);
            } else {
                row.remove();
            }
            refreshLineTotals();
            notify(true, response.msg || 'Line angkutan berhasil dihapus.');
        }).fail(function (xhr) { showError(xhr); button.prop('disabled', false); });
    });
    $('#vendor-document-delete').on('click', function () { var button = $(this); if (window.confirm('Hapus dokumen beserta seluruh tiket?')) send(button.data('url'), { _method: 'DELETE' }).done(function (response) { notify(true, response.msg || 'Dokumen dan line berhasil dihapus.'); window.setTimeout(function () { window.location.href = @json(route('asset.fleet.vendor-loadings.index')); }, 500); }).fail(showError); });
});
</script>
@endpush
@endsection
