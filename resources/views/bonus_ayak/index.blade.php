@extends('layouts.app')

@section('content')
<div class="container">

    {{-- HEADER --}}
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h3 class="mb-0"><i class="bi bi-cash-coin me-2"></i> Input Bonus Ayak</h3>
        <div>
            <a href="{{ route('bonus-ayak.index',['export'=>'excel']) }}" class="btn btn-success btn-sm me-2">
                <i class="bi bi-file-earmark-excel"></i> Export Excel
            </a>
            <a href="{{ route('bonus-ayak.index',['export'=>'pdf']) }}" class="btn btn-danger btn-sm">
                <i class="bi bi-file-earmark-pdf"></i> Export PDF
            </a>
        </div>
    </div>

    {{-- NOTIFIKASI --}}
    @if(session('success'))
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="bi bi-check-circle-fill me-2"></i> {{ session('success') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    @endif

    {{-- FORM INPUT BONUS --}}
    <div class="card shadow-sm mb-3">
        <div class="card-header bg-light fw-bold">
            <i class="bi bi-pencil-square me-2"></i> Tambah Data Bonus
        </div>
                <div class="card-body">
                    <form class="row g-3" action="{{ route('bonus-ayak.store') }}" method="POST">
                        @csrf
                        <div class="col-sm-3 col-md-2">
                            <label class="form-label">Tanggal</label>
                            <input type="date" id="tanggal" name="tanggal" class="form-control"
                                   value="{{ date('Y-m-d') }}" required>
                        </div>
                       <div class="col-sm-3 col-md-2">
            <label for="nama_operator">Nama Operator/Supir</label>
            <select id="nama_operator" name="nama_operator" class="form-select select2" required>
                <option value="">-- Pilih --</option>
                @foreach($users as $user)
                    @php
                        // buat nama gabungan
                        $displayName = trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? ''));
                        if ($displayName === '') {
                            $displayName = $user->username ?? 'User#'.$user->id;
                        }
                    @endphp
                    <option value="{{ $displayName }}">
                        {{ $displayName }}
                    </option>
                @endforeach
            </select>
        </div>
                <div class="col-sm-3 col-md-2">
                    <label class="form-label">Unit</label>
                    <input type="text" id="unit" name="unit" class="form-control"
                           placeholder="Unit" required>
                </div>
                <div class="col-sm-2 col-md-2">
                    <label class="form-label">Trip</label>
                    <input type="number" id="trip" name="trip" class="form-control"
                           min="0" value="0" required>
                </div>
                <div class="col-sm-2 col-md-2">
                    <label class="form-label">Bonus</label>
                    <input type="text" id="preview_bonus"
                           class="form-control fw-bold text-end"
                           value="Rp 0" readonly style="background-color:#f8f9fa;">
                    <input type="hidden" name="bonus" id="bonus_hidden" value="0">
                </div>
                <div class="col-sm-2 col-md-1 d-grid align-items-end">
                    <button type="submit" class="btn btn-success mt-4" id="btn-simpan">
                        <i class="bi bi-save"></i> Simpan
                    </button>
                </div>
            </form>
        </div>
    </div>

    {{-- UPLOAD EXCEL --}}
    <div class="card shadow-sm mb-3">
        <div class="card-body">
            <form action="{{ route('bonus-ayak.index') }}" method="POST" enctype="multipart/form-data"
                  class="d-flex align-items-center gap-2 flex-wrap">
                @csrf
                <div class="input-group input-group-sm">
                    <input type="file" name="file" class="form-control" id="customFile" required>
                </div>
                <input type="hidden" name="import" value="excel">
                <button type="submit" class="btn btn-warning btn-sm">Upload</button>
            </form>
        </div>
    </div>

    {{-- FILTER DATA --}}
    <div class="card shadow-sm mb-3">
        <div class="card-header bg-light fw-bold">
            <i class="bi bi-funnel me-2"></i> Filter Data
        </div>
        <div class="card-body">
            <form class="row g-2" method="GET" action="{{ route('bonus-ayak.index') }}">
                <div class="col-md-3">
                    <input type="date" name="from" class="form-control" value="{{ $from }}">
                </div>
                <div class="col-md-3">
                    <input type="date" name="to" class="form-control" value="{{ $to }}">
                </div>
                <div class="col-md-4">
                    <input type="text" name="nama" class="form-control"
                           placeholder="Filter nama" value="{{ $nama }}">
                </div>
                <div class="col-md-2 d-grid">
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-search"></i> Tampilkan
                    </button>
                </div>
            </form>
        </div>
    </div>

    {{-- TABEL DATA BONUS --}}
    <div class="card shadow-sm">
        <div class="card-body table-responsive">
            <table class="table table-bordered table-striped align-middle">
                <thead class="table-light text-center">
                    <tr>
                        <th>Tanggal</th>
                        <th>Nama Operator</th>
                        <th>Unit</th>
                        <th>Trip</th>
                        <th>Bonus</th>
                        <th>Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($data as $row)
                        <tr>
                            <td class="text-center">{{ $row->tanggal->format('d-m-Y') }}</td>
                            <td>{{ $row->nama_operator }}</td>
                            <td class="text-center">{{ $row->unit }}</td>
                            <td class="text-center">{{ $row->trip }}</td>
                            <td class="text-end">{{ number_format($row->bonus, 0, ',', '.') }}</td>
                            <td class="text-center">
                                <form onsubmit="return confirm('Hapus data ini?')" method="POST"
                                      action="{{ route('bonus-ayak.destroy', $row) }}">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="btn btn-sm btn-danger">
                                        <i class="bi bi-trash"></i> Hapus
                                    </button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="text-center text-muted">Belum ada data.</td>
                        </tr>
                    @endforelse
                </tbody>
                <tfoot>
                    <tr class="table-secondary">
                        <th colspan="4" class="text-end">Total Bonus Bulan Ini:</th>
                        <th colspan="2" class="text-start ps-3">
                            {{ number_format($totalBulanIni, 0, ',', '.') }}
                        </th>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>
</div>

{{-- Script Hitung Bonus + Select2 --}}
@push('scripts')
<script>
    function hitungBonus(trip) {
        if(trip < 50) return 0;
        return 50000 + (trip - 50) * 2000;
    }

    $('#trip').on('input', function() {
        let trip = parseInt($(this).val()) || 0;
        let bonus = hitungBonus(trip);
        $('#preview_bonus').val('Rp ' + new Intl.NumberFormat('id-ID').format(bonus));
        $('#bonus_hidden').val(bonus);
    });

    // Init Select2
    $(document).ready(function() {
        $('.select2').select2({
            theme: 'bootstrap-5',
            placeholder: "-- Pilih --",
            allowClear: true,
            width: '100%'
        });
    });
</script>
@endpush

@endsection
