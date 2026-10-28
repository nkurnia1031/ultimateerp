@extends('layouts.app')

@section('title', 'Review Backfill Buku Besar')

@section('content')
@include('accounting::layouts.nav')

<section class="content-header">
    <h1>Review Backfill Buku Besar</h1>
    <p class="text-muted">Tinjau sumber yang belum tertaut. Perbaiki mapping yang jelas, lalu kirim ulang item cocok ke worker.</p>
</section>

<section id="gl_backfill_review_page" class="content"
    data-rows-url="{{ $rowsUrl }}"
    data-options-url="{{ $optionsUrl }}"
    data-location-mapping-url="{{ $locationMappingUrl }}"
    data-main-account-mapping-url="{{ $mainAccountMappingUrl }}"
    data-vat-mapping-url="{{ $vatMappingUrl }}"
    data-retry-url="{{ $retryUrl }}"
    data-manual-journal-url-base="{{ $manualJournalUrlBase }}"
    data-batch-url-base="{{ $batchUrlBase }}"
    data-can-edit="{{ $canEdit ? '1' : '0' }}"
    data-can-retry="{{ $canRetry ? '1' : '0' }}"
    data-can-manual-journal="{{ $canManualJournal ? '1' : '0' }}">
    <div class="row">
        <div class="col-md-12">
            <div class="box box-solid">
                <div class="box-header with-border">
                    <h3 class="box-title">Ringkasan antrean</h3>
                    <div class="box-tools"><strong id="gl_backfill_unique_count">Memuat…</strong> sumber unik</div>
                </div>
                <div class="box-body">
                    <div id="gl_backfill_retry_progress" class="alert alert-info" style="display:none" role="status" aria-live="polite"></div>
                    <div class="row">
                        <div class="col-md-3 col-sm-6">
                            <div class="form-group"><label for="gl_backfill_search">Cari sumber</label><input id="gl_backfill_search" class="form-control" type="search" placeholder="Nomor, ringkasan, atau ID"></div>
                        </div>
                        <div class="col-md-2 col-sm-6">
                            <div class="form-group"><label for="gl_backfill_reason">Alasan skip</label><select id="gl_backfill_reason" class="form-control"><option value="">Semua alasan</option></select></div>
                        </div>
                        <div class="col-md-2 col-sm-6">
                            <div class="form-group"><label for="gl_backfill_source_type">Tipe sumber</label><select id="gl_backfill_source_type" class="form-control"><option value="">Semua tipe</option></select></div>
                        </div>
                        <div class="col-md-2 col-sm-6">
                            <div class="form-group"><label for="gl_backfill_status">Status</label><select id="gl_backfill_status" class="form-control"><option value="pending" selected>Menunggu mapping</option><option value="retrying">Sedang dicoba</option><option value="resolved">Selesai</option><option value="">Semua status</option></select></div>
                        </div>
                        <div class="col-md-3 col-sm-6">
                            <div class="form-group"><label>Periode sumber</label><div class="input-group"><input id="gl_backfill_start_date" class="form-control" type="date" aria-label="Tanggal mulai"><span class="input-group-addon">s.d.</span><input id="gl_backfill_end_date" class="form-control" type="date" aria-label="Tanggal akhir"></div></div>
                        </div>
                    </div>
                    @if($canEdit)
                        <hr>
                        <div class="row">
                            <div class="col-md-12"><h4>Mapping lokasi dan jenis transaksi</h4><p class="text-muted">Mapping disimpan pada lokasi dan jenis sumber yang dipilih. Akun lawan pembayaran memakai sisi akun yang digunakan Accounting.</p></div>
                            <div class="col-md-3"><div class="form-group"><label for="gl_backfill_mapping_group">Lokasi dan jenis</label><select id="gl_backfill_mapping_group" class="form-control"><option value="">Pilih kelompok antrean</option></select></div></div>
                            <div class="col-md-3"><div class="form-group"><label for="gl_backfill_payment_account">Akun CoA / pendapatan / pembayaran</label><select id="gl_backfill_payment_account" class="form-control"><option value="">Pilih akun</option></select></div></div>
                            <div class="col-md-3"><div class="form-group"><label for="gl_backfill_deposit_account">Akun CoA lawan / biaya</label><select id="gl_backfill_deposit_account" class="form-control"><option value="">Pilih akun</option></select></div></div>
                            <div class="col-md-3"><div class="form-group"><label>&nbsp;</label><button id="gl_backfill_save_location_mapping" class="btn btn-primary btn-block" type="button">Simpan mapping</button></div></div>
                            <div class="col-md-12"><p id="gl_backfill_location_mapping_result" class="help-block" aria-live="polite"></p></div>
                        </div>
                        <hr>
                        <div class="row">
                            <div class="col-md-12"><h4>Mapping akun sumber utama ke CoA</h4><p class="text-muted">Mapping ini dipakai untuk akun kas/bank dan akun utama yang belum memiliki crosswalk.</p></div>
                            <div class="col-md-4"><div class="form-group"><label for="gl_backfill_main_account">Akun sumber</label><select id="gl_backfill_main_account" class="form-control"><option value="">Pilih akun sumber</option></select></div></div>
                            <div class="col-md-4"><div class="form-group"><label for="gl_backfill_main_coa">Akun CoA</label><select id="gl_backfill_main_coa" class="form-control"><option value="">Pilih akun</option></select></div></div>
                            <div class="col-md-4"><div class="form-group"><label>&nbsp;</label><button id="gl_backfill_save_main_mapping" class="btn btn-default btn-block" type="button">Simpan crosswalk</button></div></div>
                            <div class="col-md-12"><p id="gl_backfill_main_mapping_result" class="help-block" aria-live="polite"></p></div>
                        </div>
                        <hr>
                        <div class="row">
                            <div class="col-md-12"><h4>Klasifikasi VAT business</h4><p class="text-muted">Pilih akun VAT In dan VAT Out. Nilai pajak tetap dihitung per transaksi.</p></div>
                            <div class="col-md-4"><div class="form-group"><label for="gl_backfill_vat_input">VAT In</label><select id="gl_backfill_vat_input" class="form-control"><option value="">Pilih akun VAT In</option></select></div><button class="btn btn-default gl-backfill-save-vat" data-direction="input" type="button">Simpan VAT In</button><p id="gl_backfill_vat_input_result" class="help-block" aria-live="polite"></p></div>
                            <div class="col-md-4"><div class="form-group"><label for="gl_backfill_vat_output">VAT Out</label><select id="gl_backfill_vat_output" class="form-control"><option value="">Pilih akun VAT Out</option></select></div><button class="btn btn-default gl-backfill-save-vat" data-direction="output" type="button">Simpan VAT Out</button><p id="gl_backfill_vat_output_result" class="help-block" aria-live="polite"></p></div>
                        </div>
                    @endif
                </div>
            </div>
        </div>
    </div>

    <div class="box box-solid">
        <div class="box-body table-responsive">
            @if($canRetry)
                <div class="clearfix" style="margin-bottom:10px">
                    <span id="gl_backfill_selection_summary" class="text-muted">0 sumber terpilih pada halaman ini.</span>
                    <button id="gl_backfill_retry_preview" class="btn btn-warning btn-sm pull-right" type="button" disabled>Pratinjau retry terpilih</button>
                </div>
            @endif
            <table class="table table-bordered table-striped" id="gl_backfill_review_table">
                <thead><tr>@if($canRetry)<th><label class="sr-only" for="gl_backfill_select_page">Pilih semua sumber pada halaman</label><input id="gl_backfill_select_page" type="checkbox" aria-label="Pilih semua sumber menunggu pada halaman"></th>@endif<th>Sumber</th><th>Tanggal</th><th>Ringkasan / nominal</th><th>Alasan</th><th>Status</th>@if($canManualJournal)<th>Aksi</th>@endif</tr></thead>
                <tbody><tr><td colspan="{{ $canManualJournal ? 6 : 5 }}" class="text-center">Memuat antrean…</td></tr></tbody>
            </table>
            <div class="row"><div class="col-sm-6"><span id="gl_backfill_page_summary" class="text-muted"></span></div><div class="col-sm-6 text-right"><button id="gl_backfill_prev" class="btn btn-default btn-sm" type="button">Sebelumnya</button> <span id="gl_backfill_page_number"></span> <button id="gl_backfill_next" class="btn btn-default btn-sm" type="button">Berikutnya</button></div></div>
        </div>
    </div>
    <p class="text-muted">Sumber yang tidak bisa diselesaikan dengan mapping tetap di antrean untuk jalur jurnal manual.</p>
</section>
@if($canRetry)
<div class="modal fade" id="gl_backfill_retry_preview_modal" tabindex="-1" role="dialog" aria-labelledby="gl_backfill_retry_preview_title">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <button type="button" class="close" data-dismiss="modal" aria-label="Tutup"><span aria-hidden="true">&times;</span></button>
                <h4 class="modal-title" id="gl_backfill_retry_preview_title">Konfirmasi proses ulang sumber</h4>
            </div>
            <div class="modal-body">
                <p id="gl_backfill_retry_preview_summary" class="well well-sm" aria-live="polite"></p>
                <div class="row">
                    <div class="col-sm-6"><strong>Total debit:</strong> <span id="gl_backfill_retry_preview_debit"></span></div>
                    <div class="col-sm-6"><strong>Total kredit:</strong> <span id="gl_backfill_retry_preview_credit"></span></div>
                </div>
                <h5>Ringkasan akun CoA dan sisi jurnal</h5>
                <div class="table-responsive"><table class="table table-bordered table-condensed" id="gl_backfill_retry_preview_accounts"><thead><tr><th>Akun</th><th>Sisi</th><th class="text-right">Jumlah</th></tr></thead><tbody></tbody></table></div>
                <h5>Sumber dan rincian baris jurnal</h5>
                <div class="table-responsive"><table class="table table-bordered table-condensed" id="gl_backfill_retry_preview_items"><thead><tr><th>Sumber</th><th>Label / tanggal</th><th>Rencana jurnal atau alasan dikeluarkan</th></tr></thead><tbody></tbody></table></div>
                <div class="checkbox"><label><input id="gl_backfill_retry_preview_ack" type="checkbox"> Saya sudah meninjau sumber, label, akun CoA, sisi debit/kredit, jumlah, dan baris VAT di atas.</label></div>
                <p class="text-muted">Jika mapping atau transaksi berubah sebelum worker memproses, sumber akan dikeluarkan dan tidak diposting dari rencana lama.</p>
                <p id="gl_backfill_retry_preview_error" class="text-danger" role="alert" style="display:none"></p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-default" data-dismiss="modal">Tinjau nanti</button>
                <button id="gl_backfill_retry_confirm" type="button" class="btn btn-warning" disabled>Konfirmasi dan proses</button>
            </div>
        </div>
    </div>
</div>
@endif
@if($canManualJournal)
<div class="modal fade" id="gl_backfill_manual_journal_modal" tabindex="-1" role="dialog" aria-labelledby="gl_backfill_manual_journal_title">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <form id="gl_backfill_manual_journal_form">
                <div class="modal-header">
                    <button type="button" class="close" data-dismiss="modal" aria-label="Tutup"><span aria-hidden="true">&times;</span></button>
                    <h4 class="modal-title" id="gl_backfill_manual_journal_title">Jurnal manual untuk sumber antrean</h4>
                </div>
                <div class="modal-body">
                    <p id="gl_backfill_manual_journal_source" class="well well-sm"></p>
                    <div class="form-group">
                        <label for="gl_backfill_manual_journal_reason">Alasan dan catatan koreksi *</label>
                        <textarea id="gl_backfill_manual_journal_reason" class="form-control" rows="3" maxlength="1000" minlength="5" required></textarea>
                        <p class="help-block">Wajib diisi sebagai jejak audit. Jurnal manual tidak mengubah transaksi sumber.</p>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-bordered" id="gl_backfill_manual_journal_lines">
                            <thead><tr><th>CoA</th><th>Debit</th><th>Kredit</th><th></th></tr></thead>
                            <tbody></tbody>
                            <tfoot><tr><th class="text-right">Total</th><th id="gl_backfill_manual_debit_total">0.0000</th><th id="gl_backfill_manual_credit_total">0.0000</th><th></th></tr></tfoot>
                        </table>
                    </div>
                    <button type="button" id="gl_backfill_manual_add_line" class="btn btn-default btn-sm">Tambah baris</button>
                    <p id="gl_backfill_manual_journal_error" class="text-danger" role="alert" style="display:none"></p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-default" data-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-primary" id="gl_backfill_manual_journal_submit">Simpan jurnal dan selesaikan sumber</button>
                </div>
            </form>
        </div>
    </div>
</div>
@endif
@stop

@section('javascript')
<script type="text/javascript" src="{{ versioned_asset_url(Module::asset('accounting:js/general_ledger_backfill_review.js'), $asset_v ?? null) }}"></script>
@stop
