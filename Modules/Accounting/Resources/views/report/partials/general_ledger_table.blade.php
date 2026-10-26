<table class="table table-bordered table-striped general-ledger-table">
    <thead>
        @if(!empty($isExport))
            <tr><th colspan="9" class="report-title">{{ $businessName }}</th></tr>
            <tr><th colspan="9" class="report-title">BUKU BESAR</th></tr>
            <tr><th colspan="9" class="report-period">PERIODE {{ \Carbon::parse($start_date)->format('d/m/Y') }} — {{ \Carbon::parse($end_date)->format('d/m/Y') }}</th></tr>
        @endif
        <tr>
            <th rowspan="2">Tanggal</th>
            <th colspan="2" class="text-center">Keterangan</th>
            <th colspan="3" class="text-center">Detail</th>
            <th rowspan="2" class="text-right">Debit</th>
            <th rowspan="2" class="text-right">Kredit</th>
            <th rowspan="2" class="text-right">Saldo Akhir</th>
        </tr>
        <tr>
            <th>Transaksi</th>
            <th>Nomor / Catatan</th>
            <th>Produk</th>
            <th>Supplier / Customer</th>
            <th>Plat</th>
        </tr>
    </thead>
    <tbody>
        @php $lastAccountId = null; @endphp
        @forelse($reportRows as $row)
            @if($lastAccountId !== $row['account_id'])
                <tr class="account-heading">
                    <th colspan="9">
                        Akun: {{ $row['account_name'] }}
                        @if(!empty($row['gl_code'])) <span class="account-code">({{ $row['gl_code'] }})</span> @endif
                    </th>
                </tr>
                @php $lastAccountId = $row['account_id']; @endphp
            @endif
            <tr class="{{ !empty($row['is_opening_balance']) ? 'opening-balance-row' : '' }}">
                <td>{{ \Carbon::parse($row['operation_date'])->format('d/m/Y') }}</td>
                <td>
                    <strong>{{ $row['description'] ?? '' }}</strong>
                    @if(!empty($row['reference']))<div>{{ $row['reference'] }}</div>@endif
                </td>
                <td>{{ $row['note'] ?? '' }}</td>
                <td>
                    {{ $row['product'] ?? '' }}
                    @if(isset($row['quantity']))
                        <span class="product-quantity">× {{ number_format((float) $row['quantity'], session('business.quantity_precision', 2), $decimalSeparator, $thousandSeparator) }} {{ $row['unit'] ?? '' }}</span>
                    @endif
                </td>
                <td>{{ $row['contact'] ?? '' }}</td>
                <td>{{ $row['plate'] ?? '' }}</td>
                <td class="text-right">
                    @if((float) $row['debit'] != 0)
                        {{ number_format((float) $row['debit'], $currencyPrecision, $decimalSeparator, $thousandSeparator) }}
                    @endif
                </td>
                <td class="text-right">
                    @if((float) $row['credit'] != 0)
                        {{ number_format((float) $row['credit'], $currencyPrecision, $decimalSeparator, $thousandSeparator) }}
                    @endif
                </td>
                <td class="text-right">{{ number_format((float) $row['balance'], $currencyPrecision, $decimalSeparator, $thousandSeparator) }}</td>
            </tr>
        @empty
            <tr>
                <td colspan="9" class="text-center text-muted">Belum ada transaksi pada periode ini.</td>
            </tr>
        @endforelse
    </tbody>
</table>
