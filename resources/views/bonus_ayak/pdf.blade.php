<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <title>Rekap Bonus Ayak Bulanan</title>
    <style>
        body { font-family: DejaVu Sans, Arial, sans-serif; font-size: 12px; margin: 30px; }
        table { width: 100%; border-collapse: collapse; margin-top:10px; }
        th, td { border:1px solid #333; padding:6px; text-align:center; vertical-align: middle; }
        th { background:#eee; }
        h3 { margin: 0; text-align:center; }
        .text-left { text-align:left; }
        .text-right { text-align:right; }
        .signature {
            width: 100%;
            margin-top: 50px;
            text-align: center;
        }
        .signature div {
            display: inline-block;
            width: 45%;
            vertical-align: top;
        }
        .signature .name {
            margin-top: 70px;
            text-decoration: underline;
            font-weight: bold;
        }
    </style>
</head>
<body>
    <h3 style="margin-bottom:8px;">
        LIST BONUS AYAK PERIODE {{ $displayFrom }} - {{ $displayTo }}
    </h3>

    <table>
        <thead>
            <tr>
                <th>No</th>
                <th>Nama Operator/Driver</th>
                <th>Total Trip</th>
                <th>Total Bonus</th>
                <th>Kasbon</th>
                <th>Total Diterima</th>
                <th>Tanggal Terima</th>
                <th>Paraf</th>
            </tr>
        </thead>
        <tbody>
            @php 
                $grand_total_bonus = 0;
                $grand_total_kasbon = 0;
                $grand_total_diterima = 0;
            @endphp

            @foreach($data as $i => $d)
                @php
                    $kasbonKey = $d->driver_id ?: 'name:' . $d->nama_operator;
                    $kasbon = $kasbonData[$kasbonKey] ?? 0;
                    $total_diterima = $d->total_bonus - $kasbon;

                    $grand_total_bonus += $d->total_bonus;
                    $grand_total_kasbon += $kasbon;
                    $grand_total_diterima += $total_diterima;
                @endphp

                <tr>
                    <td>{{ $i+1 }}</td>
                    <td class="text-left">{{ $d->nama_operator }}</td>
                    <td>{{ $d->total_trip }}</td>
                    <td class="text-right">Rp {{ number_format($d->total_bonus,0,',','.') }}</td>
                    <td class="text-right">Rp {{ number_format($kasbon,0,',','.') }}</td>
                    <td class="text-right">Rp {{ number_format($total_diterima,0,',','.') }}</td>
                    <td></td> {{-- Kolom tanggal terima (kosong untuk tanda tangan manual) --}}
                    <td></td> {{-- Kolom paraf --}}
                </tr>
            @endforeach
        </tbody>
        <tfoot>
            <tr>
                <th colspan="3" class="text-right">TOTAL</th>
                <th class="text-right">Rp {{ number_format($grand_total_bonus,0,',','.') }}</th>
                <th class="text-right">Rp {{ number_format($grand_total_kasbon,0,',','.') }}</th>
                <th class="text-right">Rp {{ number_format($grand_total_diterima,0,',','.') }}</th>
                <th colspan="2"></th>
            </tr>
        </tfoot>
    </table>

{{-- TANDA TANGAN --}}
<div style="margin-top: 40px; width: 100%;">
    <p style="text-align: right; margin-bottom: 40px;">
        Dumai, {{ \Carbon\Carbon::now()->locale('id')->translatedFormat('d F Y') }}
    </p>

    <div class="signature" style="display: flex; justify-content: space-between; text-align: center; margin-top: 50px;">
        <div style="width: 45%;">
            <p>Admin Operasional</p>
            <p class="name" style="margin-top: 60px; font-weight: bold;">Tika Navita.Manalu</p>
        </div>
        <div style="width: 45%;">
            <p>Manager Operasional</p>
            <p class="name" style="margin-top: 60px; font-weight: bold;">Ronald Sunjaya</p>
        </div>
    </div>
</div>
</body>
</html>
