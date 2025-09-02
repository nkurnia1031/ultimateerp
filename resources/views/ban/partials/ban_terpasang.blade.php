<div class="row">
@forelse($bans->where('status','terpasang') as $ban)
<div class="col-sm-6 col-md-4 mb-3">
<div class="card shadow-sm">
@if($ban->gambar)
<img src="{{ asset('storage/'.$ban->gambar) }}" class="card-img-top" style="height:160px;object-fit:cover;" />
@endif
<div class="card-body">
<h5 class="card-title">{{ $ban->kode_ban }} <small class="text-muted">{{ $ban->merk }}</small></h5>
<p class="mb-1">Terpasang di: {{ $ban->lokasi ?? '—' }}</p>
<a href="{{ route('ban.show',$ban) }}" class="btn btn-sm btn-outline-primary">Detail</a>
</div>
</div>
</div>
@empty
<div class="col-12">Tidak ada ban terpasang.</div>
@endforelse
</div>