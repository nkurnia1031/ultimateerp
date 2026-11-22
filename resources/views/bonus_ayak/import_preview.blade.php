@extends('layouts.app')

@section('title', 'Preview Import Bonus Ayak')

@section('css')
@include('bonus_ayak.partials.ui')
<style>
    .bonus-import-preview { max-width: 1320px; margin: 0 auto; padding: 24px 30px 48px; }
    .bonus-import-preview__header { display: flex; justify-content: space-between; gap: 20px; align-items: flex-end; margin-bottom: 20px; }
    .bonus-import-preview__header h1 { margin: 0 0 7px; color: #162b3a; font-size: clamp(25px, 3vw, 36px); letter-spacing: -.035em; }
    .bonus-import-preview__header p { margin: 0; color: #60717b; line-height: 1.6; }
    .bonus-import-preview .card { overflow: visible; border: 1px solid #dce6e8; border-radius: 12px; background: #fff; box-shadow: 0 10px 26px rgba(22,43,58,.07); }
    .bonus-import-preview .card-header { min-height: 44px; padding: 12px 18px !important; background: #f7faf9 !important; border-bottom: 1px solid #dce6e8; border-radius: 11px 11px 0 0; color: #1d3b48; line-height: 1.25; }
    .bonus-import-preview .card-body { padding: 20px !important; }
    .bonus-import-preview .preview-note { margin: 0 0 17px; padding: 13px 15px; color: #536971; background: #f2f8f7; border-left: 3px solid #0f8b8d; border-radius: 0 8px 8px 0; line-height: 1.6; }
    .bonus-import-preview .metric-grid { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 12px; margin-bottom: 18px; }
    .bonus-import-preview .metric { padding: 14px 16px; border: 1px solid #dce6e8; border-radius: 10px; background: #fff; }
    .bonus-import-preview .metric span { display: block; color: #73858d; font-size: 12px; }
    .bonus-import-preview .metric strong { display: block; margin-top: 3px; color: #162b3a; font-size: 22px; font-variant-numeric: tabular-nums; }
    .bonus-import-preview .metric--success { background: #eef8f6; border-color: #b9ded8; }
    .bonus-import-preview table td, .bonus-import-preview table th { vertical-align: middle; }
    .bonus-import-preview .number { text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; }
    .bonus-import-preview .preview-actions { display: flex; justify-content: flex-end; gap: 9px; flex-wrap: wrap; margin-top: 18px; }
    .bonus-import-preview a:focus-visible, .bonus-import-preview button:focus-visible, .bonus-import-preview input:focus-visible { outline: 3px solid #f0b85b; outline-offset: 3px; }
    @media (max-width: 640px) { .bonus-import-preview { padding: 18px 12px 34px; } .bonus-import-preview__header { display: block; } .bonus-import-preview__header .btn { width: 100%; margin-top: 15px; } .bonus-import-preview .metric-grid { grid-template-columns: 1fr; } .bonus-import-preview .preview-actions { display: grid; grid-template-columns: 1fr; } .bonus-import-preview .preview-actions .btn { width: 100%; } }
</style>
@endsection

@section('content')
@include('layouts.partials.fleet-datatables')
@php
    $entries = collect($preview['entries'] ?? []);
    $validEntries = $entries->filter(fn ($entry) => ! empty($entry['valid']));
    $invalidEntries = $entries->reject(fn ($entry) => ! empty($entry['valid']));
    $errorsList = collect($preview['errors'] ?? []);
    $totalRows = $entries->count() + $errorsList->count();
    $issueRows = $invalidEntries->count() + $errorsList->count();
@endphp
<main class="bonus-ui bonus-import-preview" aria-labelledby="bonus-import-preview-title">
    <header class="bonus-import-preview__header">
        <div>
            <p class="text-uppercase text-info small fw-bold mb-2">Pemeriksaan sebelum simpan</p>
            <h1 id="bonus-import-preview-title"><i class="fa fa-search me-2" aria-hidden="true"></i>Preview Import Bonus Ayak</h1>
            <p>File <strong>{{ $preview['original_name'] ?? 'upload' }}</strong> belum mengubah data. Centang baris valid yang ingin disimpan.</p>
        </div>
        <a href="{{ route('bonus-ayak.index') }}" class="btn bonus-btn-secondary" aria-label="Kembali ke daftar import"><i class="fa fa-arrow-left me-1" aria-hidden="true"></i>Kembali ke daftar import</a>
    </header>

    @if(session('success'))
        <div class="alert alert-success" role="alert">{{ session('success') }}</div>
    @endif
    @if(session('error'))
        <div class="alert alert-danger" role="alert">{{ session('error') }}</div>
    @endif
    @if($errors->any())
        <div class="alert alert-danger" role="alert">{{ $errors->first() }}</div>
    @endif

    <p class="preview-note"><strong>Yang dilakukan halaman ini:</strong> sistem mencocokkan sopir dan unit ke business aktif, menghitung Bonus dari Trip, lalu menandai baris yang kosong, tidak cocok, atau duplikat. Database baru ditulis setelah Anda menekan <strong>Simpan baris terpilih</strong>.</p>

    <section class="metric-grid" aria-label="Ringkasan preview import">
        <div class="metric"><span>Total baris terbaca</span><strong>{{ $totalRows }}</strong></div>
        <div class="metric metric--success"><span>Siap disimpan</span><strong>{{ $validEntries->count() }}</strong></div>
        <div class="metric"><span>Perlu diperbaiki/dilewati</span><strong>{{ $issueRows }}</strong></div>
    </section>

    @if($errorsList->isNotEmpty())
        <section class="card mb-3" aria-labelledby="bonus-import-errors-title">
            <div class="card-header fw-bold text-danger" id="bonus-import-errors-title"><i class="fa fa-exclamation-triangle me-2" aria-hidden="true"></i>Pesan per baris</div>
            <div class="card-body">
                <ul class="mb-0">
                    @foreach($errorsList as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        </section>
    @endif

    <section class="card" aria-labelledby="bonus-import-rows-title">
        <div class="card-header fw-bold" id="bonus-import-rows-title"><i class="fa fa-table me-2" aria-hidden="true"></i>Baris hasil pemeriksaan</div>
        <div class="card-body table-responsive fleet-datatable-wrap">
            <form id="bonus-import-confirm-form" method="POST" action="{{ route('bonus-ayak.import-confirm', $preview['token']) }}">
                @csrf
                <table id="bonus-ayak-import-preview-table" class="table table-bordered table-striped align-middle fleet-datatable">
                    <thead class="table-light">
                        <tr>
                            <th scope="col" class="text-center">Simpan</th>
                            <th scope="col">Baris</th>
                            <th scope="col">Tanggal</th>
                            <th scope="col">Sopir</th>
                            <th scope="col">Unit</th>
                            <th scope="col" class="text-center">Trip</th>
                            <th scope="col" class="text-end">Bonus</th>
                            <th scope="col">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($entries as $entry)
                            <tr>
                                <td class="text-center">
                                    <input type="checkbox" name="entries[]" value="{{ $entry['preview_index'] }}" @checked(!empty($entry['valid'])) @disabled(empty($entry['valid'])) aria-label="Simpan baris {{ $entry['source_row'] }}">
                                </td>
                                <td>{{ $entry['source_row'] }}</td>
                                <td>{{ date('d/m/Y', strtotime($entry['tanggal'])) }}</td>
                                <td>{{ $entry['nama_operator'] }}</td>
                                <td>{{ $entry['unit'] }}</td>
                                <td class="text-center">{{ $entry['trip'] }}</td>
                                <td class="number">Rp {{ number_format($entry['bonus'], 0, ',', '.') }}</td>
                                <td>
                                    @if(!empty($entry['valid']))
                                        <span class="badge bg-success">Siap disimpan</span>
                                    @else
                                        <span class="badge bg-danger"><span data-import-history-message-source>{{ $entry['error'] ?? 'Tidak valid' }}</span></span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="8" class="text-center text-muted">Tidak ada baris yang dapat dipreview.</td></tr>
                        @endforelse
                    </tbody>
                </table>
                <div class="preview-actions">
                    <button type="submit" class="btn btn-success" aria-label="Simpan baris terpilih" @disabled($validEntries->isEmpty())><i class="fa fa-check me-1" aria-hidden="true"></i>Simpan baris terpilih</button>
                </div>
            </form>
            <form method="POST" action="{{ route('bonus-ayak.import-cancel', $preview['token']) }}" class="preview-actions">
                @csrf
                <button type="submit" class="btn bonus-btn-secondary" aria-label="Batalkan preview import"><i class="fa fa-times me-1" aria-hidden="true"></i>Batalkan preview</button>
            </form>
        </div>
    </section>
</main>
@push('scripts')
<script>
$(function () {
    initFleetDataTable('#bonus-ayak-import-preview-table', {
        order: [[1, 'asc']],
        columnDefs: [{ targets: [0, 7], orderable: false }]
    });
});
</script>
@endpush
@endsection
