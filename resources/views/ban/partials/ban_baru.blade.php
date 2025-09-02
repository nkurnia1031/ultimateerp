<div class="row">
@forelse($bans->where('status','baru') as $ban)
<div class="col-sm-6 col-md-4 mb-3">
<div class="card shadow-sm">
@if($ban->gambar)
<img src="{{ asset('storage/'.$ban->gambar) }}" class="card-img-top" style="height:160px;object-fit:cover;" />
@endif
<div class="card-body">
<h5 class="card-title">{{ $ban->kode_ban }} <small class="text-muted">({{ $ban->merk }})</small></h5>
<p class="mb-1">Ukuran: {{ $ban->ukuran ?? '-' }}</p>
<p class="mb-1">Keterangan: {{ Str::limit($ban->keterangan,80) }}</p>
<div class="d-flex justify-content-between">
<form action="{{ route('ban.destroy',$ban) }}" method="POST" onsubmit="return confirm('Hapus ban ini?')">
@csrf @method('DELETE')
<button class="btn btn-sm btn-danger">Hapus</button>
</form>
<a href="#" class="btn btn-sm btn-secondary" data-toggle="modal" data-target="#modalEditBan{{ $ban->id }}">Edit</a>
</div>
</div>
</div>
</div>


@include('ban.partials.modal_edit', ['ban' => $ban])


@empty
<div class="col-12">Tidak ada ban baru.</div>
@endforelse
</div>


<div class="d-flex justify-content-center">{{ $bans->links() }}</div>