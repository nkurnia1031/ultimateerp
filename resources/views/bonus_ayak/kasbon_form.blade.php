@extends('layouts.app')

@section('title', $editingKasbon ? 'Edit Kasbon Sopir' : 'Kasbon Sopir')

@section('css')
@include('bonus_ayak.partials.ui')
<style>
    .kasbon-shell { max-width: 1180px; margin: 0 auto; padding: 24px 30px 48px; }
    .kasbon-header { display: flex; justify-content: space-between; gap: 18px; align-items: flex-end; margin-bottom: 22px; }
    .kasbon-header h1 { margin: 0 0 7px; color: #162b3a; font-size: clamp(25px, 3vw, 36px); letter-spacing: -.035em; }
    .kasbon-header p { margin: 0; color: #60717b; line-height: 1.6; }
    .kasbon-shell .card { overflow: visible; border: 1px solid #dce6e8; border-radius: 12px; background: #fff; box-shadow: 0 10px 26px rgba(22,43,58,.07); }
    .kasbon-shell .card-header { min-height: 44px; padding: 12px 18px !important; background: #f7faf9 !important; border-bottom: 1px solid #dce6e8; border-radius: 11px 11px 0 0; color: #1d3b48; line-height: 1.25; }
    .kasbon-shell .card-body { padding: 20px !important; }
    .kasbon-shell .form-label { display: block; margin: 0 0 7px; color: #334e5b; font-size: 13px; font-weight: 700; line-height: 1.3; }
    .kasbon-shell .form-control, .kasbon-shell .form-select { min-height: 40px; border: 1px solid #c3d2d8 !important; border-radius: 6px; background-color: #fff; color: #253b49; box-shadow: none; }
    .kasbon-shell .form-control:focus, .kasbon-shell .form-select:focus { border-color: #0f8b8d !important; box-shadow: 0 0 0 3px rgba(15,139,141,.13); }
    .kasbon-shell .kasbon-help { margin: 0 0 20px; padding: 13px 15px; color: #536971; background: #f2f8f7; border: 1px solid #d6ebe7; border-left: 4px solid #0f8b8d; border-radius: 7px; line-height: 1.6; }
    .kasbon-shell .select2-container { width: 100% !important; }
    .kasbon-shell .select2-container--bootstrap-5 .select2-selection { min-height: 40px; height: 40px; padding: 5px 10px; border: 1px solid #c3d2d8 !important; border-radius: 6px; background: #fff; color: #253b49; }
    .kasbon-shell .select2-container--bootstrap-5 .select2-selection--single { display: flex; align-items: center; }
    .kasbon-shell .select2-container--bootstrap-5 .select2-selection--single .select2-selection__rendered { padding-left: 0; color: #253b49; line-height: 28px; }
    .kasbon-shell .select2-container--bootstrap-5 .select2-selection--single .select2-selection__placeholder { color: #71828b; }
    .kasbon-shell .select2-container--open { z-index: 1060; }
    .kasbon-shell .select2-container--bootstrap-5 .select2-dropdown { border: 1px solid #b8cbd0 !important; border-radius: 7px; background: #fff; box-shadow: 0 12px 24px rgba(22,43,58,.12); }
    .kasbon-shell .select2-container--bootstrap-5 .select2-search--dropdown { padding: 8px; background: #fff; }
    .kasbon-shell .select2-container--bootstrap-5 .select2-search--dropdown .select2-search__field { min-height: 36px; padding: 7px 9px; border: 1px solid #c3d2d8 !important; border-radius: 5px; background: #fff !important; color: #253b49; }
    .kasbon-shell .select2-container--bootstrap-5 .select2-results__option { padding: 9px 12px; color: #334e5b; }
    .kasbon-shell .select2-container--bootstrap-5 .select2-results__option--highlighted[aria-selected] { background: #0f8b8d; color: #fff; }
    .kasbon-shell .table th { color: #536971; font-size: 12px; white-space: nowrap; }
    .kasbon-shell .table td { color: #334e5b; vertical-align: middle; }
    .kasbon-shell .kasbon-number { text-align: right; font-variant-numeric: tabular-nums; font-weight: 700; }
    .kasbon-shell .kasbon-actions { display: flex; justify-content: center; gap: 6px; flex-wrap: wrap; }
    .kasbon-shell .kasbon-actions form { display: inline; }
    .kasbon-shell a:focus-visible, .kasbon-shell button:focus-visible { outline: 3px solid #f0b85b; outline-offset: 3px; }
    @media (max-width: 640px) { .kasbon-shell { padding: 18px 12px 34px; } .kasbon-header { display: block; } .kasbon-header .btn { width: 100%; margin-top: 15px; } }
</style>
@endsection

@section('content')
@include('layouts.partials.fleet-datatables')
<main class="bonus-ui kasbon-shell" aria-labelledby="kasbon-page-title">
    @php($editingKasbon = $editingKasbon ?? null)
    @php($isLocked = $editingKasbon && $editingKasbon->settlements->isNotEmpty())
    {{-- HEADER --}}
    <div class="kasbon-header">
        <div><p class="text-uppercase text-info small fw-bold mb-2">Potongan payroll</p><h1 id="kasbon-page-title"><i class="fa fa-wallet me-2" aria-hidden="true"></i>{{ $editingKasbon ? 'Edit Kasbon Sopir' : 'Kasbon Sopir' }}</h1><p>Kasbon dipilih sebagai potongan pada settlement dan tidak menjadi pendapatan pelanggan.</p></div>
        <a href="{{ route('bonus-ayak.index') }}" class="btn bonus-btn-secondary" aria-label="Kembali ke Bonus Ayak">
            <i class="fa fa-arrow-left" aria-hidden="true"></i> Kembali
        </a>
    </div>

    {{-- NOTIFIKASI --}}
    @if(session('success'))
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="fa fa-check-circle me-2" aria-hidden="true"></i> {{ session('success') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    @endif
    @if($errors->any())
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="fa fa-exclamation-triangle me-2" aria-hidden="true"></i> {{ $errors->first() }}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    @endif

    {{-- FORM INPUT KASBON --}}
    <div class="card shadow-sm mb-3">
            <div class="card-header bg-light fw-bold">
                <i class="fa fa-edit me-2" aria-hidden="true"></i> {{ $editingKasbon ? 'Edit Kasbon' : 'Tambah Kasbon' }}
            </div>
            <div class="card-body">
            <p class="kasbon-help"><strong>Catat satu potongan.</strong> Pilih sopir dari business aktif, masukkan nominal yang sudah disetujui, lalu periksa statusnya pada daftar. Kasbon yang sudah masuk payroll akan terkunci.</p>
            @if($isLocked)
                <div class="alert alert-warning" role="status"><i class="fa fa-lock me-2" aria-hidden="true"></i><strong>Kasbon terkunci payroll.</strong> Data ini sudah dipotong pada rekap pembayaran dan hanya dapat dilihat.</div>
            @endif
            <form class="row g-3" action="{{ $editingKasbon ? route('bonus-ayak.kasbon-update', $editingKasbon->id) : route('bonus-ayak.kasbon-store') }}" method="POST">
                @csrf
                @if($editingKasbon)
                    @method('PUT')
                @endif

                {{-- Tanggal --}}
                <div class="col-sm-3">
                    <label class="form-label" for="kasbon-tanggal-display">Tanggal Kasbon</label>
                    <input type="text" id="kasbon-tanggal-display" class="form-control bonus-date-display"
                           value="{{ old('tanggal', $editingKasbon?->tanggal?->format('Y-m-d') ?: date('Y-m-d')) }}" placeholder="DD/MM/YYYY" inputmode="numeric" autocomplete="off" data-date-display data-date-value-id="kasbon-tanggal" required {{ $isLocked ? 'disabled' : '' }}>
                    <input type="hidden" id="kasbon-tanggal" name="tanggal" value="{{ old('tanggal', $editingKasbon?->tanggal?->format('Y-m-d') ?: date('Y-m-d')) }}">
                </div>

                {{-- Nama Operator --}}
                <div class="col-sm-4">
                    <label class="form-label" for="kasbon-driver-id">Nama Operator / Supir</label>
                    <select id="kasbon-driver-id" name="driver_id" class="form-select select2" required {{ $isLocked ? 'disabled' : '' }}>
                        <option value="">-- Pilih --</option>
                        @foreach($users as $user)
                            <option value="{{ $user->id }}" @selected(old('driver_id', $editingKasbon?->driver_id) == $user->id)>{{ $user->user_full_name }}</option>
                        @endforeach
                    </select>
                </div>

                {{-- Jumlah Kasbon --}}
                <div class="col-sm-3">
                    <label class="form-label" for="jumlah-kasbon-display">Jumlah Kasbon (Rp)</label>
                    <input type="text" id="jumlah-kasbon-display" class="form-control text-end bonus-currency-display"
                           value="{{ old('jumlah_kasbon', $editingKasbon?->jumlah_kasbon ?: 0) }}" placeholder="Rp 0" inputmode="numeric" autocomplete="off" data-currency-display data-currency-value-id="jumlah-kasbon" required {{ $isLocked ? 'disabled' : '' }}>
                    <input type="hidden" id="jumlah-kasbon" name="jumlah_kasbon" value="{{ old('jumlah_kasbon', $editingKasbon?->jumlah_kasbon ?: 0) }}">
                </div>

                {{-- Tombol Simpan --}}
                <div class="col-sm-12 bonus-action-group">
                    @if(!$isLocked)
                        <button type="submit" class="btn btn-success" aria-label="{{ $editingKasbon ? 'Simpan perubahan Kasbon' : 'Simpan Kasbon' }}">
                            <i class="fa fa-save" aria-hidden="true"></i> {{ $editingKasbon ? 'Update' : 'Simpan' }}
                        </button>
                    @else
                        <span class="bonus-locked-state"><i class="fa fa-lock" aria-hidden="true"></i> Tidak dapat diubah</span>
                    @endif
                    @if($editingKasbon)
                        <a href="{{ route('bonus-ayak.kasbon-form') }}" class="btn bonus-btn-secondary" aria-label="Batalkan edit Kasbon">
                            <i class="fa fa-times" aria-hidden="true"></i> Batal
                        </a>
                    @endif
                </div>
            </form>
        </div>
    </div>

    {{-- TABEL DATA KASBON --}}
    <div class="card shadow-sm">
        <div class="card-header bg-light fw-bold">
            <i class="fa fa-table me-2" aria-hidden="true"></i> Daftar Kasbon Bonus Ayak
        </div>
        <div class="card-body table-responsive fleet-datatable-wrap">
            <table id="bonus-ayak-kasbon-table" class="table table-bordered table-striped align-middle fleet-datatable">
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
                            <td class="text-center">{{ \Carbon\Carbon::parse($row->tanggal)->format('d/m/Y') }}</td>
                            <td>{{ optional($row->driver)->user_full_name ?: $row->nama_operator }} @if(!$row->driver)<span class="badge bg-warning">legacy</span>@endif</td>
                            <td class="kasbon-number">Rp {{ number_format($row->jumlah_kasbon, 0, ',', '.') }}</td>
                            <td class="text-center">
                                <div class="bonus-row-actions">
                                @if($row->settlements->isEmpty())
                                    <a href="{{ route('bonus-ayak.kasbon-edit', $row->id) }}" class="btn btn-sm btn-warning" title="Edit Kasbon" aria-label="Edit Kasbon">
                                        <i class="fa fa-edit" aria-hidden="true"></i> Edit
                                    </a>
                                    <form onsubmit="return confirm('Hapus kasbon ini?')" method="POST" action="{{ route('bonus-ayak.kasbon-delete', $row->id) }}">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="btn btn-sm btn-danger" title="Hapus Kasbon" aria-label="Hapus Kasbon">
                                            <i class="fa fa-trash" aria-hidden="true"></i> Hapus
                                        </button>
                                    </form>
                                @else
                                    <span class="bonus-locked-state" title="Kasbon sudah masuk payroll dan tidak dapat diubah."><i class="fa fa-lock" aria-hidden="true"></i> Terkunci payroll</span>
                                @endif
                                </div>
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
</main>

{{-- SCRIPT SELECT2 --}}
@push('scripts')
<script src="{{ asset('js/bonus-ayak-ui.js?v=' . $asset_v) }}"></script>
<script>
$(document).ready(function() {
    $('.select2').each(function() {
        const $select = $(this);
        $select.select2({
            theme: 'bootstrap-5',
            placeholder: $select.data('placeholder') || '-- Pilih --',
            allowClear: !$select.prop('required'),
            width: '100%',
            dropdownParent: $('.kasbon-shell')
        });
    });
});
</script>
<script>
$(function () {
    initFleetDataTable('#bonus-ayak-kasbon-table', {
        order: [[0, 'desc']],
        columnDefs: [{ targets: [1, 2, 3], orderable: false }]
    });
});
</script>
@endpush

@endsection
