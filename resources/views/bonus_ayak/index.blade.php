@extends('layouts.app')

@section('title', 'Bonus Ayak')

@section('css')
@include('bonus_ayak.partials.ui')
<style>
    .bonus-shell { max-width: 1440px; margin: 0 auto; padding: 26px 30px 48px; }
    .bonus-page-header { display: flex; justify-content: space-between; gap: 24px; align-items: flex-end; margin-bottom: 22px; }
    .bonus-page-header h1 { margin: 0 0 7px; color: #162b3a; font-size: clamp(26px, 3vw, 38px); letter-spacing: -.035em; }
    .bonus-page-header p { max-width: 680px; margin: 0; color: #60717b; line-height: 1.6; }
    .bonus-eyebrow { margin: 0 0 8px; color: #0f8b8d; font-size: 12px; font-weight: 700; letter-spacing: .12em; text-transform: uppercase; }
    .bonus-page-actions { display: flex; flex-wrap: wrap; justify-content: flex-end; gap: 8px; }
    .bonus-page-actions .btn { min-height: 40px; }
    .bonus-shell .card { overflow: visible; border: 1px solid #dce6e8; border-radius: 12px; background: #fff; box-shadow: 0 8px 22px rgba(22, 43, 58, .06); }
    .bonus-shell .card-header { min-height: 44px; padding: 12px 18px !important; border-bottom: 1px solid #dce6e8; border-radius: 11px 11px 0 0; background: #f7faf9 !important; color: #1d3b48; line-height: 1.25; }
    .bonus-shell .card-body { padding: 20px !important; }
    .bonus-shell .form-label, .bonus-shell .bonus-field > label, .bonus-shell .bonus-filter-grid label, .bonus-shell .bonus-export label { display: block; margin: 0 0 7px; color: #334e5b; font-size: 13px; font-weight: 700; line-height: 1.3; }
    .bonus-shell .form-control, .bonus-shell .form-select { min-height: 40px; border: 1px solid #c3d2d8 !important; border-radius: 6px; background-color: #fff; color: #253b49; box-shadow: none; }
    .bonus-shell .form-control::placeholder { color: #82929b; opacity: 1; }
    .bonus-shell .form-control:focus, .bonus-shell .form-select:focus { border-color: #0f8b8d !important; box-shadow: 0 0 0 3px rgba(15, 139, 141, .13); }
    .bonus-shell .bonus-summary { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 12px; margin-bottom: 18px; }
    .bonus-shell .bonus-metric { padding: 15px 17px; background: #fff; border: 1px solid #dce6e8; border-radius: 11px; }
    .bonus-shell .bonus-metric span { display: block; color: #73858d; font-size: 12px; }
    .bonus-shell .bonus-metric strong { display: block; margin-top: 4px; color: #162b3a; font-size: 22px; font-variant-numeric: tabular-nums; }
    .bonus-shell .bonus-metric--accent { background: #eef8f6; border-color: #b9ded8; }
    .bonus-shell .bonus-help { margin: 0 0 20px; padding: 13px 15px; color: #536971; background: #f2f8f7; border: 1px solid #d6ebe7; border-left: 4px solid #0f8b8d; border-radius: 7px; font-size: 13px; line-height: 1.6; }
    .bonus-shell .bonus-help strong { color: #1d3b48; }
    .bonus-shell .bonus-form-grid { display: grid; grid-template-columns: repeat(12, minmax(0, 1fr)); gap: 16px; }
    .bonus-shell .bonus-field { grid-column: span 3; }
    .bonus-shell .bonus-field--wide { grid-column: span 6; }
    .bonus-shell .bonus-field--result { grid-column: span 3; }
    .bonus-shell .bonus-field small { display: block; margin-top: 6px; color: #73858d; line-height: 1.4; }
    .bonus-shell .bonus-form-actions { display: flex; align-items: flex-end; justify-content: flex-end; gap: 9px; margin-top: 0; }
    .bonus-shell .bonus-form-actions .btn { margin-top: 0 !important; min-height: 40px; }
    .bonus-shell .bonus-import { display: flex; justify-content: space-between; gap: 18px; align-items: center; }
    .bonus-shell .bonus-import p { margin: 0; color: #60717b; font-size: 13px; line-height: 1.5; }
    .bonus-shell .bonus-import form { display: flex; flex: 0 1 600px; gap: 8px; align-items: center; }
    .bonus-shell .bonus-export { display: flex; justify-content: space-between; gap: 18px; align-items: flex-end; }
    .bonus-shell .bonus-export form { display: grid; grid-template-columns: repeat(2, minmax(150px, 1fr)) auto auto; gap: 10px; flex: 1; align-items: end; }
    .bonus-shell .bonus-filter-grid { display: grid; grid-template-columns: repeat(12, minmax(0, 1fr)); gap: 12px; align-items: end; }
    .bonus-shell .bonus-filter-grid > * { grid-column: span 3; }
    .bonus-shell .bonus-filter-grid .bonus-filter-action { grid-column: span 2; }
    .bonus-shell .table { margin-bottom: 0; }
    .bonus-shell .table th { color: #536971; font-size: 12px; white-space: nowrap; }
    .bonus-shell .table td { color: #334e5b; vertical-align: middle; }
    .bonus-shell .table .bonus-number { color: #162b3a; font-variant-numeric: tabular-nums; font-weight: 700; text-align: right; }
    .bonus-shell .bonus-row-actions { display: flex; justify-content: center; gap: 5px; flex-wrap: wrap; }
    .bonus-shell .bonus-row-actions form { display: inline; }
    .bonus-shell .badge { font-size: 11px; }
    .bonus-shell .select2-container { width: 100% !important; }
    .bonus-shell .select2-container--bootstrap-5 .select2-selection { min-height: 40px; height: 40px; padding: 5px 10px; border: 1px solid #c3d2d8 !important; border-radius: 6px; background-color: #fff; color: #253b49; }
    .bonus-shell .select2-container--bootstrap-5 .select2-selection--single { display: flex; align-items: center; }
    .bonus-shell .select2-container--bootstrap-5 .select2-selection--single .select2-selection__rendered { padding-left: 0; color: #253b49; line-height: 28px; }
    .bonus-shell .select2-container--bootstrap-5 .select2-selection--single .select2-selection__placeholder { color: #71828b; }
    .bonus-shell .select2-container--bootstrap-5 .select2-selection--single .select2-selection__arrow { top: 6px; right: 8px; }
    .bonus-shell .select2-container--bootstrap-5 .select2-selection--multiple { height: auto; min-height: 40px; padding: 4px 7px; }
    .bonus-shell .select2-container--bootstrap-5 .select2-selection--multiple .select2-selection__placeholder { margin-top: 3px; color: #71828b; }
    .bonus-shell .select2-container--bootstrap-5 .select2-selection--multiple .select2-search__field { min-height: 28px; margin: 0; padding: 2px 3px; border: 0 !important; border-radius: 3px; background: #fff !important; color: #253b49; box-shadow: none !important; }
    .bonus-shell .select2-container--bootstrap-5 .select2-selection--multiple .select2-search__field::placeholder { color: #71828b; opacity: 1; }
    .bonus-shell .select2-container--bootstrap-5.select2-container--focus .select2-selection, .bonus-shell .select2-container--bootstrap-5.select2-container--open .select2-selection { border-color: #0f8b8d !important; box-shadow: 0 0 0 3px rgba(15, 139, 141, .13); }
    .bonus-shell .select2-container--open { z-index: 1060; }
    .bonus-shell .select2-container--bootstrap-5 .select2-dropdown { border: 1px solid #b8cbd0 !important; border-radius: 7px; background: #fff; box-shadow: 0 12px 24px rgba(22, 43, 58, .12); }
    .bonus-shell .select2-container--bootstrap-5 .select2-search--dropdown { padding: 8px; background: #fff; }
    .bonus-shell .select2-container--bootstrap-5 .select2-search--dropdown .select2-search__field { min-height: 36px; padding: 7px 9px; border: 1px solid #c3d2d8 !important; border-radius: 5px; background: #fff !important; color: #253b49; }
    .bonus-shell .select2-container--bootstrap-5 .select2-results__option { padding: 9px 12px; color: #334e5b; }
    .bonus-shell .select2-container--bootstrap-5 .select2-results__option--highlighted[aria-selected] { background: #0f8b8d; color: #fff; }
    .bonus-shell .bonus-import input[type=file] { min-height: 40px; padding: 7px 10px; }
    .bonus-shell .bonus-import form button { min-height: 40px; white-space: nowrap; }
    .bonus-shell .bonus-export form button, .bonus-shell .bonus-filter-action .btn { min-height: 40px; }
    .bonus-shell .table-responsive { border-radius: 7px; }
    .bonus-shell .table thead th { background: #f7faf9; }
    .bonus-shell a:focus-visible, .bonus-shell button:focus-visible { outline: 3px solid #f0b85b; outline-offset: 3px; }
    @media (max-width: 1000px) { .bonus-page-header { display: block; } .bonus-page-actions { justify-content: flex-start; margin-top: 16px; } .bonus-shell .bonus-field, .bonus-shell .bonus-field--result { grid-column: span 6; } .bonus-shell .bonus-field--wide { grid-column: span 12; } .bonus-shell .bonus-filter-grid > *, .bonus-shell .bonus-filter-grid .bonus-filter-action { grid-column: span 6; } }
    @media (max-width: 640px) { .bonus-shell { padding: 18px 12px 34px; } .bonus-shell .bonus-summary { grid-template-columns: 1fr; } .bonus-shell .bonus-field, .bonus-shell .bonus-field--result, .bonus-shell .bonus-field--wide { grid-column: span 12; } .bonus-shell .bonus-import, .bonus-shell .bonus-export { display: block; } .bonus-shell .bonus-import form { display: block; margin-top: 12px; } .bonus-shell .bonus-import form button { width: 100%; margin-top: 8px; } .bonus-shell .bonus-export form { display: grid; grid-template-columns: 1fr; margin-top: 12px; } .bonus-shell .bonus-export form button { width: 100%; } .bonus-shell .bonus-filter-grid > *, .bonus-shell .bonus-filter-grid .bonus-filter-action { grid-column: span 12; } .bonus-shell .table-responsive { margin: 0 -2px; } }
</style>
@endsection

@section('content')
@include('layouts.partials.fleet-datatables')
<main class="bonus-ui bonus-shell" aria-labelledby="bonus-page-title">
    <header class="bonus-page-header">
        <div>
            <p class="bonus-eyebrow">Pool · upah sopir</p>
            <h1 id="bonus-page-title"><i class="fa fa-money-bill me-2" aria-hidden="true"></i>Bonus Ayak</h1>
            <p>Catat tambahan hak sopir untuk rekap pembayaran. Project bersifat opsional dan Bonus Ayak tidak menjadi baris tagihan pelanggan.</p>
        </div>
        <nav class="bonus-page-actions" aria-label="Aksi Bonus Ayak">
            <a href="#bonus-form" class="btn btn-success"><i class="fa fa-plus me-1" aria-hidden="true"></i>Tambah Bonus</a>
            <a href="{{ route('bonus-ayak.kasbon-form') }}" class="btn btn-info"><i class="fa fa-wallet me-1" aria-hidden="true"></i>Kasbon Sopir</a>
            <a href="{{ route('guides.show', 'bonus-ayak') }}" class="btn btn-default"><i class="fa fa-book-open me-1" aria-hidden="true"></i>Panduan</a>
        </nav>
    </header>


    {{-- NOTIFIKASI --}}
    @if(session('success'))
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="fa fa-check-circle me-2" aria-hidden="true"></i> {{ session('success') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    @endif
    @if(session('error'))
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="fa fa-exclamation-triangle me-2" aria-hidden="true"></i> {{ session('error') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    @endif
    @if($errors->any())
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="fa fa-exclamation-triangle me-2" aria-hidden="true"></i> {{ $errors->first() }}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    @endif

    <section class="bonus-summary" aria-label="Ringkasan Bonus Ayak">
        <div class="bonus-metric"><span>Data pada tampilan</span><strong>{{ $data->count() }}</strong></div>
        <div class="bonus-metric"><span>Total trip</span><strong>{{ number_format($total_trip, 0, ',', '.') }}</strong></div>
        <div class="bonus-metric bonus-metric--accent"><span>Total bonus</span><strong>Rp {{ number_format($grand_total, 0, ',', '.') }}</strong></div>
    </section>

    {{-- FORM INPUT BONUS --}}
    <section id="bonus-form" class="card mb-3" aria-labelledby="bonus-form-title">
        <div class="card-header bg-light fw-bold">
            <i class="fa fa-edit me-2" aria-hidden="true"></i><span id="bonus-form-title">Tambah data Bonus Ayak</span>
        </div>
                <div class="card-body">
                    <p class="bonus-help"><strong>Isi satu catatan bonus.</strong> Pilih sopir dan unit, isi jumlah trip, lalu pilih Project jika catatan ini perlu dikelompokkan ke sebuah Project.</p>
                    <form class="bonus-form-grid" action="{{ route('bonus-ayak.store') }}" method="POST">
                        @csrf
                        <div class="bonus-field">
                            <label class="form-label" for="tanggal_display">Tanggal</label>
                            <input type="text" id="tanggal_display" class="form-control bonus-date-display"
                                   value="{{ old('tanggal', date('Y-m-d')) }}" placeholder="DD/MM/YYYY" inputmode="numeric"
                                   autocomplete="off" data-date-display data-date-value-id="tanggal" required>
                            <input type="hidden" id="tanggal" name="tanggal" value="{{ old('tanggal', date('Y-m-d')) }}">
                        </div>
                       <div class="bonus-field">
            <label for="driver_id">Nama Operator/Supir</label>
            <select id="driver_id" name="driver_id" class="form-select select2" required>
                <option value="">-- Pilih --</option>
                @foreach($users as $user)
                    <option value="{{ $user->id }}" @selected(old('driver_id') == $user->id)>{{ $user->user_full_name }}</option>
                @endforeach
            </select>
        </div>
                <div class="bonus-field">
                    <label class="form-label" for="asset_id">Unit</label>
                    <select id="asset_id" name="asset_id" class="form-select select2" required>
                        <option value="">-- Pilih --</option>
                        @foreach($assets as $asset)
                            <option value="{{ $asset->id }}" @selected(old('asset_id') == $asset->id)>{{ $asset->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="bonus-field">
                    <label class="form-label" for="project_id">Project <span class="text-muted">(opsional)</span></label>
                    <select id="project_id" name="project_id" class="form-select select2">
                        <option value="">Tanpa Project</option>
                        @foreach($projects as $projectId => $projectName)
                            <option value="{{ $projectId }}" @selected(old('project_id', request('project_id')) == $projectId)>{{ $projectName }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="bonus-field">
                    <label class="form-label" for="trip">Trip</label>
                    <input type="number" id="trip" name="trip" class="form-control"
                           min="0" value="0" required>
                </div>
                <div class="bonus-field bonus-field--result">
                    <label class="form-label" for="preview_bonus">Bonus</label>
                    <input type="text" id="preview_bonus"
                           class="form-control fw-bold text-end"
                           value="Rp 0" readonly style="background-color:#f8f9fa;">
                    <input type="hidden" name="bonus" id="bonus_hidden" value="0">
                </div>
                <div class="bonus-action-group">
                    <button type="submit" class="btn btn-success" id="btn-simpan" aria-label="Simpan Bonus Ayak">
                            <i class="fa fa-save" aria-hidden="true"></i> Simpan
                    </button>
                </div>
            </form>
        </div>
    </section>

    {{-- UPLOAD EXCEL --}}
    <section class="card mb-3" aria-labelledby="bonus-import-title">
        <div class="card-header bg-light fw-bold"><i class="fa fa-file-excel me-2" aria-hidden="true"></i><span id="bonus-import-title">Import dari Excel</span></div>
        <div class="card-body bonus-import">
            <p>Upload file Bonus Ayak untuk melihat hasil pencocokan sopir, unit, baris bermasalah dan duplikat. Data belum disimpan sampai Anda mengonfirmasi preview.</p>
            <form id="bonus-ayak-import-form" action="{{ route('bonus-ayak.import') }}" method="POST" enctype="multipart/form-data">
                @csrf
                <div class="bonus-file-picker">
                    <input type="file" name="file" class="bonus-file-picker__input" id="customFile" required aria-label="Pilih file Excel Bonus Ayak" data-file-picker>
                    <label class="bonus-file-picker__label" for="customFile"><i class="fa fa-folder-open" aria-hidden="true"></i>Pilih file</label>
                    <span class="bonus-file-picker__name" data-file-name-for="customFile">Belum ada file dipilih</span>
                </div>
                <input type="hidden" name="import" value="excel">
                <button type="submit" class="btn btn-warning" id="bonus-ayak-import-submit"><i class="fa fa-search me-1" aria-hidden="true"></i>Upload &amp; Preview</button>
                <div id="bonus-ayak-upload-progress" class="progress hidden mt-2" style="max-width:420px"><div class="progress-bar" role="progressbar" style="width:0%">0%</div></div>
                <p id="bonus-ayak-upload-message" class="text-muted mt-1" aria-live="polite"></p>
            </form>
        </div>
    </section>

    <section class="card mb-3" aria-labelledby="bonus-import-history-title">
        <div class="card-header bg-light fw-bold" id="bonus-import-history-title"><i class="fa fa-history me-2" aria-hidden="true"></i>Riwayat Import Bonus Ayak</div>
        <div class="card-body">
            <div class="row"><div class="col-sm-3 form-group"><label for="bonus-import-history-status">Status</label><select id="bonus-import-history-status" class="form-control"><option value="">Semua status</option><option>queued_preview</option><option>analyzing</option><option>preview_ready</option><option>queued_import</option><option>importing</option><option>imported</option><option>partial</option><option>failed</option><option>cancelled</option><option>reverted</option></select></div></div>
            <div class="table-responsive"><table id="bonus-import-history-table" class="table table-bordered table-striped" width="100%"><thead><tr><th>File</th><th>Status</th><th>Progress</th><th>Pesan</th><th>Dibuat</th><th>Aksi</th></tr></thead></table></div>
            <div id="bonus-import-history-detail" class="well hidden"><h4>Detail batch Bonus Ayak</h4><pre class="bonus-import-history-detail-body" style="white-space:pre-wrap"></pre></div>
        </div>
    </section>

    <section class="card mb-3" aria-labelledby="bonus-export-title">
        <div class="card-header bg-light fw-bold"><i class="fa fa-download me-2" aria-hidden="true"></i><span id="bonus-export-title">Unduh rekap Bonus Ayak</span></div>
        <div class="card-body bonus-export">
            <p class="mb-0 text-muted">Pilih periode yang ingin diserahkan ke bagian payroll atau arsip.</p>
            <form method="GET" action="{{ route('bonus-ayak.export-excel') }}">
                <div><label class="form-label mb-1" for="bonus-export-from-display">Dari</label><input id="bonus-export-from-display" type="text" class="form-control bonus-date-display" value="{{ request('from', date('Y-m-01')) }}" placeholder="DD/MM/YYYY" inputmode="numeric" autocomplete="off" data-date-display data-date-value-id="bonus-export-from"><input id="bonus-export-from" type="hidden" name="from" value="{{ request('from', date('Y-m-01')) }}"></div>
                <div><label class="form-label mb-1" for="bonus-export-to-display">Sampai</label><input id="bonus-export-to-display" type="text" class="form-control bonus-date-display" value="{{ request('to', date('Y-m-t')) }}" placeholder="DD/MM/YYYY" inputmode="numeric" autocomplete="off" data-date-display data-date-value-id="bonus-export-to"><input id="bonus-export-to" type="hidden" name="to" value="{{ request('to', date('Y-m-t')) }}"></div>
                <button type="submit" class="btn btn-success"><i class="fa fa-file-excel me-1" aria-hidden="true"></i>Excel</button>
                <button type="submit" formaction="{{ route('bonus-ayak.export-pdf') }}" formmethod="GET" class="btn btn-danger"><i class="fa fa-file-pdf me-1" aria-hidden="true"></i>PDF</button>
            </form>
        </div>
    </section>

    {{-- FILTER DATA --}}
    <section class="card mb-3" aria-labelledby="bonus-filter-title">
        <div class="card-header bg-light fw-bold">
            <i class="fa fa-filter me-2" aria-hidden="true"></i><span id="bonus-filter-title">Cari dan saring data</span>
        </div>
        <div class="card-body">
            <form class="bonus-filter-grid" method="GET" action="{{ route('bonus-ayak.index') }}">
                <div>
                    <label class="form-label" for="bonus-filter-from">Dari tanggal</label>
                    <input id="bonus-filter-from-display" type="text" class="form-control bonus-date-display" value="{{ $from }}" placeholder="DD/MM/YYYY" inputmode="numeric" autocomplete="off" data-date-display data-date-value-id="bonus-filter-from">
                    <input id="bonus-filter-from" type="hidden" name="from" value="{{ $from }}">
                </div>
                <div>
                    <label class="form-label" for="bonus-filter-to">Sampai tanggal</label>
                    <input id="bonus-filter-to-display" type="text" class="form-control bonus-date-display" value="{{ $to }}" placeholder="DD/MM/YYYY" inputmode="numeric" autocomplete="off" data-date-display data-date-value-id="bonus-filter-to">
                    <input id="bonus-filter-to" type="hidden" name="to" value="{{ $to }}">
                </div>
                <div>
                    <label class="form-label" for="bonus-filter-name">Sopir</label>
                    <input id="bonus-filter-name" type="text" name="nama" class="form-control"
                           placeholder="Cari nama sopir" value="{{ $nama }}">
                </div>
                <div>
                    <label class="form-label" for="bonus-filter-project">Project</label>
                    <select id="bonus-filter-project" name="project_id" class="form-select">
                        <option value="">Semua Project</option>
                        @foreach($projects as $projectId => $projectName)
                            <option value="{{ $projectId }}" @selected(request('project_id') == $projectId)>{{ $projectName }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="bonus-filter-action d-flex gap-2">
                    <button type="submit" class="btn btn-primary flex-grow-1"><i class="fa fa-search me-1" aria-hidden="true"></i>Tampilkan</button>
                    <a href="{{ route('bonus-ayak.index') }}" class="btn btn-default" aria-label="Reset filter Bonus Ayak">Reset</a>
                </div>
            </form>
        </div>
    </section>

    {{-- TABEL DATA BONUS --}}
    <section class="card" aria-labelledby="bonus-list-title">
        <div class="card-header bg-light fw-bold"><i class="fa fa-table me-2" aria-hidden="true"></i><span id="bonus-list-title">Daftar Bonus Ayak</span></div>
        <div class="card-body">
            <div class="table-responsive bonus-desktop-table fleet-datatable-wrap">
            <table id="bonus-ayak-table" class="table table-bordered table-striped align-middle fleet-datatable">
                <thead class="table-light text-center">
                    <tr>
                        <th>Tanggal</th>
                        <th>Sopir</th>
                        <th>Unit</th>
                        <th>Project</th>
                        <th>Trip</th>
                        <th>Bonus</th>
                        <th>Status payroll</th>
                        <th>Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($data as $row)
                        <tr>
                            <td class="text-center">{{ $row->tanggal->format('d/m/Y') }}</td>
                            <td>{{ optional($row->driver)->user_full_name ?: $row->nama_operator }} @if(!$row->driver)<span class="badge bg-warning">legacy</span>@endif</td>
                            <td class="text-center">{{ optional($row->asset)->name ?: $row->unit }} @if(!$row->asset)<span class="badge bg-warning">legacy</span>@endif</td>
                            <td>{{ optional($row->project)->name ?: 'Tanpa Project' }}</td>
                            <td class="text-center">{{ $row->trip }}</td>
                            <td class="bonus-number">Rp {{ number_format($row->bonus, 0, ',', '.') }}</td>
                            <td class="text-center">
                                @if($row->settlements->isNotEmpty())
                                    <span class="badge bg-secondary">Terkunci payroll</span>
                                @else
                                    <span class="badge bg-success">Siap dipilih</span>
                                @endif
                            </td>
                            <td class="text-center">
                                @if($row->settlements->isNotEmpty())
                                    <span class="bonus-locked-state" title="Data sudah masuk payroll dan tidak dapat diubah.">
                                        <i class="fa fa-lock" aria-hidden="true"></i> Terkunci payroll
                                    </span>
                                @else
                                    <div class="bonus-row-actions">
                                        <a href="{{ route('bonus-ayak.edit', $row->id) }}" class="btn btn-sm btn-warning" title="Edit Bonus Ayak" aria-label="Edit Bonus Ayak">
                                            <i class="fa fa-edit" aria-hidden="true"></i><span>Edit</span>
                                        </a>
                                        <form onsubmit="return confirm('Hapus data ini?')" method="POST" action="{{ route('bonus-ayak.destroy', $row) }}">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="btn btn-sm btn-danger" title="Hapus Bonus Ayak" aria-label="Hapus Bonus Ayak">
                                                <i class="fa fa-trash" aria-hidden="true"></i><span>Hapus</span>
                                            </button>
                                        </form>
                                    </div>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="text-center text-muted">Belum ada data.</td>
                        </tr>
                    @endforelse
                </tbody>
                <tfoot>
                    <tr class="table-secondary">
                        <th colspan="4" class="text-end">TOTAL:</th>
                        <th class="text-center">{{ $total_trip }}</th>
                        <th class="text-end">{{ number_format($grand_total, 0, ',', '.') }}</th>
                        <th colspan="2"></th>
                    </tr>
                </tfoot>
            </table>
            </div>
            <div class="bonus-mobile-list" aria-label="Daftar Bonus Ayak versi ponsel">
                @forelse($data as $row)
                    <article class="bonus-mobile-card">
                        <div class="bonus-mobile-card__grid">
                            <div><span class="bonus-mobile-card__label">Tanggal</span><span class="bonus-mobile-card__value">{{ $row->tanggal->format('d/m/Y') }}</span></div>
                            <div><span class="bonus-mobile-card__label">Sopir</span><span class="bonus-mobile-card__value">{{ optional($row->driver)->user_full_name ?: $row->nama_operator }}</span></div>
                            <div><span class="bonus-mobile-card__label">Unit</span><span class="bonus-mobile-card__value">{{ optional($row->asset)->name ?: $row->unit }}</span></div>
                            <div><span class="bonus-mobile-card__label">Project</span><span class="bonus-mobile-card__value">{{ optional($row->project)->name ?: 'Tanpa Project' }}</span></div>
                            <div><span class="bonus-mobile-card__label">Trip</span><span class="bonus-mobile-card__value">{{ number_format($row->trip, 0, ',', '.') }}</span></div>
                            <div><span class="bonus-mobile-card__label">Bonus</span><span class="bonus-mobile-card__value"><strong>Rp {{ number_format($row->bonus, 0, ',', '.') }}</strong></span></div>
                            <div><span class="bonus-mobile-card__label">Status payroll</span><span class="bonus-mobile-card__value">@if($row->settlements->isNotEmpty())<span class="bonus-locked-state"><i class="fa fa-lock" aria-hidden="true"></i> Terkunci payroll</span>@else<span class="badge bg-success">Siap dipilih</span>@endif</span></div>
                        </div>
                        @if($row->settlements->isEmpty())
                            <div class="bonus-mobile-card__actions">
                                <a href="{{ route('bonus-ayak.edit', $row->id) }}" class="btn btn-warning" title="Edit Bonus Ayak" aria-label="Edit Bonus Ayak"><i class="fa fa-edit" aria-hidden="true"></i> Edit</a>
                                <form class="flex-grow-1" onsubmit="return confirm('Hapus data ini?')" method="POST" action="{{ route('bonus-ayak.destroy', $row) }}">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="btn btn-danger w-100" title="Hapus Bonus Ayak" aria-label="Hapus Bonus Ayak"><i class="fa fa-trash" aria-hidden="true"></i> Hapus</button>
                                </form>
                            </div>
                        @endif
                    </article>
                @empty
                    <p class="text-center text-muted mb-0">Belum ada data.</p>
                @endforelse
            </div>
        </div>
    </section>
</main>

{{-- Script Hitung Bonus + Select2 --}}
@push('scripts')
<script src="{{ asset('js/bonus-ayak-ui.js?v=' . $asset_v) }}"></script>
<script>
    function hitungBonus(trip) {
        if(trip < 50) return 0;
        return 50000 + (trip - 50) * 2000;
    }

    $('#trip').on('input', function() {
        let trip = parseInt($(this).val()) || 0;
        let bonus = hitungBonus(trip);
        $('#preview_bonus').val('Rp ' + new Intl.NumberFormat('id-ID').format(bonus));
        $('#bonus_hidden').val(bonus);
    });

    // Init Select2
    $(document).ready(function() {
        $('.select2').each(function() {
            const $select = $(this);
            $select.select2({
                theme: 'bootstrap-5',
                placeholder: $select.data('placeholder') || '-- Pilih --',
                allowClear: !$select.prop('required'),
                width: '100%',
                dropdownParent: $('.bonus-shell')
            });
        });
    });
</script>
<script>
$(function () {
    initFleetDataTable('#bonus-ayak-table', {
        order: [[0, 'desc']],
        columnDefs: [{ targets: [1, 2, 3, 4, 5, 6, 7], orderable: false }]
    });

    var csrf = $('meta[name="csrf-token"]').attr('content');
    var historyTable = $('#bonus-import-history-table').DataTable({
        processing: true,
        serverSide: true,
        searchDelay: 350,
        ajax: {
            url: @json(route('bonus-ayak.import-history')),
            data: function (data) { data.status = $('#bonus-import-history-status').val() || ''; }
        },
        columns: [
            {data: 'file_name', name: 'file_name', render: $.fn.dataTable.render.text()},
            {data: 'status', name: 'status', render: $.fn.dataTable.render.text()},
            {data: null, orderable: false, searchable: false, render: function (row) {
                var percent = Math.max(0, Math.min(100, parseInt(row.progress_percent || 0, 10)));
                return '<div class="progress"><div class="progress-bar" style="width:' + percent + '%">' + percent + '%</div></div>' +
                    '<small>' + parseInt(row.progress_current || 0, 10) + ' / ' + (parseInt(row.progress_total || 0, 10) || '—') + '</small>';
            }},
            {data: 'last_message', name: 'last_message', defaultContent: '', render: function (value, type, row) {
                return window.importHistoryMessageCell(row.error_message || value || '', type);
            }},
            {data: 'created_at', name: 'created_at', render: $.fn.dataTable.render.text()},
            {data: null, orderable: false, searchable: false, render: function (row) {
                var actions = '<button type="button" class="btn btn-xs btn-default bonus-import-detail" data-url="' + $('<div>').text(row.detail_url).html() + '">Detail</button> ';
                if (row.restore_url) actions += '<a class="btn btn-xs btn-success" href="' + $('<div>').text(row.restore_url).html() + '">Unduh sumber</a> ';
                if (row.can_retry) actions += '<button type="button" class="btn btn-xs btn-warning bonus-import-retry" data-url="' + $('<div>').text(row.retry_url).html() + '">Coba lagi</button> ';
                if (row.can_revert) actions += '<button type="button" class="btn btn-xs btn-danger bonus-import-revert" data-url="' + $('<div>').text(row.revert_url).html() + '">Revert</button>';
                return actions;
            }}
        ],
        order: [[4, 'desc']]
    });
    $('#bonus-import-history-status').on('change', function () { historyTable.ajax.reload(); });
    window.setInterval(function () {
        if (historyTable.rows().data().toArray().some(function (row) { return row.active; })) historyTable.ajax.reload(null, false);
    }, 5000);

    $('#bonus-ayak-import-form').on('submit', function (event) {
        event.preventDefault();
        var form = this;
        var progress = $('#bonus-ayak-upload-progress').removeClass('hidden').find('.progress-bar').css('width', '0%').text('0%');
        $('#bonus-ayak-import-submit').prop('disabled', true);
        $('#bonus-ayak-upload-message').text('Mengunggah workbook…');
        $.ajax({
            url: $(form).attr('action'), method: 'POST', data: new FormData(form), processData: false, contentType: false,
            dataType: 'json', headers: {'Accept': 'application/json', 'X-CSRF-TOKEN': csrf},
            xhr: function () {
                var xhr = $.ajaxSettings.xhr();
                if (xhr.upload) xhr.upload.addEventListener('progress', function (e) {
                    if (e.lengthComputable) { var percent = Math.round(e.loaded / e.total * 100); progress.css('width', percent + '%').text(percent + '%'); }
                });
                return xhr;
            }
        }).done(function (payload) {
            $('#bonus-ayak-upload-message').text(payload.message || 'Workbook masuk antrean. Membuka status batch…');
            if (payload.preview_url) window.location = payload.preview_url;
            historyTable.ajax.reload(null, false);
        }).fail(function (xhr) {
            $('#bonus-ayak-import-submit').prop('disabled', false);
            $('#bonus-ayak-upload-message').text((xhr.responseJSON && xhr.responseJSON.message) || 'Upload Bonus Ayak gagal.');
            if (window.toastr) toastr.error($('#bonus-ayak-upload-message').text());
        });
    });

    $('#bonus-import-history-table').on('click', '.bonus-import-detail', function () {
        var target = $('#bonus-import-history-detail');
        $.get($(this).data('url')).done(function (payload) {
            target.find('pre').text(JSON.stringify({batch: payload.batch, results: payload.results, preview: payload.preview}, null, 2));
            target.removeClass('hidden');
        });
    });
    $('#bonus-import-history-table').on('click', '.bonus-import-retry, .bonus-import-revert', function () {
        var button = $(this), reverting = button.hasClass('bonus-import-revert');
        if (reverting && !window.confirm('Revert hanya berjalan jika data belum berubah dan belum masuk settlement. Lanjutkan?')) return;
        $.ajax({url: button.data('url'), method: 'POST', data: {_token: csrf}}).done(function () {
            historyTable.ajax.reload(null, false);
        }).fail(function (xhr) {
            if (window.toastr) toastr.error((xhr.responseJSON && xhr.responseJSON.message) || 'Aksi batch gagal.');
        });
    });
});
</script>
@endpush

@endsection
