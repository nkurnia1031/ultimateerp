<!-- Modal Tambah Ban -->
<div class="modal fade" id="modalTambahBan" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <form action="{{ route('ban.store') }}" method="POST" enctype="multipart/form-data">
        @csrf
        <div class="modal-header">
          <h5 class="modal-title">Tambah Ban Baru</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <!-- Kode Ban -->
          <div class="mb-2">
            <label>Kode Ban</label>
            <input name="kode_ban" class="form-control" value="{{ old('kode_ban') }}" required>
            @error('kode_ban')
              <small class="text-danger">{{ $message }}</small>
            @enderror
          </div>

          <!-- Merk & Ukuran -->
          <div class="row">
            <div class="col-md-6 mb-2">
              <label>Merk</label>
              <input name="merk" class="form-control" value="{{ old('merk') }}">
              @error('merk')
                <small class="text-danger">{{ $message }}</small>
              @enderror
            </div>
            <div class="col-md-6 mb-2">
              <label>Ukuran</label>
              <input name="ukuran" class="form-control" value="{{ old('ukuran') }}">
              @error('ukuran')
                <small class="text-danger">{{ $message }}</small>
              @enderror
            </div>
          </div>

          <!-- Tanggal Masuk -->
          <div class="mb-2">
            <label>Tanggal Masuk</label>
            <input type="date" name="tanggal_masuk" class="form-control" value="{{ old('tanggal_masuk') }}">
            @error('tanggal_masuk')
              <small class="text-danger">{{ $message }}</small>
            @enderror
          </div>

          <!-- Status -->
          <div class="mb-2">
            <label>Status</label>
            <select name="status" class="form-control" required>
              <option value="">-- Pilih Status --</option>
              <option value="baru" {{ old('status') == 'baru' ? 'selected' : '' }}>Baru</option>
              <option value="terpasang" {{ old('status') == 'terpasang' ? 'selected' : '' }}>Terpasang</option>
              <option value="rusak" {{ old('status') == 'rusak' ? 'selected' : '' }}>Rusak</option>
              <option value="dibuang" {{ old('status') == 'dibuang' ? 'selected' : '' }}>Dibuang</option>
            </select>
            @error('status')
              <small class="text-danger">{{ $message }}</small>
            @enderror
          </div>

          <!-- Gambar -->
          <div class="mb-2">
            <label>Gambar</label>
            <input type="file" name="gambar" accept="image/*" class="form-control">
            @error('gambar')
              <small class="text-danger">{{ $message }}</small>
            @enderror
          </div>

          <!-- Keterangan -->
          <div class="mb-2">
            <label>Keterangan</label>
            <textarea name="keterangan" class="form-control">{{ old('keterangan') }}</textarea>
            @error('keterangan')
              <small class="text-danger">{{ $message }}</small>
            @enderror
          </div>

        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
          <button type="submit" class="btn btn-primary">Simpan</button>
        </div>
      </form>
    </div>
  </div>
</div>
