@extends('layouts.app')

@section('title', 'Cross-check Stok Solar')

@section('content')
<section class="content-header">
    <h1>Hasil Cross-check Stok Solar <small>preview sebelum import</small></h1>
</section>

<section class="content" id="solar-reconcile-app"
    data-batch-id="{{ $token }}"
    data-base-url="{{ route('solar.index') }}"
    data-rows-url="{{ route('solar.imports.rows', $token) }}"
    data-status-url="{{ route('solar.imports.status', $token) }}"
    data-reconcile-url="{{ route('solar.imports.reconcile', $token) }}"
    data-retry-url="{{ route('solar.imports.retry', $token) }}"
    data-cancel-url="{{ route('solar.imports.cancel', $token) }}"
    data-date-url-template="{{ route('solar.imports.rows.date', [$token, '__ROW__']) }}"
    data-confirm-url="{{ route('solar.imports.confirm', $token) }}">
    @if (session('status'))
        <div class="alert alert-danger">{{ session('status.msg') }}</div>
    @endif

    <div class="row" id="solar-preview-summary">
        @php
            $cards = [
                ['match', 'Cocok', 'bg-green'],
                ['only_excel', 'Hanya di Excel', 'bg-aqua'],
                ['only_app', 'Hanya di Aplikasi', 'bg-yellow'],
                ['different', 'Berbeda', 'bg-orange'],
                ['error', 'Error', 'bg-red'],
            ];
        @endphp
        @foreach ($cards as [$key, $label, $color])
            <div class="col-sm-6 col-md-2">
                <div class="small-box {{ $color }}">
                    <div class="inner"><h3 data-summary="{{ $key }}">{{ $summary[$key] ?? 0 }}</h3><p>{{ $label }}</p></div>
                </div>
            </div>
        @endforeach
    </div>

    <div class="box box-primary">
        <div class="box-header with-border">
            <h3 class="box-title">Perbandingan Workbook dan Aplikasi</h3>
            <div class="box-tools">
                <a href="{{ route('solar.reconcile.export', $token) }}" class="btn btn-sm btn-warning">
                    <i class="fa fa-file-excel-o"></i> Download Laporan Lengkap
                </a>
            </div>
        </div>
        <div class="box-body">
            <div class="alert alert-info">
                Hanya baris <strong>Hanya di Excel</strong> yang akan ditambahkan. Error tanggal dapat diperbaiki langsung dari tabel.
            </div>
            <div class="row" style="margin-bottom:10px">
                <div class="col-sm-3">
                    <select id="solar-preview-ledger" class="form-control">
                        <option value="">Semua ledger</option>
                        <option value="stock">Stock</option>
                        <option value="pool">Pool</option>
                        <option value="mess">Mess</option>
                    </select>
                </div>
                <div class="col-sm-3">
                    <select id="solar-preview-status" class="form-control">
                        <option value="">Semua status</option>
                        <option value="match">Cocok</option>
                        <option value="only_excel">Hanya di Excel</option>
                        <option value="only_app">Hanya di Aplikasi</option>
                        <option value="different">Berbeda</option>
                        <option value="ambiguous">Ambigu</option>
                        <option value="error">Error</option>
                    </select>
                </div>
            </div>
            <div class="table-responsive">
                <table id="solar-preview-table" class="table table-bordered table-striped" style="width:100%">
                    <thead><tr><th>Ledger</th><th>Baris Excel</th><th>Tanggal</th><th>Status</th><th>Metode</th><th>Detail</th><th>Aksi</th></tr></thead>
                </table>
            </div>
        </div>
        <div class="box-footer">
            <a href="{{ route('solar.index') }}" class="btn btn-default"><i class="fa fa-arrow-left"></i> Kembali tanpa Import</a>
            <form action="{{ route('solar.import.confirm') }}" method="post" class="pull-right" id="solar-confirm-form"
                data-ajax-url="{{ route('solar.imports.confirm', $token) }}">
                @csrf
                <input type="hidden" name="token" value="{{ $token }}">
                <button id="solar-preview-confirm" class="btn btn-success" {{ ($summary['only_excel'] ?? 0) < 1 ? 'disabled' : '' }}>
                    <i class="fa fa-check"></i> Konfirmasi Import <span data-summary="only_excel">{{ $summary['only_excel'] ?? 0 }}</span> Baris Baru
                </button>
            </form>
        </div>
    </div>

    <div class="modal fade" id="solar-date-edit-modal" tabindex="-1">
        <div class="modal-dialog modal-sm">
            <div class="modal-content">
                <form id="solar-date-edit-form">
                    @csrf
                    <div class="modal-header"><button type="button" class="close" data-dismiss="modal">&times;</button><h4 class="modal-title">Perbaiki Tanggal</h4></div>
                    <div class="modal-body">
                        <p class="text-muted">Tanggal Excel: <strong id="solar-date-edit-raw"></strong></p>
                        <input type="date" id="solar-date-edit-value" class="form-control" required>
                    </div>
                    <div class="modal-footer"><button type="button" class="btn btn-default" data-dismiss="modal">Batal</button><button class="btn btn-primary" type="submit">Simpan &amp; Proses Ulang</button></div>
                </form>
            </div>
        </div>
    </div>
</section>

<div class="modal fade" id="solar-process-modal" tabindex="-1" data-backdrop="static" data-keyboard="false">
    <div class="modal-dialog modal-sm"><div class="modal-content">
        <div class="modal-header"><h4 class="modal-title"><i class="fa fa-refresh fa-spin"></i> <span id="solar-process-title">Memproses</span></h4></div>
        <div class="modal-body">
            <div class="progress"><div id="solar-process-progress" class="progress-bar progress-bar-info progress-bar-striped active" style="width:0%">0%</div></div>
            <p id="solar-process-stage" class="text-muted">Menunggu proses...</p>
            <p id="solar-process-message">Memulai...</p>
            <small class="text-muted">Berjalan: <span id="solar-process-elapsed">0 detik</span> · Pembaruan terakhir: <span id="solar-process-heartbeat">—</span></small>
            <div id="solar-process-error" class="alert alert-danger" style="display:none;margin-top:10px"></div>
            <div id="solar-process-stale" class="alert alert-warning" style="display:none;margin-top:10px">Tidak ada pembaruan dari server. Proses mungkin masih berjalan.</div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-warning" id="solar-process-retry" style="display:none">Coba cek lagi</button>
            <button type="button" class="btn btn-default" id="solar-process-cancel">Batalkan</button>
            <button type="button" class="btn btn-primary" id="solar-process-close" style="display:none" data-dismiss="modal">Tutup</button>
        </div>
    </div></div>
</div>
@endsection

@section('javascript')
<script src="{{ asset('js/solar.js') }}"></script>
@endsection
