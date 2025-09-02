<!-- Modal Edit Ban (dipanggil per card dengan include) -->
<div class="modal fade" id="modalEditBan{{ $ban->id }}" tabindex="-1">
<div class="modal-dialog modal-lg">
<div class="modal-content">
<form action="{{ route('ban.update', $ban) }}" method="POST" enctype="multipart/form-data">
@csrf @method('PUT')
<div class="modal-header">
<h5 class="modal-title">Edit Ban {{ $ban->kode_ban }}</h5>
<button type="button" class="btn-close" data-bs-dismiss="modal"></button>
</div>
<div class="modal-body">
<div class="mb-2"><label>Kode Ban</label><input name="kode_ban" value="{{ $ban->kode_ban }}" class="form-control" required></div>
<div class="row">
<div class="col-md-6 mb-2"><label>Merk</label><input name="merk" value="{{ $ban->merk }}" class="form-control"></div>
<div class="col-md-6 mb-2"><label>Ukuran</label><input name="ukuran" value="{{ $ban->ukuran }}" class="form-control"></div>
</div>
<div class="mb-2"><label>Status</label>
<select name="status" class="form-control">
<option value="baru" {{ $ban->status=='baru'? 'selected':'' }}>Baru</option>
<option value="terpasang" {{ $ban->status=='terpasang'? 'selected':'' }}>Terpasang</option>
<option value="rusak" {{ $ban->status=='rusak'? 'selected':'' }}>Rusak</option>
<option value="dibuang" {{ $ban->status=='dibuang'? 'selected':'' }}>Dibuang</option>
</select>
</div>
<div class="mb-2"><label>Tanggal Masuk</label><input type="date" name="tanggal_masuk" value="{{ optional($ban->tanggal_masuk)->format('Y-m-d') }}" class="form-control"></div>
<div class="mb-2"><label>Gambar (opsional)</label><input type="file" name="gambar" accept="image/*" class="form-control"></div>
<div class="mb-2"><label>Keterangan</label><textarea name="keterangan" class="form-control">{{ $ban->keterangan }}</textarea></div>
</div>
<div class="modal-footer">
<button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
<button type="submit" class="btn btn-primary">Simpan Perubahan</button>
</div>
</form>
</div>
</div>
</div>