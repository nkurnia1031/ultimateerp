<div class="modal-dialog modal-lg" role="document">
    <div class="modal-content">

        {!! Form::open([
            'url' => action([\Modules\Crm\Http\Controllers\SuratKontrakController::class, 'importproses']),
            'method' => 'post',
            'id' => 'surat_kontrak_import_form',
            'enctype' => 'multipart/form-data',
        ]) !!}

        <div class="modal-header">
            <button type="button" class="close" data-dismiss="modal">
                <span>&times;</span>
            </button>
            <h4 class="modal-title">
                Import Surat Kontrak (SPK)
            </h4>
        </div>

        <div class="modal-body">

            {{-- Informasi Format --}}
            <div class="alert alert-info">
                <b>Petunjuk Import:</b>
                <ul style="margin-bottom:0;">
                    <li>File harus berformat <b>.xlsx</b></li>
                    <li>Gunakan template sample agar format sesuai</li>
                    <li>Nomor boleh dikosongkan (akan digenerate otomatis)</li>
                    <li>Format tanggal: <b>YYYY-MM-DD</b></li>
                </ul>
            </div>

            {{-- Penjelasan Kolom --}}
            <div class="well">
                <b>Kolom yang harus ada di file Excel:</b>
                <ul style="margin-bottom:0;">
                    <li><b>nomor</b> (opsional)</li>
                    <li><b>contact_id</b> (wajib)</li>
                    <li><b>pekerjaan</b> (wajib)</li>
                    <li><b>kapal_mv</b> (opsional)</li>
                    <li><b>lokasi_muat</b> (opsional)</li>
                    <li><b>lokasi_bongkar</b> (opsional)</li>
                    <li><b>mulai_kerja</b> (wajib, format: 2026-01-25)</li>
                    <li><b>selesai</b> (wajib, format: 2026-01-28)</li>
                    <li><b>jangka_waktu</b> (opsional)</li>
                    <li><b>notes</b> (opsional)</li>
                </ul>
            </div>

            <hr>

            {{-- Input File --}}
            <div class="form-group">
                {!! Form::label('import_file', 'Upload File Excel:*') !!}
                {!! Form::file('import_file', [
                    'class' => 'form-control',
                
                    'required',
                ]) !!}
                <p class="help-block">
                    Maksimal ukuran file: 5MB
                </p>
            </div>

        </div>

        <div class="modal-footer">

            {{-- Download Sample --}}
            <a href="{{ action([\Modules\Crm\Http\Controllers\SuratKontrakController::class, 'downloadSample']) }}"
                class="btn btn-success">
                <i class="fa fa-download"></i> Download Sample Excel
            </a>

            <button type="submit" class="btn btn-primary">
                <i class="fa fa-upload"></i> Import
            </button>

            <button type="button" class="btn btn-default" data-dismiss="modal">
                Tutup
            </button>

        </div>

        {!! Form::close() !!}
    </div>
</div>
