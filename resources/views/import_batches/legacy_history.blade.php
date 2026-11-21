<div class="row">
    <div class="col-sm-12">
        <h3>Riwayat import</h3>
        <div class="form-group" style="max-width:220px">
            <label for="legacy_import_status_filter">Filter status</label>
            <select id="legacy_import_status_filter" class="form-control">
                <option value="">Semua status</option>
                <option value="queued_import">Queued</option>
                <option value="importing">Importing</option>
                <option value="imported">Imported</option>
                <option value="failed">Failed</option>
                <option value="reverted">Reverted</option>
            </select>
        </div>
        <table class="table table-bordered table-striped" id="legacy_import_batch_history" width="100%"
            data-history-url="{{ $historyUrl }}">
            <thead><tr><th>ID</th><th>Workbook</th><th>Status</th><th>Progress</th><th>Waktu</th><th>Aksi</th></tr></thead>
        </table>
    </div>
</div>
<div class="modal fade" id="legacy_import_detail_modal" tabindex="-1" role="dialog" aria-labelledby="legacy_import_detail_title">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <button type="button" class="close" data-dismiss="modal" aria-label="Tutup"><span aria-hidden="true">&times;</span></button>
                <h4 class="modal-title" id="legacy_import_detail_title">Detail import</h4>
            </div>
            <div class="modal-body">
                <p id="legacy_import_detail_status"></p>
                <div id="legacy_import_detail_records"></div>
                <pre id="legacy_import_detail_rows" style="white-space:pre-wrap"></pre>
            </div>
        </div>
    </div>
</div>
