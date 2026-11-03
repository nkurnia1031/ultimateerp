@extends('layouts.app')

@section('title', 'Temuan Rekonsiliasi')

@section('content')
@include('accounting::layouts.nav')

<section class="content-header">
    <h1>Temuan rekonsiliasi <small>Audit legacy per business</small></h1>
    <p class="text-muted">Selisih dari audit terakhir. Koreksi operasional hanya ditawarkan setelah sumber dan nilai pajak terverifikasi; Jurnal legacy tetap menjadi bukti pembanding.</p>
</section>

<section id="reconciliation_findings_page" class="content"
    data-data-url="{{ route('accounting.reconciliation.findings.data') }}"
    data-preview-url-template="{{ route('accounting.reconciliation.findings.correctionPreview', ['findingId' => 999999999999]) }}"
    data-confirm-url-template="{{ route('accounting.reconciliation.findings.correctionConfirm', ['findingId' => 999999999999]) }}"
    data-accept-url-template="{{ route('accounting.reconciliation.findings.accept', ['findingId' => 999999999999]) }}"
    data-adjustment-preview-url-template="{{ route('accounting.reconciliation.findings.adjustmentPreview', ['findingId' => 999999999999]) }}"
    data-adjustment-confirm-url-template="{{ route('accounting.reconciliation.findings.adjustmentConfirm', ['findingId' => 999999999999]) }}"
    data-rounding-account-url="{{ route('accounting.reconciliation.findings.roundingAccount') }}"
    data-currency-precision="{{ $currencyPrecision }}"
    data-rounding-account-id="{{ $roundingAccountId ?: '' }}">
    @if($canManageRounding)
        <div class="box box-warning">
            <div class="box-header with-border"><h3 class="box-title">Akun pembulatan business</h3></div>
            <div class="box-body">
                <div class="row">
                    <div class="col-md-6">
                        <label for="finding_rounding_account">CoA pembulatan yang dipakai untuk selisih di bawah {{ number_format(10 ** (-$currencyPrecision), $currencyPrecision, ',', '.') }}</label>
                        <select id="finding_rounding_account" class="form-control">
                            <option value="">Belum dipetakan</option>
                            @foreach($accountOptions as $account)
                                <option value="{{ $account['id'] }}" @if((int) $roundingAccountId === $account['id']) selected @endif>{{ $account['name'] }} (#{{ $account['id'] }})</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-6">
                        <p class="help-block">Pilih dan simpan CoA secara eksplisit. Sistem tidak akan memilih atau membuat akun otomatis.</p>
                        <button type="button" id="finding_save_rounding_account" class="btn btn-warning">Simpan mapping</button>
                        <span id="finding_rounding_account_status" class="text-muted" role="status" aria-live="polite"></span>
                    </div>
                </div>
            </div>
        </div>
    @endif
    <script type="application/json" id="finding_account_options">@json($accountOptions)</script>
    <div class="row">
        <div class="col-md-4 col-sm-6">
            <div class="info-box"><span class="info-box-icon bg-yellow"><i class="fa fa-exclamation-triangle"></i></span>
                <div class="info-box-content"><span class="info-box-text">Temuan terbuka</span><span id="finding_open_count" class="info-box-number">{{ number_format($summary['open_count']) }}</span></div>
            </div>
        </div>
        <div class="col-md-4 col-sm-6">
            <div class="info-box"><span class="info-box-icon bg-blue"><i class="fa fa-filter"></i></span>
                <div class="info-box-content"><span class="info-box-text">Temuan pada filter</span><span id="finding_filtered_count" class="info-box-number">{{ number_format($summary['filtered_count']) }}</span></div>
            </div>
        </div>
        <div class="col-md-4 col-sm-6">
            <div class="info-box"><span class="info-box-icon bg-green"><i class="fa fa-clock-o"></i></span>
                <div class="info-box-content"><span class="info-box-text">Audit terakhir</span><span id="finding_last_audited_card" class="info-box-number">{{ $summary['last_audited_at'] ?: '—' }}</span></div>
            </div>
        </div>
    </div>

    <div class="box box-primary">
        <div class="box-header with-border">
            <h3 class="box-title">Ringkasan per tahun, jenis, dan komponen</h3>
            <span class="pull-right text-muted">Audit terakhir: <span id="finding_last_audited_at">{{ $summary['last_audited_at'] ?: 'Belum ada audit tersimpan' }}</span></span>
        </div>
        <div class="box-body table-responsive">
            <table class="table table-bordered table-striped" aria-describedby="finding_summary_caption">
                <caption id="finding_summary_caption" class="sr-only">Jumlah temuan beserta selisih bruto dan neto untuk setiap tahun dan jenis temuan.</caption>
                <thead><tr><th>Tahun</th><th>Jenis</th><th>Komponen</th><th class="text-right">Temuan</th><th class="text-right">Bruto</th><th class="text-right">Neto</th></tr></thead>
                <tbody id="finding_summary_rows">@include('accounting::reconciliation.partials.summary')</tbody>
            </table>
        </div>
    </div>

    <div class="box box-default">
        <div class="box-header with-border"><h3 class="box-title">Filter temuan</h3></div>
        <div class="box-body">
            <form id="reconciliation_findings_filter" method="get" action="{{ route('accounting.reconciliation.findings') }}">
                <input type="hidden" name="page" value="1">
                <div class="row">
                    <div class="col-md-2 col-sm-6"><div class="form-group"><label for="finding_filter_year">Tahun</label><select class="form-control" id="finding_filter_year" name="year"><option value="">Semua tahun</option>@foreach($years as $year)<option value="{{ $year }}" @if((string) $filters['year'] === (string) $year) selected @endif>{{ $year }}</option>@endforeach</select></div></div>
                    <div class="col-md-3 col-sm-6"><div class="form-group"><label for="finding_filter_type">Jenis</label><select class="form-control" id="finding_filter_type" name="type"><option value="">Semua jenis</option>@foreach(['transaction', 'control', 'account_balance', 'unbalanced_journal', 'cash_bank', 'loan_classification'] as $type)<option value="{{ $type }}" @if($filters['type'] === $type) selected @endif>{{ $findings->typeLabel($type) }}</option>@endforeach</select></div></div>
                    <div class="col-md-2 col-sm-6"><div class="form-group"><label for="finding_filter_status">Status</label><select class="form-control" id="finding_filter_status" name="status"><option value="open" @if($filters['status'] === 'open') selected @endif>Terbuka</option><option value="accepted" @if($filters['status'] === 'accepted') selected @endif>Diterima</option><option value="resolved" @if($filters['status'] === 'resolved') selected @endif>Selesai</option><option value="all" @if($filters['status'] === 'all') selected @endif>Semua status</option></select></div></div>
                    <div class="col-md-4 col-sm-10"><div class="form-group"><label for="finding_filter_search">Cari nomor, akun, tanggal, atau keterangan</label><input class="form-control" id="finding_filter_search" name="search" type="search" maxlength="120" value="{{ $filters['search'] }}" placeholder="Contoh: INV-2023 atau VAT In"></div></div>
                    <div class="col-md-1 col-sm-2"><div class="form-group"><label>&nbsp;</label><button class="btn btn-primary btn-block" type="submit" aria-label="Terapkan filter"><i class="fa fa-search"></i></button></div></div>
                </div>
            </form>
            <div id="finding_filter_status_message" class="text-muted" role="status" aria-live="polite">{{ number_format($summary['filtered_count']) }} temuan ditampilkan.</div>
        </div>
        <div id="finding_results" class="box-body table-responsive" aria-busy="false">
            <table class="table table-bordered table-striped">
                <thead><tr><th>Tanggal / Tahun</th><th>Temuan</th><th>Sumber</th><th>Akun</th><th class="text-right">Nilai pembanding</th><th class="text-right">Nilai aktual</th><th class="text-right">Selisih</th><th>Status / Audit</th><th>Aksi</th></tr></thead>
                <tbody id="finding_rows">@include('accounting::reconciliation.partials.rows')</tbody>
            </table>
            <div id="finding_pagination">@include('accounting::reconciliation.partials.pagination')</div>
        </div>
    </div>
</section>

<div class="modal fade" id="reconciliation_finding_action_modal" tabindex="-1" role="dialog" aria-labelledby="finding_action_title" aria-hidden="true">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <button type="button" class="close" data-dismiss="modal" aria-label="Tutup"><span aria-hidden="true">&times;</span></button>
                <h4 class="modal-title" id="finding_action_title">Tinjau selisih</h4>
            </div>
            <div class="modal-body">
                <div id="finding_action_status" class="alert alert-info" role="status" aria-live="polite">Memuat bukti…</div>
                <div id="finding_action_proposal" class="hidden">
                    <p id="finding_action_source"></p>
                    <div class="table-responsive">
                        <table class="table table-bordered">
                            <thead><tr><th>Komponen</th><th>Sebelum</th><th>Usulan</th></tr></thead>
                            <tbody id="finding_action_values"></tbody>
                        </table>
                    </div>
                    <p id="finding_action_journal" class="text-muted"></p>
                </div>
                <div id="finding_action_fallback" class="hidden">
                    <p id="finding_action_reason"></p>
                    <a id="finding_action_editor_link" class="btn btn-default hidden" target="_blank" rel="noopener">Buka transaksi sumber</a>
                </div>
                <div id="finding_accept_reason_wrap" class="hidden">
                    <label for="finding_accept_reason">Alasan menerima selisih <span class="text-danger">(wajib)</span></label>
                    <textarea id="finding_accept_reason" class="form-control" rows="3" maxlength="2000" aria-describedby="finding_accept_help"></textarea>
                    <p id="finding_accept_help" class="help-block">Alasan, pengguna, dan waktu akan tersimpan di histori temuan.</p>
                </div>
                <div id="finding_adjustment_wrap" class="hidden">
                    <p id="finding_adjustment_context" class="text-muted"></p>
                    <div class="form-group">
                        <label for="finding_adjustment_reason">Alasan jurnal <span class="text-danger">(wajib)</span></label>
                        <textarea id="finding_adjustment_reason" class="form-control" rows="2" maxlength="2000" aria-describedby="finding_adjustment_help"></textarea>
                        <p id="finding_adjustment_help" class="help-block">Alasan, pelaku, dan jurnal yang tertaut akan tersimpan di histori. Nilai harus diarahkan untuk mengurangi selisih yang dipilih.</p>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-bordered" id="finding_adjustment_lines_table">
                            <thead><tr><th>CoA</th><th class="text-right">Debit</th><th class="text-right">Kredit</th><th></th></tr></thead>
                            <tbody id="finding_adjustment_lines"></tbody>
                        </table>
                    </div>
                    <button type="button" id="finding_adjustment_add_line" class="btn btn-default btn-sm">Tambah baris CoA</button>
                    <button type="button" id="finding_adjustment_reverse_sides" class="btn btn-default btn-sm">Balik sisi debit/kredit</button>
                    <button type="button" id="finding_adjustment_preview" class="btn btn-info btn-sm pull-right">Tinjau usulan</button>
                    <div id="finding_adjustment_review" class="hidden" aria-live="polite">
                        <hr>
                        <h4>Perbandingan sebelum dan sesudah</h4>
                        <div class="table-responsive">
                            <table class="table table-bordered">
                                <thead><tr><th>Nilai</th><th class="text-right">Sebelum</th><th class="text-right">Setelah pemeriksaan jurnal</th></tr></thead>
                                <tbody id="finding_adjustment_comparison"></tbody>
                            </table>
                        </div>
                        <div class="table-responsive">
                            <table class="table table-bordered table-striped">
                                <thead><tr><th>Akun</th><th class="text-right">Debit</th><th class="text-right">Kredit</th></tr></thead>
                                <tbody id="finding_adjustment_review_lines"></tbody>
                                <tfoot><tr><th>Total jurnal</th><th id="finding_adjustment_debit_total" class="text-right"></th><th id="finding_adjustment_credit_total" class="text-right"></th></tr></tfoot>
                            </table>
                        </div>
                        <p id="finding_adjustment_vat_components" class="text-muted"></p>
                        <label><input type="checkbox" id="finding_adjustment_confirm_intent"> Saya sudah memeriksa bukti dan memastikan jurnal ini ditujukan untuk mengurangi selisih tersebut.</label>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-default" data-dismiss="modal">Batal</button>
                <button type="button" id="finding_action_confirm" class="btn btn-primary hidden">Konfirmasi koreksi pajak</button>
                <button type="button" id="finding_action_accept" class="btn btn-warning hidden">Terima selisih</button>
                <button type="button" id="finding_adjustment_confirm" class="btn btn-primary hidden" disabled>Konfirmasi dan posting jurnal</button>
            </div>
        </div>
    </div>
</div>
@stop

@section('javascript')
<script type="text/javascript" src="{{ versioned_asset_url(Module::asset('accounting:js/reconciliation_findings.js'), $asset_v ?? null) }}"></script>
@stop
