@extends('layouts.app')

@section('title', 'Riwayat Import Solar')

@section('content')
<section class="content-header">
    <h1>Riwayat Import Solar <small>kelola, pulihkan, dan rollback batch</small></h1>
</section>

<section class="content" id="solar-import-history"
    data-table-url="{{ route('solar.imports.data') }}"
    data-status-url-template="{{ route('solar.imports.status', ['batchId' => '__BATCH__']) }}">
    @if (session('status'))
        @php $status = session('status'); @endphp
        <div class="alert alert-{{ ($status['success'] ?? false) ? 'success' : 'danger' }}">{{ $status['msg'] ?? '' }}</div>
    @endif

    <div class="box box-primary">
        <div class="box-header with-border">
            <h3 class="box-title">Daftar Batch Import</h3>
            <div class="box-tools">
                <a href="{{ route('solar.index') }}" class="btn btn-default btn-sm">
                    <i class="fa fa-arrow-left"></i> Kembali ke Solar
                </a>
            </div>
        </div>
        <div class="box-body">
            <div class="row" style="margin-bottom:12px">
                <div class="col-sm-4 col-md-3">
                    <label for="solar-import-history-status">Filter status</label>
                    <select id="solar-import-history-status" class="form-control input-sm">
                        <option value="">Semua status</option>
                        <option value="queued_preview">Menunggu preview</option>
                        <option value="analyzing">Menganalisis</option>
                        <option value="preview_ready">Preview siap</option>
                        <option value="queued_import">Menunggu import</option>
                        <option value="importing">Mengimpor</option>
                        <option value="imported">Selesai</option>
                        <option value="failed">Gagal</option>
                        <option value="cancelled">Dibatalkan</option>
                        <option value="reverted">Rollback</option>
                    </select>
                </div>
                <div class="col-sm-4 col-md-3">
                    <label for="solar-import-history-start-date">Dibuat mulai</label>
                    <input id="solar-import-history-start-date" type="date" class="form-control input-sm">
                </div>
                <div class="col-sm-4 col-md-3">
                    <label for="solar-import-history-end-date">Dibuat sampai</label>
                    <input id="solar-import-history-end-date" type="date" class="form-control input-sm">
                </div>
            </div>
            <div class="table-responsive">
                <table id="solar-import-history-table" class="table table-striped table-bordered table-hover" style="width:100%">
                    <thead>
                        <tr>
                            <th>File</th><th>Dibuat</th><th>Status</th><th>Proses</th>
                            <th>Stock</th><th>Pool</th><th>Mess</th><th>Pemakaian alat</th><th>Aksi</th>
                        </tr>
                    </thead>
                    <tbody></tbody>
                </table>
            </div>
            <form id="solar-history-action-token" class="hidden">@csrf</form>
        </div>
    </div>
    <div id="solar-history-feedback" class="alert" hidden aria-live="polite"></div>
</section>
@endsection

@section('javascript')
<script src="{{ asset('js/solar.js?v=' . $asset_v) }}"></script>
@endsection
