@extends('layouts.app')

@section('title', 'Review Settlement Payroll')

@section('content')
@includeIf('assetmanagement::layouts.nav')
@include('layouts.partials.fleet-datatables')
<section class="content-header"><a href="{{ route('asset.fleet.settlements.index') }}" class="btn btn-default pull-right">Kembali</a><h1>Review Settlement Payroll</h1></section>
<section class="content">
    <form method="POST" action="{{ route('asset.fleet.settlements.store') }}">
        @csrf
        <input type="hidden" name="driver_id" value="{{ $driver->id }}">
        <input type="hidden" name="period_start" value="{{ $periodStart }}">
        <input type="hidden" name="period_end" value="{{ $periodEnd }}">
        <div class="box box-primary"><div class="box-body">
            <p><strong>Sopir:</strong> {{ $driver->user_full_name }} &nbsp; <strong>Periode:</strong> {{ $periodStart }} s/d {{ $periodEnd }}</p>
            @php
                $tripPayTotal = (float) $trips->sum('driver_pay_total');
                $bonusTotal = (float) $bonusAyaks->sum('bonus');
                $kasbonTotal = (float) $kasbons->sum('jumlah_kasbon');
            @endphp
            <div class="row">
                <div class="col-md-3"><div class="well well-sm"><strong>Upah Trip</strong><br><span id="settlement-trip-total">@format_currency($tripPayTotal)</span></div></div>
                <div class="col-md-3"><div class="well well-sm"><strong>Bonus Ayak</strong><br><span id="settlement-bonus-total">@format_currency($bonusTotal)</span></div></div>
                <div class="col-md-3"><div class="well well-sm"><strong>Bruto</strong><br><span id="settlement-gross-total">@format_currency($tripPayTotal + $bonusTotal)</span></div></div>
                <div class="col-md-3"><div class="well well-sm"><strong>Netto setelah Kasbon</strong><br><span id="settlement-net-total">@format_currency($tripPayTotal + $bonusTotal - $kasbonTotal)</span></div></div>
            </div>
            <input type="hidden" name="trip_ids_present" value="1">
            <h4>SPB Verified</h4>
            <div class="table-responsive fleet-datatable-wrap fleet-datatable-scroll"><table id="fleet-settlement-trips-table" class="table table-bordered fleet-datatable"><thead><tr><th><input type="checkbox" onclick="document.querySelectorAll('.trip-source').forEach(x => x.checked = this.checked); refreshSettlementTotals()" aria-label="Pilih semua SPB"></th><th>SPB</th><th>Tanggal</th><th>Project</th><th>Unit</th><th>Ton</th><th>Upah</th></tr></thead><tbody>@forelse($trips as $trip)<tr><td><input class="trip-source" type="checkbox" data-amount="{{ (float) $trip->driver_pay_total }}" name="trip_ids[]" value="{{ $trip->id }}" checked></td><td>{{ $trip->trip_no }}</td><td>{{ optional($trip->trip_date)->format('d-m-Y') }}</td><td>{{ optional($trip->project)->name ?: '-' }}</td><td>{{ optional($trip->asset)->name ?: '-' }}</td><td>{{ number_format($trip->actual_tons, 2, ',', '.') }}</td><td>@format_currency($trip->driver_pay_total)</td></tr>@empty<tr><td colspan="7" class="text-center">Tidak ada SPB eligible.</td></tr>@endforelse</tbody></table></div>
            <input type="hidden" name="bonus_ids_present" value="1">
            <h4>Bonus Ayak</h4>
            <div class="table-responsive fleet-datatable-wrap fleet-datatable-scroll"><table id="fleet-settlement-bonus-table" class="table table-bordered fleet-datatable"><thead><tr><th><input type="checkbox" onclick="document.querySelectorAll('.bonus-source').forEach(x => x.checked = this.checked); refreshSettlementTotals()" aria-label="Pilih semua Bonus Ayak"></th><th>Tanggal</th><th>Project</th><th>Unit</th><th>Trip</th><th>Bonus</th></tr></thead><tbody>@forelse($bonusAyaks as $bonus)<tr><td><input class="bonus-source" type="checkbox" data-amount="{{ (float) $bonus->bonus }}" name="bonus_ids[]" value="{{ $bonus->id }}" checked></td><td>{{ optional($bonus->tanggal)->format('d-m-Y') }}</td><td>{{ optional($bonus->project)->name ?: '-' }}</td><td>{{ optional($bonus->asset)->name ?: $bonus->unit }}</td><td>{{ $bonus->trip }}</td><td>@format_currency($bonus->bonus)</td></tr>@empty<tr><td colspan="6" class="text-center">Tidak ada Bonus Ayak eligible.</td></tr>@endforelse</tbody></table></div>
            <input type="hidden" name="kasbon_ids_present" value="1">
            <h4>Kasbon sebagai potongan</h4>
            <div class="table-responsive fleet-datatable-wrap fleet-datatable-scroll"><table id="fleet-settlement-kasbon-table" class="table table-bordered fleet-datatable"><thead><tr><th><input type="checkbox" onclick="document.querySelectorAll('.kasbon-source').forEach(x => x.checked = this.checked); refreshSettlementTotals()" aria-label="Pilih semua Kasbon"></th><th>Tanggal</th><th>Driver</th><th>Nominal</th></tr></thead><tbody>@forelse($kasbons as $kasbon)<tr><td><input class="kasbon-source" type="checkbox" data-amount="{{ (float) $kasbon->jumlah_kasbon }}" name="kasbon_ids[]" value="{{ $kasbon->id }}" checked></td><td>{{ optional($kasbon->tanggal)->format('d-m-Y') }}</td><td>{{ $kasbon->nama_operator }}</td><td>@format_currency($kasbon->jumlah_kasbon)</td></tr>@empty<tr><td colspan="4" class="text-center">Tidak ada Kasbon eligible.</td></tr>@endforelse</tbody></table></div>
            <button type="submit" class="btn btn-primary" id="submit-settlement-draft">Buat Settlement Draft</button>
            <a href="{{ route('asset.fleet.settlements.index') }}" class="btn btn-default">Batal</a>
        </div></div>
    </form>
</section>
@push('scripts')
<script>
    $(function () {
        var selectionTableOptions = {
            paging: false,
            info: false,
            responsive: true,
            searching: true,
            columnDefs: [{ targets: 0, orderable: false, searchable: false }]
        };
        initFleetDataTable('#fleet-settlement-trips-table', selectionTableOptions);
        initFleetDataTable('#fleet-settlement-bonus-table', selectionTableOptions);
        initFleetDataTable('#fleet-settlement-kasbon-table', selectionTableOptions);
    });

    function refreshSettlementTotals() {
        const sum = selector => Array.from(document.querySelectorAll(selector + ':checked')).reduce((total, input) => total + (parseFloat(input.dataset.amount) || 0), 0);
        const trip = sum('.trip-source');
        const bonus = sum('.bonus-source');
        const kasbon = sum('.kasbon-source');
        const format = value => 'Rp ' + new Intl.NumberFormat('id-ID').format(value);
        document.getElementById('settlement-trip-total').textContent = format(trip);
        document.getElementById('settlement-bonus-total').textContent = format(bonus);
        document.getElementById('settlement-gross-total').textContent = format(trip + bonus);
        document.getElementById('settlement-net-total').textContent = format(trip + bonus - kasbon);
    }
    document.querySelectorAll('.trip-source, .bonus-source, .kasbon-source').forEach(input => input.addEventListener('change', refreshSettlementTotals));
    refreshSettlementTotals();
</script>
@endpush
@endsection
