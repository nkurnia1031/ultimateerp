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
    data-batch-url-base="{{ $batchUrlBase }}"
    data-can-edit="{{ $canEdit ? '1' : '0' }}"
    data-can-retry="{{ $canRetry ? '1' : '0' }}">
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
                            <div class="col-md-12"><p id="gl_backfill_location_mapping_result" class="help-block" aria-live="polite"></p><button id="gl_backfill_location_retry" class="btn btn-warning" type="button" style="display:none">Coba ulang item cocok</button></div>
                        </div>
                        <hr>
                        <div class="row">
                            <div class="col-md-12"><h4>Mapping akun sumber utama ke CoA</h4><p class="text-muted">Mapping ini dipakai untuk akun kas/bank dan akun utama yang belum memiliki crosswalk.</p></div>
                            <div class="col-md-4"><div class="form-group"><label for="gl_backfill_main_account">Akun sumber</label><select id="gl_backfill_main_account" class="form-control"><option value="">Pilih akun sumber</option></select></div></div>
                            <div class="col-md-4"><div class="form-group"><label for="gl_backfill_main_coa">Akun CoA</label><select id="gl_backfill_main_coa" class="form-control"><option value="">Pilih akun</option></select></div></div>
                            <div class="col-md-4"><div class="form-group"><label>&nbsp;</label><button id="gl_backfill_save_main_mapping" class="btn btn-default btn-block" type="button">Simpan crosswalk</button></div></div>
                            <div class="col-md-12"><p id="gl_backfill_main_mapping_result" class="help-block" aria-live="polite"></p><button id="gl_backfill_main_retry" class="btn btn-warning" type="button" style="display:none">Coba ulang item cocok</button></div>
                        </div>
                        <hr>
                        <div class="row">
                            <div class="col-md-12"><h4>Klasifikasi VAT business</h4><p class="text-muted">Pilih akun VAT In dan VAT Out. Nilai pajak tetap dihitung per transaksi.</p></div>
                            <div class="col-md-4"><div class="form-group"><label for="gl_backfill_vat_input">VAT In</label><select id="gl_backfill_vat_input" class="form-control"><option value="">Pilih akun VAT In</option></select></div><button class="btn btn-default gl-backfill-save-vat" data-direction="input" type="button">Simpan VAT In</button><p id="gl_backfill_vat_input_result" class="help-block" aria-live="polite"></p><button id="gl_backfill_vat_input_retry" class="btn btn-warning" type="button" style="display:none">Coba ulang item cocok</button></div>
                            <div class="col-md-4"><div class="form-group"><label for="gl_backfill_vat_output">VAT Out</label><select id="gl_backfill_vat_output" class="form-control"><option value="">Pilih akun VAT Out</option></select></div><button class="btn btn-default gl-backfill-save-vat" data-direction="output" type="button">Simpan VAT Out</button><p id="gl_backfill_vat_output_result" class="help-block" aria-live="polite"></p><button id="gl_backfill_vat_output_retry" class="btn btn-warning" type="button" style="display:none">Coba ulang item cocok</button></div>
                        </div>
                    @endif
                </div>
            </div>
        </div>
    </div>

    <div class="box box-solid">
        <div class="box-body table-responsive">
            <table class="table table-bordered table-striped" id="gl_backfill_review_table">
                <thead><tr><th>Sumber</th><th>Tanggal</th><th>Ringkasan / nominal</th><th>Alasan</th><th>Status</th></tr></thead>
                <tbody><tr><td colspan="5" class="text-center">Memuat antrean…</td></tr></tbody>
            </table>
            <div class="row"><div class="col-sm-6"><span id="gl_backfill_page_summary" class="text-muted"></span></div><div class="col-sm-6 text-right"><button id="gl_backfill_prev" class="btn btn-default btn-sm" type="button">Sebelumnya</button> <span id="gl_backfill_page_number"></span> <button id="gl_backfill_next" class="btn btn-default btn-sm" type="button">Berikutnya</button></div></div>
        </div>
    </div>
    <p class="text-muted">Sumber yang tidak bisa diselesaikan dengan mapping tetap di antrean untuk jalur jurnal manual.</p>
</section>
@stop

@section('javascript')
<script type="text/javascript" src="{{ versioned_asset_url(Module::asset('accounting:js/general_ledger_backfill_review.js'), $asset_v ?? null) }}"></script>
@stop
