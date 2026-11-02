@extends('layouts.app')

@section('title', 'Temuan Rekonsiliasi')

@section('content')
@include('accounting::layouts.nav')

<section class="content-header">
    <h1>Temuan rekonsiliasi <small>Audit legacy per business</small></h1>
    <p class="text-muted">Selisih dari audit terakhir. Jurnal legacy dan transaksi operasional tidak diubah dari halaman ini.</p>
</section>

<section id="reconciliation_findings_page" class="content"
    data-data-url="{{ route('accounting.reconciliation.findings.data') }}">
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
                    <div class="col-md-3 col-sm-6"><div class="form-group"><label for="finding_filter_type">Jenis</label><select class="form-control" id="finding_filter_type" name="type"><option value="">Semua jenis</option>@foreach(['transaction', 'control', 'account_balance', 'unbalanced_journal', 'cash_bank'] as $type)<option value="{{ $type }}" @if($filters['type'] === $type) selected @endif>{{ $findings->typeLabel($type) }}</option>@endforeach</select></div></div>
                    <div class="col-md-2 col-sm-6"><div class="form-group"><label for="finding_filter_status">Status</label><select class="form-control" id="finding_filter_status" name="status"><option value="open" @if($filters['status'] === 'open') selected @endif>Terbuka</option><option value="accepted" @if($filters['status'] === 'accepted') selected @endif>Diterima</option><option value="resolved" @if($filters['status'] === 'resolved') selected @endif>Selesai</option><option value="all" @if($filters['status'] === 'all') selected @endif>Semua status</option></select></div></div>
                    <div class="col-md-4 col-sm-10"><div class="form-group"><label for="finding_filter_search">Cari nomor, akun, tanggal, atau keterangan</label><input class="form-control" id="finding_filter_search" name="search" type="search" maxlength="120" value="{{ $filters['search'] }}" placeholder="Contoh: INV-2023 atau VAT In"></div></div>
                    <div class="col-md-1 col-sm-2"><div class="form-group"><label>&nbsp;</label><button class="btn btn-primary btn-block" type="submit" aria-label="Terapkan filter"><i class="fa fa-search"></i></button></div></div>
                </div>
            </form>
            <div id="finding_filter_status_message" class="text-muted" role="status" aria-live="polite">{{ number_format($summary['filtered_count']) }} temuan ditampilkan.</div>
        </div>
        <div id="finding_results" class="box-body table-responsive" aria-busy="false">
            <table class="table table-bordered table-striped">
                <thead><tr><th>Tanggal / Tahun</th><th>Temuan</th><th>Sumber</th><th>Akun</th><th class="text-right">Nilai pembanding</th><th class="text-right">Nilai aktual</th><th class="text-right">Selisih</th><th>Status / Audit</th></tr></thead>
                <tbody id="finding_rows">@include('accounting::reconciliation.partials.rows')</tbody>
            </table>
            <div id="finding_pagination">@include('accounting::reconciliation.partials.pagination')</div>
        </div>
    </div>
</section>
@stop

@section('javascript')
<script type="text/javascript" src="{{ versioned_asset_url(Module::asset('accounting:js/reconciliation_findings.js'), $asset_v ?? null) }}"></script>
@stop
