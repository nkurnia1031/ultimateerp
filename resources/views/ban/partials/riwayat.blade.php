<div class="card">
<div class="card-body">
<table class="table table-sm">
<thead>
<tr>
<th>#</th>
<th>Kode Ban</th>
<th>Unit</th>
<th>Tanggal Pasang</th>
<th>Tanggal Lepas</th>
<th>Aksi</th>
</tr>
</thead>
<tbody>
@foreach(App\Models\BanRiwayat::with('ban')->latest()->limit(50)->get() as $r)
<tr>
<td>{{ $r->id }}</td>
<td>{{ $r->ban?->kode_ban ?? '-' }}</td>
<td>{{ $r->unit }}</td>
<td>{{ $r->tanggal_pasang?->format('Y-m-d') }}</td>
<td>{{ $r->tanggal_lepas?->format('Y-m-d') ?? '-' }}</td>
<td>
<form action="{{ route('ban.riwayat.destroy', $r) }}" method="POST" onsubmit="return confirm('Hapus riwayat?')">
@csrf @method('DELETE')
<button class="btn btn-sm btn-danger">Hapus</button>
</form>
</td>
</tr>
@endforeach
</tbody>
</table>
</div>
</div>