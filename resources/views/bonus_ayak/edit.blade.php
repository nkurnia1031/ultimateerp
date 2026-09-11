@extends('layouts.app')

@section('title', 'Edit Bonus Ayak')

@section('css')
@include('bonus_ayak.partials.ui')
<style>
    .bonus-editor { max-width: 1120px; margin: 0 auto; padding: 24px 30px 48px; }
    .bonus-editor__header { display: flex; justify-content: space-between; gap: 18px; align-items: flex-end; margin-bottom: 22px; }
    .bonus-editor__header h1 { margin: 0 0 7px; color: #162b3a; font-size: clamp(25px, 3vw, 36px); letter-spacing: -.035em; }
    .bonus-editor__header p { margin: 0; color: #60717b; line-height: 1.6; }
    .bonus-editor .card { border: 1px solid #dce6e8; border-radius: 12px; background: #fff; box-shadow: 0 10px 26px rgba(22,43,58,.07); overflow: visible; }
    .bonus-editor .card-header { min-height: 44px; padding: 12px 18px !important; background: #f7faf9 !important; border-bottom: 1px solid #dce6e8; border-radius: 11px 11px 0 0; color: #1d3b48; line-height: 1.25; }
    .bonus-editor .card-body { padding: 20px !important; }
    .bonus-editor .form-label { display: block; margin: 0 0 7px; color: #334e5b; font-size: 13px; font-weight: 700; line-height: 1.3; }
    .bonus-editor .form-control, .bonus-editor .form-select { min-height: 40px; border: 1px solid #c3d2d8 !important; border-radius: 6px; background-color: #fff; color: #253b49; box-shadow: none; }
    .bonus-editor .form-control:focus, .bonus-editor .form-select:focus { border-color: #0f8b8d !important; box-shadow: 0 0 0 3px rgba(15,139,141,.13); }
    .bonus-editor .bonus-help { margin: 0 0 20px; padding: 13px 15px; color: #536971; background: #f2f8f7; border: 1px solid #d6ebe7; border-left: 4px solid #0f8b8d; border-radius: 7px; line-height: 1.6; }
    .bonus-editor .select2-container { width: 100% !important; }
    .bonus-editor .select2-container--bootstrap-5 .select2-selection { min-height: 40px; height: 40px; padding: 5px 10px; border: 1px solid #c3d2d8 !important; border-radius: 6px; background: #fff; color: #253b49; }
    .bonus-editor .select2-container--bootstrap-5 .select2-selection--single { display: flex; align-items: center; }
    .bonus-editor .select2-container--bootstrap-5 .select2-selection--single .select2-selection__rendered { padding-left: 0; color: #253b49; line-height: 28px; }
    .bonus-editor .select2-container--bootstrap-5 .select2-selection--single .select2-selection__placeholder { color: #71828b; }
    .bonus-editor .select2-container--bootstrap-5 .select2-selection--multiple { height: auto; min-height: 40px; padding: 4px 7px; }
    .bonus-editor .select2-container--bootstrap-5 .select2-selection--multiple .select2-search__field { min-height: 28px; margin: 0; padding: 2px 3px; border: 0 !important; background: #fff !important; color: #253b49; box-shadow: none !important; }
    .bonus-editor .select2-container--bootstrap-5 .select2-selection--multiple .select2-search__field::placeholder { color: #71828b; opacity: 1; }
    .bonus-editor .select2-container--open { z-index: 1060; }
    .bonus-editor .select2-container--bootstrap-5 .select2-dropdown { border: 1px solid #b8cbd0 !important; border-radius: 7px; background: #fff; box-shadow: 0 12px 24px rgba(22,43,58,.12); }
    .bonus-editor .select2-container--bootstrap-5 .select2-search--dropdown { padding: 8px; background: #fff; }
    .bonus-editor .select2-container--bootstrap-5 .select2-search--dropdown .select2-search__field { min-height: 36px; padding: 7px 9px; border: 1px solid #c3d2d8 !important; border-radius: 5px; background: #fff !important; color: #253b49; }
    .bonus-editor .select2-container--bootstrap-5 .select2-results__option { padding: 9px 12px; color: #334e5b; }
    .bonus-editor .select2-container--bootstrap-5 .select2-results__option--highlighted[aria-selected] { background: #0f8b8d; color: #fff; }
    .bonus-editor a:focus-visible, .bonus-editor button:focus-visible { outline: 3px solid #f0b85b; outline-offset: 3px; }
    @media (max-width: 640px) { .bonus-editor { padding: 18px 12px 34px; } .bonus-editor__header { display: block; } .bonus-editor__header .btn { margin-top: 15px; width: 100%; } }
</style>
@endsection

@section('content')
    @php($isLocked = $bonus->settlements->isNotEmpty())
    <main class="bonus-ui bonus-editor mb-5 pb-5" aria-labelledby="bonus-edit-title">

        {{-- HEADER --}}
        <div class="bonus-editor__header">
            <div><p class="text-uppercase text-info small fw-bold mb-2">Perubahan data</p><h1 id="bonus-edit-title"><i class="fa fa-edit me-2" aria-hidden="true"></i>Edit Bonus Ayak</h1><p>Periksa kembali relasi sopir, unit, Project dan sumber SPB sebelum menyimpan.</p></div>
            <a href="{{ route('bonus-ayak.index') }}" class="btn bonus-btn-secondary" aria-label="Kembali ke daftar Bonus Ayak">
                <i class="fa fa-arrow-left" aria-hidden="true"></i> Kembali
            </a>
        </div>

        {{-- NOTIFIKASI --}}
        @if (session('error'))
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <i class="fa fa-exclamation-triangle me-2" aria-hidden="true"></i> {{ session('error') }}
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        @endif
        @if ($errors->any())
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <i class="fa fa-exclamation-triangle me-2" aria-hidden="true"></i> {{ $errors->first() }}
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        @endif

        {{-- FORM EDIT BONUS --}}
        <div class="card shadow-sm">
            <div class="card-header bg-light fw-bold">
                <i class="fa fa-edit me-2" aria-hidden="true"></i> Form Edit Data
            </div>

            <div class="card-body p-4">
                <p class="bonus-help"><strong>Data terkunci?</strong> Bonus yang sudah masuk settlement payroll tidak dapat diedit. Jika masih tersedia, perubahan akan dihitung ulang sesuai jumlah trip dan sumber SPB.</p>
                @if($isLocked)
                    <div class="alert alert-warning" role="status"><i class="fa fa-lock me-2" aria-hidden="true"></i><strong>Data terkunci payroll.</strong> Bonus ini sudah masuk rekap pembayaran dan hanya dapat dilihat.</div>
                @endif
                <form class="row gy-3 gx-3 align-items-end" action="{{ route('bonus-ayak.update', $bonus->id) }}"
                    method="POST">
                    @csrf
                    @method('PUT')

                    <div class="col-sm-2 col-md-2">
                        <label class="form-label" for="tanggal_display">Tanggal</label>
                        <input type="text" id="tanggal_display" class="form-control bonus-date-display"
                            value="{{ old('tanggal', $bonus->tanggal->format('Y-m-d')) }}" placeholder="DD/MM/YYYY" inputmode="numeric" autocomplete="off" data-date-display data-date-value-id="tanggal" required {{ $isLocked ? 'disabled' : '' }}>
                        <input type="hidden" id="tanggal" name="tanggal" value="{{ old('tanggal', $bonus->tanggal->format('Y-m-d')) }}">
                    </div>

                    <div class="col-sm-3 col-md-3">
                        <label class="form-label" for="driver_id">Nama Operator/Supir</label>
                        <select id="driver_id" name="driver_id" class="form-select select2" required {{ $isLocked ? 'disabled' : '' }}>
                            <option value="">-- Pilih --</option>
                            @foreach ($users as $user)
                                <option value="{{ $user->id }}"
                                    {{ old('driver_id', $bonus->driver_id) == $user->id ? 'selected' : '' }}>
                                    {{ $user->user_full_name }}
                                </option>
                            @endforeach
                        </select>
                    </div>

                    <div class="col-sm-3 col-md-3">
                        <label class="form-label" for="asset_id">Unit</label>
                        <select id="asset_id" name="asset_id" class="form-select select2" required {{ $isLocked ? 'disabled' : '' }}>
                            <option value="">-- Pilih Unit --</option>
                            @foreach ($assets as $asset)
                                <option value="{{ $asset->id }}"
                                    {{ old('asset_id', $bonus->asset_id) == $asset->id ? 'selected' : '' }}>
                                    {{ $asset->name }}
                                </option>
                            @endforeach
                        </select>
                    </div>

                    <div class="col-sm-4 col-md-4">
                        <label class="form-label" for="project_id">Project</label>
                        <select id="project_id" name="project_id" class="form-select select2" {{ $isLocked ? 'disabled' : '' }}>
                            <option value="">-- Manual / tanpa Project --</option>
                            @foreach ($projects as $projectId => $projectName)
                                <option value="{{ $projectId }}" @selected(old('project_id', $bonus->project_id) == $projectId)>{{ $projectName }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div class="col-sm-2 col-md-2">
                        <label class="form-label" for="trip">Trip</label>
                        <input type="number" id="trip" name="trip" class="form-control" min="0"
                            value="{{ old('trip', $bonus->trip) }}" required {{ $isLocked ? 'disabled' : '' }}>
                    </div>

                    <div class="col-sm-2 col-md-2">
                        <label class="form-label" for="preview_bonus">Bonus</label>
                        <input type="text" id="preview_bonus" class="form-control fw-bold text-end"
                            value="Rp {{ number_format(old('bonus', $bonus->bonus), 0, ',', '.') }}" readonly
                            style="background-color:#f8f9fa;" {{ $isLocked ? 'disabled' : '' }}>
                        <input type="hidden" name="bonus" id="bonus_hidden" value="{{ old('bonus', $bonus->bonus) }}">
                    </div>

                    <div class="col-sm-12">
                        <label class="form-label" for="trip_ids">SPB sumber (opsional)</label>
                        @php($selectedTripIds = old('trip_ids', $bonus->trips->pluck('id')->all()))
                        <select id="trip_ids" name="trip_ids[]" class="form-select select2" multiple data-placeholder="Pilih SPB yang menjadi sumber Bonus Ayak" {{ $isLocked ? 'disabled' : '' }}>
                            @foreach ($availableTrips as $sourceTrip)
                                <option value="{{ $sourceTrip->id }}" @selected(in_array($sourceTrip->id, $selectedTripIds))>
                                    {{ $sourceTrip->trip_no }} &mdash; {{ optional($sourceTrip->trip_date)->format('d/m/Y') }} &mdash; {{ optional($sourceTrip->asset)->name ?: '-' }} &mdash; {{ number_format((float) $sourceTrip->actual_tons, 2, ',', '.') }} ton
                                </option>
                            @endforeach
                        </select>
                        <small class="text-muted">SPB harus verified dan menggunakan sopir serta unit yang sama. Satu SPB hanya boleh menjadi sumber satu Bonus Ayak.</small>
                    </div>

                    {{-- ✅ Tombol di bawah, terlihat penuh dan sejajar --}}
                    <div class="col-sm-12 bonus-action-group">
                        @if(!$isLocked)
                            <button type="submit" class="btn btn-success" aria-label="Simpan perubahan Bonus Ayak">
                                <i class="fa fa-save" aria-hidden="true"></i> Update
                            </button>
                        @else
                            <span class="bonus-locked-state"><i class="fa fa-lock" aria-hidden="true"></i> Tidak dapat diubah</span>
                        @endif
                        <a href="{{ route('bonus-ayak.index') }}" class="btn bonus-btn-secondary" aria-label="Batalkan dan kembali ke daftar Bonus Ayak">
                            <i class="fa fa-times" aria-hidden="true"></i> Batal
                        </a>
                    </div>
                </form>
            </div>
        </div>
    </main>

    {{-- Script Hitung Bonus + Select2 --}}
    @push('scripts')
        <script src="{{ asset('js/bonus-ayak-ui.js?v=' . $asset_v) }}"></script>
        <script>
            function hitungBonus(trip) {
                if (trip < 50) return 0;
                return 50000 + (trip - 50) * 2000;
            }

            $(document).ready(function() {
                // Select2
                $('.select2').each(function() {
                    const $select = $(this);
                    $select.select2({
                        theme: 'bootstrap-5',
                        placeholder: $select.data('placeholder') || '-- Pilih --',
                        allowClear: !$select.prop('required'),
                        width: '100%',
                        dropdownParent: $('.bonus-editor')
                    });
                });

                // Hitung bonus realtime
                $('#trip').on('input', function() {
                    let trip = parseInt($(this).val()) || 0;
                    let bonus = hitungBonus(trip);
                    $('#preview_bonus').val('Rp ' + new Intl.NumberFormat('id-ID').format(bonus));
                    $('#bonus_hidden').val(bonus);
                });

                // ✅ Jalankan saat load awal
                let tripAwal = parseInt($('#trip').val()) || 0;
                let bonusAwal = hitungBonus(tripAwal);
                $('#preview_bonus').val('Rp ' + new Intl.NumberFormat('id-ID').format(bonusAwal));
                $('#bonus_hidden').val(bonusAwal);
            });
        </script>
    @endpush
@endsection
