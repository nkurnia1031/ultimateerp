@extends('layouts.app')

@section('title', 'Import Loading Vendor')

@section('content')
@includeIf('assetmanagement::layouts.nav')
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
        <div class="box-header with-border"><h3 class="box-title"><i class="fas fa-history"></i> Riwayat Import Anda</h3></div>
        <div class="box-body table-responsive">
            @if($canReverseImports)
                <p class="text-muted">Batch yang selesai dapat di-reverse. Dokumen dan line Loading Vendor dari batch akan dihapus; transaksi Expense yang sudah ada tetap disimpan.</p>
            @endif
            @if($batches->isEmpty())
                <p class="text-muted">Belum ada batch import Loading Vendor.</p>
            @else
                <table class="table table-striped table-condensed vendor-import-history-table">
                    <thead><tr><th>File</th><th>Status</th><th>Progress</th><th>Hasil</th><th>Diperbarui</th><th></th></tr></thead>
                    <tbody>
                    @foreach($batches as $batch)
                        @php
                            $batchStatusLabels = ['queued_preview' => 'Menunggu analisis', 'analyzing' => 'Menganalisis', 'preview_ready' => 'Preview siap', 'queued_import' => 'Menunggu import', 'importing' => 'Mengimpor', 'imported' => 'Selesai', 'partial' => 'Selesai sebagian', 'failed' => 'Gagal', 'expired' => 'Preview kedaluwarsa', 'reversed' => 'Data impor dihapus'];
                        @endphp
                        <tr data-import-batch-row="{{ $batch->id }}">
                            <td>{{ $batch->file_name }}</td>
                            <td><span class="label label-default">{{ $batchStatusLabels[$batch->status] ?? $batch->status }}</span></td>
                            <td>{{ $batch->processed_sheets }}/{{ $batch->total_sheets }} sheet · {{ $batch->processed_rows }}/{{ $batch->total_rows }} baris</td>
                            <td>@if($batch->status === 'reversed') {{ $batch->reversed_documents ?? 0 }} dokumen / {{ $batch->reversed_lines ?? 0 }} line dihapus @else {{ $batch->imported_lines }} line / {{ $batch->skipped_rows }} dilewati @endif</td>
                            <td>{{ $batch->updated_at }}</td>
                            <td>
                                <a class="btn btn-xs btn-default" href="{{ route('asset.fleet.vendor-loadings.import.form', ['batch_id' => $batch->id]) }}">Pantau / Buka</a>
                                @if($canReverseImports)
                                    <button type="button" class="btn btn-xs btn-danger vendor-import-reverse" data-batch-id="{{ $batch->id }}" @if(!in_array($batch->status, ['imported', 'partial'], true)) style="display:none" @endif><i class="fas fa-undo"></i> Reverse import</button>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            @endif
        </div>
    </div>

    <div id="vendor-import-preview-box" class="box box-info" hidden>
        <div class="box-header with-border"><h3 class="box-title">Preview per Sheet</h3></div>
        <div class="box-body">
            <div id="vendor-import-summary" class="row"></div>
            <div class="alert alert-info"><strong>Perubahan tersimpan saat konfirmasi.</strong> Grid dipisah per sheet. <strong>Double klik cell bertanda pensil pada kolom Tanggal untuk mengedit.</strong> Isian tanggal bersama hanya mengisi tanggal yang kosong pada sheet aktif. Supplier, customer, dan rute diatur dari header sheet.</div>
            <div id="vendor-import-tabs" class="vendor-import-tabs" role="tablist"></div>
            <div id="vendor-import-sheet-header" class="vendor-import-sheet-header"></div>
            <div class="vendor-import-grid-tools">
                <label for="vendor-import-filter">Tampilkan</label>
                <select id="vendor-import-filter" class="form-control input-sm">
                    <option value="all">Semua baris</option>
                    <option value="issues">Perlu diperiksa</option>
                    <option value="ready">Siap diimpor</option>
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
    .vendor-import-history-table { min-width: 760px; }
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
    previewUrl: @json(route('asset.fleet.vendor-loadings.import.preview')),
    confirmUrl: @json(route('asset.fleet.vendor-loadings.import.confirm')),
    statusUrlTemplate: @json(route('asset.fleet.vendor-loadings.import.batches.status', ['id' => '__ID__'])),
    batchPreviewUrlTemplate: @json(route('asset.fleet.vendor-loadings.import.batches.preview', ['id' => '__ID__'])),
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
