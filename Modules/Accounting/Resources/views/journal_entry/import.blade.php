@extends('layouts.app')

@section('title', 'Impor Jurnal dari Workbook')

@section('content')

@include('accounting::layouts.nav')

<section class="content-header">
    <h1>Impor Jurnal dari Workbook</h1>
</section>

<section id="journal_import_page" class="content no-print" data-status-url-base="{{ url('accounting/journal-entry/import') }}" data-terminate-url-base="{{ url('accounting/journal-entry/import') }}" data-active-statuses="{{ implode(',', \Modules\Accounting\Entities\JournalImportBatchStatus::activeStatuses()) }}" data-waiting-statuses="{{ implode(',', \Modules\Accounting\Entities\JournalImportBatchStatus::waitingStatuses()) }}" data-queued-statuses="{{ implode(',', \Modules\Accounting\Entities\JournalImportBatchStatus::queuedStatuses()) }}">
    @component('components.widget', ['class' => 'box-primary', 'title' => 'Upload & Analisa XLSX'])
        {!! Form::open([
            'url' => action([\Modules\Accounting\Http\Controllers\JournalEntryController::class, 'analyzeImport']),
            'method' => 'post',
            'files' => true,
        ]) !!}
            <div class="row">
                <div class="col-md-6">
                    <div class="form-group">
                        {!! Form::label('journal_file', 'File Journal XLSX:*') !!}
                        {!! Form::file('journal_file', ['class' => 'form-control', 'required', 'accept' => '.xlsx,.xls,.csv']) !!}
                        <p class="help-block">Format yang dibaca: Nomor Akun, Akun, Deskripsi, Debit, Kredit dengan header transaksi seperti Journal Entry #7 | 01/05/2010.</p>
                    </div>
                </div>
                <div class="col-md-6">
                    <button type="submit" class="btn btn-primary" style="margin-top: 25px;">
                        <i class="fas fa-search"></i> Analisa File
                    </button>
                </div>
            </div>
        {!! Form::close() !!}
    @endcomponent

    @if(!empty($analysis))
        @component('components.widget', ['class' => 'box-solid', 'title' => 'Hasil Analisa'])
            <div class="row">
                <div class="col-md-3 col-sm-6">
                    <div class="info-box">
                        <span class="info-box-icon bg-aqua"><i class="fas fa-book"></i></span>
                        <div class="info-box-content">
                            <span class="info-box-text">Total Entry</span>
                            <span class="info-box-number">{{ $analysis['total_entries'] }}</span>
                        </div>
                    </div>
                </div>
                <div class="col-md-3 col-sm-6">
                    <div class="info-box">
                        <span class="info-box-icon bg-green"><i class="fas fa-check"></i></span>
                        <div class="info-box-content">
                            <span class="info-box-text">Journal Entry</span>
                            <span class="info-box-number">{{ $analysis['journal_entry_count'] }}</span>
                        </div>
                    </div>
                </div>
                <div class="col-md-3 col-sm-6">
                    <div class="info-box">
                        <span class="info-box-icon bg-yellow"><i class="fas fa-layer-group"></i></span>
                        <div class="info-box-content">
                            <span class="info-box-text">Transaksi selain Journal Entry</span>
                            <span class="info-box-number">{{ $analysis['non_journal_count'] }}</span>
                        </div>
                    </div>
                </div>
                <div class="col-md-3 col-sm-6">
                    <div class="info-box">
                        <span class="info-box-icon bg-red"><i class="fas fa-exclamation-triangle"></i></span>
                        <div class="info-box-content">
                            <span class="info-box-text">Missing Account</span>
                            <span class="info-box-number">{{ count($analysis['missing_accounts']) }}</span>
                        </div>
                    </div>
                </div>
            </div>

            <div class="row">
                <div class="col-md-6">
                    <table class="table table-bordered table-striped">
                        <tr>
                            <th>Periode file</th>
                            <td>{{ $analysis['source_date_range'] }}</td>
                        </tr>
                        <tr>
                            <th>Unbalanced</th>
                            <td>{{ $analysis['unbalanced_count'] }}</td>
                        </tr>
                        <tr>
                            <th>Duplikasi dalam file</th>
                            <td>{{ $analysis['duplicate_count'] }}</td>
                        </tr>
                        <tr>
                            <th>Sudah pernah diimport</th>
                            <td>{{ $analysis['existing_count'] }}</td>
                        </tr>
                        <tr>
                            <th>Relasi sumber cocok</th>
                            <td>{{ $analysis['source_link_matched_count'] ?? 0 }}</td>
                        </tr>
                        <tr>
                            <th>Relasi perlu ditinjau</th>
                            <td>{{ $analysis['source_link_review_count'] ?? 0 }} ({{ $analysis['source_link_suggested_count'] ?? 0 }} saran, {{ $analysis['source_link_unmatched_count'] ?? 0 }} tidak ditemukan, {{ $analysis['source_link_ambiguous_count'] ?? 0 }} ambigu)</td>
                        </tr>
                    </table>
                </div>
                <div class="col-md-6">
                    <table class="table table-bordered table-striped">
                        <thead>
                            <tr>
                                <th>Jenis transaksi</th>
                                <th class="text-right">Jumlah</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($analysis['types'] as $type => $count)
                                <tr>
                                    <td>{{ $type }}</td>
                                    <td class="text-right">{{ $count }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>

            @php
                $source_link_review_entries = collect($analysis['entries'])->filter(function ($entry) {
                    return in_array($entry['source_link_status'] ?? '', ['unmatched', 'suggested', 'ambiguous'], true);
                })->take(50);
            @endphp
            @if($source_link_review_entries->isNotEmpty())
                <h4>Relasi Sumber Perlu Ditinjau</h4>
                <p class="help-block">Kandidat terdekat ditampilkan sebagai saran dan tidak dipilih otomatis. Pilih sumber atau Buat baru secara eksplisit dari modal kandidat.</p>
                <div class="table-responsive" style="max-height: 320px; overflow: auto;">
                    <table class="table table-bordered table-condensed">
                        <thead>
                            <tr>
                                <th>Jenis</th>
                                <th>Nomor</th>
                                <th>Tanggal</th>
                                <th>Status relasi</th>
                                <th>Kandidat</th>
                                <th>Catatan</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($source_link_review_entries as $entry)
                                <tr class="{{ in_array(($entry['source_link_status'] ?? ''), ['ambiguous', 'suggested'], true) ? 'warning' : 'danger' }}">
                                    <td>{{ $entry['source_type'] }}</td>
                                    <td>{{ $entry['source_number'] }}</td>
                                    <td>{{ $entry['date'] }}</td>
                                    <td>{{ ($entry['source_link_status'] ?? '') === 'ambiguous' ? 'Ambigu' : ((($entry['source_link_status'] ?? '') === 'suggested') ? 'Saran terdekat' : 'Tidak ditemukan') }}</td>
                                    <td>{{ implode(', ', $entry['source_link_candidates'] ?? []) ?: '—' }}</td>
                                    <td>{{ $entry['source_link_note'] ?? '' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                @if(($analysis['source_link_review_count'] ?? 0) > 50)
                    <p class="help-block">Menampilkan 50 dari {{ $analysis['source_link_review_count'] }} entry yang perlu ditinjau.</p>
                @endif
            @endif

            @php
                $activeDraftType = collect($draftTypeCounts ?? [])->filter(function ($count) { return $count > 0; })->keys()->first() ?? 'Journal Entry';
            @endphp
            <section id="journal_import_draft"
                data-import-id="{{ $batch->id }}"
                data-rows-url="{{ url('accounting/journal-entry/import/'.$batch->id.'/draft-rows') }}"
                data-row-url-base="{{ url('accounting/journal-entry/import/'.$batch->id.'/draft-rows') }}"
                data-source-groups-preview-url="{{ url('accounting/journal-entry/import/'.$batch->id.'/source-groups/preview') }}"
                data-source-groups-confirm-url="{{ url('accounting/journal-entry/import/'.$batch->id.'/source-groups/confirm') }}"
                data-cohort-mappings-preview-url="{{ url('accounting/journal-entry/import/'.$batch->id.'/cohort-mappings/preview') }}"
                data-cohort-mappings-apply-url="{{ url('accounting/journal-entry/import/'.$batch->id.'/cohort-mappings/apply') }}"
                data-review-groups-url="{{ url('accounting/journal-entry/import/'.$batch->id.'/review-groups') }}"
                data-review-group-resolve-url="{{ url('accounting/journal-entry/import/'.$batch->id.'/review-groups/resolve') }}"
                data-bulk-review-url="{{ url('accounting/journal-entry/import/'.$batch->id.'/draft-rows/approve-differences') }}"
                data-options-url="{{ url('accounting/journal-entry/import/'.$batch->id.'/draft-options') }}"
                data-csrf-token="{{ csrf_token() }}"
                data-default-type="{{ $activeDraftType }}">
                <h4>Draft transaksi per jenis</h4>
                <p class="help-block">Daftar awal hanya menampilkan baris yang belum selesai. Baris selesai tetap tersimpan dan dapat dilihat dari filter status proses. Selisih nominal dicatat untuk audit dan tidak menahan proses; nilai Journal menjadi acuan.</p>
                <div class="journal-import-tab-scroll" role="region" aria-label="Jenis transaksi Journal" tabindex="0" style="overflow-x:auto; overflow-y:hidden;">
                    <ul class="nav nav-tabs journal-import-type-tabs" role="tablist" style="display:flex; flex-wrap:nowrap; min-width:max-content; margin-bottom:0;">
                        @foreach($draftTypeCounts ?? [] as $sourceType => $typeCount)
                            <li role="presentation" class="{{ $sourceType === $activeDraftType ? 'active' : '' }}" style="float:none; flex:0 0 auto; margin-bottom:-1px;">
                                <button type="button" class="btn btn-link journal-import-type-tab" role="tab" data-type="{{ $sourceType }}" aria-selected="{{ $sourceType === $activeDraftType ? 'true' : 'false' }}">
                                    {{ $sourceType }} <span class="badge" data-type-count="{{ $sourceType }}">{{ $typeCount }}</span>
                                </button>
                            </li>
                        @endforeach
                    </ul>
                </div>
                <div class="row" style="margin-top: 12px;">
                    <div class="col-sm-4 col-md-4">
                        <label for="journal_import_draft_search">Cari baris</label>
                        <input id="journal_import_draft_search" type="search" class="form-control" maxlength="100" placeholder="Nomor, tanggal, deskripsi, atau akun">
                    </div>
                    <div class="col-sm-3 col-md-3">
                        <label for="journal_import_processing_filter">Status proses</label>
                        <select id="journal_import_processing_filter" class="form-control">
                            <option value="remaining" selected>Belum selesai</option>
                            <option value="completed">Selesai (arsip)</option>
                            <option value="all">Semua baris</option>
                        </select>
                    </div>
                    <div class="col-sm-2 col-md-2">
                        <label for="journal_import_status_filter">Filter masalah</label>
                        <select id="journal_import_status_filter" class="form-control">
                            <option value="all">Semua status</option>
                            <option value="errors">Ada error</option>
                            <option value="differences">Ada selisih</option>
                        </select>
                    </div>
                    <div class="col-sm-3 col-md-3">
                        <label for="journal_import_review_filter">Penanda review</label>
                        <select id="journal_import_review_filter" class="form-control">
                            <option value="all">Semua baris</option>
                            <option value="unreviewed">Belum ditinjau</option>
                            <option value="reviewed">Sudah ditinjau</option>
                        </select>
                    </div>
                </div>
                <div class="row">
                    <div class="col-sm-12 text-right" style="margin-top: 8px;">
                        <button type="button" class="btn btn-info btn-sm" id="journal_import_preview_source_groups" style="display:none;">
                            Tinjau grup sumber split
                        </button>
                        <button type="button" class="btn btn-primary btn-sm" id="journal_import_preview_cohort_mappings" style="display:none;">
                            Mapping berulang massal
                        </button>
                        <span id="journal_import_cohort_mappings_status" class="text-muted" aria-live="polite"></span>
                        <span id="journal_import_source_groups_status" class="text-muted" aria-live="polite"></span>
                    </div>
                </div>
                <div class="row">
                    <div class="col-sm-12 text-right">
                        <span id="journal_import_draft_status" class="text-muted" aria-live="polite"></span>
                    </div>
                </div>
                <div class="panel panel-info" id="journal_import_review_groups_panel" style="margin-top: 14px;">
                    <div class="panel-heading clearfix">
                        <strong>Kelompok baris yang perlu ditindaklanjuti</strong>
                        <button type="button" class="btn btn-default btn-xs pull-right" id="journal_import_refresh_review_groups">Perbarui kelompok</button>
                    </div>
                    <div class="panel-body">
                        <p class="help-block" style="margin-bottom: 8px;">Baris dikelompokkan menurut jenis, alasan blokir, dan field yang kurang. Nilai bersama hanya diterapkan ke kelompok yang lolos validasi business; produk, jumlah, harga, dan pilihan sumber tetap ditinjau per baris.</p>
                        <div id="journal_import_review_groups_status" class="text-muted" aria-live="polite">Memuat kelompok...</div>
                        <div class="table-responsive" style="margin-top: 8px;">
                            <table class="table table-condensed table-bordered" id="journal_import_review_groups_table">
                                <thead>
                                    <tr>
                                        <th>Jumlah</th>
                                        <th>Jenis / alasan blokir</th>
                                        <th>Field kurang</th>
                                        <th>Tindakan berikutnya</th>
                                        <th>Aksi</th>
                                    </tr>
                                </thead>
                                <tbody><tr><td colspan="5" class="text-center text-muted">Memuat kelompok...</td></tr></tbody>
                            </table>
                        </div>
                        <div id="journal_import_group_filter_notice" class="alert alert-info hide" role="status">
                            <span>Daftar baris sedang dibatasi ke satu kelompok masalah.</span>
                            <button type="button" class="btn btn-link btn-xs" id="journal_import_clear_group_filter">Tampilkan semua baris tersisa</button>
                        </div>
                    </div>
                </div>
                <div class="row" style="margin: 8px 0;">
                        <div class="col-sm-6">
                            <label for="journal_import_select_page_differences" style="font-weight:normal;">
                                <input type="checkbox" id="journal_import_select_page_differences" disabled>
                                Pilih semua selisih yang tampil di halaman ini
                            </label>
                            <button type="button" class="btn btn-warning btn-sm" id="journal_import_review_selected_differences" disabled style="margin-left:8px;">Tinjau selisih terpilih</button>
                        </div>
                        <div class="col-sm-6 text-right">
                            <span id="journal_import_bulk_review_status" class="text-muted" aria-live="polite">Belum ada selisih dipilih.</span>
                        </div>
                        <div class="col-sm-12" id="journal_import_bulk_review_reason_wrap" style="display:none; margin-top:8px;">
                            <label for="journal_import_bulk_review_reason">Alasan wajib untuk selisih yang belum terjelaskan</label>
                            <textarea id="journal_import_bulk_review_reason" class="form-control" rows="2" maxlength="1000" placeholder="Jelaskan dasar persetujuan selisih yang belum terjelaskan"></textarea>
                        </div>
                </div>
                <div class="table-responsive" role="region" aria-label="Baris transaksi Journal import" tabindex="0" style="margin-top: 10px;">
                    <table class="table table-bordered table-striped" id="journal_import_draft_table">
                        <thead>
                            <tr>
                                <th class="text-center">
                                    <span class="sr-only">Pilih</span>
                                    <input type="checkbox" id="journal_import_select_all_differences_header" aria-label="Pilih semua selisih pada halaman ini" disabled>
                                </th>
                                <th>Nomor / tanggal</th>
                                <th>Deskripsi</th>
                                <th>Status proses / selisih</th>
                                <th class="text-right">Debit</th>
                                <th class="text-right">Kredit</th>
                                <th>Aksi sumber</th>
                                <th>Sumber yang dipilih</th>
                                <th>Ditinjau</th>
                                <th>Detail</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr><td colspan="10" class="text-center text-muted">Memuat draft...</td></tr>
                        </tbody>
                    </table>
                </div>
                <div class="clearfix">
                    <small id="journal_import_draft_pagination_info" class="text-muted pull-left"></small>
                    <div class="btn-group pull-right" role="group" aria-label="Navigasi baris draft">
                        <button type="button" class="btn btn-default btn-sm" id="journal_import_draft_previous" disabled>Sebelumnya</button>
                        <button type="button" class="btn btn-default btn-sm" id="journal_import_draft_next" disabled>Berikutnya</button>
                    </div>
                </div>
            </section>

            <div class="modal fade" id="journal_import_detail_modal" tabindex="-1" role="dialog" aria-labelledby="journal_import_detail_title">
                <div class="modal-dialog modal-lg" role="document">
                    <div class="modal-content">
                        <form id="journal_import_detail_form">
                            <div class="modal-header">
                                <button type="button" class="close" data-dismiss="modal" aria-label="Tutup"><span aria-hidden="true">&times;</span></button>
                                <h4 class="modal-title" id="journal_import_detail_title">Lengkapi detail transaksi</h4>
                            </div>
                            <div class="modal-body">
                                <div id="journal_import_detail_error" class="alert alert-danger hide" role="alert"></div>
                                <div class="row">
                                    <div class="col-sm-6 journal-draft-field journal-draft-field-contact">
                                        <div class="form-group">
                                            <label for="journal_draft_contact">Customer / Supplier / Contact</label>
                                            <select id="journal_draft_contact" class="form-control journal-draft-select" data-kind="contact" style="width:100%"></select>
                                        </div>
                                    </div>
                                    <div class="col-sm-6 journal-draft-field journal-draft-field-location">
                                        <div class="form-group">
                                            <label for="journal_draft_location">Lokasi</label>
                                            <select id="journal_draft_location" class="form-control journal-draft-select" data-kind="location" style="width:100%"></select>
                                        </div>
                                    </div>
                                    <div class="col-sm-6 journal-draft-field journal-draft-field-stock-direction">
                                        <div class="form-group">
                                            <label for="journal_draft_movement_direction">Arah stok</label>
                                            <select id="journal_draft_movement_direction" class="form-control journal-draft-select">
                                                <option value="">Pilih arah pergerakan</option>
                                                <option value="in">Masuk (opening stock)</option>
                                                <option value="out">Keluar (stock adjustment)</option>
                                            </select>
                                            <p class="help-block">Pilih secara eksplisit; alokasi produk dan jumlah tidak diambil dari baris GL.</p>
                                        </div>
                                    </div>
                                    <div class="col-sm-6 journal-draft-field journal-draft-field-payment-account">
                                        <div class="form-group">
                                            <label id="journal_draft_payment_account_label" for="journal_draft_payment_account">Akun pembayaran / bank</label>
                                            <select id="journal_draft_payment_account" class="form-control journal-draft-select" data-kind="payment_account" style="width:100%"></select>
                                        </div>
                                    </div>
                                    <div class="col-sm-6 journal-draft-field journal-draft-field-transfer-account">
                                        <div class="form-group">
                                            <label id="journal_draft_transfer_account_label" for="journal_draft_transfer_account">Akun pasangan</label>
                                            <select id="journal_draft_transfer_account" class="form-control journal-draft-select" data-kind="transfer_account" style="width:100%"></select>
                                            <p class="help-block" id="journal_draft_transfer_account_help"></p>
                                        </div>
                                    </div>
                                    <div class="col-sm-6 journal-draft-field journal-draft-field-reversal-target">
                                        <div class="form-group">
                                            <label for="journal_draft_reversal_target">Transaksi yang dibalik</label>
                                            <select id="journal_draft_reversal_target" class="form-control journal-draft-select" data-kind="reversal_target" style="width:100%"></select>
                                            <p class="help-block">Pilih transaksi bank/kas aktif dari business ini. Sistem membalik nilai pada akun dan nominal target.</p>
                                        </div>
                                    </div>
                                    <div class="col-sm-6 journal-draft-field journal-draft-field-invoice">
                                        <div class="form-group">
                                            <label for="journal_draft_invoice">Invoice yang dibayar</label>
                                            <select id="journal_draft_invoice" class="form-control journal-draft-select" data-kind="invoice" style="width:100%"></select>
                                            <p class="help-block">Pilih invoice final yang sudah ada, atau baris invoice dalam batch yang sama. Pembayaran menunggu invoice tersebut berhasil dan final.</p>
                                        </div>
                                    </div>
                                    <div class="col-sm-6 journal-draft-field journal-draft-field-payment-method">
                                        <div class="form-group">
                                            <label for="journal_draft_payment_method">Metode pembayaran</label>
                                            <select id="journal_draft_payment_method" class="form-control journal-draft-select" data-kind="payment_method" style="width:100%"></select>
                                        </div>
                                    </div>
                                    <div class="col-sm-6 journal-draft-field journal-draft-field-expense-category">
                                        <div class="form-group">
                                            <label for="journal_draft_expense_category">Kategori bawaan (satu akun biaya)</label>
                                            <select id="journal_draft_expense_category" class="form-control journal-draft-select" data-kind="expense_category" style="width:100%"></select>
                                            <p class="help-block">Jika jurnal memiliki beberapa akun biaya, petakan kategori masing-masing di bawah. Nilai ini hanya menjadi fallback untuk satu akun biaya.</p>
                                        </div>
                                    </div>
                                </div>
                                <div class="journal-draft-field journal-draft-field-expense-splits" style="display:none; margin-top: 8px;">
                                    <h5>Pengelompokan akun Expense</h5>
                                    <p class="help-block">Setiap akun debit biaya menjadi baris Expense terpisah. Akun pajak dipisahkan ke nilai pajak transaksi.</p>
                                    <div class="table-responsive">
                                        <table class="table table-condensed" id="journal_draft_expense_splits_table">
                                            <thead><tr><th>Akun Journal</th><th class="text-right">Debit</th><th>Kategori Expense</th></tr></thead>
                                            <tbody></tbody>
                                        </table>
                                    </div>
                                </div>
                                <div class="journal-draft-field journal-draft-field-details">
                                    <h5>Baris detail</h5>
                                    <div class="table-responsive" role="region" aria-label="Detail item transaksi" tabindex="0">
                                        <table class="table table-condensed" id="journal_draft_details_table">
                                            <thead>
                                                <tr><th style="min-width:220px">Produk / variasi</th><th style="min-width:160px">Satuan</th><th style="width:100px">Jumlah</th><th style="width:130px">Harga / satuan</th><th style="min-width:180px">Akun opsional</th><th style="width:70px"></th></tr>
                                            </thead>
                                            <tbody></tbody>
                                        </table>
                                    </div>
                                    <button type="button" class="btn btn-default btn-sm" id="journal_draft_add_detail"><i class="fas fa-plus"></i> Tambah baris</button>
                                    <p class="help-block">Pilih produk yang sudah ada. Jumlah dan harga harus diisi; sistem tidak menebak alokasi dari baris GL.</p>
                                </div>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-default" data-dismiss="modal">Batal</button>
                                <button type="submit" class="btn btn-primary">Simpan detail</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            <div class="modal fade" id="journal_import_candidates_modal" tabindex="-1" role="dialog" aria-labelledby="journal_import_candidates_title" aria-modal="true">
                <div class="modal-dialog modal-lg" role="document">
                    <div class="modal-content">
                        <div class="modal-header">
                            <button type="button" class="close" data-dismiss="modal" aria-label="Tutup"><span aria-hidden="true">&times;</span></button>
                            <h4 class="modal-title" id="journal_import_candidates_title">Kandidat transaksi sumber</h4>
                        </div>
                        <div class="modal-body">
                            <p id="journal_import_candidates_summary" class="text-muted"></p>
                            <div id="journal_import_candidates_journal" class="well well-sm"></div>
                            <div id="journal_import_candidates_list" aria-live="polite"></div>
                            <div id="journal_import_candidates_empty" class="alert alert-info hide">Tidak ada kandidat sumber pada business aktif. Transaksi baru dapat dibuat dari detail Journal setelah lokasi default dan rincian wajib tersedia.</div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-warning journal-draft-candidate-create">Buat transaksi baru</button>
                            <button type="button" class="btn btn-default" data-dismiss="modal">Tutup</button>
                        </div>
                    </div>
                </div>
            </div>

            <div class="modal fade" id="journal_import_source_groups_modal" tabindex="-1" role="dialog" aria-labelledby="journal_import_source_groups_title" aria-modal="true">
                <div class="modal-dialog modal-lg" role="document">
                    <div class="modal-content">
                        <div class="modal-header">
                            <button type="button" class="close" data-dismiss="modal" aria-label="Tutup"><span aria-hidden="true">&times;</span></button>
                            <h4 class="modal-title" id="journal_import_source_groups_title">Konfirmasi grup transaksi split</h4>
                        </div>
                        <div class="modal-body">
                            <p id="journal_import_source_groups_summary" class="text-muted"></p>
                            <div id="journal_import_source_groups_totals"></div>
                            <p id="journal_import_source_groups_limits" class="help-block"></p>
                            <div id="journal_import_source_groups_error" class="alert alert-danger hide" role="alert"></div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-default" data-dismiss="modal">Batal</button>
                            <button type="button" class="btn btn-primary" id="journal_import_confirm_source_groups" disabled>Konfirmasi grup unik</button>
                        </div>
                    </div>
                </div>
            </div>

            <div class="modal fade" id="journal_import_cohort_mappings_modal" tabindex="-1" role="dialog" aria-labelledby="journal_import_cohort_mappings_title" aria-modal="true">
                <div class="modal-dialog modal-lg" role="document">
                    <div class="modal-content">
                        <div class="modal-header">
                            <button type="button" class="close" data-dismiss="modal" aria-label="Tutup"><span aria-hidden="true">&times;</span></button>
                            <h4 class="modal-title" id="journal_import_cohort_mappings_title">Mapping berulang massal</h4>
                        </div>
                        <div class="modal-body">
                            <p id="journal_import_cohort_mappings_summary" class="text-muted"></p>
                            <div class="table-responsive">
                                <table class="table table-bordered table-striped" id="journal_import_cohort_mappings_table">
                                    <thead>
                                        <tr><th>Jenis</th><th>Konteks Journal</th><th>Field</th><th class="text-right">Baris</th><th class="text-right">Total komponen</th><th class="text-right">Total Journal</th><th>Nilai mapping</th></tr>
                                    </thead>
                                    <tbody></tbody>
                                </table>
                            </div>
                            <p class="help-block">Expense dikelompokkan menurut akun Journal dan lokasi. Bank dikelompokkan menurut jenis transaksi dan susunan akun Journal; pilih akun kas/bank yang benar, termasuk kedua akun untuk transfer. Pembayaran hanya menyarankan invoice dengan nomor referensi yang cocok persis dan unik. Kandidat invoice ganda atau yang hanya mirip tetap perlu ditinjau per baris.</p>
                            <div id="journal_import_cohort_mappings_error" class="alert alert-danger hide" role="alert"></div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-default" data-dismiss="modal">Batal</button>
                            <button type="button" class="btn btn-primary" id="journal_import_apply_cohort_mappings" disabled>Terapkan mapping dan proses ulang</button>
                        </div>
                    </div>
                </div>
            </div>

            {!! Form::open([
                'url' => action([\Modules\Accounting\Http\Controllers\JournalEntryController::class, 'startImport']),
                'method' => 'post',
                'id' => 'journal_import_form',
            ]) !!}
                {!! Form::hidden('import_id', $batch->id ?? '') !!}
                {!! Form::hidden('original_file_name', $original_file_name ?? '') !!}

            @if(!empty($analysis['missing_accounts']))
                <h4>Kurasi Mapping Akun</h4>
                <p class="help-block">
                    Pilih akun utama dulu dari tabel accounts, lalu pilih mapping Chart of Accounts. Saat import, transaksi akan masuk ke account_transactions dan accounting_accounts_transactions sekaligus.
                </p>
                <div class="table-responsive" style="max-height: 360px; overflow: auto;">
                    <table class="table table-bordered table-condensed">
                        <thead>
                            <tr>
                                <th>Row</th>
                                <th>Nomor Akun</th>
                                <th>Akun</th>
                                <th>Mapping ke Account Utama</th>
                                <th>Mapping ke Chart of Accounts</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach(array_slice($analysis['missing_accounts'], 0, 50) as $account)
                                @php
                                    $suggested = $account['suggested_accounting_accounts'][0]['id'] ?? null;
                                    $suggested_main = $account['suggested_payment_accounts'][0]['id'] ?? null;
                                @endphp
                                <tr>
                                    <td>{{ $account['sample_row'] }}</td>
                                    <td>{{ $account['account_no'] }}</td>
                                    <td>{{ $account['account_name'] }}</td>
                                    <td style="min-width: 320px;">
                                        {!! Form::select('main_account_mappings['.$account['key'].']', ['' => __('messages.please_select')] + $main_account_options, $suggested_main, ['class' => 'form-control select2', 'style' => 'width: 100%;']) !!}
                                        @if(!empty($account['suggested_payment_accounts']))
                                            <small class="text-muted">
                                                Saran:
                                                @foreach($account['suggested_payment_accounts'] as $suggestion)
                                                    {{ $suggestion['number'] }} - {{ $suggestion['name'] }} ({{ $suggestion['score'] }}%)@if(!$loop->last), @endif
                                                @endforeach
                                            </small>
                                        @endif
                                    </td>
                                    <td style="min-width: 320px;">
                                        {!! Form::select('account_mappings['.$account['key'].']', ['' => __('messages.please_select')] + $account_options, $suggested, ['class' => 'form-control select2', 'style' => 'width: 100%;']) !!}
                                        @if(!empty($account['suggested_accounting_accounts']))
                                            <small class="text-muted">
                                                Saran:
                                                @foreach($account['suggested_accounting_accounts'] as $suggestion)
                                                    {{ $suggestion['number'] }} - {{ $suggestion['name'] }} ({{ $suggestion['score'] }}%)@if(!$loop->last), @endif
                                                @endforeach
                                            </small>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                @if(count($analysis['missing_accounts']) > 50)
                    <p class="help-block">Menampilkan 50 dari {{ count($analysis['missing_accounts']) }} akun yang belum cocok.</p>
                @endif
            @endif

            <div class="alert alert-info">
                <strong>Tarif pajak dihitung per transaksi.</strong>
                Akun VAT In/VAT Out dikenali dari nama dan sisi debit/kredit jurnal. Sistem menghitung tarif dari nominal pajak dibanding basis biaya atau produk transaksi, lalu mencocokkannya dengan TaxRate business yang tersedia.
            </div>

            @php
                $duplicate_entries = collect($analysis['entries'])->filter(function ($entry) {
                    return !empty($entry['duplicate_matches']);
                })->take(20);
            @endphp
            @if($duplicate_entries->isNotEmpty())
                <h4>Sudah Pernah Diimport</h4>
                <div class="table-responsive" style="max-height: 260px; overflow: auto;">
                    <table class="table table-bordered table-condensed">
                        <thead>
                            <tr>
                                <th>Tanggal</th>
                                <th>Ref</th>
                                <th>Jumlah</th>
                                <th>Ditemukan di</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($duplicate_entries as $entry)
                                <tr>
                                    <td>{{ $entry['date'] }}</td>
                                    <td>{{ $entry['source_type'] }} #{{ $entry['source_number'] }}</td>
                                    <td>@format_currency($entry['debit_total'])</td>
                                    <td>{{ implode(', ', $entry['duplicate_matches']) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <p class="help-block">Entry dengan jenis, nomor, dan tanggal yang sudah ada dari import Journal sebelumnya akan dilewati agar tidak terimport dua kali.</p>
            @endif

                <div class="alert alert-info">
                    Baris Journal Entry yang valid diproses melalui worker. Jenis transaksi lain tetap tersimpan di draft sampai datanya lengkap dan handler sumbernya tersedia.
                </div>

                <div class="checkbox">
                    <label>
                        {!! Form::checkbox('create_missing_main_accounts', 1, false) !!}
                        Buat otomatis Account Utama yang belum ada berdasarkan Nomor Akun dan Akun dari file
                    </label>
                </div>

                <div class="checkbox">
                    <label>
                        {!! Form::checkbox('create_missing_accounts', 1, false) !!}
                        Buat otomatis Chart of Accounts yang belum ada dan hubungkan ke Account Utama
                    </label>
                </div>

                <button type="submit" class="btn btn-success">
                    <i class="fas fa-file-import"></i> Proses Baris Valid
                </button>
                <div id="journal_import_progress_box" class="hide" style="margin-top: 15px;">
                    <div class="progress">
                        <div id="journal_import_progress_bar" class="progress-bar progress-bar-striped active" role="progressbar" style="width: 0%;">
                            0%
                        </div>
                    </div>
                    <p class="help-block">
                        <strong id="journal_import_progress_stage">Menyiapkan import...</strong>
                        <span id="journal_import_progress_count"></span>
                        <span> · Pembaruan terakhir: <span id="journal_import_progress_heartbeat">—</span></span>
                    </p>
                    <p id="journal_import_progress_text" class="help-block" aria-live="polite">Menunggu update worker.</p>
                    <button type="button" id="journal_import_terminate" class="btn btn-warning btn-sm journal-import-terminate hide" data-csrf-token="{{ csrf_token() }}">
                        <i class="fas fa-stop"></i> Hentikan proses dengan aman
                    </button>
                    <p id="journal_import_progress_stale" class="text-warning hide"></p>
                    <p id="journal_import_progress_error" class="text-danger hide"></p>
                    <div class="well well-sm" style="max-height: 150px; overflow-y: auto; margin-bottom: 0;">
                        <strong>Log aktivitas</strong>
                        <ul id="journal_import_progress_log" style="margin: 5px 0 0; padding-left: 20px;"></ul>
                    </div>
                </div>
            {!! Form::close() !!}
        @endcomponent
    @endif

    @component('components.widget', ['class' => 'box-solid', 'title' => 'Riwayat Import'])
        <div class="table-responsive">
            <table class="table table-bordered table-striped">
                <thead>
                    <tr>
                        <th>Tanggal</th>
                        <th>File</th>
                        <th>Periode</th>
                        <th>Imported</th>
                        <th>Skipped</th>
                        <th>Relasi perlu review</th>
                        <th>Tax perlu review</th>
                        <th>Status</th>
                        <th>Oleh</th>
                        <th>@lang('messages.action')</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($imports as $import)
                        @php
                            $isJournalImportActive = \Modules\Accounting\Entities\JournalImportBatchStatus::isActive((string) $import->status);
                            $isJournalImportWaiting = \Modules\Accounting\Entities\JournalImportBatchStatus::isWaiting((string) $import->status);
                            $journalImportProgressData = json_decode((string) ($import->progress_data ?? ''), true) ?: [];
                            $journalImportPhase = $journalImportProgressData['phase'] ?? null;
                            $journalImportPhaseProgress = is_string($journalImportPhase)
                                ? ($journalImportProgressData['phases'][$journalImportPhase] ?? null)
                                : null;
                            $journalImportProgressTotal = is_array($journalImportPhaseProgress)
                                ? (int) ($journalImportPhaseProgress['total'] ?? 0)
                                : null;
                            $journalImportProgressIndeterminate = is_array($journalImportPhaseProgress)
                                && $journalImportProgressTotal <= 0;
                            $journalImportPercent = is_array($journalImportPhaseProgress)
                                ? ($journalImportProgressTotal > 0
                                    ? min(100, (int) round(((int) ($journalImportPhaseProgress['processed'] ?? 0) / $journalImportProgressTotal) * 100))
                                    : 0)
                                : (!empty($import->total_entries)
                                    ? min(100, (int) round(((int) $import->processed_entries / (int) $import->total_entries) * 100))
                                    : 0);
                            $journalImportProgressCount = is_array($journalImportPhaseProgress)
                                ? ((int) ($journalImportPhaseProgress['processed'] ?? 0).' / '.(int) ($journalImportPhaseProgress['total'] ?? 0).' '.($journalImportPhaseProgress['unit'] ?? 'baris'))
                                : ((int) $import->processed_entries.' / '.(int) $import->total_entries.' entry');
                        @endphp
                        <tr>
                            @php
                                $importSummary = json_decode((string) $import->summary, true) ?: [];
                            @endphp
                            <td>{{ @format_datetime($import->created_at) }}</td>
                            <td>{{ $import->file_name }}</td>
                            <td>{{ $import->source_date_range }}</td>
                            <td>{{ $import->imported_entries }}</td>
                            <td>{{ $import->skipped_entries }}</td>
                            <td>{{ $importSummary['source_link_review_count'] ?? '—' }}</td>
                            <td>{{ (int) ($import->tax_reconciliation_review_count ?? 0) }}</td>
                            <td>
                                @if($isJournalImportActive)
                                    <span class="label bg-yellow" id="journal-import-status-{{ $import->id }}">{{ $import->status }}</span>
                                @elseif($import->status == 'analyzed')
                                    <span class="label bg-aqua" id="journal-import-status-{{ $import->id }}">analyzed</span>
                                @elseif($import->status == 'imported')
                                    <span class="label bg-green" id="journal-import-status-{{ $import->id }}">imported</span>
                                @elseif($import->status == 'partially_imported')
                                    <span class="label bg-aqua" id="journal-import-status-{{ $import->id }}">partially_imported</span>
                                @elseif($import->status == 'failed')
                                    <span class="label bg-red" id="journal-import-status-{{ $import->id }}">failed</span>
                                @elseif($import->status == 'cancelled')
                                    <span class="label bg-gray" id="journal-import-status-{{ $import->id }}">cancelled</span>
                                @else
                                    <span class="label bg-gray" id="journal-import-status-{{ $import->id }}">{{ $import->status }}</span>
                                @endif
                                @if($isJournalImportActive)
                                    <div class="progress" style="margin-top: 5px; margin-bottom: 0;">
                                        <div id="journal-import-progress-{{ $import->id }}" class="progress-bar progress-bar-striped active" role="progressbar" aria-valuenow="{{ $journalImportPercent }}" aria-valuemin="0" aria-valuemax="100" style="width: {{ ($isJournalImportWaiting && !is_array($journalImportPhaseProgress)) || $journalImportProgressIndeterminate ? '100' : $journalImportPercent }}%;">
                                            {{ (($isJournalImportWaiting && !is_array($journalImportPhaseProgress)) || $journalImportProgressIndeterminate) ? '' : $journalImportPercent.'%' }}
                                        </div>
                                    </div>
                                    <small id="journal-import-count-{{ $import->id }}" class="text-muted">
                                        @if(is_array($journalImportPhaseProgress))
                                            {{ $journalImportProgressIndeterminate ? 'Menyiapkan total '.($journalImportPhaseProgress['unit'] ?? 'baris') : $journalImportProgressCount }}
                                        @elseif(in_array($import->status, ['preparing_import', 'importing', 'reconciling', 'processing']))
                                            {{ $journalImportProgressCount }}
                                        @else
                                            Menunggu update worker
                                        @endif
                                    </small><br>
                                    <small id="journal-import-message-{{ $import->id }}" class="text-muted" aria-live="polite">{{ $import->last_message ?: 'Menunggu worker queue imports.' }}</small><br>
                                    <small class="text-muted">Pembaruan terakhir: <span id="journal-import-heartbeat-{{ $import->id }}">{{ $import->updated_at }}</span></small><br>
                                    <small id="journal-import-stale-{{ $import->id }}" class="text-warning hide"></small>
                                @elseif($import->status === 'failed')
                                    <small class="text-danger">{{ $import->last_message ?: 'Import gagal.' }}</small>
                                    @if(!empty($import->error_message))
                                        <details><summary>Detail error</summary><small class="text-danger">{{ \Illuminate\Support\Str::limit($import->error_message, 500) }}</small></details>
                                    @endif
                                @elseif(!empty($import->last_message))
                                    <br><small class="text-muted">{{ $import->last_message }}</small>
                                @endif
                            </td>
                            <td>{{ $import->added_by }}</td>
                            <td id="journal-import-action-{{ $import->id }}"
                                data-review-url="{{ action([\Modules\Accounting\Http\Controllers\JournalEntryController::class, 'reviewImport'], [$import->id]) }}"
                                data-revert-url="{{ action([\Modules\Accounting\Http\Controllers\JournalEntryController::class, 'revertImport'], [$import->id]) }}"
                                data-terminate-url="{{ action([\Modules\Accounting\Http\Controllers\JournalEntryController::class, 'terminateImport'], [$import->id]) }}"
                                data-csrf-token="{{ csrf_token() }}">
                                @if(in_array($import->status, ['analyzed', 'partially_imported', 'failed', 'cancelled'], true))
                                    <a href="{{ action([\Modules\Accounting\Http\Controllers\JournalEntryController::class, 'reviewImport'], [$import->id]) }}" class="btn btn-primary btn-xs">
                                        <i class="fas fa-check"></i> {{ $import->status === 'analyzed' ? 'Review & Import' : 'Review & Continue' }}
                                    </a>
                                @elseif($isJournalImportActive)
                                    <button type="button" class="btn btn-default btn-xs journal-import-watch" data-id="{{ $import->id }}">
                                        <i class="fas fa-sync"></i> Pantau progres
                                    </button>
                                    <button type="button" class="btn btn-warning btn-xs journal-import-terminate" data-id="{{ $import->id }}" data-cancel-requested="{{ !empty($import->cancel_requested_at) ? '1' : '0' }}" disabled>
                                        <i class="fas fa-stop"></i> {{ !empty($import->cancel_requested_at) ? 'Menunggu berhenti' : 'Terminate' }}
                                    </button>
                                @elseif($import->status == 'imported')
                                    {!! Form::open([
                                        'url' => action([\Modules\Accounting\Http\Controllers\JournalEntryController::class, 'revertImport'], [$import->id]),
                                        'method' => 'post',
                                        'style' => 'display:inline;',
                                        'onsubmit' => 'return confirm("Revert import ini? Journal yang dibuat dari batch ini akan dihapus.")',
                                    ]) !!}
                                        <button type="submit" class="btn btn-danger btn-xs">
                                            <i class="fas fa-undo"></i> Revert
                                        </button>
                                    {!! Form::close() !!}
                                @else
                                    -
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="10" class="text-center">@lang('purchase.no_records_found')</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    @endcomponent

    @if($taxReconciliationReviews->isNotEmpty())
        @component('components.widget', ['class' => 'box-warning', 'title' => 'Rekonsiliasi Pajak Perlu Ditinjau'])
            <p class="help-block">Periksa mapping yang belum tunggal, perbedaan pajak item, atau hasil total transaksi yang tidak sama dengan total debit Journal. Bila pajak item cocok, pajak transaksi diperbarui dari Journal dan selisih total tetap ditandai untuk ditinjau.</p>
            <div class="table-responsive">
                <table class="table table-bordered table-striped">
                    <thead>
                        <tr>
                            <th>Tanggal import</th>
                            <th>File</th>
                            <th>Entry Journal</th>
                            <th>Transaksi sumber</th>
                            <th>Pajak Journal</th>
                            <th>Pajak item hitung</th>
                            <th>Total debit Journal</th>
                            <th>Total akhir transaksi</th>
                            <th>Catatan</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($taxReconciliationReviews as $review)
                            <tr class="warning">
                                <td>{{ @format_datetime($review->import_created_at) }}</td>
                                <td>{{ $review->file_name }}</td>
                                <td>{{ $review->ref_no }}</td>
                                <td>#{{ $review->source_record_id }}</td>
                                <td>@if($review->journal_tax_amount === null) — @else @format_currency($review->journal_tax_amount) @endif</td>
                                <td>@if($review->calculated_tax_amount === null) — @else @format_currency($review->calculated_tax_amount) @endif</td>
                                <td>@if($review->journal_total_amount === null) — @else @format_currency($review->journal_total_amount) @endif</td>
                                <td>@if($review->recalculated_final_total === null) — @else @format_currency($review->recalculated_final_total) @endif</td>
                                <td>{{ $review->tax_reconciliation_note }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @if($taxReconciliationReviews->count() === 50)
                <p class="help-block">Menampilkan maksimal 50 rekonsiliasi terbaru yang perlu ditinjau.</p>
            @endif
        @endcomponent
    @endif
</section>

@endsection

@section('javascript')
<script type="text/javascript" src="{{ versioned_asset_url(Module::asset('accounting:js/journal_import.js'), $asset_v ?? null) }}"></script>
@endsection
