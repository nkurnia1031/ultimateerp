@extends('layouts.app')

@section('title', 'Audit Buku Besar')

@section('css')
    <style>
        .gl-audit-heading { margin: 10px 0 18px; text-align: center; }
        .gl-audit-heading h2 { margin: 0 0 5px; font-size: 20px; font-weight: 600; }
        .gl-audit-summary { display: grid; grid-template-columns: repeat(4, minmax(160px, 1fr)); gap: 12px; margin-bottom: 15px; }
        .gl-audit-card { background: #fff; border: 1px solid #e5e7eb; border-top: 3px solid #294965; border-radius: 3px; padding: 12px 14px; min-height: 88px; }
        .gl-audit-card small { display: block; color: #64748b; margin-bottom: 6px; }
        .gl-audit-card strong { display: block; font-size: 17px; overflow-wrap: anywhere; }
        .gl-audit-secondary { color: #64748b; font-size: 12px; margin-top: 4px; }
        .gl-audit-table-wrap { overflow-x: auto; }
        .gl-audit-table { min-width: 1320px; font-size: 12px; }
        .gl-audit-table th, .gl-audit-table td { vertical-align: top !important; padding: 7px 8px !important; }
        .gl-audit-table th { white-space: nowrap; background: #f5f6f8; }
        .gl-audit-table .amount { text-align: right; white-space: nowrap; }
        .gl-audit-table .reason { min-width: 220px; max-width: 300px; }
        .gl-audit-table .category { min-width: 160px; }
        .gl-audit-toolbar { display: flex; align-items: center; justify-content: space-between; gap: 10px; flex-wrap: wrap; }
        @media (max-width: 767px) { .gl-audit-summary { grid-template-columns: repeat(2, minmax(140px, 1fr)); } }
        @media (max-width: 420px) { .gl-audit-summary { grid-template-columns: 1fr; } }
    </style>
@endsection

@section('content')
    @include('accounting::layouts.nav')

    <section class="content-header">
        <h1>Audit Buku Besar Kas/Bank</h1>
    </section>

    <section class="content">
        @php
            $money = function ($value) use ($currencyPrecision, $decimalSeparator, $thousandSeparator) {
                return number_format((float) $value, $currencyPrecision, $decimalSeparator, $thousandSeparator);
            };
            $statusLabels = [
                'matched' => 'Cocok', 'missing_posting' => 'Posting hilang', 'missing_source' => 'Sumber hilang',
                'duplicate_posting' => 'Posting ganda', 'ambiguous_match' => 'Kecocokan ambigu',
                'amount_difference' => 'Selisih nominal', 'vat_difference' => 'Selisih VAT', 'vat_ambiguous' => 'VAT ambigu',
                'unmapped_account' => 'Akun belum dipetakan', 'invalid_amount' => 'Nominal tidak valid',
                'invalid_cash_bank_account' => 'CoA bukan Asset', 'classification_missing' => 'Label belum jelas',
                'ambiguous_account_mapping' => 'Mapping akun ambigu', 'duplicate_journal_import' => 'Import jurnal duplikat',
                'conflicting_journal_import' => 'Isi import jurnal konflik',
                'excluded_draft' => 'Draft, dikecualikan', 'excluded_cancelled' => 'Batal, dikecualikan',
                'excluded_soft_deleted' => 'Dihapus, dikecualikan', 'excluded_non_cash' => 'Bukan Kas/Bank',
                'internal_transfer' => 'Transfer internal',
            ];
        @endphp

        <div class="gl-audit-heading">
            <h2>{{ $businessName }}</h2>
            <p>Periode {{ \Carbon::parse($start_date)->format('d/m/Y') }} — {{ \Carbon::parse($end_date)->format('d/m/Y') }}</p>
            <p class="text-muted">Perbandingan read-only. Hanya akun dengan mapping Kas/Bank eksplisit yang masuk perhitungan saldo.</p>
        </div>

        <div class="gl-audit-summary">
            <div class="gl-audit-card">
                <small>Saldo pembuka Accounting</small>
                <strong>{{ $money($summary['opening_balance']) }}</strong>
                <div class="gl-audit-secondary">Operasional: {{ $money($summary['source_opening_balance']) }}</div>
            </div>
            <div class="gl-audit-card">
                <small>Arus operasional masuk / keluar</small>
                <strong>{{ $money($summary['source_inflow']) }} / {{ $money($summary['source_outflow']) }}</strong>
                <div class="gl-audit-secondary">Accounting: {{ $money($summary['gl_inflow']) }} / {{ $money($summary['gl_outflow']) }}</div>
            </div>
            <div class="gl-audit-card">
                <small>Saldo penutup Accounting</small>
                <strong>{{ $money($summary['closing_balance']) }}</strong>
                <div class="gl-audit-secondary">Selisih saldo akhir: {{ $money($summary['closing_balance_difference']) }}</div>
            </div>
            <div class="gl-audit-card">
                <small>Temuan audit</small>
                <strong>{{ number_format($summary['exception_count']) }}</strong>
                <div class="gl-audit-secondary">{{ number_format($summary['source_count']) }} movement sumber · {{ number_format($summary['posting_group_count']) }} grup posting</div>
            </div>
        </div>

        <div class="alert {{ abs($summary['opening_balance_difference']) > 0.01 || abs($summary['closing_balance_difference']) > 0.01 ? 'alert-warning' : 'alert-info' }}">
            Selisih saldo pembuka sumber vs Accounting: <strong>{{ $money($summary['opening_balance_difference']) }}</strong>.
            VAT In sumber/jurnal: <strong>{{ $money($summary['source_vat_in']) }} / {{ $money($summary['journal_vat_in']) }}</strong>.
            VAT Out sumber/jurnal: <strong>{{ $money($summary['source_vat_out']) }} / {{ $money($summary['journal_vat_out']) }}</strong>.
            Total debit/kredit posting Kas/Bank: <strong>{{ $money($summary['debit_total']) }} / {{ $money($summary['credit_total']) }}</strong>.
        </div>

        <div class="box box-warning">
            <div class="box-body">
                <form method="get" action="{{ route('accounting.generalLedger.audit') }}">
                    <div class="row">
                    <div class="col-md-3 col-sm-6">
                        <label for="audit-start-date">Dari tanggal</label>
                        <input id="audit-start-date" class="form-control" type="date" name="start_date" value="{{ $start_date }}">
                    </div>
                    <div class="col-md-3 col-sm-6">
                        <label for="audit-end-date">Sampai tanggal</label>
                        <input id="audit-end-date" class="form-control" type="date" name="end_date" value="{{ $end_date }}">
                    </div>
                    <div class="col-md-3 col-sm-6">
                        <label for="audit-account">Akun pembayaran</label>
                        <select id="audit-account" class="form-control" name="account_id">
                            <option value="">Semua akun</option>
                            @foreach($accounts as $account)
                                <option value="{{ $account->id }}" {{ (string) $account_id === (string) $account->id ? 'selected' : '' }}>{{ $account->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-3 col-sm-6">
                        <label for="audit-source-type">Tipe sumber</label>
                        <select id="audit-source-type" class="form-control" name="source_type">
                            <option value="">Semua tipe</option>
                            @foreach($source_types as $sourceType)
                                <option value="{{ $sourceType }}" {{ ($source_type ?? '') === $sourceType ? 'selected' : '' }}>{{ $sourceType }}</option>
                            @endforeach
                        </select>
                    </div>
                    </div>
                    <div class="row m-t-10">
                    <div class="col-md-4 col-sm-6">
                        <label for="audit-status">Status</label>
                        <select id="audit-status" class="form-control" name="status">
                            <option value="">Semua status</option>
                            <option value="matched" {{ ($status ?? '') === 'matched' ? 'selected' : '' }}>Cocok</option>
                            <option value="exception" {{ ($status ?? '') === 'exception' ? 'selected' : '' }}>Perlu ditinjau</option>
                            <option value="excluded" {{ ($status ?? '') === 'excluded' ? 'selected' : '' }}>Dikecualikan</option>
                            <option value="internal_transfer" {{ ($status ?? '') === 'internal_transfer' ? 'selected' : '' }}>Transfer internal</option>
                        </select>
                    </div>
                    <div class="col-md-4 col-sm-6">
                        <label for="audit-reason">Alasan / temuan</label>
                        <select id="audit-reason" class="form-control" name="reason">
                            <option value="">Semua alasan</option>
                            @foreach($statuses as $auditStatus)
                                @if($auditStatus !== 'matched' && strpos($auditStatus, 'excluded_') !== 0 && $auditStatus !== 'internal_transfer')
                                    <option value="{{ $auditStatus }}" {{ ($reason ?? '') === $auditStatus ? 'selected' : '' }}>{{ $statusLabels[$auditStatus] ?? $auditStatus }}</option>
                                @endif
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-4 col-sm-6">
                        <label for="audit-search">Cari</label>
                        <input id="audit-search" class="form-control" type="search" name="search" value="{{ $search ?? '' }}" placeholder="Referensi, kategori, catatan">
                    </div>
                    </div>
                    <div class="row m-t-10">
                        <div class="col-md-12 gl-audit-toolbar">
                            <button type="submit" class="btn btn-primary"><i class="fa fa-filter"></i> Terapkan filter</button>
                            <a class="btn btn-success" href="{{ route('accounting.generalLedger.audit.export', request()->query()) }}"><i class="fa fa-download"></i> Ekspor CSV</a>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <div class="box box-warning">
            <div class="box-header with-border gl-audit-toolbar">
                <h3 class="box-title">Perbandingan transaksi dan posting</h3>
                <span class="text-muted">{{ number_format($rows->total()) }} baris setelah filter</span>
            </div>
            <div class="box-body gl-audit-table-wrap">
                <table class="table table-bordered table-striped gl-audit-table">
                    <thead>
                        <tr>
                            <th>Tanggal</th><th>Tipe / nomor</th><th>Keterangan sumber</th><th>Kategori / detail jurnal</th>
                            <th>Supplier / Customer</th><th>Akun Kas/Bank</th><th>Operasional</th><th>Accounting</th>
                            <th>Selisih</th><th>VAT sumber / jurnal</th><th>Status / alasan</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($rows as $row)
                            <tr>
                                <td>{{ \Carbon::parse($row['date'])->format('d/m/Y') }}</td>
                                <td><strong>{{ $row['source_type'] }}</strong><br>{{ $row['source_number'] }}</td>
                                <td>{{ $row['description'] }}</td>
                                <td class="category">{{ $row['category'] }}<br><small>{{ $row['detail_category'] }} {{ $row['journal_detail'] }}</small></td>
                                <td>{{ $row['contact'] }}</td>
                                <td>{{ $row['payment_account'] }}</td>
                                <td class="amount">{{ $row['operational_amount'] === null ? '—' : $money($row['operational_amount']) }}</td>
                                <td class="amount">{{ $row['accounting_amount'] === null ? '—' : $money($row['accounting_amount']) }}</td>
                                <td class="amount">{{ $row['difference'] === null ? '—' : $money($row['difference']) }}</td>
                                <td class="amount">{{ $row['source_vat'] === null ? '—' : $money($row['source_vat']) }} / {{ $row['journal_vat'] === null ? '—' : $money($row['journal_vat']) }}</td>
                                <td class="reason"><span class="label {{ $row['status'] === 'matched' ? 'label-success' : (strpos($row['status'], 'excluded_') === 0 || $row['status'] === 'internal_transfer' ? 'label-default' : 'label-warning') }}">{{ $statusLabels[$row['status']] ?? $row['status'] }}</span><br><small>{{ $row['reason'] }}</small></td>
                            </tr>
                        @empty
                            <tr><td colspan="11" class="text-center text-muted">Tidak ada baris audit pada filter ini.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @if($rows->total() > 0)
                <div class="box-footer">{{ $rows->appends(request()->except('page'))->links() }}</div>
            @endif
        </div>
    </section>
@endsection
