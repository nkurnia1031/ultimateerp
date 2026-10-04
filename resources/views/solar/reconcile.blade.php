@extends('layouts.app')

@section('title', 'Preview Import Solar')

@section('content')
<section class="content-header"><h1>Preview Import Solar <small>batch {{ $token }}</small></h1></section>
@include('solar._preview-workspace', ['token' => $token, 'summary' => $summary, 'assets' => $assets, 'customers' => $customers])

<div class="modal fade" id="solar-process-modal" tabindex="-1" data-backdrop="static" data-keyboard="false">
        <div class="modal-dialog modal-lg"><div class="modal-content">
        <div class="modal-header"><h4 class="modal-title"><i class="fa fa-refresh fa-spin"></i> <span id="solar-process-title">Memproses</span></h4></div>
        <div class="modal-body">
            <div class="progress"><div id="solar-process-progress" class="progress-bar progress-bar-info progress-bar-striped active" style="width:0%">0%</div></div>
            <p id="solar-process-stage" class="text-muted">Menunggu proses...</p><p id="solar-process-message">Memulai...</p>
            <small>Berjalan: <span id="solar-process-elapsed">0 detik</span> · Checkpoint terakhir: <span id="solar-process-heartbeat">—</span></small>
            <div id="solar-process-activity" class="alert alert-info" style="margin-top:10px;margin-bottom:8px">Menunggu informasi proses...</div>
            <div class="solar-process-log-wrap"><strong>Aktivitas terbaru</strong><ol id="solar-process-log" class="solar-process-log"><li class="text-muted">Log akan muncul saat server mengirim checkpoint.</li></ol></div>
            <div id="solar-process-error" class="alert alert-danger" style="display:none;margin-top:10px"></div>
            <div id="solar-process-stale" class="alert alert-warning" style="display:none;margin-top:10px"></div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-warning" id="solar-process-retry" style="display:none">Coba cek lagi</button>
            <button type="button" class="btn btn-default" id="solar-process-cancel">Batalkan</button>
            <button type="button" class="btn btn-primary" id="solar-process-close" style="display:none" data-dismiss="modal">Tutup</button>
        </div>
    </div></div>
</div>
<style>
    .solar-process-log-wrap { margin-top:12px; border-top:1px solid #e4e8eb; padding-top:8px; }
    .solar-process-log { max-height:180px; overflow:auto; padding-left:22px; margin:6px 0 0; }
    .solar-process-log li { padding:3px 0; }
</style>
@endsection

@section('javascript')
<script src="{{ versioned_asset_url(asset('modules/assetmanagement/js/vendor/ag-grid-community-36.2.0.min.js')) }}"></script>
<script src="{{ asset('js/solar.js?v=' . $asset_v) }}"></script>
@endsection
