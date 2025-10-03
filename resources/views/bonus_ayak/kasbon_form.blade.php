@extends('layouts.app')

@section('content')
<div class="container">
    {{-- HEADER --}}
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h3 class="mb-0"><i class="bi bi-wallet2 me-2"></i> Form Kasbon Bonus Ayak</h3>
        <a href="{{ route('bonus-ayak.index') }}" class="btn btn-secondary">
            <i class="bi bi-arrow-left"></i> Kembali
        </a>
    </div>

    {{-- NOTIFIKASI --}}
    @if(session('success'))
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="bi bi-check-circle-fill me-2"></i> {{ session('success') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    @endif

    {{-- FORM INPUT KASBON --}}
    <div class="card shadow-sm mb-3">
        <div class="card-header bg-light fw-bold">
            <i class="bi bi-pencil-square me-2"></i> Tambah Kasbon
        </div>
        <div class="card-body">
            <form class="row g-3" action="{{ route('bonus-ayak.kasbon-store') }}" method="POST">
                @csrf

                {{-- Tanggal --}}
                <div class="col-sm-3">
                    <label class="form-label">Tanggal Kasbon</label>
                    <input type="date" name="tanggal" class="form-control"
                           value="{{ date('Y-m-d') }}" required>
                </div>

                {{-- Nama Operator --}}
                <div class="col-sm-4">
                    <label class="form-label">Nama Operator / Supir</label>
                    <select name="nama_operator" class="form-select select2" required>
                        <option value="">-- Pilih --</option>
                        @foreach($users as $user)
                            @php
                                $displayName = trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? ''));
                                if ($displayName === '') {
                                    $displayName = $user->username ?? 'User#'.$user->id;
                                }
                            @endphp
                            <option value="{{ $displayName }}">{{ $displayName }}</option>
                        @endforeach
                    </select>
                </div>

                {{-- Jumlah Kasbon --}}
                <div class="col-sm-3">
                    <label class="form-label">Jumlah Kasbon (Rp)</label>
                    <input type="number" name="jumlah_kasbon" class="form-control text-end"
                           min="0" value="0" required>
                </div>

                {{-- Tombol Simpan --}}
                <div class="col-sm-2 d-grid align-items-end">
                    <button type="submit" class="btn btn-success mt-4">
                        <i class="bi bi-save"></i> Simpan
                    </button>
                </div>
            </form>
        </div>
    </div>

    {{-- TABEL DATA KASBON --}}
    <div class="card shadow-sm">
        <div class="card-header bg-light fw-bold">
            <i class="bi bi-table me-2"></i> Daftar Kasbon Bonus Ayak
        </div>
        <div class="card-body table-responsive">
            <table class="table table-bordered table-striped align-middle">
                <thead class="table-light text-center">
                    <tr>
                        <th>Tanggal</th>
                        <th>Nama Operator / Supir</th>
                        <th>Jumlah Kasbon (Rp)</th>
                        <th>Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($kasbon as $row)
                        <tr>
                            <td class="text-center">{{ \Carbon\Carbon::parse($row->tanggal)->format('d-m-Y') }}</td>
                            <td>{{ $row->nama_operator }}</td>
                            <td class="text-end">{{ number_format($row->jumlah_kasbon, 0, ',', '.') }}</td>
                            <td class="text-center">
                                <form onsubmit="return confirm('Hapus kasbon ini?')" method="POST" 
                                      action="{{ route('bonus-ayak.kasbon-delete', $row->id) }}">
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
                            <td colspan="4" class="text-center text-muted">Belum ada data kasbon.</td>
                        </tr>
                    @endforelse
                </tbody>
                @if($kasbon->count() > 0)
                <tfoot>
                    <tr class="table-secondary">
                        <th colspan="2" class="text-end">TOTAL:</th>
                        <th class="text-end">{{ number_format($kasbon->sum('jumlah_kasbon'), 0, ',', '.') }}</th>
                        <th></th>
                    </tr>
                </tfoot>
                @endif
            </table>
        </div>
    </div>
</div>

{{-- SCRIPT SELECT2 --}}
@push('scripts')
<script>
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
