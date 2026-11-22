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
    .solar-daily-report { display:flex; flex-wrap:wrap; align-items:flex-end; gap:10px; margin-bottom:14px; }
    .solar-matrix th, .solar-matrix td { white-space:nowrap; text-align:right; }
    .solar-matrix th:first-child, .solar-matrix td:first-child { text-align:left; position:sticky; left:0; background:#fff; z-index:1; }
    .solar-matrix thead th { position:sticky; top:0; z-index:2; background:#f5f7fa; }
    .solar-usage-scroll { overflow:auto; max-height:65vh; }
    .solar-process-log-wrap { margin-top:12px; border-top:1px solid #e4e8eb; padding-top:8px; }
    .solar-process-log { max-height:180px; overflow:auto; padding-left:22px; margin:6px 0 0; }
    .solar-process-log li { padding:3px 0; }
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

    <form method="get" action="{{ route('solar.index') }}" class="solar-daily-report">
        <div class="form-group">
            <label for="solar-report-date">Tanggal laporan harian</label>
            <input type="date" id="solar-report-date" name="report_date" class="form-control" value="{{ $reportDate }}">
        </div>
        <button type="submit" class="btn btn-primary"><i class="fa fa-calendar"></i> Tampilkan saldo tanggal</button>
        <a class="btn btn-default" target="_blank" href="{{ route('solar.reports.daily', ['date' => $reportDate]) }}"><i class="fa fa-print"></i> Cetak laporan harian</a>
        <input type="hidden" name="date1" value="{{ $dateFrom }}">
        <input type="hidden" name="date2" value="{{ $dateTo }}">
        <input type="hidden" name="usage_month" value="{{ $usageMonth }}">
        <input type="hidden" name="tab" value="{{ request('tab', 'stock') }}">
    </form>

    <div class="row">
        <div class="col-sm-6 col-lg-3">
            <div class="solar-card actual">
                <div class="label">Stock Actual di Tangki · L</div>
                <div class="value">{{ number_format($balances['stock']['actual'] ?? 0, 2, ',', '.') }} <small>L</small></div>
            </div>
        </div>
        <div class="col-sm-6 col-lg-3">
            <div class="solar-card meter">
                <div class="label">Stock Berdasarkan Meteran · L</div>
                <div class="value">{{ number_format($balances['stock']['meter'] ?? 0, 2, ',', '.') }} <small>L</small></div>
            </div>
        </div>
        <div class="col-sm-6 col-lg-3">
            <div class="solar-card pool">
                <div class="label">Standby di Pool · Gln</div>
                <div class="value">{{ number_format($balances['pool']['quantity'] ?? 0, 2, ',', '.') }} <small>Gln</small></div>
            </div>
        </div>
        <div class="col-sm-6 col-lg-3">
            <div class="solar-card mess">
                <div class="label">Standby di Mess Jatim · Gln</div>
                <div class="value">{{ number_format($balances['mess']['quantity'] ?? 0, 2, ',', '.') }} <small>Gln</small></div>
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
                        <i class="fa fa-download"></i> Template Transaksi
                    </a>
                    <a href="{{ route('solar.export', ['date1' => $dateFrom, 'date2' => $dateTo]) }}" class="btn btn-warning">
                        <i class="fa fa-file-excel-o"></i> Export
                    </a>
                    <button type="button" class="btn btn-default" data-toggle="modal" data-target="#solar-import-modal">
                        <i class="fa fa-exchange"></i> Cross-check / Import
                    </button>
                    <a href="{{ route('solar.imports') }}" class="btn btn-default">
                        <i class="fa fa-history"></i> Riwayat Import
                    </a>
                </div>
            </div>
        </div>

        <div class="box-body">
            <ul class="nav nav-tabs" id="solar-tabs">
                <li class="active"><a href="#stock" data-toggle="tab" data-ledger="stock">Stock Utama</a></li>
                <li><a href="#pool" data-toggle="tab" data-ledger="pool">Pool</a></li>
                <li><a href="#mess" data-toggle="tab" data-ledger="mess">Mess</a></li>
                <li><a href="#usage" data-toggle="tab" data-ledger="usage">Pemakaian Alat</a></li>
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
                                    <th>OUT Meteran</th><th>STOCK Meteran</th><th>Unit Asset</th><th>Supir</th><th>Keterangan</th><th>Sumber</th><th>Aksi</th>
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
                                        <td>{{ $row->linked_asset_name ?: ($row->unit ?: '—') }}</td>
                                        <td>{{ $row->linked_driver_name ?: ($row->operator ?: '—') }}</td>
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
                                    <tr><td colspan="11" class="solar-empty">Belum ada transaksi Stock pada rentang tanggal ini.</td></tr>
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
                                    <th>Unit Asset</th><th>Supir</th><th>Customer</th><th>Sumber</th><th>Aksi</th>
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
                                        <td>{{ $row->linked_asset_name ?: ($row->unit ?: '—') }}</td>
                                        <td>{{ $row->linked_driver_name ?: ($row->operator ?: '—') }}</td>
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
                                    <th>Unit Asset</th><th>Supir</th><th>Customer</th><th>Lokasi</th><th>Sumber</th><th>Aksi</th>
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
                                        <td>{{ $row->linked_asset_name ?: ($row->unit ?: '—') }}</td>
                                        <td>{{ $row->linked_driver_name ?: ($row->operator ?: '—') }}</td>
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

                <div id="usage" class="tab-pane">
                    <div class="solar-toolbar" style="margin:15px 0">
                        <form method="get" action="{{ route('solar.index') }}" class="solar-filter">
                            <input type="hidden" name="tab" value="usage">
                            <input type="hidden" name="report_date" value="{{ $reportDate }}">
                            <input type="hidden" name="date1" value="{{ $dateFrom }}">
                            <input type="hidden" name="date2" value="{{ $dateTo }}">
                            <div class="form-group">
                                <label for="solar-usage-month">Bulan pemakaian</label>
                                <input type="month" id="solar-usage-month" name="usage_month" value="{{ $usageMonth }}" class="form-control">
                            </div>
                            <button type="submit" class="btn btn-primary"><i class="fa fa-filter"></i> Tampilkan</button>
                        </form>
                        <div class="solar-actions">
                            <a class="btn btn-info" href="{{ route('solar.template.usage', ['month' => $usageMonth]) }}"><i class="fa fa-download"></i> Template bulan ini</a>
                            <a class="btn btn-warning" href="{{ route('solar.export.usage', ['month' => $usageMonth]) }}"><i class="fa fa-file-excel-o"></i> Export matriks</a>
                            <button type="button" class="btn btn-success solar-add" data-ledger="usage"><i class="fa fa-plus"></i> Tambah pemakaian</button>
                        </div>
                    </div>
                    <div class="alert alert-info">
                        Nilai harian dicatat dalam Gln. Total liter mengikuti workbook, yaitu jumlah Gln × 30. Klik Tambah Pemakaian untuk mencatat satu alat pada satu tanggal.
                    </div>
                    <div class="table-responsive solar-usage-scroll">
                        <table class="table table-bordered table-condensed solar-table solar-matrix" data-ledger-table="usage-matrix">
                            <thead><tr><th>Alat Berat</th>
                                @for ($day = 1; $day <= $usageDays; $day++) <th>{{ $day }}</th> @endfor
                                <th>Total Gln</th><th>Total L</th>
                            </tr></thead>
                            <tbody>
                                @forelse ($usageMatrix as $equipment)
                                    <tr>
                                        <th>{{ $equipment['equipment_name'] }}</th>
                                        @for ($day = 1; $day <= $usageDays; $day++)
                                            <td>{{ isset($equipment['days'][$day]) ? number_format($equipment['days'][$day], 2, ',', '.') : '—' }}</td>
                                        @endfor
                                        <td><strong>{{ number_format($equipment['total_gln'], 2, ',', '.') }}</strong></td>
                                        <td><strong>{{ number_format($equipment['total_gln'] * 30, 2, ',', '.') }}</strong></td>
                                    </tr>
                                @empty
                                    <tr><td colspan="{{ $usageDays + 3 }}" class="solar-empty">Belum ada pemakaian alat pada {{ \Carbon\Carbon::createFromFormat('!Y-m', $usageMonth)->locale('id')->translatedFormat('F Y') }}.</td></tr>
                                @endforelse
                            </tbody>
                            @if (count($usageMatrix))
                                <tfoot><tr><th>Total per hari</th>
                                    @for ($day = 1; $day <= $usageDays; $day++)
                                        @php $dailyTotal = $usageRows->filter(fn ($r) => (int) \Carbon\Carbon::parse($r->usage_date)->format('j') === $day)->sum('quantity_gln'); @endphp
                                        <th>{{ number_format((float) $dailyTotal, 2, ',', '.') }}</th>
                                    @endfor
                                    @php $monthTotal = collect($usageMatrix)->sum('total_gln'); @endphp
                                    <th>{{ number_format($monthTotal, 2, ',', '.') }}</th><th>{{ number_format($monthTotal * 30, 2, ',', '.') }}</th>
                                </tr></tfoot>
                            @endif
                        </table>
                    </div>
                    <h4>Rincian baris pemakaian</h4>
                    <div class="table-responsive">
                        <table class="table table-bordered table-hover solar-table" data-ledger-table="usage">
                            <thead><tr><th>Tanggal</th><th>Alat</th><th>Asset</th><th>Gln</th><th>Liter</th><th>Sumber</th><th>Aksi</th></tr></thead>
                            <tbody>
                                @forelse ($usageRows as $row)
                                    <tr>
                                        <td>{{ \Carbon\Carbon::parse($row->usage_date)->format('d/m/Y') }}</td>
                                        <td>{{ $row->equipment_name }}</td>
                                        <td>{{ $row->asset_current_name ?: 'Label sumber' }}</td>
                                        <td class="text-right">{{ number_format((float) $row->quantity_gln, 2, ',', '.') }}</td>
                                        <td class="text-right">{{ number_format((float) $row->quantity_gln * 30, 2, ',', '.') }}</td>
                                        <td>{{ $row->source ?: 'manual' }}</td>
                                        <td class="text-nowrap">
                                            <button class="btn btn-xs btn-primary solar-edit" data-ledger="usage" data-record="{{ base64_encode(json_encode($row, JSON_UNESCAPED_UNICODE)) }}"><i class="fa fa-pencil"></i></button>
                                            <button class="btn btn-xs btn-danger solar-delete" data-ledger="usage" data-id="{{ $row->id }}"><i class="fa fa-trash"></i></button>
                                        </td>
                                    </tr>
                                @empty
                                    <tr><td colspan="7" class="solar-empty">Belum ada rincian pemakaian pada bulan ini.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="box box-default">
        <div class="box-header with-border">
            <h3 class="box-title">Import Solar Terbaru</h3>
            <a href="{{ route('solar.imports') }}" class="btn btn-xs btn-default pull-right">Lihat semua</a>
        </div>
        <div class="box-body table-responsive no-padding">
            <table class="table table-striped">
                <thead><tr><th>File</th><th>Waktu</th><th>Status</th><th>Jumlah</th><th>Aksi</th></tr></thead>
                <tbody>
                    @forelse ($importBatches as $batch)
                        @php
                            $counts = json_decode((string) $batch->counts, true) ?: [];
                            $ledgerCounts = ['stock' => 0, 'pool' => 0, 'mess' => 0, 'usage' => 0];
                            if (! empty($counts['sheets']) && is_array($counts['sheets'])) {
                                foreach ($counts['sheets'] as $sheet) {
                                    $ledger = strtolower((string) ($sheet['ledger'] ?? ''));
                                    if (array_key_exists($ledger, $ledgerCounts)) $ledgerCounts[$ledger] += (int) ($sheet['total'] ?? 0);
                                }
                            } else {
                                foreach ($ledgerCounts as $ledger => $value) $ledgerCounts[$ledger] = (int) ($counts[$ledger] ?? 0);
                            }
                            $processState = $batch->process_state ?: 'completed';
                            $isPreviewBatch = $batch->status === 'preview' && ($batch->operation ?: 'preview') === 'preview';
                            $processColor = $processState === 'failed' ? 'danger' : ($processState === 'completed' ? 'success' : ($processState === 'cancelled' ? 'default' : 'info'));
                        @endphp
                        <tr>
                            <td>
                                @if ($isPreviewBatch)
                                    <a href="{{ route('solar.imports.reconcile', $batch->id) }}">{{ $batch->file_name ?: 'Workbook Solar' }}</a>
                                @else
                                    <a href="{{ route('solar.imports.detail', $batch->id) }}">{{ $batch->file_name ?: 'Workbook Solar' }}</a>
                                @endif
                            </td>
                            <td>{{ $batch->created_at ? \Carbon\Carbon::parse($batch->created_at)->format('d/m/Y H:i') : '—' }}</td>
                            <td>
                                <span class="label label-{{ $batch->status === 'imported' ? 'success' : ($batch->status === 'rolled_back' ? 'default' : 'warning') }}">{{ ucfirst($batch->status) }}</span>
                                <span class="label label-{{ $processColor }}">{{ ucfirst($processState) }}</span>
                                @if (in_array($processState, ['queued', 'processing'], true))
                                    <small class="text-muted">{{ (int) ($batch->progress_percent ?? 0) }}% · <span data-import-history-message-source>{{ $batch->progress_message }}</span></small>
                                @elseif ($processState === 'failed')
                                    <small class="text-danger">Gagal diproses. Buka batch untuk mencoba ulang.</small>
                                @endif
                            </td>
                            <td>{{ array_sum($ledgerCounts) }} baris</td>
                            <td>
                                @if ($batch->status === 'imported')
                                    <form method="post" action="{{ route('solar.imports.rollback', $batch->id) }}" onsubmit="return confirm('Rollback batch import ini?')" style="display:inline">
                                        @csrf
                                        <button class="btn btn-xs btn-danger"><i class="fa fa-undo"></i> Rollback</button>
                                    </form>
                                @else
                                    <span class="text-muted">—</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="text-center text-muted">Belum ada riwayat import.</td></tr>
                    @endforelse
                </tbody>
            </table>
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
                            <div class="col-sm-6 form-group ledger-not-usage">
                                <label for="solar-unit">Nama Unit Asset / nomor polisi</label>
                                <input type="text" class="form-control" id="solar-unit" name="unit" placeholder="Nama Asset atau nomor polisi dari sumber">
                            </div>
                            <div class="col-sm-6 form-group ledger-not-usage">
                                <label for="solar-operator">Nama Supir / Operator</label>
                                <input type="text" class="form-control" id="solar-operator" name="operator">
                            </div>
                            <div class="col-sm-6 form-group ledger-not-usage">
                                <label for="solar-record-asset-id">Asset terdaftar (opsional)</label>
                                <select class="form-control select2" id="solar-record-asset-id" name="asset_id" style="width:100%">
                                    <option value="">Tanpa relasi Asset</option>
                                    @foreach ($assets as $asset)
                                        <option value="{{ $asset->id }}" data-asset-name="{{ $asset->name }}">{{ $asset->asset_code }} Â· {{ $asset->name }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-sm-6 form-group ledger-not-usage">
                                <label for="solar-record-driver-id">Cocokkan Supir</label>
                                <select class="form-control select2" id="solar-record-driver-id" name="driver_id" style="width:100%">
                                    <option value="">Tanpa relasi pengguna</option>
                                    @foreach ($drivers as $driver)
                                        <option value="{{ $driver->id }}" data-driver-name="{{ $driver->full_name }}">{{ $driver->full_name }}</option>
                                    @endforeach
                                </select>
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
                            <div class="col-sm-6 form-group ledger-usage">
                                <label for="solar-equipment-name">Nama alat</label>
                                <input type="text" class="form-control" id="solar-equipment-name" name="equipment_name" maxlength="255">
                            </div>
                            <div class="col-sm-6 form-group ledger-usage">
                                <label for="solar-asset-id">Asset (opsional)</label>
                                <select class="form-control select2" id="solar-asset-id" name="asset_id" style="width:100%">
                                    <option value="">Simpan sebagai label sumber</option>
                                    @foreach ($assets as $asset)
                                        <option value="{{ $asset->id }}" data-asset-name="{{ $asset->name }}">{{ $asset->asset_code }} · {{ $asset->name }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-sm-6 form-group ledger-usage">
                                <label for="solar-quantity-gln">Pemakaian (Gln)</label>
                                <input type="number" min="0.01" step="0.01" class="form-control" id="solar-quantity-gln" name="quantity_gln" required>
                                <p class="help-block">Setara <span id="solar-quantity-liter">0</span> L (Gln × 30).</p>
                            </div>
                            <div class="col-sm-12 form-group ledger-not-usage">
                                <label for="solar-keterangan">Keterangan</label>
                                <textarea class="form-control" id="solar-keterangan" name="keterangan" rows="3"></textarea>
                            </div>
                        </div>
                        <p class="solar-help solar-not-usage"><i class="fa fa-info-circle"></i> Saldo akan dihitung ulang oleh server setelah transaksi disimpan.</p>
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
                <form action="{{ route('solar.reconcile') }}" method="post" enctype="multipart/form-data" id="solar-import-form"
                    data-ajax-url="{{ route('solar.reconcile.upload') }}"
                    data-status-url-template="{{ route('solar.imports.status', ['batchId' => '__BATCH__']) }}"
                    data-cancel-url-template="{{ route('solar.imports.cancel', ['batchId' => '__BATCH__']) }}"
                    data-retry-url-template="{{ route('solar.imports.retry', ['batchId' => '__BATCH__']) }}"
                    data-reconcile-url-template="{{ route('solar.imports.reconcile', ['batchId' => '__BATCH__']) }}"
                    data-events-url-template="{{ route('solar.imports.events', ['batchId' => '__BATCH__']) }}">
                    @csrf
                    <div class="modal-header">
                        <button type="button" class="close" data-dismiss="modal">&times;</button>
                        <h4 class="modal-title"><i class="fa fa-exchange"></i> Cross-check dan Import Excel</h4>
                    </div>
                    <div class="modal-body">
                        <div class="alert alert-info">
                            File tidak langsung mengubah data. Sistem akan menampilkan preview setiap sheet, baris yang bisa diimpor, konflik, dan data yang perlu diperiksa sebelum konfirmasi.
                        </div>
                        <div class="form-group">
                            <label for="solar-import-file">Workbook Solar (.xlsx / .xls)</label>
                            <input type="file" id="solar-import-file" name="file" class="form-control" accept=".xlsx,.xls" required>
                            <p class="help-block">Unggah workbook transaksi (sheet SOLAR, POOL, MESS) atau workbook pemakaian bulanan (sheet per bulan). Data akan diproses di background; Anda bisa kembali memantau dari Riwayat Import.</p>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-default" data-dismiss="modal">Batal</button>
                        <button type="submit" class="btn btn-primary" id="solar-import-submit"><i class="fa fa-search"></i> Tampilkan Preview</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="modal fade" id="solar-process-modal" tabindex="-1" data-backdrop="static" data-keyboard="false">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header"><h4 class="modal-title"><i class="fa fa-refresh fa-spin"></i> <span id="solar-process-title">Memproses</span></h4></div>
                <div class="modal-body">
                    <div class="progress"><div id="solar-process-progress" class="progress-bar progress-bar-info progress-bar-striped active" style="width:0%">0%</div></div>
                    <p id="solar-process-stage" class="text-muted">Menunggu proses...</p>
                    <p id="solar-process-message">Memulai...</p>
                    <small class="text-muted">Berjalan: <span id="solar-process-elapsed">0 detik</span> · Checkpoint terakhir: <span id="solar-process-heartbeat">—</span></small>
                    <div id="solar-process-activity" class="alert alert-info" style="margin-top:10px;margin-bottom:8px">Menunggu informasi proses...</div>
                    <div class="solar-process-log-wrap"><strong>Aktivitas terbaru</strong><ol id="solar-process-log" class="solar-process-log"><li class="text-muted">Log akan muncul saat server mengirim checkpoint.</li></ol></div>
                    <div id="solar-process-error" class="alert alert-danger" style="display:none;margin-top:10px"></div>
                    <div id="solar-process-stale" class="alert alert-warning" style="display:none;margin-top:10px"></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-warning" id="solar-process-retry" style="display:none">Coba cek lagi</button>
                    <button type="button" class="btn btn-default" id="solar-process-cancel">Batalkan</button>
                    <button type="button" class="btn btn-primary" id="solar-process-close" style="display:none" data-dismiss="modal">Tutup</button>
                </div>
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
<script src="{{ versioned_asset_url(asset('modules/assetmanagement/js/vendor/ag-grid-community-36.2.0.min.js')) }}"></script>
<script src="{{ asset('js/solar.js?v=' . $asset_v) }}"></script>
@endsection
