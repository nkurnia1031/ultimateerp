@extends('layouts.app')

@section('title', 'Cross-check Stok Solar')

@section('content')
<section class="content-header">
    <h1>Hasil Cross-check Stok Solar <small>preview sebelum import</small></h1>
</section>

<section class="content">
    @if (session('status'))
        <div class="alert alert-danger">{{ session('status.msg') }}</div>
    @endif

    <div class="row">
        @php
            $cards = [
                ['match', 'Cocok', 'bg-green'],
                ['only_excel', 'Hanya di Excel', 'bg-aqua'],
                ['only_app', 'Hanya di Aplikasi', 'bg-yellow'],
                ['different', 'Berbeda', 'bg-orange'],
                ['error', 'Error', 'bg-red'],
            ];
        @endphp
        @foreach ($cards as [$key, $label, $color])
            <div class="col-sm-6 col-md-{{ $loop->first ? '2' : '2' }}">
                <div class="small-box {{ $color }}">
                    <div class="inner"><h3>{{ $result['summary'][$key] ?? 0 }}</h3><p>{{ $label }}</p></div>
                </div>
            </div>
        @endforeach
    </div>

    <div class="box box-primary">
        <div class="box-header with-border">
            <h3 class="box-title">Perbandingan Workbook dan Aplikasi</h3>
            <div class="box-tools">
                <a href="{{ route('solar.reconcile.export', $token) }}" class="btn btn-sm btn-warning">
                    <i class="fa fa-file-excel-o"></i> Download Laporan Lengkap
                </a>
            </div>
        </div>
        <div class="box-body">
            <div class="alert alert-info">
                Konfirmasi hanya menambahkan {{ $result['summary']['only_excel'] ?? 0 }} baris yang valid dan belum ada.
                Baris berstatus Berbeda tidak akan menimpa aplikasi.
            </div>
            @if ($totalItems > count($visibleItems))
                <p class="text-muted">Menampilkan 500 dari {{ $totalItems }} hasil. Unduh laporan untuk melihat seluruh baris.</p>
            @endif
            <div class="table-responsive">
                <table class="table table-bordered table-striped">
                    <thead>
                        <tr><th>Ledger</th><th>Baris Excel</th><th>Tanggal</th><th>Status</th><th>Detail</th></tr>
                    </thead>
                    <tbody>
                        @forelse ($visibleItems as $item)
                            @php
                                $labels = [
                                    'match' => ['Cocok', 'success'],
                                    'only_excel' => ['Hanya di Excel', 'info'],
                                    'only_app' => ['Hanya di Aplikasi', 'warning'],
                                    'different' => ['Berbeda', 'warning'],
                                    'error' => ['Error', 'danger'],
                                ];
                                [$statusLabel, $statusColor] = $labels[$item['status']] ?? [$item['status'], 'default'];
                            @endphp
                            <tr>
                                <td>{{ strtoupper($item['ledger']) }}</td>
                                <td>{{ $item['row'] ?: '—' }}</td>
                                <td>{{ $item['date'] ? \Carbon\Carbon::parse($item['date'])->format('d/m/Y') : '—' }}</td>
                                <td><span class="label label-{{ $statusColor }}">{{ $statusLabel }}</span></td>
                                <td>
                                    @if (empty($item['differences']))
                                        <span class="text-muted">Data dan saldo sesuai.</span>
                                    @else
                                        <ul class="list-unstyled" style="margin:0">
                                            @foreach ($item['differences'] as $difference)
                                                <li>{{ $difference }}</li>
                                            @endforeach
                                        </ul>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="text-center text-muted">Tidak ada data untuk dibandingkan.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
        <div class="box-footer">
            <a href="{{ route('solar.index') }}" class="btn btn-default"><i class="fa fa-arrow-left"></i> Kembali tanpa Import</a>
            <form action="{{ route('solar.import.confirm') }}" method="post" class="pull-right">
                @csrf
                <input type="hidden" name="token" value="{{ $token }}">
                <button class="btn btn-success" {{ ($result['summary']['only_excel'] ?? 0) < 1 ? 'disabled' : '' }}>
                    <i class="fa fa-check"></i> Konfirmasi Import {{ $result['summary']['only_excel'] ?? 0 }} Baris Baru
                </button>
            </form>
        </div>
    </div>
</section>
@endsection
