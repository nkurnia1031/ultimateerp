@extends('layouts.app')

@section('content')
<div class="container">

    {{-- HEADER --}}
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h3 class="mb-0"><i class="bi bi-pencil-square me-2"></i> Edit Bonus Ayak</h3>
        <a href="{{ route('bonus-ayak.index') }}" class="btn btn-secondary btn-sm">
            <i class="bi bi-arrow-left"></i> Kembali
        </a>
    </div>

    {{-- NOTIFIKASI --}}
    @if(session('error'))
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="bi bi-exclamation-triangle-fill me-2"></i> {{ session('error') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    @endif

    {{-- FORM EDIT BONUS --}}
    <div class="card shadow-sm">
        <div class="card-header bg-light fw-bold">
            <i class="bi bi-pencil-square me-2"></i> Form Edit Data
        </div>
        <div class="card-body">
            <form class="row g-3" action="{{ route('bonus-ayak.update', $bonus->id) }}" method="POST">
                @csrf
                @method('PUT')

                <div class="col-sm-2 col-md-2">
                    <label class="form-label">Tanggal</label>
                    <input type="date" id="tanggal" name="tanggal" class="form-control"
                           value="{{ old('tanggal', $bonus->tanggal->format('Y-m-d')) }}" required>
                </div>

                <div class="col-sm-3 col-md-3">
                    <label class="form-label">Nama Operator/Supir</label>
                    <select id="nama_operator" name="nama_operator" class="form-select select2" required>
                        <option value="">-- Pilih --</option>
                        @foreach($users as $user)
                            @php
                                $displayName = trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? ''));
                                if ($displayName === '') {
                                    $displayName = $user->username ?? 'User#'.$user->id;
                                }
                            @endphp
                            <option value="{{ $displayName }}"
                                {{ old('nama_operator', $bonus->nama_operator) == $displayName ? 'selected' : '' }}>
                                {{ $displayName }}
                            </option>
                        @endforeach
                    </select>
                </div>

                <div class="col-sm-3 col-md-3">
                    <label class="form-label">Unit</label>
                    <select id="unit" name="unit" class="form-select select2" required>
                        <option value="">-- Pilih Unit --</option>
                        @foreach($assets as $asset)
                            <option value="{{ $asset->name }}"
                                {{ old('unit', $bonus->unit) == $asset->name ? 'selected' : '' }}>
                                {{ $asset->name }}
                            </option>
                        @endforeach
                    </select>
                </div>

                <div class="col-sm-2 col-md-2">
                    <label class="form-label">Trip</label>
                    <input type="number" id="trip" name="trip" class="form-control"
                           min="0" value="{{ old('trip', $bonus->trip) }}" required>
                </div>

                <div class="col-sm-2 col-md-2">
                    <label class="form-label">Bonus</label>
                    <input type="text" id="preview_bonus"
                           class="form-control fw-bold text-end"
                           value="Rp {{ number_format(old('bonus', $bonus->bonus), 0, ',', '.') }}"
                           readonly style="background-color:#f8f9fa;">
                    <input type="hidden" name="bonus" id="bonus_hidden"
                           value="{{ old('bonus', $bonus->bonus) }}">
                </div>

                <div class="col-12 d-flex justify-content-end mt-3">
                    <button type="submit" class="btn btn-primary me-2">
                        <i class="bi bi-save"></i> Update
                    </button>
                    <a href="{{ route('bonus-ayak.index') }}" class="btn btn-secondary">
                        <i class="bi bi-x-circle"></i> Batal
                    </a>
                </div>
            </form>
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
