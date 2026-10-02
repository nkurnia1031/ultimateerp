<section class="content solar-preview-workspace" id="solar-reconcile-app"
    data-batch-id="{{ $token ?? '' }}"
    data-base-url="{{ route('solar.index') }}"
    data-status-url="{{ ! empty($token) ? route('solar.imports.status', $token) : '' }}"
    data-reconcile-url-template="{{ route('solar.imports.reconcile', ['batchId' => '__BATCH__']) }}"
    data-rows-url-template="{{ route('solar.imports.rows', ['batchId' => '__BATCH__']) }}"
    data-summary-url-template="{{ route('solar.imports.summary', ['batchId' => '__BATCH__']) }}"
    data-confirm-url-template="{{ route('solar.imports.confirm', ['batchId' => '__BATCH__']) }}"
    data-status-url-template="{{ route('solar.imports.status', ['batchId' => '__BATCH__']) }}"
    data-cancel-url-template="{{ route('solar.imports.cancel', ['batchId' => '__BATCH__']) }}"
    data-retry-url-template="{{ route('solar.imports.retry', ['batchId' => '__BATCH__']) }}"
    data-update-url-template="{{ route('solar.imports.rows.update', ['batchId' => '__BATCH__', 'rowId' => '__ROW__']) }}"
    data-sheets='@json($summary['sheets'] ?? [])'
    data-assets='@json($assets ?? [])'
    data-customers='@json($customers ?? [])'>
    <div class="solar-preview-header">
        <div>
            <h3>Preview Import Solar</h3>
            <p class="text-muted">Periksa per sheet. Nilai saldo/subtotal dari Excel hanya untuk pembanding dan akan dihitung ulang aplikasi.</p>
        </div>
        <a href="{{ route('solar.imports') }}" class="btn btn-default"><i class="fa fa-history"></i> Riwayat import</a>
    </div>
    <div id="solar-preview-summary" class="row solar-preview-summary"></div>
    <input type="hidden" id="solar-preview-csrf" value="{{ csrf_token() }}">
    <div class="alert alert-info">
        Kolom bertanda pensil dapat dikoreksi langsung. Perubahan disimpan di preview melalui AJAX dan baru masuk ke ledger setelah dikonfirmasi.
    </div>
    <div id="solar-preview-tabs" class="solar-preview-tabs" role="tablist"></div>
    <div class="solar-preview-sheet-heading">
        <strong id="solar-preview-sheet-title">Pilih sheet</strong>
        <span id="solar-preview-sheet-counts" class="text-muted"></span>
    </div>
    <div class="solar-preview-tools">
        <label for="solar-preview-filter">Tampilkan</label>
        <select id="solar-preview-filter" class="form-control input-sm">
            <option value="all">Semua baris</option>
            <option value="issues">Perlu diperiksa</option>
            <option value="ready">Siap diimpor</option>
        </select>
        <span class="text-muted">Double klik cell bertanda pensil untuk mengedit. Tanggal gunakan format YYYY-MM-DD.</span>
    </div>
    <div id="solar-preview-grid" class="solar-preview-grid ag-theme-quartz" role="region" aria-label="Preview sheet Solar"></div>
    <div class="solar-preview-footer">
        <span>Baris siap diimpor: <strong id="solar-preview-importable-count">0</strong></span>
        <span>Baris akan dilewati / diperiksa: <strong id="solar-preview-skip-count">0</strong></span>
        @if (! empty($token))
            <button type="button" id="solar-preview-confirm" class="btn btn-success" data-token="{{ $token }}" {{ ($summary['only_excel'] ?? 0) < 1 ? 'disabled' : '' }}>
                <i class="fa fa-check"></i> Konfirmasi &amp; Antrekan Import
            </button>
        @else
            <button type="button" id="solar-preview-confirm" class="btn btn-success" disabled>
                <i class="fa fa-check"></i> Konfirmasi &amp; Antrekan Import
            </button>
        @endif
    </div>
    <div id="solar-preview-feedback" class="alert" role="alert" hidden></div>
</section>
<style>
    .solar-preview-workspace { padding-top:12px; }
    .solar-preview-header { display:flex; justify-content:space-between; align-items:flex-start; gap:12px; }
    .solar-preview-header h3 { margin:0 0 5px; }
    .solar-preview-summary .solar-preview-stat { border:1px solid #dce7ea; background:#f8fbfc; padding:10px 13px; margin-bottom:10px; min-height:65px; }
    .solar-preview-stat span { display:block; color:#5e7078; font-size:12px; }
    .solar-preview-stat strong { display:block; font-size:20px; }
    .solar-preview-tabs { display:flex; gap:5px; overflow-x:auto; padding:0 0 8px; border-bottom:1px solid #d9e1e5; }
    .solar-preview-tab { white-space:nowrap; border:1px solid #ccd8de; border-bottom:0; border-radius:4px 4px 0 0; background:#f3f6f7; padding:8px 11px; color:#31454e; }
    .solar-preview-tab.active { background:#fff; color:#174761; font-weight:700; box-shadow:inset 0 3px #2783a3; }
    .solar-preview-tab .badge { margin-left:5px; }
    .solar-preview-sheet-heading { display:flex; justify-content:space-between; padding:12px 2px 4px; }
    .solar-preview-tools { display:flex; align-items:center; gap:10px; padding:8px 0; flex-wrap:wrap; }
    .solar-preview-tools select { width:auto; min-width:165px; }
    .solar-preview-grid { width:100%; min-height:400px; height:min(62vh, 680px); margin-bottom:12px; }
    #solar-reconcile-app .alert-info { color:#16384a !important; background-color:#e8f4f8 !important; border-color:#c7e3ec !important; }
    .solar-preview-tab .badge { background-color:#666; }
    .solar-preview-editable-cell { background:#fff8d6 !important; border-left:2px solid #d8a900 !important; }
    .solar-preview-row-issue { background:#fff4f0 !important; }
    .solar-preview-row-warning { background:#fffbea !important; }
    .solar-preview-footer { display:flex; align-items:center; gap:14px; flex-wrap:wrap; }
    .solar-preview-footer span { margin-right:auto; }
    #solar-preview-modal .solar-preview-modal-dialog { width:96vw; max-width:1500px; margin:2vh auto; }
    #solar-preview-modal .modal-body { padding:12px 18px 18px; }
    @media(max-width:767px) {
        .solar-preview-header { flex-direction:column; }
        .solar-preview-header .btn,.solar-preview-footer .btn { width:100%; }
        .solar-preview-footer span { width:100%; }
        .solar-preview-grid { height:60vh; min-height:350px; }
        #solar-preview-modal .solar-preview-modal-dialog { width:auto; margin:8px; }
    }
</style>
