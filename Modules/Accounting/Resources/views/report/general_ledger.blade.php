@extends('layouts.app')

@section('title', 'Buku Besar')

@section('css')
    <style>
        .general-ledger-heading { margin: 12px 0 22px; text-align: center; }
        .general-ledger-heading h2 { margin: 0 0 6px; font-size: 20px; font-weight: 600; }
        .general-ledger-heading h3 { margin: 0 0 4px; font-size: 18px; font-weight: 600; }
        .general-ledger-heading p { margin: 0; }
        .general-ledger-wrap { overflow-x: auto; }
        .general-ledger-table { min-width: 1120px; font-size: 13px; }
        .general-ledger-table th, .general-ledger-table td { vertical-align: top !important; padding: 6px 8px !important; }
        .general-ledger-table thead th { background: #f5f5f5; white-space: nowrap; }
        .general-ledger-table .opening-balance-row { font-weight: 600; }
        .general-ledger-table .product-quantity { white-space: nowrap; }
        .general-ledger-table .ledger-account-source, .general-ledger-table .ledger-detail-label { color: #666; font-size: 12px; }
        .general-ledger-table .ledger-detail-label { font-weight: 600; }
        .general-ledger-mapping-list { max-height: 420px; overflow: auto; }
        .general-ledger-mapping-list .form-control { min-width: 170px; }
        .general-ledger-pagination { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 8px; }
        @media print {
            .content-header, .general-ledger-filters, .general-ledger-pagination, .accounting-navigation { display: none !important; }
            .general-ledger-table { min-width: 0; font-size: 10px; }
        }
    </style>
@endsection

@section('content')
    @include('accounting::layouts.nav')

    <section class="content-header">
        <h1>Buku Besar</h1>
    </section>

    <section class="content">
        @if(session('status'))
            <div class="alert alert-success">{{ session('status') }}</div>
        @endif

        @if($accounts->isEmpty())
            <div class="alert alert-warning">
                Belum ada akun Kas/Bank yang dipetakan. Laporan tetap kosong sampai akun diklasifikasikan secara eksplisit.
            </div>
        @endif

        @if($canManageAccounts)
        <div class="box box-default">
            <div class="box-header with-border">
                <h3 class="box-title">Pemetaan akun untuk Buku Kas/Bank</h3>
                <span class="pull-right">{{ $accounts->count() }} akun Kas/Bank aktif</span>
            </div>
            <div class="box-body">
                <p class="text-muted">Pilih Kas atau Bank hanya untuk akun yang memang menampung uang. Tandai akun lain sebagai bukan kas/bank; jenis akun CoA tidak dipakai untuk menebak pemetaan.</p>
                <details {{ $accounts->isEmpty() ? 'open' : '' }}>
                    <summary>Kelola pemetaan dan akun yang perlu ditinjau ({{ $mappingAccounts->count() }} akun CoA)</summary>
                    <form method="post" action="{{ route('accounting.generalLedger.accountMappings.save') }}" class="m-t-10">
                        @csrf
                        <input type="hidden" name="start_date" value="{{ $start_date }}">
                        <input type="hidden" name="end_date" value="{{ $end_date }}">
                        <div class="table-responsive general-ledger-mapping-list">
                            <table class="table table-condensed table-striped">
                                <thead>
                                    <tr>
                                        <th>Akun CoA</th>
                                        <th>Posting tersedia</th>
                                        <th>Klasifikasi Buku Kas/Bank</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($mappingAccounts as $mappingAccount)
                                        <tr>
                                            <td>
                                                @if($mappingAccount->gl_code)<strong>{{ $mappingAccount->gl_code }}</strong> — @endif
                                                {{ $mappingAccount->name }}
                                            </td>
                                            <td>{{ number_format((int) ($mappingAccount->posting_count ?? 0)) }}</td>
                                            <td>
                                                <select class="form-control input-sm" name="mappings[{{ $mappingAccount->id }}]">
                                                    <option value="" {{ empty($mappingAccount->classification) ? 'selected' : '' }}>Belum ditinjau</option>
                                                    <option value="cash" {{ $mappingAccount->classification === 'cash' ? 'selected' : '' }}>Kas</option>
                                                    <option value="bank" {{ $mappingAccount->classification === 'bank' ? 'selected' : '' }}>Bank</option>
                                                    <option value="excluded" {{ $mappingAccount->classification === 'excluded' ? 'selected' : '' }}>Bukan kas/bank</option>
                                                </select>
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                        <button type="submit" class="btn btn-primary">Simpan pemetaan</button>
                    </form>
                </details>
            </div>
        </div>
        @endif

        <div class="box box-warning general-ledger-filters">
            <div class="box-body">
                <form method="get" action="{{ route('accounting.generalLedger') }}">
                    <div class="row">
                        <div class="col-md-4">
                            <div class="form-group">
                                <label for="start_date">Dari tanggal</label>
                                <input class="form-control" type="date" id="start_date" name="start_date" value="{{ $start_date }}" required>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-group">
                                <label for="end_date">Sampai tanggal</label>
                                <input class="form-control" type="date" id="end_date" name="end_date" value="{{ $end_date }}" required>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-group">
                                <label for="account_id">Filter akun Kas/Bank</label>
                                <select class="form-control select2" id="account_id" name="account_id">
                                    <option value="">Semua Kas/Bank</option>
                                    @foreach($accounts as $account)
                                        <option value="{{ $account->id }}" {{ (string) $account_id === (string) $account->id ? 'selected' : '' }}>
                                            {{ $account->classification === 'bank' ? 'Bank' : 'Kas' }} — {{ $account->gl_code ? $account->gl_code.' — ' : '' }}{{ $account->name }}
                                        </option>
                                    @endforeach
                                </select>
                            </div>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-12">
                            <button type="submit" class="btn btn-primary">Tampilkan</button>
                            <a href="{{ route('accounting.generalLedger.export', ['start_date' => $start_date, 'end_date' => $end_date, 'account_id' => $account_id]) }}" class="btn btn-success">
                                <i class="fa fa-download"></i> Ekspor Excel
                            </a>
                            <a href="{{ route('accounting.generalLedger.audit', ['start_date' => $start_date, 'end_date' => $end_date]) }}" class="btn btn-default">
                                <i class="fa fa-search"></i> Audit rekonsiliasi
                            </a>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <div class="box box-warning">
            <div class="box-body">
                <div class="general-ledger-heading">
                    <h2>{{ $businessName }}</h2>
                    <h3>BUKU BESAR</h3>
                    <p>BUKU KAS/BANK GABUNGAN · PERIODE {{ \Carbon::parse($start_date)->format('d/m/Y') }} — {{ \Carbon::parse($end_date)->format('d/m/Y') }}</p>
                </div>

                <div class="general-ledger-wrap">
                    @include('accounting::report.partials.general_ledger_table')
                </div>

                @if($report->total() > 0)
                    <div class="general-ledger-pagination">
                        <span>Menampilkan {{ $report->firstItem() }}–{{ $report->lastItem() }} dari {{ $report->total() }} posting. Saldo menggabungkan akun Kas/Bank terpetakan.</span>
                        {{ $report->appends(request()->except('page'))->links() }}
                    </div>
                @endif
            </div>
        </div>
    </section>
@endsection
