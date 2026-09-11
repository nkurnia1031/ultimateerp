@extends('layouts.app')

@section('title', 'Tarif Angkutan')

@section('content')
@includeIf('assetmanagement::layouts.nav')
@include('layouts.partials.fleet-datatables')
@php
    $canManageRates = auth()->user()->can('superadmin')
        || auth()->user()->can('asset.fleet_rates.manage')
        || auth()->user()->can('asset.create')
        || auth()->user()->can('asset.update');
@endphp
<style>
    .fleet-rate-index { max-width: 1480px; margin: 0 auto; padding-bottom: 40px; }
    .fleet-rate-index__header { display: flex; justify-content: space-between; align-items: flex-end; gap: 20px; margin: 0 0 18px; }
    .fleet-rate-index__header h1 { margin-bottom: 6px; }
    .fleet-rate-index__header p { margin: 0; color: #60717b; }
    .fleet-rate-index__actions { display: flex; flex-wrap: wrap; justify-content: flex-end; gap: 8px; }
    .fleet-rate-index .rate-crud-btn, .fleet-rate-index .rate-secondary-btn { display: inline-flex; align-items: center; justify-content: center; gap: 6px; min-height: 40px; padding: 8px 13px; border-radius: 6px; white-space: nowrap; }
    .fleet-rate-index .rate-secondary-btn { color: #334e5b; background: #f7fafb; border: 1px solid #b8cbd0; }
    .fleet-rate-index .rate-secondary-btn:hover, .fleet-rate-index .rate-secondary-btn:focus { background: #e8f0f1; border-color: #8faeb5; }
    .fleet-rate-index .rate-table th { white-space: nowrap; }
    .fleet-rate-index .rate-table td { vertical-align: middle; }
    .fleet-rate-index .rate-date-hint { display: block; margin-top: 5px; color: #6b7d85; font-size: 12px; }
    .fleet-rate-index .rate-date-error { display: none; margin-top: 5px; color: #b42318; font-size: 12px; }
    .fleet-rate-index .rate-date-display.is-invalid { border-color: #d64545; box-shadow: 0 0 0 3px rgba(214,69,69,.12); }
    .fleet-rate-index .rate-actions { display: flex; align-items: center; justify-content: center; gap: 6px; flex-wrap: wrap; min-width: 160px; }
    .fleet-rate-index .rate-actions form { display: inline-flex; margin: 0; }
    .fleet-rate-index .rate-actions .btn { min-height: 40px; white-space: nowrap; }
    .fleet-rate-index .rate-locked { display: inline-flex; align-items: center; gap: 5px; min-height: 32px; padding: 5px 9px; border: 1px solid #d5dde0; border-radius: 999px; background: #eef1f2; color: #53616a; font-size: 11px; font-weight: 700; white-space: nowrap; }
    .fleet-rate-index a:focus-visible, .fleet-rate-index button:focus-visible, .fleet-rate-index input:focus-visible, .fleet-rate-index select:focus-visible { outline: 3px solid #f0b85b !important; outline-offset: 3px; }
    @media (max-width: 700px) { .fleet-rate-index__header { display: block; } .fleet-rate-index__actions { justify-content: flex-start; margin-top: 14px; } .fleet-rate-index__actions .btn { width: 100%; } .fleet-rate-index .box-body { padding: 15px; } .fleet-rate-index .table-responsive { margin: 0 -3px; } }
</style>
<main class="fleet-rate-index">
<section class="content-header">
    <div class="fleet-rate-index__header">
        <div>
            <h1><i class="fas fa-road" aria-hidden="true"></i> Tarif Angkutan <small>Master tarif dan histori berlaku</small></h1>
            <p>Import workbook untuk banyak baris, atau buat satu tarif secara manual dari halaman ini.</p>
        </div>
        @if($canManageRates)
            <div class="fleet-rate-index__actions" aria-label="Aksi master tarif">
                <a href="{{ route('asset.fleet.rates.create') }}" class="btn btn-success rate-crud-btn" aria-label="Tambah tarif angkutan manual"><i class="fas fa-plus" aria-hidden="true"></i> Tambah Tarif Manual</a>
            </div>
        @endif
    </div>
</section>
<section class="content">
    @if(session('status'))
        <div class="alert {{ session('status.success') ? 'alert-success' : 'alert-danger' }}" role="alert">
            <i class="fas {{ session('status.success') ? 'fa-check-circle' : 'fa-exclamation-triangle' }} me-2" aria-hidden="true"></i>{{ session('status.msg') }}
        </div>
    @endif
    <div class="box box-primary">
        <div class="box-header with-border"><h3 class="box-title"><i class="fas fa-file-import me-2" aria-hidden="true"></i>Import Workbook Tarif</h3></div>
        <div class="box-body">
            <p class="text-muted">Workbook akan menjadi draft preview. <strong>Berlaku mulai</strong> wajib diisi manual; tanggal pada keterangan Excel tidak ditebak oleh sistem. Untuk satu atau beberapa unit, gunakan <strong>Tambah Tarif Manual</strong>.</p>
            <form id="fleet-rate-upload-form" method="POST" action="{{ route('asset.fleet.rates.upload') }}" enctype="multipart/form-data">
                @csrf
                <div class="row">
                    <div class="col-md-5 form-group">
                        <label>File Excel tarif *</label>
                        <input type="file" name="file" class="form-control" accept=".xlsx,.xls,.csv" required>
                    </div>
                    <div class="col-md-3 form-group">
                        <label for="rate_effective_from_display">Berlaku mulai *</label>
                        <input id="rate_effective_from_display" name="effective_from_display" type="text" class="form-control rate-date-display" value="{{ old('effective_from_display') }}" placeholder="DD/MM/YYYY" inputmode="numeric" autocomplete="off" required>
                        <input id="rate_effective_from" name="effective_from" type="hidden" value="{{ old('effective_from') }}">
                        <small class="rate-date-hint">Contoh: 01/01/2026</small>
                        <small class="rate-date-error">Masukkan tanggal yang valid dengan format DD/MM/YYYY.</small>
                    </div>
                    <div class="col-md-4 form-group" style="padding-top: 25px;">
                        <button class="btn btn-primary rate-crud-btn"><i class="fas fa-upload" aria-hidden="true"></i> Upload &amp; Preview</button>
                        <a href="{{ route('asset.fleet.rates.export') }}" class="btn btn-success rate-crud-btn"><i class="fas fa-file-excel" aria-hidden="true"></i> Export Aktif</a>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <div class="box box-solid">
        <div class="box-header with-border"><h3 class="box-title">Batch Import</h3></div>
        <div class="box-body table-responsive fleet-datatable-wrap">
            <table id="fleet-rate-batches-table" class="table table-bordered table-striped fleet-datatable">
                <thead><tr><th>ID</th><th>File</th><th>Berlaku mulai</th><th>Baris</th><th>Rute</th><th>Unit</th><th>Status</th><th>Aksi</th></tr></thead>
                <tbody>
                @forelse($batches as $batch)
                    <tr>
                        <td>{{ $batch->id }}</td><td>{{ $batch->file_name }}</td>
                        <td>{{ optional($batch->effective_from)->format('d/m/Y') }}</td>
                        <td>{{ $batch->valid_row_count }}/{{ $batch->source_row_count }}</td>
                        <td>{{ $batch->route_count }}</td><td>{{ $batch->unit_count }}</td>
                        <td><span class="label label-{{ $batch->status === 'active' ? 'success' : ($batch->status === 'draft' ? 'warning' : 'default') }}">{{ strtoupper($batch->status) }}</span>
                            @if($batch->error_message)
                                @php
                                    $batchErrors = json_decode($batch->error_message, true);
                                    $batchErrors = is_array($batchErrors) ? $batchErrors : [$batch->error_message];
                                    $batchErrorId = 'fleet-rate-batch-error-'.$batch->id;
                                @endphp
                                <button type="button" class="fleet-table-detail-toggle" aria-expanded="false" aria-controls="{{ $batchErrorId }}">Lihat detail</button>
                                <div id="{{ $batchErrorId }}" class="fleet-table-detail" hidden>{{ implode(' ', $batchErrors) }}</div>
                            @endif
                        </td>
                        <td>
                            @if($batch->status === 'draft')
                                <form method="POST" action="{{ route('asset.fleet.rates.confirm', $batch->id) }}" style="display:inline" onsubmit="return confirm('Aktifkan batch tarif ini?')">@csrf<button class="btn btn-success rate-crud-btn" aria-label="Konfirmasi batch tarif">Konfirmasi Aktif</button></form>
                                <form method="POST" action="{{ route('asset.fleet.rates.cancel', $batch->id) }}" style="display:inline" onsubmit="return confirm('Batalkan batch ini?')">@csrf<button class="btn btn-danger rate-crud-btn" aria-label="Batalkan batch tarif">Batal</button></form>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="text-center">Belum ada batch tarif.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="box box-solid">
        <div class="box-header with-border"><h3 class="box-title">Tarif Aktif, Preview, dan Histori</h3></div>
        <div class="box-body table-responsive fleet-datatable-wrap">
            <table id="fleet-rates-table" class="table table-bordered table-striped rate-table fleet-datatable">
                <thead><tr><th>Rute</th><th>Unit</th><th>Berlaku</th><th>Supir/Ton</th><th>Uang Jalan</th><th>Harga/Ton</th><th>Keterangan</th><th>Status</th><th>Aksi</th></tr></thead>
                <tbody>
                @forelse($rates as $rate)
                    <tr>
                        <td>{{ optional($rate->route)->name ?: '-' }}<br><small class="text-muted">{{ optional($rate->route)->origin }}{{ optional($rate->route)->origin && optional($rate->route)->destination ? ' → ' : '' }}{{ optional($rate->route)->destination }}</small></td>
                        <td>
                            @foreach($rate->units as $unit)
                                <div>
                                    {{ $unit->unit_label }}
                                    @if($unit->asset)
                                        → {{ $unit->asset->name }}
                                    @elseif($rate->status === 'draft')
                                        <span class="label label-danger">Belum cocok</span>
                                        <form method="POST" action="{{ route('asset.fleet.rates.units.map', $unit->id) }}" style="display:inline;margin-left:5px">
                                            @csrf
                                            <select name="asset_id" onchange="this.form.submit()" style="max-width:180px">
                                                <option value="">Pilih Asset</option>
                                                @foreach($allAssets as $assetId => $assetName)
                                                    <option value="{{ $assetId }}">{{ $assetName }}</option>
                                                @endforeach
                                            </select>
                                        </form>
                                    @else
                                        → BELUM COCOK
                                    @endif
                                </div>
                            @endforeach
                        </td>
                        <td>{{ optional($rate->effective_from)->format('d/m/Y') }} s/d {{ optional($rate->effective_to)->format('d/m/Y') ?: 'sekarang' }}</td>
                        <td>@format_currency($rate->driver_rate_per_ton)</td>
                        <td>@format_currency($rate->travel_allowance)</td>
                        <td>@if($rate->sale_rate_per_ton !== null) @format_currency($rate->sale_rate_per_ton) @else <span class="label label-warning">Kosong</span> @endif</td>
                        <td>{{ $rate->notes }}</td>
                        <td>
                            <span class="label label-{{ $rate->status === 'active' ? 'success' : ($rate->status === 'draft' ? 'warning' : ($rate->status === 'cancelled' ? 'default' : 'info')) }}">{{ strtoupper($rate->status) }}</span>
                            @if($rate->source_status === 'modified')
                                <br><span class="label label-warning" title="Nilai pernah dikoreksi dari sumber import">Diedit dari import</span>
                            @endif
                        </td>
                        <td>
                            @php($isManualRate = str_starts_with((string) optional($rate->batch)->file_name, 'Manual - '))
                            <div class="rate-actions">
                                @if($rate->status === 'cancelled')
                                    <span class="rate-locked" title="Tarif dibatalkan tetapi metadata sumber tetap dipertahankan."><i class="fas fa-ban" aria-hidden="true"></i> Dibatalkan</span>
                                @elseif($rate->trips->isNotEmpty())
                                    <span class="rate-locked" title="Tarif sudah dipakai SPB dan tidak dapat diubah."><i class="fas fa-lock" aria-hidden="true"></i> Terkunci SPB</span>
                                    @if($canManageRates)
                                        <a href="{{ route('asset.fleet.rates.create', ['from_rate' => $rate->id]) }}" class="btn rate-secondary-btn rate-crud-btn" aria-label="Buat revisi tarif {{ $rate->id }}" title="Buat tarif baru berdasarkan tarif ini"><i class="fas fa-code-branch" aria-hidden="true"></i> Revisi</a>
                                    @endif
                                @elseif($canManageRates)
                                    <a href="{{ route('asset.fleet.rates.edit', $rate->id) }}" class="btn btn-warning rate-crud-btn" aria-label="Edit tarif {{ $rate->id }}"><i class="fas fa-edit" aria-hidden="true"></i> Edit</a>
                                    <form method="POST" action="{{ route('asset.fleet.rates.destroy', $rate->id) }}">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="btn btn-danger rate-crud-btn" aria-label="{{ $isManualRate ? 'Hapus' : 'Batalkan' }} tarif {{ $rate->id }}" onclick="return confirm('{{ $isManualRate ? 'Hapus tarif manual ini?' : 'Batalkan tarif hasil import ini? Data sumber tetap disimpan sebagai histori.' }}')"><i class="fas {{ $isManualRate ? 'fa-trash' : 'fa-ban' }}" aria-hidden="true"></i> {{ $isManualRate ? 'Hapus' : 'Batalkan' }}</button>
                                    </form>
                                    @if(!$isManualRate)
                                        <small class="text-muted rate-source-note">Import dapat dikoreksi karena belum dipakai SPB.</small>
                                    @endif
                                @else
                                    <span class="text-muted small">Tidak ada akses pengelolaan</span>
                                @endif
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="9" class="text-center">Belum ada tarif aktif.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>
</section>
</main>
@push('scripts')
<script>
$(function () {
    initFleetDataTable('#fleet-rate-batches-table', {
        order: [[2, 'desc']],
        columnDefs: [{ targets: [0, 3, 4, 5, 6, 7], orderable: false }]
    });
    initFleetDataTable('#fleet-rates-table', {
        order: [[2, 'desc']],
        columnDefs: [{ targets: [1, 6, 7, 8], orderable: false }]
    });

    function syncRateDate() {
        const display = document.getElementById('rate_effective_from_display');
        const hidden = document.getElementById('rate_effective_from');
        const error = document.querySelector('.rate-date-error');
        if (!display || !hidden) return true;

        const match = display.value.trim().match(/^(\d{2})\/(\d{2})\/(\d{4})$/);
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

    $('.rate-date-display').on('input blur change', syncRateDate);
    $('#fleet-rate-upload-form').on('submit', function (event) {
        if (!syncRateDate()) {
            event.preventDefault();
            event.stopImmediatePropagation();
            $('#rate_effective_from_display').trigger('focus');
        }
    });
});
</script>
@endpush
@endsection
