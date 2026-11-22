<div class="box box-default import-copy-history" data-import-copy-type="{{ $copyType }}" data-history-url="{{ route('import-copies.history', $copyType) }}">
    <div class="box-header with-border"><h3 class="box-title">{{ $historyTitle ?? 'Riwayat salin dari bisnis' }}</h3></div>
    <div class="box-body">
        <div class="row">
            <div class="col-sm-3 form-group">
                <label>Status</label>
                <select class="form-control input-sm import-copy-status">
                    <option value="">Semua status</option>
                    <option value="queued_preview">queued</option>
                    <option value="analyzing">memeriksa</option>
                    <option value="importing">menyalin</option>
                    <option value="imported">selesai</option>
                    <option value="partial">sebagian gagal</option>
                    <option value="failed">gagal</option>
                    <option value="reverted">direvert</option>
                </select>
            </div>
        </div>
        <div class="table-responsive">
            <table class="table table-bordered table-striped import-copy-history-table">
                <thead><tr><th>ID</th><th>Operasi</th><th>Status</th><th>Progres</th><th>Dibuat</th><th>Aksi</th></tr></thead>
            </table>
        </div>
        <div class="well import-copy-detail hidden">
            <h4>Detail hasil salin</h4>
            <p class="import-copy-summary"></p>
            <div class="table-responsive">
                <table class="table table-condensed table-bordered">
                    <thead><tr><th>ID sumber</th><th>Sumber</th><th>Status</th><th>Tujuan / error</th></tr></thead>
                    <tbody></tbody>
                </table>
            </div>
        </div>
    </div>
</div>
<script src="{{ versioned_asset_url(asset('js/business-copy-import-history.js'), $asset_v ?? null) }}"></script>
