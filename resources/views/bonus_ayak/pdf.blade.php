<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <title>Rekap Bonus Ayak</title>
    <style>
        body { font-family: DejaVu Sans, Arial, sans-serif; font-size: 12px; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border:1px solid #333; padding:6px; }
        th { background:#eee; }
        h3 { margin: 0 0 8px 0; }
    </style>
</head>
<body>
    <h3>Rekap Bonus Ayak</h3>
    <table>
        <thead>
            <tr>
                <th>Tanggal</th>
                <th>Nama Operator</th>
                <th>Unit</th>
                <th>Trip</th>
                <th>Bonus</th>
            </tr>
        </thead>
        <tbody>
            @foreach($data as $d)
            <tr>
                <td>{{ $d->tanggal->format('Y-m-d') }}</td>
                <td>{{ $d->nama_operator }}</td>
                <td>{{ $d->unit }}</td>
                <td>{{ $d->trip }}</td>
                <td>{{ number_format($d->bonus,0,',','.') }}</td>
            </tr>
            @endforeach
        </tbody>
        <tfoot>
            <tr>
                <th colspan="4" style="text-align:right">TOTAL</th>
                <th>{{ number_format($total,0,',','.') }}</th>
            </tr>
        </tfoot>
    </table>
</body>
</html>
