@extends('layouts.app')

@section('title', 'Preview Import Solar')

@section('content')
<section class="content-header"><h1>Preview Import Solar <small>batch {{ $token }}</small></h1></section>
@include('solar._preview-workspace', ['token' => $token, 'summary' => $summary, 'assets' => $assets, 'customers' => $customers])

<div class="modal fade" id="solar-process-modal" tabindex="-1" data-backdrop="static" data-keyboard="false">
    <div class="modal-dialog modal-sm"><div class="modal-content">
        <div class="modal-header"><h4 class="modal-title"><i class="fa fa-refresh fa-spin"></i> <span id="solar-process-title">Memproses</span></h4></div>
        <div class="modal-body">
            <div class="progress"><div id="solar-process-progress" class="progress-bar progress-bar-info progress-bar-striped active" style="width:0%">0%</div></div>
            <p id="solar-process-stage" class="text-muted">Menunggu proses...</p><p id="solar-process-message">Memulai...</p>
            <small>Berjalan: <span id="solar-process-elapsed">0 detik</span> · Pembaruan terakhir: <span id="solar-process-heartbeat">—</span></small>
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
<script src="{{ versioned_asset_url(asset('modules/assetmanagement/js/vendor/ag-grid-community-36.2.0.min.js')) }}"></script>
<script src="{{ asset('js/solar.js?v=' . $asset_v) }}"></script>
@endsection
