@extends('layouts.app')

@section('title', 'Manajemen Stok Solar')

@section('css')
<style>
    .solar-toolbar { display:flex; flex-wrap:wrap; gap:8px; justify-content:space-between; align-items:center; }
    .solar-actions { display:flex; flex-wrap:wrap; gap:6px; }
    .solar-card { border-radius:8px; color:#fff; padding:16px; min-height:105px; margin-bottom:15px; box-shadow:0 2px 8px rgba(0,0,0,.08); }
    .solar-card .value { font-size:25px; font-weight:700; line-height:1.25; }
    .solar-card .label { font-size:12px; text-transform:uppercase; opacity:.9; }
    .solar-card.actual { background:#286090; }
    .solar-card.meter { background:#00897b; }
    .solar-card.pool { background:#ef6c00; }
    .solar-card.mess { background:#6a1b9a; }
    .solar-table th { white-space:nowrap; background:#f5f7fa; }
    .solar-table td { vertical-align:middle !important; }
    .solar-empty { padding:35px !important; color:#777; text-align:center; }
    .solar-source { text-transform:capitalize; }
    .solar-help { margin-top:8px; color:#666; }
    .solar-filter { display:flex; flex-wrap:wrap; gap:8px; align-items:flex-end; }
    .solar-filter .form-group { margin-bottom:0; }
    @media (max-width:767px) {
        .solar-toolbar { align-items:stretch; }
        .solar-actions .btn { flex:1 1 auto; }
    }
</style>
@endsection

@section('content')
<section class="content-header">
    <h1>Manajemen Stok Solar <small>ledger aktual, meteran, pool, dan mess</small></h1>
</section>

<section class="content" id="solar-app"
    data-base-url="{{ url('/solar') }}"
    data-active-ledger="{{ request('tab', 'stock') }}">
    @php
        $status = session('status');
        $isSuccess = is_array($status) ? ($status['success'] ?? (($status['alert'] ?? '') === 'success')) : false;
    @endphp
    @if ($status)
        <div class="alert alert-{{ $isSuccess ? 'success' : 'danger' }} alert-dismissible">
            <button type="button" class="close" data-dismiss="alert">&times;</button>
            {{ is_array($status) ? ($status['msg'] ?? '') : $status }}
        </div>
    @endif
    @if ($errors->any())
        <div class="alert alert-danger">
            <strong>Data belum dapat disimpan.</strong>
            <ul class="mb-0">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="row">
        <div class="col-sm-6 col-lg-3">
            <div class="solar-card actual">
                <div class="label">Stock Actual Terkini</div>
                <div class="value">{{ number_format($balances['stock']['actual'] ?? 0, 2, ',', '.') }}</div>
            </div>
        </div>
        <div class="col-sm-6 col-lg-3">
            <div class="solar-card meter">
                <div class="label">Stock Meteran Terkini</div>
                <div class="value">{{ number_format($balances['stock']['meter'] ?? 0, 2, ',', '.') }}</div>
            </div>
        </div>
        <div class="col-sm-6 col-lg-3">
            <div class="solar-card pool">
                <div class="label">Stock Pool Terkini</div>
                <div class="value">{{ number_format($balances['pool']['quantity'] ?? 0, 2, ',', '.') }}</div>
            </div>
        </div>
        <div class="col-sm-6 col-lg-3">
            <div class="solar-card mess">
                <div class="label">Stock Mess Terkini</div>
                <div class="value">{{ number_format($balances['mess']['quantity'] ?? 0, 2, ',', '.') }}</div>
            </div>
        </div>
    </div>

    <div class="box box-primary">
        <div class="box-header with-border">
            <div class="solar-toolbar">
                <form method="get" class="solar-filter">
                    <input type="hidden" name="tab" id="solar-filter-tab" value="{{ request('tab', 'stock') }}">
                    <div class="form-group">
                        <label for="date1">Dari tanggal</label>
                        <input type="date" id="date1" name="date1" value="{{ $dateFrom }}" class="form-control">
                    </div>
                    <div class="form-group">
                        <label for="date2">Sampai tanggal</label>
                        <input type="date" id="date2" name="date2" value="{{ $dateTo }}" class="form-control">
                    </div>
                    <button type="submit" class="btn btn-primary"><i class="fa fa-filter"></i> Terapkan</button>
                </form>
                <div class="solar-actions">
                    <button type="button" class="btn btn-success solar-add" data-ledger="stock">
                        <i class="fa fa-plus"></i> Tambah Transaksi
                    </button>
                    <a href="{{ route('solar.template') }}" class="btn btn-info">
                        <i class="fa fa-download"></i> Template
                    </a>
                    <a href="{{ route('solar.export', ['date1' => $dateFrom, 'date2' => $dateTo]) }}" class="btn btn-warning">
                        <i class="fa fa-file-excel-o"></i> Export
                    </a>
                    <button type="button" class="btn btn-default" data-toggle="modal" data-target="#solar-import-modal">
                        <i class="fa fa-exchange"></i> Cross-check / Import
                    </button>
                </div>
            </div>
        </div>

        <div class="box-body">
            <ul class="nav nav-tabs" id="solar-tabs">
                <li class="active"><a href="#stock" data-toggle="tab" data-ledger="stock">Stock Utama</a></li>
                <li><a href="#pool" data-toggle="tab" data-ledger="pool">Pool</a></li>
                <li><a href="#mess" data-toggle="tab" data-ledger="mess">Mess</a></li>
            </ul>

            <div class="tab-content">
                <div id="stock" class="tab-pane active">
                    <div class="clearfix" style="margin:15px 0 10px">
                        <input type="search" class="form-control solar-search pull-right" data-table="stock" placeholder="Cari transaksi Stock..." style="max-width:300px">
                    </div>
                    <div class="table-responsive">
                        <table class="table table-bordered table-hover solar-table" data-ledger-table="stock">
                            <thead>
                                <tr>
                                    <th>Tanggal</th><th>IN</th><th>OUT ACT</th><th>STOCK ACT</th>
                                    <th>OUT Meteran</th><th>STOCK Meteran</th><th>Keterangan</th><th>Sumber</th><th>Aksi</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($rows['stock'] as $row)
                                    <tr>
                                        <td>{{ \Carbon\Carbon::parse($row->tanggal)->format('d/m/Y') }}</td>
                                        <td class="text-right">{{ number_format($row->stock_in, 2, ',', '.') }}</td>
                                        <td class="text-right">{{ number_format($row->out_actual, 2, ',', '.') }}</td>
                                        <td class="text-right"><strong>{{ number_format($row->stock_actual, 2, ',', '.') }}</strong></td>
                                        <td class="text-right">{{ number_format($row->out_meteran, 2, ',', '.') }}</td>
                                        <td class="text-right"><strong>{{ number_format($row->stock_meteran, 2, ',', '.') }}</strong></td>
                                        <td>{{ $row->keterangan ?: '—' }}</td>
                                        <td><span class="label label-default solar-source">{{ $row->source ?? 'legacy' }}</span></td>
                                        <td class="text-nowrap">
                                            <button class="btn btn-xs btn-primary solar-edit" data-ledger="stock"
                                                data-record="{{ base64_encode(json_encode($row, JSON_UNESCAPED_UNICODE)) }}">
                                                <i class="fa fa-pencil"></i>
                                            </button>
                                            <button class="btn btn-xs btn-danger solar-delete" data-ledger="stock" data-id="{{ $row->id }}">
                                                <i class="fa fa-trash"></i>
                                            </button>
                                        </td>
                                    </tr>
                                @empty
                                    <tr><td colspan="9" class="solar-empty">Belum ada transaksi Stock pada rentang tanggal ini.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>

                <div id="pool" class="tab-pane">
                    <div class="clearfix" style="margin:15px 0 10px">
                        <input type="search" class="form-control solar-search pull-right" data-table="pool" placeholder="Cari transaksi Pool..." style="max-width:300px">
                    </div>
                    <div class="table-responsive">
                        <table class="table table-bordered table-hover solar-table" data-ledger-table="pool">
                            <thead>
                                <tr>
                                    <th>Tanggal</th><th>IN</th><th>OUT</th><th>Current</th><th>Keterangan</th>
                                    <th>Unit</th><th>Operator</th><th>Customer</th><th>Sumber</th><th>Aksi</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($rows['pool'] as $row)
                                    <tr>
                                        <td>{{ \Carbon\Carbon::parse($row->tanggal)->format('d/m/Y') }}</td>
                                        <td class="text-right">{{ number_format($row->qty_in, 2, ',', '.') }}</td>
                                        <td class="text-right">{{ number_format($row->qty_out, 2, ',', '.') }}</td>
                                        <td class="text-right"><strong>{{ number_format($row->current_stock, 2, ',', '.') }}</strong></td>
                                        <td>{{ $row->keterangan ?: '—' }}</td>
                                        <td>{{ $row->unit ?: '—' }}</td>
                                        <td>{{ $row->operator ?: '—' }}</td>
                                        <td>{{ $row->customer_name ?: '—' }}</td>
                                        <td><span class="label label-default solar-source">{{ $row->source ?? 'legacy' }}</span></td>
                                        <td class="text-nowrap">
                                            <button class="btn btn-xs btn-primary solar-edit" data-ledger="pool"
                                                data-record="{{ base64_encode(json_encode($row, JSON_UNESCAPED_UNICODE)) }}"><i class="fa fa-pencil"></i></button>
                                            <button class="btn btn-xs btn-danger solar-delete" data-ledger="pool" data-id="{{ $row->id }}"><i class="fa fa-trash"></i></button>
                                        </td>
                                    </tr>
                                @empty
                                    <tr><td colspan="10" class="solar-empty">Belum ada transaksi Pool pada rentang tanggal ini.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>

                <div id="mess" class="tab-pane">
                    <div class="clearfix" style="margin:15px 0 10px">
                        <input type="search" class="form-control solar-search pull-right" data-table="mess" placeholder="Cari transaksi Mess..." style="max-width:300px">
                    </div>
                    <div class="table-responsive">
                        <table class="table table-bordered table-hover solar-table" data-ledger-table="mess">
                            <thead>
                                <tr>
                                    <th>Tanggal</th><th>IN</th><th>OUT</th><th>Current</th><th>Keterangan</th><th>Nama Kapal</th>
                                    <th>Unit</th><th>Operator</th><th>Customer</th><th>Lokasi</th><th>Sumber</th><th>Aksi</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($rows['mess'] as $row)
                                    <tr>
                                        <td>{{ \Carbon\Carbon::parse($row->tanggal)->format('d/m/Y') }}</td>
                                        <td class="text-right">{{ number_format($row->qty_in, 2, ',', '.') }}</td>
                                        <td class="text-right">{{ number_format($row->qty_out, 2, ',', '.') }}</td>
                                        <td class="text-right"><strong>{{ number_format($row->current_stock, 2, ',', '.') }}</strong></td>
                                        <td>{{ $row->keterangan ?: '—' }}</td>
                                        <td>{{ $row->nama_kapal ?: '—' }}</td>
                                        <td>{{ $row->unit ?: '—' }}</td>
                                        <td>{{ $row->operator ?: '—' }}</td>
                                        <td>{{ $row->customer_name ?: '—' }}</td>
                                        <td>{{ $row->lokasi ?: '—' }}</td>
                                        <td><span class="label label-default solar-source">{{ $row->source ?? 'legacy' }}</span></td>
                                        <td class="text-nowrap">
                                            <button class="btn btn-xs btn-primary solar-edit" data-ledger="mess"
                                                data-record="{{ base64_encode(json_encode($row, JSON_UNESCAPED_UNICODE)) }}"><i class="fa fa-pencil"></i></button>
                                            <button class="btn btn-xs btn-danger solar-delete" data-ledger="mess" data-id="{{ $row->id }}"><i class="fa fa-trash"></i></button>
                                        </td>
                                    </tr>
                                @empty
                                    <tr><td colspan="12" class="solar-empty">Belum ada transaksi Mess pada rentang tanggal ini.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="solar-record-modal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="post" id="solar-record-form">
                    @csrf
                    <input type="hidden" name="_method" id="solar-form-method" value="POST">
                    <div class="modal-header">
                        <button type="button" class="close" data-dismiss="modal">&times;</button>
                        <h4 class="modal-title" id="solar-record-title">Tambah Transaksi Solar</h4>
                    </div>
                    <div class="modal-body">
                        <div class="row">
                            <div class="col-sm-6 form-group">
                                <label for="solar-tanggal">Tanggal *</label>
                                <input type="date" class="form-control" id="solar-tanggal" name="tanggal" required>
                            </div>
                            <div class="col-sm-6 form-group ledger-stock">
                                <label for="solar-stock-in">IN</label>
                                <input type="number" min="0" step="0.01" class="form-control" id="solar-stock-in" name="stock_in">
                            </div>
                            <div class="col-sm-6 form-group ledger-stock">
                                <label for="solar-out-actual">OUT ACT</label>
                                <input type="number" min="0" step="0.01" class="form-control" id="solar-out-actual" name="out_actual">
                            </div>
                            <div class="col-sm-6 form-group ledger-stock">
                                <label for="solar-out-meteran">OUT Meteran</label>
                                <input type="number" min="0" step="0.01" class="form-control" id="solar-out-meteran" name="out_meteran">
                            </div>
                            <div class="col-sm-6 form-group ledger-quantity">
                                <label for="solar-qty-in">IN</label>
                                <input type="number" min="0" step="0.01" class="form-control" id="solar-qty-in" name="qty_in">
                            </div>
                            <div class="col-sm-6 form-group ledger-quantity">
                                <label for="solar-qty-out">OUT</label>
                                <input type="number" min="0" step="0.01" class="form-control" id="solar-qty-out" name="qty_out">
                            </div>
                            <div class="col-sm-6 form-group ledger-quantity">
                                <label for="solar-unit">Unit</label>
                                <input type="text" class="form-control" id="solar-unit" name="unit" placeholder="Liter / gln">
                            </div>
                            <div class="col-sm-6 form-group ledger-quantity">
                                <label for="solar-operator">Operator</label>
                                <input type="text" class="form-control" id="solar-operator" name="operator">
                            </div>
                            <div class="col-sm-12 form-group ledger-quantity">
                                <label for="solar-customer">Customer</label>
                                <select class="form-control select2" id="solar-customer" name="customer_id" style="width:100%">
                                    <option value="">— Opsional —</option>
                                    @foreach ($customers as $customer)
                                        <option value="{{ $customer->id }}">{{ $customer->display_name }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-sm-6 form-group ledger-mess">
                                <label for="solar-nama-kapal">Nama Kapal</label>
                                <input type="text" class="form-control" id="solar-nama-kapal" name="nama_kapal">
                            </div>
                            <div class="col-sm-6 form-group ledger-mess">
                                <label for="solar-lokasi">Lokasi</label>
                                <input type="text" class="form-control" id="solar-lokasi" name="lokasi">
                            </div>
                            <div class="col-sm-12 form-group">
                                <label for="solar-keterangan">Keterangan</label>
                                <textarea class="form-control" id="solar-keterangan" name="keterangan" rows="3"></textarea>
                            </div>
                        </div>
                        <p class="solar-help"><i class="fa fa-info-circle"></i> Saldo akan dihitung ulang oleh server setelah transaksi disimpan.</p>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-default" data-dismiss="modal">Batal</button>
                        <button type="submit" class="btn btn-primary"><i class="fa fa-save"></i> Simpan</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="modal fade" id="solar-import-modal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form action="{{ route('solar.reconcile') }}" method="post" enctype="multipart/form-data">
                    @csrf
                    <div class="modal-header">
                        <button type="button" class="close" data-dismiss="modal">&times;</button>
                        <h4 class="modal-title"><i class="fa fa-exchange"></i> Cross-check dan Import Excel</h4>
                    </div>
                    <div class="modal-body">
                        <div class="alert alert-info">
                            File tidak langsung mengubah data. Sistem akan menampilkan baris cocok, berbeda, dan baru sebelum Anda mengonfirmasi import.
                        </div>
                        <div class="form-group">
                            <label for="solar-import-file">Workbook Solar (.xlsx / .xls)</label>
                            <input type="file" id="solar-import-file" name="file" class="form-control" accept=".xlsx,.xls" required>
                            <p class="help-block">Gunakan satu file yang berisi sheet SOLAR, POOL, dan MESS dengan header pada baris 4.</p>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-default" data-dismiss="modal">Batal</button>
                        <button type="submit" class="btn btn-primary"><i class="fa fa-search"></i> Tampilkan Preview</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <form method="post" id="solar-delete-form" class="hide">
        @csrf
        @method('DELETE')
    </form>
</section>
@endsection

@section('javascript')
<script src="{{ asset('js/solar.js') }}"></script>
@endsection
