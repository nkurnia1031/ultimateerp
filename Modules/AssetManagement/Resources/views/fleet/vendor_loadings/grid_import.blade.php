@extends('layouts.app')

@section('title', 'Import Loading Vendor')

@section('content')
@includeIf('assetmanagement::layouts.nav')
@include('layouts.partials.fleet-datatables')
<section class="content-header">
    <a href="{{ route('asset.fleet.vendor-loadings.index') }}" class="btn btn-default pull-right">Kembali</a>
    <h1><i class="fas fa-file-import"></i> Import Loading Vendor</h1>
    <p class="text-muted">Setiap sheet menjadi satu dokumen. Periksa bendera/customer pada header sheet dan koreksi tanggal langsung pada grid sebelum konfirmasi.</p>
</section>
<section class="content" id="vendor-import-app">
    <div id="vendor-import-feedback" class="alert" role="alert" hidden></div>
    <div class="box box-primary">
        <div class="box-header with-border"><h3 class="box-title">Upload Workbook</h3></div>
        <div class="box-body">
            <form id="vendor-import-upload" method="POST" action="{{ route('asset.fleet.vendor-loadings.import.preview') }}" enctype="multipart/form-data">
                @csrf
                <div class="form-group"><label for="vendor-import-file">File Loading Vendor</label><input id="vendor-import-file" type="file" name="file" class="form-control" accept=".xlsx,.xls,.csv" required><small class="text-muted">Semua sheet akan dibaca. Preview belum menyimpan data.</small></div>
                <button class="btn btn-primary" type="submit"><i class="fas fa-search"></i> Upload &amp; Preview</button>
                <a href="{{ route('asset.fleet.vendor-loadings.import.template') }}" class="btn btn-success"><i class="fas fa-download"></i> Download Template XLSX</a>
            </form>
            <div id="vendor-import-upload-progress" class="vendor-import-progress" hidden>
                <strong id="vendor-import-upload-title">Mengunggah file…</strong>
                <div class="progress"><div id="vendor-import-upload-bar" class="progress-bar progress-bar-info progress-bar-striped active" role="progressbar" style="width:0%">0%</div></div>
                <div id="vendor-import-upload-message" class="text-muted">Menunggu upload.</div>
            </div>
        </div>
    </div>

    <div id="vendor-import-batch-progress" class="box box-info vendor-import-progress" hidden>
        <div class="box-header with-border"><h3 class="box-title"><i class="fas fa-tasks"></i> Status Batch Import</h3></div>
        <div class="box-body">
            <div class="vendor-import-batch-heading"><strong id="vendor-import-batch-title">Batch import</strong><span id="vendor-import-batch-status" class="label label-info">Menunggu</span></div>
            <div class="progress"><div id="vendor-import-batch-bar" class="progress-bar progress-bar-info progress-bar-striped active" role="progressbar" style="width:0%">0%</div></div>
            <p id="vendor-import-batch-message" class="text-muted">Status batch akan muncul di sini.</p>
            <div id="vendor-import-batch-counters" class="vendor-import-batch-counters"></div>
            <div id="vendor-import-batch-errors" class="alert alert-warning" hidden></div>
        </div>
    </div>

    <div class="box box-default" id="vendor-import-history">
        <div class="box-header with-border"><h3 class="box-title"><i class="fas fa-history"></i> Riwayat Import Vendor</h3></div>
        <div class="box-body">
            @if($canReverseImports)
                <p class="text-muted">Reverse hanya tersedia selama dokumen belum diverifikasi dan belum tertaut ke transaksi Expense. Dokumen dan line Loading Vendor dari batch akan dihapus.</p>
            @endif
            <div class="form-group vendor-import-history-filter">
                <label for="vendor-import-history-status">Filter status</label>
                <select id="vendor-import-history-status" class="form-control input-sm">
                    <option value="">Semua status</option>
                    <option value="queued_preview">Menunggu analisis</option>
                    <option value="analyzing">Menganalisis</option>
                    <option value="preview_ready">Preview siap</option>
                    <option value="queued_import">Menunggu import</option>
                    <option value="importing">Mengimpor</option>
                    <option value="imported">Selesai</option>
                    <option value="partial">Selesai sebagian</option>
                    <option value="failed">Gagal</option>
                    <option value="expired">Kedaluwarsa</option>
                    <option value="reverted">Di-reverse</option>
                </select>
            </div>
            <div class="table-responsive fleet-datatable-wrap">
                <table id="vendor-import-history-table" class="table table-bordered table-striped table-condensed fleet-datatable vendor-import-history-table" style="width:100%">
                    <thead><tr><th>Dibuat</th><th>Workbook</th><th>Status</th><th>Progres</th><th>Hasil</th><th>Pesan</th><th>Aksi</th></tr></thead>
                    <tbody></tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="modal fade vendor-import-detail-modal" id="vendor-import-detail-modal" tabindex="-1" role="dialog" aria-labelledby="vendor-import-detail-title" aria-hidden="true">
        <div class="modal-dialog modal-lg" role="document"><div class="modal-content">
            <div class="modal-header"><button type="button" class="close" data-dismiss="modal" aria-label="Tutup"><span aria-hidden="true">&times;</span></button><h4 class="modal-title" id="vendor-import-detail-title">Detail batch import</h4></div>
            <div class="modal-body">
                <div id="vendor-import-detail-summary" class="alert alert-info" role="status"></div>
                <div class="table-responsive fleet-datatable-wrap"><table id="vendor-import-detail-table" class="table table-bordered table-striped table-condensed fleet-datatable" style="width:100%"><thead><tr><th>Sheet</th><th>Baris</th><th>No. tiket</th><th>Status</th><th>Dokumen / line</th><th>Catatan</th></tr></thead><tbody></tbody></table></div>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-default" data-dismiss="modal">Tutup</button></div>
        </div></div>
    </div>
    <div id="vendor-import-preview-box" class="box box-info" hidden>
        <div class="box-header with-border"><h3 class="box-title">Preview per Sheet</h3></div>
        <div class="box-body">
            <div id="vendor-import-summary" class="row"></div>
            <div class="alert alert-info"><strong>Perubahan tersimpan saat konfirmasi.</strong> Grid dipisah per sheet. <strong>Double klik cell bertanda pensil pada kolom Tanggal untuk mengedit.</strong> Isian tanggal bersama hanya mengisi tanggal yang kosong pada sheet aktif. Supplier, customer, dan rute diatur dari header sheet.</div>
            <div id="vendor-import-tabs" class="vendor-import-tabs" role="tablist"></div>
            <div id="vendor-import-sheet-header" class="vendor-import-sheet-header"></div>
            <div class="vendor-import-grid-tools">
                <label for="vendor-import-filter">Filter status</label>
                <select id="vendor-import-filter" class="form-control input-sm">
                    <option value="all">Semua status</option>
                    <option value="issues">Perlu diperiksa</option>
                    <option value="ready">Siap</option>
                </select>
                <span class="text-muted">Kolom Tanggal dengan tanda <i class="fas fa-pen"></i> bisa diedit dengan double klik.</span>
            </div>
            <div class="vendor-import-bulk-date row">
                <div class="col-sm-3 form-group"><label for="vendor-import-missing-date">Isi tanggal kosong di sheet ini</label><input id="vendor-import-missing-date" type="date" class="form-control"></div>
                <div class="col-sm-9"><p class="help-block">Tanggal yang sudah ada tidak akan berubah. Tanggal tiap tiket juga dapat diedit langsung pada grid.</p><button type="button" class="btn btn-default" id="vendor-import-apply-date">Terapkan ke tanggal kosong</button></div>
            </div>
            <div id="vendor-import-grid" class="ag-theme-quartz vendor-import-grid" aria-label="Preview tiket pada sheet aktif"></div>
            <div class="vendor-import-footer">
                <span>Siap di sheet ini: <strong id="vendor-import-sheet-ready">0</strong></span>
                <span>Perlu diperiksa: <strong id="vendor-import-sheet-issues">0</strong></span>
                <button type="button" id="vendor-import-confirm" class="btn btn-success" disabled><i class="fas fa-check"></i> Konfirmasi &amp; Antrekan Import</button>
                <a href="{{ route('asset.fleet.vendor-loadings.import.form') }}" class="btn btn-default">Batalkan Preview</a>
            </div>
            <div id="vendor-import-confirm-errors" class="alert alert-warning" hidden></div>
        </div>
    </div>
</section>
<style>
    .vendor-import-stat { padding: 10px 14px; border: 1px solid #dce7ea; border-radius: 4px; margin-bottom: 12px; background: #f8fbfc; min-height: 64px; }
    .vendor-import-tabs { display: flex; flex-wrap: nowrap; gap: 5px; overflow-x: auto; padding: 0 0 10px; border-bottom: 1px solid #d9e1e5; }
    .vendor-import-tab { flex: 0 0 auto; white-space: nowrap; border: 1px solid #ccd8de; border-bottom: 0; border-radius: 4px 4px 0 0; background: #f3f6f7; padding: 9px 12px; color: #31454e; }
    .vendor-import-tab.active { background: #fff; color: #174761; font-weight: 700; box-shadow: inset 0 3px #2783a3; }
    .vendor-import-tab .badge { margin-left: 5px; }
    .vendor-import-sheet-header { border: 1px solid #d9e1e5; border-top: 0; padding: 15px; background: #fbfcfd; }
    .vendor-import-metadata { margin: 0; line-height: 1.8; }
    .vendor-import-metadata dt { float: left; clear: left; width: 115px; }
    .vendor-import-metadata dd { margin-left: 120px; }
    #vendor-import-grid { display: block; width: 100%; min-height: 430px !important; height: min(62vh, 720px) !important; margin: 0 0 14px; }
    .vendor-import-bulk-date { padding: 12px 8px 2px; }
    .vendor-import-footer { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; }
    .vendor-import-footer span { margin-right: auto; }
    .vendor-import-progress { margin-top: 14px; }
    .vendor-import-progress .progress { height: 20px; margin: 10px 0 7px; }
    .vendor-import-batch-heading { display: flex; align-items: center; justify-content: space-between; gap: 10px; }
    .vendor-import-batch-counters { display: flex; flex-wrap: wrap; gap: 8px 18px; color: #53636a; }
    .vendor-import-grid-tools { display: flex; align-items: center; gap: 10px; padding: 10px 0; flex-wrap: wrap; }
    .vendor-import-grid-tools select { width: auto; min-width: 170px; }
    .vendor-import-editable-cell { background: #fff8d6 !important; border-left: 2px solid #d8a900 !important; }
    .vendor-import-editable-cell:after { content: ' ✎'; color: #997400; font-size: 11px; }
    .vendor-import-history-table { width: 100%; table-layout: fixed; }
    .vendor-import-history-table th, .vendor-import-history-table td { overflow-wrap: anywhere; }
    .vendor-import-history-actions { display: flex; flex-wrap: wrap; gap: 4px; }
    .vendor-import-history-actions .btn { white-space: normal; }
    .vendor-import-row-issue { background-color: #fff4f0 !important; }
    .vendor-import-row-warning { background-color: #fffbea !important; }
    @media (max-width: 767px) {
        #vendor-import-grid { height: 62vh !important; min-height: 360px !important; }
        .vendor-import-footer { align-items: stretch; }
        .vendor-import-footer span { width: 100%; }
        .vendor-import-footer .btn { width: 100%; margin: 0; }
        .vendor-import-metadata dt { width: 92px; }
        .vendor-import-metadata dd { margin-left: 97px; }
    }
</style>
@push('scripts')
<script>
window.vendorLoadingImportConfig = {
    formUrl: @json(route('asset.fleet.vendor-loadings.import.form')),
    previewUrl: @json(route('asset.fleet.vendor-loadings.import.preview')),
    confirmUrl: @json(route('asset.fleet.vendor-loadings.import.confirm')),
    statusUrlTemplate: @json(route('asset.fleet.vendor-loadings.import.batches.status', ['id' => '__ID__'])),
    batchPreviewUrlTemplate: @json(route('asset.fleet.vendor-loadings.import.batches.preview', ['id' => '__ID__'])),
    historyUrl: @json(route('asset.fleet.vendor-loadings.import.batches.index')),
    detailUrlTemplate: @json(route('asset.fleet.vendor-loadings.import.batches.details', ['id' => '__ID__'])),
    downloadUrlTemplate: @json(route('asset.fleet.vendor-loadings.import.batches.download', ['id' => '__ID__'])),
    retryUrlTemplate: @json(route('asset.fleet.vendor-loadings.import.batches.retry', ['id' => '__ID__'])),
    reverseUrlTemplate: @json(route('asset.fleet.vendor-loadings.import.batches.reverse', ['id' => '__ID__'])),
    canReverseImports: @json($canReverseImports),
    resumeBatchId: @json($resumeBatchId),
    supplierUrl: @json(route('asset.fleet.vendor-loadings.suppliers')),
    customerUrl: @json(route('asset.fleet.vendor-loadings.customers')),
    routes: @json($routes),
    canCreateSupplier: @json($canCreateSupplier),
    canCreateCustomer: @json($canCreateCustomer),
    csrf: @json(csrf_token())
};
</script>
<script src="{{ versioned_asset_url(asset('modules/assetmanagement/js/vendor/ag-grid-community-36.2.0.min.js')) }}"></script>
<script src="{{ versioned_asset_url(asset('modules/assetmanagement/js/vendor-loading-import.js')) }}"></script>
@endpush
@endsection
