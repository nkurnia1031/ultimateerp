@extends('layouts.app')

@section('title', 'Preview import Expense')

@section('css')
    <style>
        html, body { overflow-x: hidden; }
        #expense_import_preview_page { max-width: 100%; overflow-x: hidden; }
        #expense_import_preview_table_wrapper,
        #expense_import_preview_table_wrapper .row,
        #expense_import_preview_page .table-responsive { max-width: 100%; overflow-x: hidden; }
        #expense_import_preview_table { width: 100% !important; min-width: 0 !important; table-layout: fixed; }
        #expense_import_preview_table th,
        #expense_import_preview_table td { vertical-align: top; white-space: normal; overflow-wrap: anywhere; }
        #expense_import_preview_table_wrapper .row { display: flex; flex-wrap: wrap; margin-left: -6px; margin-right: -6px; }
        #expense_import_preview_table_wrapper .row > [class*="col-"] { float: none; flex: 1 1 230px; width: auto; max-width: 100%; padding-left: 6px; padding-right: 6px; }
        #expense_import_preview_table_wrapper .dt-buttons { display: flex; flex-wrap: wrap; gap: 4px; margin-bottom: 8px; }
        #expense_import_preview_table_wrapper .dataTables_filter label { white-space: normal; }
        #expense_import_preview_table_wrapper .dataTables_filter input { max-width: 100%; }
        .expense-import-existing-action, .expense-import-map-tax, .expense-import-map-journal { width: 100%; min-width: 0; max-width: 100%; margin-bottom: 6px; }
        .expense-import-journal-candidates > summary { cursor: pointer; font-weight: 600; margin-top: 6px; }
        .expense-import-journal-candidates .table { margin: 6px 0 0; width: 100%; min-width: 0; table-layout: fixed; }
        .expense-import-journal-candidates .table th,
        .expense-import-journal-candidates .table td { white-space: normal; overflow-wrap: anywhere; }
        #expense_import_preview_summary { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 12px; }
        #expense_import_preview_summary > [class*="col-"] { float: none; width: auto; max-width: 100%; padding-left: 0; padding-right: 0; }
        #expense_import_preview_summary .small-box { margin-bottom: 0; }
        .expense-import-responsive-hint { display: none; }
        .expense-import-preview-filter { display: flex; align-items: center; flex-wrap: wrap; gap: 8px; }
        .expense-import-preview-filter .text-muted { flex: 1 1 260px; }
        @media (max-width: 1199px) {
            .expense-import-responsive-hint { display: block; }
            #expense_import_preview_table { display: block; table-layout: auto; }
            #expense_import_preview_table thead { position: absolute; width: 1px; height: 1px; padding: 0; margin: -1px; overflow: hidden; clip: rect(0, 0, 0, 0); white-space: nowrap; border: 0; }
            #expense_import_preview_table tbody { display: block; width: 100%; }
            #expense_import_preview_table tbody tr { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 8px 14px; width: 100%; margin: 0 0 12px; padding: 10px; border: 1px solid #d2d6de; border-radius: 4px; background: #fff; }
            #expense_import_preview_table tbody tr td[colspan] { grid-column: 1 / -1; }
            #expense_import_preview_table tbody td { display: block; width: auto !important; min-width: 0; padding: 4px 2px !important; border: 0 !important; }
            #expense_import_preview_table tbody td::before { display: block; margin-bottom: 3px; color: #666; font-size: 11px; font-weight: 700; content: attr(data-label); }
            #expense_import_preview_table tbody td:nth-child(5), #expense_import_preview_table tbody td:nth-child(8) { grid-column: 1 / -1; }
        }
        @media (max-width: 767px) {
            #expense_import_preview_table_wrapper .dataTables_filter { float: none; text-align: left; }
            #expense_import_preview_table_wrapper .dataTables_filter label { display: flex; align-items: center; flex-wrap: wrap; gap: 6px; }
            #expense_import_preview_table_wrapper .dataTables_filter input { flex: 1 1 140px; width: auto; }
            #expense_import_preview_table_wrapper .dataTables_paginate { float: none; text-align: left; white-space: normal; }
            #expense_import_preview_summary { grid-template-columns: repeat(2, minmax(0, 1fr)); }
            .expense-import-preview-actions .btn { margin: 0 4px 6px 0; }
            .expense-import-preview-filter .text-muted { flex-basis: 100%; }
        }
    </style>
@endsection

@section('content')
    <section id="expense_import_preview_page" class="content" data-batch-id="{{ $batch->id }}" data-history-url="{{ route('expenses.import', ['focus_batch' => $batch->id]).'#expense_import_batch_'.$batch->id }}">
        <div class="content-header">
            <h1 class="tw-text-xl md:tw-text-3xl tw-font-bold tw-text-black">Preview import Expense</h1>
            <p class="text-muted">{{ $batch->file_name }} · {{ @format_datetime($batch->created_at) }}</p>
        </div>

        <div id="expense_import_preview_config"
            data-status-template="{{ route('expenses.import.status', ['batch' => '__BATCH__']) }}"
            data-preview-template="{{ route('expenses.import.preview', ['batch' => '__BATCH__']) }}"
            data-refresh-template="{{ route('expenses.import.refresh-preview', ['batch' => '__BATCH__']) }}"
            data-confirm-template="{{ route('expenses.import.confirm', ['batch' => '__BATCH__']) }}"
            data-cancel-template="{{ route('expenses.import.cancel', ['batch' => '__BATCH__']) }}"
            data-date-template="{{ route('expenses.import.rows.date', ['batch' => '__BATCH__', 'row' => '__ROW__']) }}"
            data-tax-template="{{ route('expenses.import.rows.tax', ['batch' => '__BATCH__', 'row' => '__ROW__']) }}"
            data-journal-template="{{ route('expenses.import.rows.journal', ['batch' => '__BATCH__', 'row' => '__ROW__']) }}"
            data-existing-expense-template="{{ route('expenses.import.rows.existing-expense', ['batch' => '__BATCH__', 'row' => '__ROW__']) }}"></div>
        <form id="expense_import_csrf_form" class="hidden">@csrf</form>

        <div class="alert alert-info">
            Total Amount dianggap sudah termasuk pajak. Jika REFERENCE NO cocok dengan satu posting Journal Expense yang siap, nominal pajak Journal menjadi acuan; atribut operasional lain mengikuti Excel. Tanpa Journal yang cocok, pajak mengikuti Excel. Kolom <code>EXPENSE NOTE</code> menjadi deskripsi expense-line; <code>DESCRIPTION</code> dipakai hanya jika kolom itu kosong. Catatan induk yang sudah ada dan Note manual per expense-line tetap terpisah. Nama file <code>ATTACH DOCUMENT</code> disimpan pada expense-line, tetapi file fisiknya tidak disertakan di workbook.
        </div>

        <div class="expense-import-preview-actions tw-mb-3">
            <a class="btn btn-default" href="{{ route('expenses.import', ['focus_batch' => $batch->id]).'#expense_import_batch_'.$batch->id }}"><i class="fa fa-arrow-left"></i> Kembali ke riwayat</a>
            <button type="button" id="expense_import_refresh_preview" class="btn btn-default"><i class="fa fa-refresh"></i> Perbarui preview &amp; kandidat Journal</button>
            <button type="button" id="expense_import_confirm" class="btn btn-success" disabled><i class="fa fa-check"></i> Import baris valid</button>
        </div>
        <div id="expense_import_confirm_feedback" class="alert alert-info" role="status" aria-live="polite" style="display:none"></div>

        <div class="expense-import-preview-filter tw-mb-3">
            <label for="expense_import_preview_status" class="tw-mr-2">Filter status</label>
            <select id="expense_import_preview_status" class="form-control"><option value="">Semua</option><option value="valid">Valid</option><option value="error">Error</option><option value="skipped">Dilewati</option></select>
            <span class="text-muted tw-ml-2">Pilihan tanggal, pajak, dan kandidat tetap tersimpan jika preview diperbarui.</span>
        </div>

        <div class="row" id="expense_import_preview_summary">
            <div class="col-sm-3"><div class="small-box bg-blue"><div class="inner"><h3 data-expense-summary="total">0</h3><p>Total baris</p></div></div></div>
            <div class="col-sm-3"><div class="small-box bg-green"><div class="inner"><h3 data-expense-summary="valid">0</h3><p>Valid / siap import</p></div></div></div>
            <div class="col-sm-3"><div class="small-box bg-red"><div class="inner"><h3 data-expense-summary="errors">0</h3><p>Perlu diperbaiki</p></div></div></div>
            <div class="col-sm-3"><div class="small-box bg-yellow"><div class="inner"><h3 data-expense-summary="skipped">0</h3><p>Dilewati</p></div></div></div>
        </div>
        <p class="text-muted" id="expense_import_journal_summary">Journal cocok: 0 grup · perlu pilihan: 0 grup</p>
        <p class="text-muted" id="expense_import_existing_summary">Expense lama diperbarui: 0 grup · grup split cocok: 0 · perlu pilihan: 0 grup</p>
        <p class="text-muted small expense-import-responsive-hint">Pada layar sempit, informasi baris tersusun sebagai kartu; pilihan aksi dan rincian tetap tampil tanpa scroll mendatar.</p>

        <div class="table-responsive">
            <table class="table table-bordered table-striped" id="expense_import_preview_table" style="width:100%">
                <thead><tr><th>Baris Excel</th><th>Referensi</th><th>Tanggal</th><th>Tanggal bayar</th><th>Aksi</th><th>Pajak</th><th>Status</th><th>Detail</th></tr></thead>
            </table>
        </div>
    </section>

    <div class="modal fade" id="expense_import_date_modal" tabindex="-1" role="dialog" aria-labelledby="expenseImportDateTitle">
        <div class="modal-dialog" role="document">
            <div class="modal-content">
                <form id="expense_import_date_form">
                    <div class="modal-header"><button type="button" class="close" data-dismiss="modal"><span aria-hidden="true">&times;</span></button><h4 class="modal-title" id="expenseImportDateTitle">Perbaiki tanggal</h4></div>
                    <div class="modal-body">
                        <div id="expense_import_date_error" class="alert alert-danger" style="display:none"></div>
                        <input type="hidden" id="expense_import_date_row"><input type="hidden" id="expense_import_date_field">
                        <div class="form-group"><label for="expense_import_date_value">Tanggal</label><input type="date" class="form-control" id="expense_import_date_value" required></div>
                    </div>
                    <div class="modal-footer"><button type="button" class="btn btn-default" data-dismiss="modal">Batal</button><button type="submit" class="btn btn-primary"><i class="fa fa-save"></i> Simpan dan validasi ulang</button></div>
                </form>
            </div>
        </div>
    </div>
@endsection

@section('javascript')
    <script>window.expenseImportTaxOptions = @json($expense_import_tax_options ?? []);</script>
    <script src="{{ versioned_asset_url(asset('js/expense-import-preview.js'), $asset_v ?? null) }}"></script>
@endsection
