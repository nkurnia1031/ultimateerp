@extends('layouts.app')

@section('title', 'Tarif Angkutan')

@section('content')
@includeIf('assetmanagement::layouts.nav')
@include('layouts.partials.fleet-datatables')
@php
    $canManageRates = auth()->user()->can('superadmin')
        || auth()->user()->can('asset.fleet_rates.manage')
        || auth()->user()->can('asset.create')
        || auth()->user()->can('asset.update');
    // The rate rows are loaded by server-side DataTables. Keep these empty
    // fallbacks so the legacy empty-state markup remains harmless.
    $rates = collect();
    $allAssets = collect();
    $rateIssues = [];
    $batchIssues = $batchIssues ?? [];
    $hasPreviewIssues = (bool) ($hasPreviewIssues ?? false);
@endphp
<style>
    .fleet-rate-index { max-width: 1480px; margin: 0 auto; padding-bottom: 40px; }
    .fleet-rate-index__header { display: flex; justify-content: space-between; align-items: flex-end; gap: 20px; margin: 0 0 18px; }
    .fleet-rate-index__header h1 { margin-bottom: 6px; }
    .fleet-rate-index__header p { margin: 0; color: #60717b; }
    .fleet-rate-index__actions { display: flex; flex-wrap: wrap; justify-content: flex-end; gap: 8px; }
    .fleet-rate-index .rate-crud-btn, .fleet-rate-index .rate-secondary-btn { display: inline-flex; align-items: center; justify-content: center; gap: 6px; min-height: 40px; padding: 8px 13px; border-radius: 6px; white-space: nowrap; }
    .fleet-rate-index .rate-secondary-btn { color: #334e5b; background: #f7fafb; border: 1px solid #b8cbd0; }
    .fleet-rate-index .rate-secondary-btn:hover, .fleet-rate-index .rate-secondary-btn:focus { background: #e8f0f1; border-color: #8faeb5; }
    .fleet-rate-index .rate-table th { white-space: nowrap; }
    .fleet-rate-index .rate-table td { vertical-align: middle; }
    .fleet-rate-index .rate-date-hint { display: block; margin-top: 5px; color: #6b7d85; font-size: 12px; }
    .fleet-rate-index .rate-date-error { display: none; margin-top: 5px; color: #b42318; font-size: 12px; }
    .fleet-rate-index .rate-date-display.is-invalid { border-color: #d64545; box-shadow: 0 0 0 3px rgba(214,69,69,.12); }
    .fleet-rate-index .rate-actions { display: flex; align-items: center; justify-content: center; gap: 6px; flex-wrap: wrap; min-width: 160px; }
    .fleet-rate-index .rate-actions form { display: inline-flex; margin: 0; }
    .fleet-rate-index .rate-actions .btn { min-height: 40px; white-space: nowrap; }
    .fleet-rate-index .rate-file-picker { display: flex; align-items: center; gap: 10px; min-height: 44px; }
    .fleet-rate-index .rate-file-trigger { position: relative; display: inline-flex; flex: 0 0 auto; min-height: 44px; }
    .fleet-rate-index .rate-file-input { position: absolute; inset: 0; z-index: 2; width: 100%; height: 100%; padding: 0; margin: 0; opacity: 0; cursor: pointer; -webkit-tap-highlight-color: transparent; }
    .fleet-rate-index .rate-file-input:focus-visible { outline: 3px solid #f0b85b; outline-offset: 3px; }
    .fleet-rate-index .rate-file-button { display: inline-flex; align-items: center; justify-content: center; gap: 7px; min-height: 44px; padding: 9px 13px; border: 1px solid #9fb8bf; border-radius: 6px; background: #f7fafb; color: #294653; cursor: pointer; font-weight: 600; white-space: nowrap; pointer-events: none; }
    .fleet-rate-index .rate-file-button:hover, .fleet-rate-index .rate-file-button:focus { background: #e8f0f1; border-color: #6f9da8; }
    .fleet-rate-index .rate-file-name { min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; color: #60717b; font-size: 13px; }
    /* The Tailwind bundle follows the OS dark-mode preference. Keep the
       native asset matcher readable because this page uses the light AdminLTE
       shell and the dropdown contains business data. */
    .fleet-rate-index .rate-asset-select { color-scheme: light !important; color: #243746; background-color: #fff; border: 1px solid #b8cbd0; border-radius: 4px; min-height: 34px; padding: 5px 28px 5px 8px; font: inherit; }
    .fleet-rate-index .rate-asset-select option { color: #243746; background-color: #fff; }
    .fleet-rate-index .rate-asset-select:hover { border-color: #6f9da8; }
    .fleet-rate-index .rate-asset-select:focus { border-color: #0f8b8d; outline: 3px solid rgba(240,184,91,.45); outline-offset: 2px; }
    .fleet-rate-index .rate-bulk-map { margin-top: 8px; padding: 9px; border: 1px solid #cfe3e4; border-radius: 6px; background: #f7fbfb; min-width: 250px; }
    .fleet-rate-index .rate-bulk-map__label { display: block; margin-bottom: 5px; color: #294653; font-size: 12px; font-weight: 700; }
    .fleet-rate-index .rate-bulk-map .select2-container { width: 100% !important; max-width: 100%; color-scheme: light; }
    .fleet-rate-index .rate-bulk-map .select2-container--bootstrap-5 .select2-selection--multiple { min-height: 42px; padding: 4px 6px; border: 1px solid #b8cbd0; border-radius: 6px; background: #fff; color: #243746; box-shadow: none; }
    .fleet-rate-index .rate-bulk-map .select2-container--bootstrap-5 .select2-selection--multiple:focus,
    .fleet-rate-index .rate-bulk-map .select2-container--bootstrap-5.select2-container--focus .select2-selection--multiple { border-color: #0f8b8d; box-shadow: 0 0 0 3px rgba(15,139,141,.14); }
    .fleet-rate-index .rate-bulk-map .select2-selection__rendered { display: flex; align-items: center; flex-wrap: wrap; gap: 4px; margin: 0; padding: 0; list-style: none; }
    .fleet-rate-index .rate-bulk-map .select2-selection__choice { position: relative; display: inline-flex; align-items: center; min-height: 28px; margin: 0 !important; padding: 4px 9px 4px 25px !important; border: 1px solid #c1dfe2 !important; border-radius: 999px; background: #edf8f8 !important; color: #1f4450 !important; font-size: 12px; line-height: 1.25; }
    .fleet-rate-index .rate-bulk-map .select2-selection__choice__remove { position: absolute; left: 8px; top: 50%; display: inline-flex; align-items: center; justify-content: center; width: 13px; height: 13px; margin: 0; transform: translateY(-50%); border: 0; color: #0f7278; font-size: 15px; font-weight: 700; line-height: 1; }
    .fleet-rate-index .rate-bulk-map .select2-selection__choice__remove:hover { background: transparent; color: #a52a2a; }
    .fleet-rate-index .rate-bulk-map .select2-search--inline { flex: 1 1 90px; min-width: 80px; margin: 0 !important; padding: 0 !important; }
    .fleet-rate-index .rate-bulk-map .select2-search__field { width: 100% !important; min-height: 28px; margin: 0 !important; padding: 4px 3px !important; border: 0 !important; border-radius: 4px; background: #fff !important; color: #243746 !important; box-shadow: none !important; font: inherit; }
    .fleet-rate-index .rate-bulk-map .select2-search__field::placeholder { color: #758891; opacity: 1; }
    .fleet-rate-index .rate-bulk-map .select2-dropdown { overflow: hidden; border: 1px solid #b8cbd0; border-radius: 6px; background: #fff; color: #243746; color-scheme: light; box-shadow: 0 8px 20px rgba(36,55,70,.14); }
    .fleet-rate-index .rate-bulk-map .select2-results__options { max-height: 240px; }
    .fleet-rate-index .rate-bulk-map .select2-results__option { padding: 8px 10px; background: #fff; color: #243746; font-size: 13px; }
    .fleet-rate-index .rate-bulk-map .select2-results__option--highlighted[aria-selected] { background: #e8f5f5; color: #0b6066; }
    .fleet-rate-index .rate-bulk-map .select2-results__option[aria-selected="true"] { background: #f3f7f7; color: #536b74; }
    .fleet-rate-index .rate-bulk-map__order { display: block; margin-top: 5px; color: #60717b; font-size: 11px; line-height: 1.45; }
    .fleet-rate-index .rate-bulk-map__limit { display: block; margin-top: 3px; color: #60717b; font-size: 11px; line-height: 1.45; }
    .fleet-rate-index .rate-bulk-map__button { margin-top: 7px; min-height: 38px; border: 1px solid #0d6efd; background: #0d6efd; color: #fff; white-space: nowrap; }
    .fleet-rate-index .rate-bulk-map__button:disabled { border-color: #b8c5cc; background: #d9e1e5; color: #5e6d75; opacity: 1; cursor: not-allowed; }
    .fleet-rate-index .rate-locked { display: inline-flex; align-items: center; gap: 5px; min-height: 32px; padding: 5px 9px; border: 1px solid #d5dde0; border-radius: 999px; background: #eef1f2; color: #53616a; font-size: 11px; font-weight: 700; white-space: nowrap; }
    .fleet-rate-index .rate-flash { display: flex; align-items: flex-start; gap: 10px; margin-bottom: 16px; padding: 12px 14px; border: 1px solid #c8dfe2; border-left: 4px solid #0f8b8d; border-radius: 5px; background: #f2fbfb; color: #294653; }
    .fleet-rate-index .rate-flash--error { border-color: #efb4b0; border-left-color: #c0392b; background: #fff5f4; color: #7f1d1d; }
    .fleet-rate-index .rate-flash__content { min-width: 0; flex: 1; }
    .fleet-rate-index .rate-flash__content strong { display: block; margin-bottom: 2px; }
    .fleet-rate-index .rate-flash__link { margin-left: 8px; color: #086f73; font-weight: 700; white-space: nowrap; }
    .fleet-rate-index .rate-row--problem { background: #fff4f2 !important; box-shadow: inset 4px 0 0 #d64545; }
    .fleet-rate-index .rate-row--warning { background: #fffaf0 !important; box-shadow: inset 4px 0 0 #e5a11a; }
    .fleet-rate-index .rate-issue-badge { display: inline-flex; align-items: center; gap: 4px; margin-top: 5px; padding: 3px 7px; border-radius: 4px; background: #fde3e1; color: #9d2626; font-size: 11px; font-weight: 700; }
    .fleet-rate-index .rate-warning-badge { display: inline-flex; align-items: center; gap: 4px; margin-top: 5px; padding: 3px 7px; border-radius: 4px; background: #fff0c2; color: #765300; font-size: 11px; font-weight: 700; }
    .fleet-rate-index .rate-issue-list { margin: 6px 0 0; padding-left: 18px; color: #7f1d1d; font-size: 12px; line-height: 1.45; }
    .fleet-rate-index .rate-issue-list li + li { margin-top: 3px; }
    .fleet-rate-index .rate-issue-table tr.rate-issue-table__row { background: #fff4f2; }
    .fleet-rate-index .rate-issue-table td:first-child { font-weight: 700; white-space: nowrap; }
    .fleet-rate-index .rate-issue-help { color: #7f1d1d; }
    .fleet-rate-index a:focus-visible, .fleet-rate-index button:focus-visible, .fleet-rate-index input:focus-visible, .fleet-rate-index select:focus-visible { outline: 3px solid #f0b85b !important; outline-offset: 3px; }
    @media (max-width: 700px) {
        .fleet-rate-index__header { display: block; }
        .fleet-rate-index__actions { justify-content: flex-start; margin-top: 14px; }
        .fleet-rate-index__actions .btn { width: 100%; }
        .fleet-rate-index .box-body { padding: 15px; }
        .fleet-rate-index .table-responsive { margin: 0 -3px; }
        .fleet-rate-index #fleet-rates-table tr.child,
        .fleet-rate-index #fleet-rates-table tr.child > td,
        .fleet-rate-index #fleet-rates-table .dtr-details,
        .fleet-rate-index #fleet-rates-table .dtr-details > li,
        .fleet-rate-index #fleet-rates-table .dtr-data { width: 100% !important; max-width: 100%; box-sizing: border-box; }
        .fleet-rate-index #fleet-rates-table tr.child > td { padding: 8px !important; }
        .fleet-rate-index .rate-bulk-map { width: calc(100vw - 48px); max-width: calc(100vw - 48px); min-width: 0; padding: 10px; box-sizing: border-box; }
        .fleet-rate-index .rate-bulk-map__button { width: 100%; }
        .fleet-rate-index .rate-bulk-map .select2-selection__choice { max-width: 100%; overflow-wrap: anywhere; }
    }
</style>
<main class="fleet-rate-index">
<section class="content-header">
    <div class="fleet-rate-index__header">
        <div>
            <h1><i class="fas fa-road" aria-hidden="true"></i> Tarif Angkutan <small>Master tarif dan histori berlaku</small></h1>
            <p>Import workbook untuk banyak baris, atau buat satu tarif secara manual dari halaman ini.</p>
        </div>
        @if($canManageRates)
            <div class="fleet-rate-index__actions" aria-label="Aksi master tarif">
                <a href="{{ route('asset.fleet.rates.create') }}" class="btn btn-success rate-crud-btn" aria-label="Tambah tarif angkutan manual"><i class="fas fa-plus" aria-hidden="true"></i> Tambah Tarif Manual</a>
            </div>
        @endif
    </div>
</section>
<section class="content">
    @if(session('status'))
        <div class="rate-flash {{ session('status.success') ? '' : 'rate-flash--error' }}" role="status">
            <i class="fas {{ session('status.success') ? 'fa-check-circle' : 'fa-exclamation-triangle' }}" aria-hidden="true"></i>
            <div class="rate-flash__content">
                <strong>{{ session('status.success') ? 'Informasi import tarif' : 'Import tarif belum selesai' }}</strong>
                <span>{{ session('status.msg') }}</span>
                @if(session('status.batch_id'))
                    <a class="rate-flash__link" href="#fleet-rate-issues-panel">Lihat baris bermasalah</a>
                @endif
            </div>
        </div>
    @endif
    <div class="box box-primary">
        <div class="box-header with-border"><h3 class="box-title"><i class="fas fa-file-import me-2" aria-hidden="true"></i>Import Workbook Tarif</h3></div>
        <div class="box-body">
            <p class="text-muted">Workbook akan menjadi draft preview. <strong>Berlaku mulai</strong> wajib diisi manual; tanggal pada keterangan Excel tidak ditebak oleh sistem. Untuk satu atau beberapa unit, gunakan <strong>Tambah Tarif Manual</strong>.</p>
            <form id="fleet-rate-upload-form" method="POST" action="{{ route('asset.fleet.rates.upload') }}" enctype="multipart/form-data" class="rate-ajax-form">
                @csrf
                <div class="row">
                    <div class="col-md-5 form-group">
                        <label for="fleet-rate-file">File Excel tarif *</label>
                        <div class="rate-file-picker">
                            <span class="rate-file-trigger">
                                <input id="fleet-rate-file" type="file" name="file" class="rate-file-input" accept=".xlsx,.xls,.csv" aria-describedby="fleet-rate-file-help" required>
                                <label for="fleet-rate-file" class="rate-file-button"><i class="fas fa-folder-open" aria-hidden="true"></i> Pilih file</label>
                            </span>
                            <span id="fleet-rate-file-name" class="rate-file-name" aria-live="polite">Belum ada file dipilih</span>
                        </div>
                        <small id="fleet-rate-file-help" class="text-muted">Ketuk Pilih file untuk membuka aplikasi Files di iPad.</small>
                    </div>
                    <div class="col-md-3 form-group">
                        <label for="rate_effective_from_display">Berlaku mulai *</label>
                        <input id="rate_effective_from_display" name="effective_from_display" type="text" class="form-control rate-date-display" value="{{ old('effective_from_display') }}" placeholder="DD/MM/YYYY" inputmode="numeric" autocomplete="off" required>
                        <input id="rate_effective_from" name="effective_from" type="hidden" value="{{ old('effective_from') }}">
                        <small class="rate-date-hint">Contoh: 01/01/2026</small>
                        <small class="rate-date-error">Masukkan tanggal yang valid dengan format DD/MM/YYYY.</small>
                    </div>
                    <div class="col-md-4 form-group" style="padding-top: 25px;">
                        <button class="btn btn-primary rate-crud-btn"><i class="fas fa-upload" aria-hidden="true"></i> Upload &amp; Preview</button>
                        <a href="{{ route('asset.fleet.rates.export') }}" class="btn btn-success rate-crud-btn"><i class="fas fa-file-excel" aria-hidden="true"></i> Export Aktif</a>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <div class="box box-solid">
        <div class="box-header with-border" style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap">
            <h3 class="box-title">Riwayat Import Tarif</h3>
            <label for="fleet-rate-batch-status" class="sr-only">Filter status import</label>
            <select id="fleet-rate-batch-status" class="form-control" style="max-width:220px">
                <option value="">Semua status</option>
                <option value="queued_preview">Menunggu analisis</option>
                <option value="analyzing">Menganalisis</option>
                <option value="preview_ready">Preview siap</option>
                <option value="queued_import">Menunggu import</option>
                <option value="importing">Mengaktifkan</option>
                <option value="imported">Selesai</option>
                <option value="failed">Gagal</option>
                <option value="reverted">Di-revert</option>
            </select>
        </div>
        <div class="box-body table-responsive fleet-datatable-wrap">
            <table id="fleet-rate-batches-table" class="table table-bordered table-striped fleet-datatable"
                data-preview-template="{{ route('asset.fleet.rates.import.batches.preview', ['id' => '__ID__']) }}"
                data-download-template="{{ route('asset.fleet.rates.import.batches.download', ['id' => '__ID__']) }}"
                data-confirm-template="{{ route('asset.fleet.rates.import.batches.confirm', ['id' => '__ID__']) }}"
                data-retry-template="{{ route('asset.fleet.rates.import.batches.retry', ['id' => '__ID__']) }}"
                data-cancel-template="{{ route('asset.fleet.rates.import.batches.cancel', ['id' => '__ID__']) }}"
                data-revert-template="{{ route('asset.fleet.rates.import.batches.revert', ['id' => '__ID__']) }}">
                <thead><tr><th>ID</th><th>File</th><th>Berlaku mulai</th><th>Ringkasan</th><th>Progres</th><th>Status</th><th>Dibuat</th><th>Aksi</th></tr></thead>
                <tbody></tbody>
            </table>
        </div>
    </div>

    @if($hasPreviewIssues)
        <div class="box box-warning" id="fleet-rate-issues-panel">
            <div class="box-header with-border">
                <h3 class="box-title"><i class="fas fa-list-check me-2" aria-hidden="true"></i>Baris yang perlu diperiksa</h3>
                <p class="text-muted" style="margin:6px 0 0">Tabel ini membantu menemukan masalah tanpa membaca satu pesan panjang. Setelah diperbaiki, ulangi preview atau cocokkan Asset dari tabel tarif.</p>
            </div>
            <div class="box-body table-responsive fleet-datatable-wrap">
                <table id="fleet-rate-issues-table" class="table table-bordered table-striped fleet-datatable rate-issue-table">
                    <thead><tr><th>Batch</th><th>Baris Excel</th><th>Masalah</th><th>Langkah berikutnya</th></tr></thead>
                    <tbody></tbody>
                </table>
            </div>
        </div>
    @endif

    <div class="box box-solid">
        <div class="box-header with-border"><h3 class="box-title">Tarif Aktif, Preview, dan Histori</h3></div>
        <div class="box-body table-responsive fleet-datatable-wrap">
            <table id="fleet-rates-table" class="table table-bordered table-striped rate-table fleet-datatable">
                <thead><tr><th>Rute</th><th>Unit</th><th>Berlaku</th><th>Supir/Ton</th><th>Uang Jalan</th><th>Harga/Ton</th><th>Keterangan</th><th>Status</th><th>Aksi</th></tr></thead>
                <tbody>
                @forelse($rates as $rate)
                    @php($rowIssue = $rateIssues[$rate->id] ?? ['errors' => [], 'warnings' => []])
                    @php($hasRowError = count($rowIssue['errors']) > 0)
                    @php($hasRowWarning = ! $hasRowError && count($rowIssue['warnings']) > 0)
                    <tr class="{{ $hasRowError ? 'rate-row--problem' : ($hasRowWarning ? 'rate-row--warning' : '') }}">
                        <td>{{ optional($rate->route)->name ?: '-' }}<br><small class="text-muted">{{ optional($rate->route)->origin }}{{ optional($rate->route)->origin && optional($rate->route)->destination ? ' → ' : '' }}{{ optional($rate->route)->destination }}</small></td>
                        <td>
                            @php($unmatchedUnits = $rate->status === 'draft' ? $rate->units->filter(fn ($unit) => ! $unit->asset_id) : collect())
                            @foreach($rate->units as $unit)
                                <div>
                                    {{ $unit->unit_label }}
                                    @if($unit->asset)
                                        → {{ $unit->asset->name }}
                                    @elseif($rate->status === 'draft')
                                        <span class="label label-danger">Belum cocok</span>
                                    @else
                                        → BELUM COCOK
                                    @endif
                                </div>
                            @endforeach
                            @if($unmatchedUnits->isNotEmpty())
                                <div class="rate-bulk-map">
                                    <form method="POST" action="{{ route('asset.fleet.rates.units.bulk-map', $rate->id) }}" class="rate-bulk-map__form rate-ajax-form">
                                        @csrf
                                        <label class="rate-bulk-map__label" for="rate-assets-{{ $rate->id }}">Cocokkan beberapa unit sekaligus</label>
                                        <select id="rate-assets-{{ $rate->id }}" name="asset_ids[]" class="rate-bulk-asset-select" multiple required data-placeholder="Pilih Asset" data-max-units="{{ $unmatchedUnits->count() }}">
                                            @foreach($allAssets as $assetOption)
                                                <option value="{{ $assetOption->id }}">
                                                    {{ $assetOption->name }}{{ $assetOption->asset_code ? ' ('.$assetOption->asset_code.')' : '' }}
                                                </option>
                                            @endforeach
                                        </select>
                                        <small class="rate-bulk-map__order">
                                            Urutan pencocokan: {{ $unmatchedUnits->pluck('unit_label')->implode(' → ') }}. Asset pilihan pertama akan dipasangkan dengan unit pertama.
                                        </small>
                                        <small class="rate-bulk-map__limit" aria-live="polite">Maksimal {{ $unmatchedUnits->count() }} Asset dapat dipilih pada baris ini.</small>
                                        <button type="submit" class="btn btn-primary rate-bulk-map__button" disabled>Cocokkan Asset</button>
                                    </form>
                                </div>
                            @endif
                        </td>
                        <td>{{ optional($rate->effective_from)->format('d/m/Y') }} s/d {{ optional($rate->effective_to)->format('d/m/Y') ?: 'sekarang' }}</td>
                        <td>@format_currency($rate->driver_rate_per_ton)</td>
                        <td>@format_currency($rate->travel_allowance)</td>
                        <td>@if($rate->sale_rate_per_ton !== null) @format_currency($rate->sale_rate_per_ton) @else <span class="label label-warning">Kosong</span> @endif</td>
                        <td>{{ $rate->notes }}</td>
                        <td>
                            <span class="label label-{{ $rate->status === 'active' ? 'success' : ($rate->status === 'draft' ? 'warning' : ($rate->status === 'cancelled' ? 'default' : 'info')) }}">{{ strtoupper($rate->status) }}</span>
                            @if($hasRowError)
                                <br><span class="rate-issue-badge" title="Baris ini perlu diperiksa"><i class="fas fa-triangle-exclamation" aria-hidden="true"></i> Perlu diperiksa</span>
                            @elseif($hasRowWarning)
                                <br><span class="rate-warning-badge" title="Baris ini memiliki catatan"><i class="fas fa-circle-info" aria-hidden="true"></i> Ada catatan</span>
                            @endif
                            @if($rate->source_status === 'modified')
                                <br><span class="label label-warning" title="Nilai pernah dikoreksi dari sumber import">Diedit dari import</span>
                            @endif
                        </td>
                        <td>
                            @php($isManualRate = str_starts_with((string) optional($rate->batch)->file_name, 'Manual - '))
                            <div class="rate-actions">
                                @if($rate->status === 'cancelled')
                                    <span class="rate-locked" title="Tarif dibatalkan tetapi metadata sumber tetap dipertahankan."><i class="fas fa-ban" aria-hidden="true"></i> Dibatalkan</span>
                                @elseif($rate->trips->isNotEmpty())
                                    <span class="rate-locked" title="Tarif sudah dipakai SPB dan tidak dapat diubah."><i class="fas fa-lock" aria-hidden="true"></i> Terkunci SPB</span>
                                    @if($canManageRates)
                                        <a href="{{ route('asset.fleet.rates.create', ['from_rate' => $rate->id]) }}" class="btn rate-secondary-btn rate-crud-btn" aria-label="Buat revisi tarif {{ $rate->id }}" title="Buat tarif baru berdasarkan tarif ini"><i class="fas fa-code-branch" aria-hidden="true"></i> Revisi</a>
                                    @endif
                                @elseif($canManageRates)
                                    <a href="{{ route('asset.fleet.rates.edit', $rate->id) }}" class="btn btn-warning rate-crud-btn" aria-label="Edit tarif {{ $rate->id }}"><i class="fas fa-edit" aria-hidden="true"></i> Edit</a>
                                <form method="POST" action="{{ route('asset.fleet.rates.destroy', $rate->id) }}" class="rate-ajax-form">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="btn btn-danger rate-crud-btn" aria-label="{{ $isManualRate ? 'Hapus' : 'Batalkan' }} tarif {{ $rate->id }}" onclick="return confirm('{{ $isManualRate ? 'Hapus tarif manual ini?' : 'Batalkan tarif hasil import ini? Data sumber tetap disimpan sebagai histori.' }}')"><i class="fas {{ $isManualRate ? 'fa-trash' : 'fa-ban' }}" aria-hidden="true"></i> {{ $isManualRate ? 'Hapus' : 'Batalkan' }}</button>
                                    </form>
                                    @if(!$isManualRate)
                                        <small class="text-muted rate-source-note">Import dapat dikoreksi karena belum dipakai SPB.</small>
                                    @endif
                                @else
                                    <span class="text-muted small">Tidak ada akses pengelolaan</span>
                                @endif
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="9" class="text-center">Belum ada tarif aktif.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>
</section>
</main>
@push('scripts')
<script>
$(function () {
    const historyNode = document.getElementById('fleet-rate-batches-table');
    let historyPollTimer = null;

    function escapeRateHtml(value) {
        return String(value == null ? '' : value).replace(/[&<>"']/g, function (character) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' }[character];
        });
    }

    function historyUrl(action, id) {
        const template = historyNode ? historyNode.getAttribute('data-' + action + '-template') : '';
        return String(template || '').replace('__ID__', encodeURIComponent(id));
    }

    function scheduleHistoryPoll() {
        if (historyPollTimer || !historyNode || !$.fn.DataTable.isDataTable(historyNode)) return;
        historyPollTimer = window.setTimeout(function () {
            historyPollTimer = null;
            $(historyNode).DataTable().ajax.reload(null, false);
        }, 2200);
    }

    function renderBatchActions(row) {
        const id = Number(row.id) || 0;
        const actions = [];
        if (row.can_details) actions.push('<a class="btn btn-default btn-xs" href="' + escapeRateHtml(historyUrl('preview', id)) + '"><i class="fas fa-eye"></i> ' + (row.can_preview ? 'Tinjau preview' : 'Detail') + '</a>');
        if (row.can_download) actions.push('<button type="button" class="btn btn-success btn-xs rate-history-action" data-action="download" data-id="' + id + '"><i class="fas fa-file-excel"></i> Unduh ulang</button>');
        if (row.can_confirm) actions.push('<button type="button" class="btn btn-primary btn-xs rate-history-action" data-action="confirm" data-id="' + id + '">Konfirmasi</button>');
        if (row.can_retry) actions.push('<button type="button" class="btn btn-warning btn-xs rate-history-action" data-action="retry" data-id="' + id + '">Coba lagi</button>');
        if (row.can_cancel) actions.push('<button type="button" class="btn btn-danger btn-xs rate-history-action" data-action="cancel" data-id="' + id + '">Batalkan</button>');
        if (row.can_revert) actions.push('<button type="button" class="btn btn-danger btn-xs rate-history-action" data-action="revert" data-id="' + id + '">Revert</button>');
        return '<div class="rate-actions">' + (actions.join(' ') || '<span class="text-muted">—</span>') + '</div>';
    }

    const historyTable = initFleetDataTable('#fleet-rate-batches-table', {
        processing: true,
        serverSide: true,
        searchDelay: 350,
        pageLength: 25,
        lengthMenu: [[10, 25, 50, 100], [10, 25, 50, 100]],
        order: [[6, 'desc']],
        ajax: {
            url: "{{ route('asset.fleet.rates.import.batches.index') }}",
            data: function (request) {
                request.status = $('#fleet-rate-batch-status').val() || '';
            },
            dataSrc: function (json) {
                const rows = Array.isArray(json.data) ? json.data : [];
                if (rows.some(function (row) { return row.active; })) scheduleHistoryPoll();
                return rows;
            }
        },
        columns: [
            { data: 'id', name: 'id' },
            { data: 'file_name', name: 'file_name', render: function (value, type) { return type === 'display' ? escapeRateHtml(value) : value; } },
            { data: 'effective_from', name: 'effective_from', defaultContent: '—', render: function (value, type) { return type === 'display' ? escapeRateHtml(value || '—') : value; } },
            { data: 'result_summary', name: 'summary', orderable: false, searchable: false, defaultContent: '—', render: function (value, type) { return type === 'display' ? escapeRateHtml(value || 'Menunggu analisis') : value; } },
            { data: null, name: 'progress', orderable: false, searchable: false, render: function (value, type, row) {
                if (type !== 'display') return Number(row.progress_percent) || 0;
                const percent = Math.max(0, Math.min(100, Number(row.progress_percent) || 0));
                const message = escapeRateHtml(row.last_message || row.stage || 'Menunggu proses');
                const error = row.error_message ? '<br><small class="text-danger">' + escapeRateHtml(row.error_message) + '</small>' : '';
                return '<div class="progress" style="height:16px;margin-bottom:4px"><div class="progress-bar progress-bar-info" role="progressbar" aria-valuenow="' + percent + '" aria-valuemin="0" aria-valuemax="100" style="width:' + percent + '%">' + percent + '%</div></div><small title="' + message + '">' + message + '</small>' + error;
            } },
            { data: 'status', name: 'status', render: function (value, type) {
                if (type !== 'display') return value;
                const labels = { queued_preview: 'Menunggu analisis', analyzing: 'Menganalisis', preview_ready: 'Preview siap', queued_import: 'Menunggu import', importing: 'Mengaktifkan', imported: 'Selesai', failed: 'Gagal', reverted: 'Di-revert' };
                const colors = { imported: 'success', preview_ready: 'primary', failed: 'danger', reverted: 'default', queued_preview: 'info', analyzing: 'info', queued_import: 'info', importing: 'info' };
                return '<span class="label label-' + (colors[value] || 'default') + '">' + escapeRateHtml(labels[value] || value) + '</span>';
            } },
            { data: 'created_at', name: 'created_at', render: function (value, type) { return type === 'display' ? escapeRateHtml(value || '—') : value; } },
            { data: null, name: 'actions', orderable: false, searchable: false, render: function (value, type, row) { return type === 'display' ? renderBatchActions(row) : ''; } }
        ],
        columnDefs: [{ targets: [3, 4, 7], orderable: false }]
    });

    $('#fleet-rate-batch-status').on('change', function () {
        if ($.fn.DataTable.isDataTable(historyNode)) $(historyNode).DataTable().ajax.reload();
    });
    initFleetDataTable('#fleet-rate-issues-table', {
        processing: true,
        serverSide: true,
        searchDelay: 350,
        ajax: {
            url: "{{ route('asset.fleet.rates.issues') }}",
            dataSrc: 'data'
        },
        order: [[1, 'asc']],
        pageLength: 10,
        lengthMenu: [[10, 25, 50, 100], [10, 25, 50, 100]],
        columns: [
            { data: 0, name: 'batch', orderable: false },
            { data: 1, name: 'row' },
            { data: 2, name: 'message', orderable: false },
            { data: 3, name: 'help', orderable: false, searchable: false }
        ]
    });
    const ratesTable = initFleetDataTable('#fleet-rates-table', {
        processing: true,
        serverSide: true,
        searchDelay: 350,
        ajax: {
            url: "{{ route('asset.fleet.rates.data') }}",
            dataSrc: 'data'
        },
        order: [[2, 'desc']],
        lengthMenu: [[10, 25, 50, 100], [10, 25, 50, 100]],
        columns: [
            { data: 0, name: 'route_id' },
            { data: 1, name: 'units', orderable: false, searchable: false },
            { data: 2, name: 'effective_from' },
            { data: 3, name: 'driver_rate_per_ton' },
            { data: 4, name: 'travel_allowance' },
            { data: 5, name: 'sale_rate_per_ton' },
            { data: 6, name: 'notes', orderable: false },
            { data: 7, name: 'status' },
            { data: 8, name: 'actions', orderable: false, searchable: false }
        ],
        columnDefs: [{ targets: [1, 6, 8], orderable: false }],
        createdRow: function (row, data) {
            const statusCell = String(data[7] || '');
            $(row).toggleClass('rate-row--problem', statusCell.indexOf('rate-issue-badge') !== -1);
            $(row).toggleClass('rate-row--warning', statusCell.indexOf('rate-warning-badge') !== -1);
        }
    });

    function initRateBulkAssetSelects(scope) {
        const $scope = scope ? $(scope) : $(document);
        $scope.find('.rate-bulk-asset-select').addBack('.rate-bulk-asset-select').each(function () {
            const $select = $(this);
            if ($select.hasClass('select2-hidden-accessible')) return;

            const $form = $select.closest('form');
            const $button = $form.find('button[type="submit"]');
            const $limit = $form.find('.rate-bulk-map__limit');
            const maxUnits = Number($select.attr('data-max-units')) || 0;
            $select.select2({
                theme: 'bootstrap-5',
                width: '100%',
                placeholder: $select.attr('data-placeholder'),
                closeOnSelect: false,
                allowClear: false,
                maximumSelectionLength: maxUnits,
                minimumResultsForSearch: 0,
                ajax: {
                    url: $select.attr('data-ajax-url'),
                    dataType: 'json',
                    delay: 200,
                    data: function (params) {
                        return {
                            q: params.term || '',
                            page: params.page || 1
                        };
                    },
                    processResults: function (data) {
                        return data;
                    },
                    cache: true
                },
                dropdownParent: $form,
            });

            function syncBulkMapButton() {
                let values = $select.val() || [];
                if (maxUnits > 0 && values.length > maxUnits) {
                    values = values.slice(0, maxUnits);
                    $select.val(values).trigger('change.select2');
                }
                const count = values.length;
                $button.prop('disabled', count === 0).text(count ? 'Cocokkan ' + count + ' Asset' : 'Pilih Asset');
                if ($limit.length) {
                    $limit.text('Maksimal ' + maxUnits + ' Asset dapat dipilih pada baris ini.' + (count ? ' Saat ini ' + count + ' dipilih.' : ''));
                }
            }

            $select.on('change', syncBulkMapButton);
            syncBulkMapButton();
        });
    }

    initRateBulkAssetSelects(document);
    $('#fleet-rates-table').on('draw.dt', function () {
        window.setTimeout(function () {
            initRateBulkAssetSelects('#fleet-rates-table');
        }, 0);
    });
    $('#fleet-rates-table').on('responsive-display.dt', function (event, datatable, row, show) {
        if (show) {
            window.setTimeout(function () {
                initRateBulkAssetSelects(row.child());
            }, 0);
        }
    });

    function syncRateDate() {
        const display = document.getElementById('rate_effective_from_display');
        const hidden = document.getElementById('rate_effective_from');
        const error = document.querySelector('.rate-date-error');
        if (!display || !hidden) return true;

        const match = display.value.trim().match(/^(\d{2})\/(\d{2})\/(\d{4})$/);
        if (!match) {
            hidden.value = '';
            display.classList.add('is-invalid');
            if (error) error.style.display = 'block';
            return false;
        }

        const day = Number(match[1]);
        const month = Number(match[2]);
        const year = Number(match[3]);
        const candidate = new Date(Date.UTC(year, month - 1, day));
        const valid = candidate.getUTCFullYear() === year
            && candidate.getUTCMonth() === month - 1
            && candidate.getUTCDate() === day;
        if (!valid) {
            hidden.value = '';
            display.classList.add('is-invalid');
            if (error) error.style.display = 'block';
            return false;
        }

        hidden.value = year + '-' + String(month).padStart(2, '0') + '-' + String(day).padStart(2, '0');
        display.classList.remove('is-invalid');
        if (error) error.style.display = 'none';
        return true;
    }

    $('.rate-date-display').on('input blur change', syncRateDate);
    $('#fleet-rate-file').on('change', function () {
        const name = this.files && this.files.length ? this.files[0].name : 'Belum ada file dipilih';
        $('#fleet-rate-file-name').text(name);
    });
    $('#fleet-rate-upload-form').on('submit', function (event) {
        if (!syncRateDate()) {
            event.preventDefault();
            event.stopImmediatePropagation();
            $('#rate_effective_from_display').trigger('focus');
        }
    });

    function notifyRateAction(message, success) {
        if (window.toastr) {
            (success ? toastr.success : toastr.error)(message);
            return;
        }
        window.alert(message);
    }

    function reloadRateTables(resetHistoryPage) {
        ['#fleet-rate-batches-table', '#fleet-rates-table', '#fleet-rate-issues-table'].forEach(function (selector) {
            const table = $(selector);
            if (table.length && $.fn.DataTable.isDataTable(table[0])) {
                table.DataTable().ajax.reload(null, selector === '#fleet-rate-batches-table' ? Boolean(resetHistoryPage) : false);
            }
        });
    }

    $(document).on('click', '.rate-history-action', function () {
        const button = this;
        const action = button.getAttribute('data-action');
        const id = Number(button.getAttribute('data-id'));
        if (!id || button.disabled) return;
        if (action === 'confirm' && !window.confirm('Aktifkan tarif pada batch ini? Tarif sebelumnya untuk unit/rute yang sama akan ditutup.')) return;
        if (action === 'cancel' && !window.confirm('Batalkan draft batch tarif ini?')) return;
        if (action === 'revert' && !window.confirm('Revert batch ini? Proses hanya berjalan jika tarif belum berubah dan belum dipakai SPB.')) return;

        const urls = { download: historyUrl('download', id), confirm: historyUrl('confirm', id), retry: historyUrl('retry', id), cancel: historyUrl('cancel', id), revert: historyUrl('revert', id) };
        if (action === 'download') {
            window.location.assign(urls.download);
            return;
        }

        button.disabled = true;
        fetch(urls[action], {
            method: 'POST',
            headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content') || ''
            },
            credentials: 'same-origin'
        }).then(function (response) {
            return response.json().catch(function () { return {}; }).then(function (data) { return { response: response, data: data }; });
        }).then(function (result) {
            const data = result.data || {};
            if (!result.response.ok || data.success === false) throw new Error(data.msg || 'Permintaan batch belum berhasil.');
            notifyRateAction(data.msg || 'Aksi batch berhasil diproses.', true);
            reloadRateTables();
        }).catch(function (error) {
            notifyRateAction(error.message || 'Permintaan batch belum berhasil.', false);
        }).finally(function () {
            button.disabled = false;
        });
    });

    $(document).on('submit', '.rate-ajax-form', function (event) {
        event.preventDefault();
        event.stopImmediatePropagation();

        const form = this;
        if (form.dataset.submitting === 'true') return;
        form.dataset.submitting = 'true';
        const submitButtons = $(form).find('button[type="submit"]');
        const originalLabels = submitButtons.map(function () { return $(this).text(); }).get();
        submitButtons.prop('disabled', true).each(function () { $(this).text('Memproses...'); });

        fetch(form.action, {
            method: (form.getAttribute('method') || 'POST').toUpperCase(),
            body: new FormData(form),
            headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            },
            credentials: 'same-origin'
        }).then(function (response) {
            return response.json().catch(function () {
                return { success: false, msg: 'Server mengembalikan respons yang tidak dapat dibaca.' };
            }).then(function (data) {
                return { response: response, data: data };
            });
        }).then(function (result) {
            const data = result.data || {};
            if (!result.response.ok || data.success === false) {
                const errors = data.errors ? Object.values(data.errors).flat().join(' ') : '';
                throw new Error(errors || data.msg || 'Permintaan belum berhasil diproses.');
            }

            notifyRateAction(data.msg || 'Perubahan berhasil disimpan.', true);
            if (form.id === 'fleet-rate-upload-form') {
                form.reset();
                $('#fleet-rate-file-name').text('Belum ada file dipilih');
                $('#rate_effective_from').val('');
                const historySection = document.getElementById('fleet-rate-batches-table');
                if (historySection) historySection.scrollIntoView({ behavior: 'smooth', block: 'center' });
            }
            if (form.dataset.reloadPage === 'true') {
                window.setTimeout(function () { window.location.reload(); }, 450);
            } else {
                reloadRateTables(form.id === 'fleet-rate-upload-form');
            }
        }).catch(function (error) {
            notifyRateAction(error.message || 'Permintaan belum berhasil diproses.', false);
            if (form.id === 'fleet-rate-upload-form') reloadRateTables(true);
        }).finally(function () {
            form.dataset.submitting = 'false';
            submitButtons.prop('disabled', false).each(function (index) { $(this).text(originalLabels[index]); });
        });
    });
});
</script>
@endpush
@endsection
