@extends('layouts.app')
@section('content')
<div class="container">
<a href="{{ route('ban.index') }}" class="btn btn-sm btn-secondary mb-2">Kembali</a>
<div class="row">
<div class="col-md-4">
@if($ban->gambar)
<img src="{{ asset('storage/'.$ban->gambar) }}" class="img-fluid" />
@endif
<h4 class="mt-2">{{ $ban->kode_ban }}</h4>
<p>Merk: {{ $ban->merk }}</p>
<p>Ukuran: {{ $ban->ukuran }}</p>
<p>Status: {{ $ban->status }}</p>
</div>
<div class="col-md-8">
<h5>Riwayat Pemasangan</h5>
<form action="{{ route('ban.riwayat.store', $ban) }}" method="POST">
@csrf
<div class="row">
<div class="col-md-4"><input type="date" name="tanggal_pasang" class="form-control" required></div>
<div class="col-md-4"><input type="text" name="unit" placeholder="Unit" class="form-control"></div>
<div class="col-md-4"><input type="text" name="lokasi" placeholder="Lokasi" class="form-control"></div>
</div>
<div class="mt-2"><textarea name="keterangan" class="form-control" placeholder="Keterangan"></textarea></div>
<div class="mt-2"><button class="btn btn-primary">Simpan Riwayat</button></div>
</form>


<hr>
<ul class="list-group">
@foreach($ban->riwayat as $r)
<li class="list-group-item d-flex justify-content-between">
<div>
<strong>{{ $r->unit }}</strong> — {{ $r->tanggal_pasang?->format('Y-m-d') }}
<div class="small text-muted">{{ $r->keterangan }}</div>
</div>
<form action="{{ route('ban.riwayat.destroy', $r) }}" method="POST" onsubmit="return confirm('Hapus riwayat?')">
@csrf @method('DELETE')
<button class="btn btn-sm btn-danger">Hapus</button>
</form>
</li>
@endforeach
</ul>
</div>
</div>
</div>
@endsection