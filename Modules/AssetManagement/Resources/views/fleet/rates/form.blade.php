@extends('layouts.app')

@section('title', isset($rate) ? 'Edit Tarif Angkutan' : (isset($copyRate) ? 'Buat Revisi Tarif Angkutan' : 'Tambah Tarif Angkutan'))

@section('content')
@includeIf('assetmanagement::layouts.nav')
<style>
    .fleet-rate-form { max-width: 1180px; margin: 0 auto; padding: 24px 30px 48px; color: #253b49; }
    .fleet-rate-form__header { display: flex; justify-content: space-between; align-items: flex-end; gap: 18px; margin-bottom: 22px; }
    .fleet-rate-form__header h1 { margin: 0 0 7px; color: #162b3a; font-size: clamp(26px, 3vw, 38px); letter-spacing: -.035em; }
    .fleet-rate-form__header p { margin: 0; color: #60717b; line-height: 1.6; }
    .fleet-rate-form__header .btn { min-height: 40px; }
    .fleet-rate-form .box { border: 1px solid #dce6e8; border-radius: 12px; box-shadow: 0 8px 22px rgba(22,43,58,.06); overflow: visible; }
    .fleet-rate-form .box-header { min-height: 46px; padding: 13px 18px; border-bottom: 1px solid #dce6e8; border-radius: 11px 11px 0 0; background: #f7faf9; color: #1d3b48; }
    .fleet-rate-form .box-body { padding: 22px; }
    .fleet-rate-form .rate-help { margin: 0 0 20px; padding: 13px 15px; border: 1px solid #d6ebe7; border-left: 4px solid #0f8b8d; border-radius: 7px; background: #f2f8f7; color: #536971; line-height: 1.6; }
    .fleet-rate-form label { display: block; margin-bottom: 7px; color: #334e5b; font-weight: 700; }
    .fleet-rate-form .form-control, .fleet-rate-form .select2-selection { min-height: 40px; border: 1px solid #c3d2d8 !important; border-radius: 6px; box-shadow: none; }
    .fleet-rate-form .form-control:focus, .fleet-rate-form .select2-container--focus .select2-selection { border-color: #0f8b8d !important; box-shadow: 0 0 0 3px rgba(15,139,141,.13); }
    .fleet-rate-form textarea.form-control { min-height: 96px; }
    .fleet-rate-form .select2-container { width: 100% !important; }
    .fleet-rate-form .select2-selection--multiple { min-height: 40px; padding: 4px 7px; }
    .fleet-rate-form .select2-selection__choice { margin-top: 3px !important; }
    .fleet-rate-form .asset-selection-summary { display: block; margin-top: 7px; color: #536971; font-size: 12px; font-weight: 600; }
    .fleet-rate-form .date-hint { display: block; margin-top: 6px; color: #6b7d85; font-size: 12px; }
    .fleet-rate-form .date-error { display: none; margin-top: 6px; color: #b42318; font-size: 12px; }
    .fleet-rate-form .date-display.is-invalid { border-color: #d64545 !important; box-shadow: 0 0 0 3px rgba(214,69,69,.12); }
    .fleet-rate-form .rate-actions { display: flex; justify-content: flex-end; align-items: center; gap: 9px; margin-top: 20px; padding-top: 16px; border-top: 1px solid #e6edef; }
    .fleet-rate-form .rate-actions .btn { min-height: 40px; }
    .fleet-rate-form .rate-btn-secondary { color: #334e5b !important; background: #f7fafb !important; border: 1px solid #b8cbd0 !important; }
    .fleet-rate-form .rate-btn-secondary:hover, .fleet-rate-form .rate-btn-secondary:focus { background: #e8f0f1 !important; border-color: #8faeb5 !important; }
    .fleet-rate-form a:focus-visible, .fleet-rate-form button:focus-visible, .fleet-rate-form input:focus-visible, .fleet-rate-form textarea:focus-visible { outline: 3px solid #f0b85b !important; outline-offset: 3px; }
    @media (max-width: 640px) { .fleet-rate-form { padding: 18px 12px 34px; } .fleet-rate-form__header { display: block; } .fleet-rate-form__header .btn { width: 100%; margin-top: 15px; } .fleet-rate-form .box-body { padding: 16px; } .fleet-rate-form .rate-actions { flex-direction: column; align-items: stretch; } .fleet-rate-form .rate-actions .btn { width: 100%; } }
</style>

@php
    $rate = $rate ?? null;
    $copyRate = $copyRate ?? null;
    $formRate = $rate ?: $copyRate;
    $isImportedSource = $formRate && !str_starts_with((string) optional($formRate->batch)->file_name, 'Manual - ');
    $selectedAssetIds = old('asset_ids', $formRate ? $formRate->units->pluck('asset_id')->filter()->all() : []);
    $selectedAssetIds = is_array($selectedAssetIds) ? $selectedAssetIds : [$selectedAssetIds];
    $effectiveFrom = old('effective_from', $formRate?->effective_from?->format('Y-m-d'));
    $effectiveFromDisplay = old('effective_from_display', $formRate?->effective_from?->format('d/m/Y'));
    $effectiveTo = old('effective_to', $formRate?->effective_to?->format('Y-m-d'));
    $effectiveToDisplay = old('effective_to_display', $formRate?->effective_to?->format('d/m/Y'));
@endphp

<main class="fleet-rate-form" aria-labelledby="fleet-rate-form-title">
    <header class="fleet-rate-form__header">
        <div>
            <p class="text-uppercase text-info small fw-bold mb-2">Master tarif angkutan</p>
            <h1 id="fleet-rate-form-title">{{ $rate ? 'Edit Tarif Angkutan' : ($copyRate ? 'Buat Revisi Tarif Angkutan' : 'Tambah Tarif Angkutan') }}</h1>
            <p>{{ $rate ? 'Perbarui tarif yang belum dipakai SPB.' : ($copyRate ? 'Gunakan tarif lama sebagai dasar, lalu tentukan tanggal berlaku baru.' : 'Buat tarif tanpa harus menunggu import Excel.') }} Tarif aktif akan dipakai saat membuat SPB baru.</p>
        </div>
        <a href="{{ route('asset.fleet.rates.index') }}" class="btn rate-btn-secondary" aria-label="Kembali ke Master Tarif Angkutan">
            <i class="fas fa-arrow-left" aria-hidden="true"></i> Kembali
        </a>
    </header>

    @if($errors->any())
        <div class="alert alert-danger" role="alert"><i class="fas fa-exclamation-triangle me-2" aria-hidden="true"></i>{{ $errors->first() }}</div>
    @endif
    @if(session('status') && !session('status.success'))
        <div class="alert alert-danger" role="alert">{{ session('status.msg') }}</div>
    @endif

    <section class="box" aria-labelledby="fleet-rate-fields-title">
        <div class="box-header"><strong id="fleet-rate-fields-title"><i class="fas fa-edit me-2" aria-hidden="true"></i>Data tarif</strong></div>
        <div class="box-body">
            <p class="rate-help"><strong>Bagaimana tarif ini dipakai?</strong> Satu tarif menghubungkan rute, satu atau beberapa unit, upah sopir per ton, uang jalan, dan harga yang ditagihkan per ton. Perubahan tarif tidak mengubah snapshot tarif pada SPB yang sudah dibuat.</p>
            @if($copyRate)
                <div class="alert alert-warning" role="alert"><i class="fas fa-code-branch me-2" aria-hidden="true"></i>Ini revisi dari tarif #{{ $copyRate->id }}. Ubah <strong>Berlaku mulai</strong> agar tidak menggantikan histori tarif lama.</div>
            @elseif($rate && $isImportedSource)
                <div class="alert alert-info" role="alert"><i class="fas fa-file-import me-2" aria-hidden="true"></i>Tarif ini berasal dari batch import. Perubahan hanya diizinkan karena belum dipakai SPB. Sumber Excel dan nomor baris asal tetap dipertahankan sebagai histori.</div>
            @endif

            <div id="rate-form-ajax-status" class="alert" role="alert" hidden></div>
            <form id="fleet-rate-form" method="POST" action="{{ $rate ? route('asset.fleet.rates.update', $rate->id) : route('asset.fleet.rates.store') }}" class="rate-ajax-form">
                @csrf
                @if($rate) @method('PUT') @endif
                <div class="row">
                    <div class="col-md-6 form-group">
                        <label for="route_name">Nama rute *</label>
                        <input id="route_name" name="route_name" type="text" class="form-control" list="fleet-rate-route-options" value="{{ old('route_name', $formRate?->route?->name) }}" placeholder="Contoh: SSP2 - EUP/GBE" required>
                        <datalist id="fleet-rate-route-options">
                            @foreach($routes as $routeOption)
                                <option value="{{ $routeOption->name }}">{{ $routeOption->origin }} → {{ $routeOption->destination }}</option>
                            @endforeach
                        </datalist>
                    </div>
                    <div class="col-md-3 form-group">
                        <label for="origin">Asal</label>
                        <input id="origin" name="origin" type="text" class="form-control" value="{{ old('origin', $formRate?->route?->origin) }}" placeholder="Opsional">
                    </div>
                    <div class="col-md-3 form-group">
                        <label for="destination">Tujuan</label>
                        <input id="destination" name="destination" type="text" class="form-control" value="{{ old('destination', $formRate?->route?->destination) }}" placeholder="Opsional">
                    </div>

                    <div class="col-md-12 form-group">
                        <label for="asset_ids">Unit yang memakai tarif *</label>
                        <select id="asset_ids" name="asset_ids[]" class="form-control select2" multiple required data-placeholder="Pilih satu atau beberapa unit" aria-describedby="asset_ids_help asset_ids_summary">
                            @foreach($assets as $asset)
                                <option value="{{ $asset->id }}" @selected(in_array($asset->id, $selectedAssetIds))>{{ $asset->name }}</option>
                            @endforeach
                        </select>
                        <small id="asset_ids_help" class="text-muted">Bisa memilih beberapa unit sekaligus. Unit dipilih dari Asset/Alat Berat pada business aktif.</small>
                        <small id="asset_ids_summary" class="asset-selection-summary" aria-live="polite"></small>
                    </div>

                    <div class="col-md-3 form-group">
                        <label for="effective_from_display">Berlaku mulai *</label>
                        <input id="effective_from_display" name="effective_from_display" type="text" class="form-control date-display" value="{{ $effectiveFromDisplay }}" placeholder="DD/MM/YYYY" inputmode="numeric" autocomplete="off" required>
                        <input id="effective_from" name="effective_from" type="hidden" value="{{ $effectiveFrom }}">
                        <small class="date-hint">Contoh: 12/09/2026</small>
                        <small class="date-error" data-for="effective_from_display">Masukkan tanggal yang valid dengan format DD/MM/YYYY.</small>
                    </div>
                    <div class="col-md-3 form-group">
                        <label for="effective_to_display">Berlaku sampai</label>
                        <input id="effective_to_display" name="effective_to_display" type="text" class="form-control date-display" value="{{ $effectiveToDisplay }}" placeholder="DD/MM/YYYY" inputmode="numeric" autocomplete="off">
                        <input id="effective_to" name="effective_to" type="hidden" value="{{ $effectiveTo }}">
                        <small class="date-hint">Kosongkan jika masih berlaku.</small>
                        <small class="date-error" data-for="effective_to_display">Masukkan tanggal yang valid dengan format DD/MM/YYYY.</small>
                    </div>
                    <div class="col-md-3 form-group">
                        <label for="driver_rate_per_ton">Borongan Supir/Ton *</label>
                        <input id="driver_rate_per_ton" name="driver_rate_per_ton" type="number" min="0" step="0.01" class="form-control text-end" value="{{ old('driver_rate_per_ton', $formRate?->driver_rate_per_ton) }}" placeholder="0" required>
                    </div>
                    <div class="col-md-3 form-group">
                        <label for="travel_allowance">Uang Jalan *</label>
                        <input id="travel_allowance" name="travel_allowance" type="number" min="0" step="0.01" class="form-control text-end" value="{{ old('travel_allowance', $formRate?->travel_allowance) }}" placeholder="0" required>
                    </div>
                    <div class="col-md-3 form-group">
                        <label for="sale_rate_per_ton">Harga/Ton</label>
                        <input id="sale_rate_per_ton" name="sale_rate_per_ton" type="number" min="0" step="0.01" class="form-control text-end" value="{{ old('sale_rate_per_ton', $formRate?->sale_rate_per_ton) }}" placeholder="Kosong bila belum ditentukan">
                    </div>
                    <div class="col-md-9 form-group">
                        <label for="notes">Keterangan</label>
                        <textarea id="notes" name="notes" class="form-control" placeholder="Catatan tarif, syarat, atau sumber keputusan">{{ old('notes', $formRate?->notes) }}</textarea>
                    </div>
                </div>

                <div class="rate-actions">
                    <a href="{{ route('asset.fleet.rates.index') }}" class="btn rate-btn-secondary">Batal</a>
                    <button type="submit" class="btn btn-success"><i class="fas fa-save me-1" aria-hidden="true"></i>{{ $rate ? 'Simpan perubahan' : ($copyRate ? 'Simpan revisi tarif' : 'Simpan tarif') }}</button>
                </div>
            </form>
        </div>
    </section>
</main>

@push('scripts')
<script>
$(function () {
    const $assetSelect = $('#asset_ids');
    const $assetSummary = $('#asset_ids_summary');

    $assetSelect.select2({
        theme: 'bootstrap-5',
        width: '100%',
        placeholder: $assetSelect.data('placeholder'),
        closeOnSelect: false,
        allowClear: true,
        minimumResultsForSearch: 0,
        dropdownParent: $('.fleet-rate-form'),
    });

    function updateAssetSummary() {
        const count = $assetSelect.val() ? $assetSelect.val().length : 0;
        $assetSummary.text(count ? count + ' unit dipilih' : 'Belum ada unit dipilih');
    }

    $assetSelect.on('change', updateAssetSummary);
    updateAssetSummary();

    function syncDate(displayId, hiddenId) {
        const display = document.getElementById(displayId);
        const hidden = document.getElementById(hiddenId);
        const error = document.querySelector('.date-error[data-for="' + displayId + '"]');
        if (!display || !hidden) return true;

        const value = display.value.trim();
        if (!value && displayId === 'effective_to_display') {
            hidden.value = '';
            display.classList.remove('is-invalid');
            if (error) error.style.display = 'none';
            return true;
        }

        const match = value.match(/^(\d{2})\/(\d{2})\/(\d{4})$/);
        if (!match) {
            hidden.value = '';
            display.classList.add('is-invalid');
            if (error) error.style.display = 'block';
            return false;
        }

        const day = Number(match[1]);
        const month = Number(match[2]);
        const year = Number(match[3]);
        const candidate = new Date(Date.UTC(year, month - 1, day));
        const valid = candidate.getUTCFullYear() === year
            && candidate.getUTCMonth() === month - 1
            && candidate.getUTCDate() === day;
        if (!valid) {
            hidden.value = '';
            display.classList.add('is-invalid');
            if (error) error.style.display = 'block';
            return false;
        }

        hidden.value = year + '-' + String(month).padStart(2, '0') + '-' + String(day).padStart(2, '0');
        display.classList.remove('is-invalid');
        if (error) error.style.display = 'none';
        return true;
    }

    $('.date-display').on('input blur change', function () {
        syncDate(this.id, this.id.replace('_display', ''));
    });
    $('.fleet-rate-form form').on('submit', function (event) {
        const fromValid = syncDate('effective_from_display', 'effective_from');
        const toValid = syncDate('effective_to_display', 'effective_to');
        if (!fromValid || !toValid) {
            event.preventDefault();
            event.stopImmediatePropagation();
            $('.date-display.is-invalid').first().trigger('focus');
        }
    });

    $('#fleet-rate-form').on('submit', function (event) {
        event.preventDefault();
        event.stopImmediatePropagation();

        const form = this;
        if (form.dataset.submitting === 'true') return;
        form.dataset.submitting = 'true';
        const $button = $(form).find('button[type="submit"]');
        const originalLabel = $button.text();
        const $status = $('#rate-form-ajax-status');
        $button.prop('disabled', true).text('Menyimpan...');
        $status.prop('hidden', true).removeClass('alert-success alert-danger').text('');

        fetch(form.action, {
            method: (form.getAttribute('method') || 'POST').toUpperCase(),
            body: new FormData(form),
            headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            },
            credentials: 'same-origin'
        }).then(function (response) {
            return response.json().catch(function () {
                return { success: false, msg: 'Server mengembalikan respons yang tidak dapat dibaca.' };
            }).then(function (data) {
                return { response: response, data: data };
            });
        }).then(function (result) {
            const data = result.data || {};
            if (!result.response.ok || data.success === false) {
                const errors = data.errors ? Object.values(data.errors).flat().join(' ') : '';
                throw new Error(errors || data.msg || 'Tarif belum berhasil disimpan.');
            }

            $status.addClass('alert-success').text(data.msg || 'Tarif berhasil disimpan.').prop('hidden', false);
            window.setTimeout(function () {
                window.location.href = "{{ route('asset.fleet.rates.index') }}";
            }, 450);
        }).catch(function (error) {
            $status.addClass('alert-danger').text(error.message || 'Tarif belum berhasil disimpan.').prop('hidden', false);
        }).finally(function () {
            form.dataset.submitting = 'false';
            $button.prop('disabled', false).text(originalLabel);
        });
    });
});
</script>
@endpush
@endsection
