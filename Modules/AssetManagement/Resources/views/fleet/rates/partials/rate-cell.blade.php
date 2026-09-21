@php
    $rowIssue = $rowIssue ?? ['errors' => [], 'warnings' => []];
    $hasRowError = count($rowIssue['errors'] ?? []) > 0;
    $hasRowWarning = ! $hasRowError && count($rowIssue['warnings'] ?? []) > 0;
    $unmatchedUnits = $rate->status === 'draft'
        ? $rate->units->filter(fn ($unit) => ! $unit->asset_id)
        : collect();
@endphp

@if($column === 'route')
    <td>
        {{ optional($rate->route)->name ?: '-' }}<br>
        <small class="text-muted">{{ optional($rate->route)->origin }}{{ optional($rate->route)->origin && optional($rate->route)->destination ? ' → ' : '' }}{{ optional($rate->route)->destination }}</small>
    </td>
@elseif($column === 'unit')
    <td>
        @foreach($rate->units as $unit)
            <div>
                {{ $unit->unit_label }}
                @if($unit->asset)
                    → {{ $unit->asset->name }}
                @elseif($rate->status === 'draft')
                    <span class="label label-danger">Belum cocok</span>
                @else
                    → BELUM COCOK
                @endif
            </div>
        @endforeach

        @if($unmatchedUnits->isNotEmpty())
            <div class="rate-bulk-map">
                <form method="POST" action="{{ route('asset.fleet.rates.units.bulk-map', $rate->id) }}" class="rate-bulk-map__form rate-ajax-form">
                    @csrf
                    <label class="rate-bulk-map__label" for="rate-assets-{{ $rate->id }}">Cocokkan beberapa unit sekaligus</label>
                    <select id="rate-assets-{{ $rate->id }}"
                            name="asset_ids[]"
                            class="rate-bulk-asset-select"
                            multiple
                            required
                            data-placeholder="Pilih Asset"
                            data-ajax-url="{{ route('asset.fleet.rates.assets.options') }}"
                            data-max-units="{{ $unmatchedUnits->count() }}"></select>
                    <small class="rate-bulk-map__order">
                        Urutan pencocokan: {{ $unmatchedUnits->pluck('unit_label')->implode(' → ') }}. Asset pilihan pertama akan dipasangkan dengan unit pertama.
                    </small>
                    <small class="rate-bulk-map__limit" aria-live="polite">Maksimal {{ $unmatchedUnits->count() }} Asset dapat dipilih pada baris ini.</small>
                    <button type="submit" class="btn btn-primary rate-bulk-map__button" disabled>Pilih Asset</button>
                </form>
            </div>
        @endif
    </td>
@elseif($column === 'effective')
    <td>{{ optional($rate->effective_from)->format('d/m/Y') }} s/d {{ optional($rate->effective_to)->format('d/m/Y') ?: 'sekarang' }}</td>
@elseif($column === 'driver_rate')
    <td>@format_currency($rate->driver_rate_per_ton)</td>
@elseif($column === 'travel_allowance')
    <td>@format_currency($rate->travel_allowance)</td>
@elseif($column === 'sale_rate')
    <td>@if($rate->sale_rate_per_ton !== null) @format_currency($rate->sale_rate_per_ton) @else <span class="label label-warning">Kosong</span> @endif</td>
@elseif($column === 'notes')
    <td>{{ $rate->notes }}</td>
@elseif($column === 'status')
    <td>
        <span class="label label-{{ $rate->status === 'active' ? 'success' : ($rate->status === 'draft' ? 'warning' : ($rate->status === 'cancelled' ? 'default' : 'info')) }}">{{ strtoupper($rate->status) }}</span>
        @if($hasRowError)
            <br><span class="rate-issue-badge" title="Baris ini perlu diperiksa"><i class="fas fa-triangle-exclamation" aria-hidden="true"></i> Perlu diperiksa</span>
        @elseif($hasRowWarning)
            <br><span class="rate-warning-badge" title="Baris ini memiliki catatan"><i class="fas fa-circle-info" aria-hidden="true"></i> Ada catatan</span>
        @endif
        @if($rate->source_status === 'modified')
            <br><span class="label label-warning" title="Nilai pernah dikoreksi dari sumber import">Diedit dari import</span>
        @endif
    </td>
@elseif($column === 'actions')
    <td>
        @php($isManualRate = str_starts_with((string) optional($rate->batch)->file_name, 'Manual - '))
        <div class="rate-actions">
            @if($rate->status === 'cancelled')
                <span class="rate-locked" title="Tarif dibatalkan tetapi metadata sumber tetap dipertahankan."><i class="fas fa-ban" aria-hidden="true"></i> Dibatalkan</span>
            @elseif(($rate->trips_count ?? 0) > 0)
                <span class="rate-locked" title="Tarif sudah dipakai SPB dan tidak dapat diubah."><i class="fas fa-lock" aria-hidden="true"></i> Terkunci SPB</span>
                @if($canManageRates)
                    <a href="{{ route('asset.fleet.rates.create', ['from_rate' => $rate->id]) }}" class="btn rate-secondary-btn rate-crud-btn" aria-label="Buat revisi tarif {{ $rate->id }}" title="Buat tarif baru berdasarkan tarif ini"><i class="fas fa-code-branch" aria-hidden="true"></i> Revisi</a>
                @endif
            @elseif($canManageRates)
                <a href="{{ route('asset.fleet.rates.edit', $rate->id) }}" class="btn btn-warning rate-crud-btn" aria-label="Edit tarif {{ $rate->id }}"><i class="fas fa-edit" aria-hidden="true"></i> Edit</a>
                <form method="POST" action="{{ route('asset.fleet.rates.destroy', $rate->id) }}" class="rate-ajax-form">
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
@endif
