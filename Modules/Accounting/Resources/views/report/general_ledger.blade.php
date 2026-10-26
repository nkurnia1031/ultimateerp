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
        .general-ledger-table .account-heading th { background: #e9eef4; font-size: 13px; }
        .general-ledger-table .account-code { color: #555; font-weight: normal; }
        .general-ledger-table .opening-balance-row { font-weight: 600; }
        .general-ledger-table .product-quantity { white-space: nowrap; }
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
                                <label for="account_id">Akun</label>
                                <select class="form-control select2" id="account_id" name="account_id">
                                    <option value="">Semua akun</option>
                                    @foreach($accounts as $account)
                                        <option value="{{ $account->id }}" {{ (string) $account_id === (string) $account->id ? 'selected' : '' }}>
                                            {{ $account->gl_code ? $account->gl_code.' — ' : '' }}{{ $account->name }}
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
                    <p>PERIODE {{ \Carbon::parse($start_date)->format('d/m/Y') }} — {{ \Carbon::parse($end_date)->format('d/m/Y') }}</p>
                </div>

                <div class="general-ledger-wrap">
                    @include('accounting::report.partials.general_ledger_table')
                </div>

                @if($report->total() > 0)
                    <div class="general-ledger-pagination">
                        <span>Menampilkan posting {{ $report->firstItem() }}–{{ $report->lastItem() }} dari {{ $report->total() }}. Produk ditampilkan pada baris masing-masing.</span>
                        {{ $report->appends(request()->except('page'))->links() }}
                    </div>
                @endif
            </div>
        </div>
    </section>
@endsection
