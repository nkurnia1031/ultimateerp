@extends('layouts.app')
@section('content')
<div class="container">
    <h3>Manajemen Ban</h3>

    @if(session('success'))
        <div class="alert alert-success">{{ session('success') }}</div>
    @endif

    <ul class="nav nav-tabs">
        <li class="active"><a data-toggle="tab" href="#data_ban">Data Ban Baru</a></li>
        <li><a data-toggle="tab" href="#pemasangan_ban">Pemasangan Ban</a></li>
    </ul>

    <div class="tab-content mt-3">
        <!-- Tab Data Ban Baru -->
        <div id="data_ban" class="tab-pane fade in active">
            <form method="POST" action="{{ route('ban.store') }}" enctype="multipart/form-data">
                @csrf
                <div class="row">
                    <div class="col-md-3"><input type="text" name="kode_ban" placeholder="Kode Ban" class="form-control" required></div>
                    <div class="col-md-2"><input type="text" name="merk" placeholder="Merk" class="form-control"></div>
                    <div class="col-md-2"><input type="text" name="ukuran" placeholder="Ukuran" class="form-control"></div>
                    <div class="col-md-2"><input type="date" name="tanggal_masuk" class="form-control"></div>
                    <div class="col-md-2"><input type="text" name="keterangan" placeholder="Keterangan" class="form-control"></div>
                    <div class="col-md-1"><button type="submit" class="btn btn-success">Tambah</button></div>
                </div>
            </form>

            <hr>
            <table class="table table-bordered table-striped">
                <thead>
                    <tr><th>Kode</th><th>Merk</th><th>Ukuran</th><th>Tanggal Masuk</th><th>Status</th><th>Aksi</th></tr>
                </thead>
                <tbody>
                    @foreach($banBaru as $b)
                    <tr>
                        <td>{{ $b->kode_ban }}</td>
                        <td>{{ $b->merk }}</td>
                        <td>{{ $b->ukuran }}</td>
                        <td>{{ $b->tanggal_masuk }}</td>
                        <td>{{ $b->status }}</td>
                        <td>
                            <form action="{{ route('ban.destroy',$b) }}" method="POST" onsubmit="return confirm('Yakin hapus?')">
                                @csrf @method('DELETE')
                                <button class="btn btn-danger btn-sm">Hapus</button>
                            </form>
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <!-- Tab Pemasangan Ban -->
        <div id="pemasangan_ban" class="tab-pane fade">
            <form method="POST" action="{{ route('ban.pasang') }}" enctype="multipart/form-data">
                @csrf
                <div class="row mt-2">
                    <div class="col-md-6">
                        <label>No. Plat Kendaraan</label>
                        <input type="text" name="vehicle_number" class="form-control">
                    </div>
                    <div class="col-md-6">
                        <label>Posisi Ban</label>
                        <select name="posisi_ban" class="form-control">
                            <option value="">-- Pilih --</option>
                            @foreach($posisi_ban as $pos)
                                <option value="{{ $pos }}">{{ $pos }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>

                <div class="row mt-2">
                    <div class="col-md-6">
                        <label>Ban Baru</label>
                        <select name="ban_id_baru" class="form-control" required>
                            <option value="">-- Pilih Ban --</option>
                            @foreach($banBaru as $b)
                                <option value="{{ $b->id }}">{{ $b->kode_ban }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label>Ban Lama (Opsional)</label>
                        <input type="text" name="ban_id_lama" class="form-control">
                    </div>
                </div>

                <div class="row mt-2">
                    <div class="col-md-4"><label>Tanggal Pasang</label><input type="date" name="tanggal_pasang" class="form-control"></div>
                    <div class="col-md-4"><label>KM Awal</label><input type="number" name="km_awal" class="form-control"></div>
                    <div class="col-md-4"><label>KM Akhir</label><input type="number" name="km_akhir" class="form-control"></div>
                </div>

                <div class="row mt-2">
                    <div class="col-md-6">
                        <label>Kondisi Dicopot</label>
                        <select name="kondisi_cabut" class="form-control">
                            <option value="">-- Pilih --</option>
                            <option value="Bagus">Bagus</option>
                            <option value="Retread">Retread</option>
                            <option value="Rusak Berat">Rusak Berat</option>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label>Foto Ban</label>
                        <input type="file" name="foto_ban" class="form-control">
                    </div>
                </div>

                <div class="row mt-2">
                    <div class="col-md-12">
                        <label>Keterangan</label>
                        <textarea name="keterangan" class="form-control"></textarea>
                    </div>
                </div>

                <div class="row mt-2">
                    <div class="col-md-12 text-right">
                        <button type="submit" class="btn btn-primary">Simpan Pemasangan</button>
                    </div>
                </div>
            </form>

            <hr>
            <h4>Riwayat Pemasangan Ban</h4>
            <table class="table table-bordered table-striped table-hover">
                <thead>
                    <tr>
                        <th>No Plat</th>
                        <th>Kode Ban</th>
                        <th>Posisi</th>
                        <th>Tanggal Pasang</th>
                        <th>Tanggal Cabut</th>
                        <th>KM Awal - Akhir</th>
                        <th>Kondisi</th>
                        <th>Foto</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach(\App\Models\BanPemasangan::all() as $r)
                    <tr>
                        <td>{{ $r->vehicle_number ?? '-' }}</td>
                        <td>{{ optional($r->banBaru)->kode_ban ?? '-' }}</td>
                        <td>{{ $r->posisi_ban }}</td>
                        <td>{{ $r->tanggal_pasang }}</td>
                        <td>{{ $r->tanggal_cabut ?? '-' }}</td>
                        <td>{{ $r->km_awal }} - {{ $r->km_akhir ?? '-' }}</td>
                        <td>{{ $r->kondisi_cabut ?? '-' }}</td>
                        <td>
                            @if($r->foto_ban)
                                <img src="{{ asset('storage/'.$r->foto_ban) }}" width="60">
                            @else
                                -
                            @endif
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>

        </div>
    </div>
</div>
@endsection
