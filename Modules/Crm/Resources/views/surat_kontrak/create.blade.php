@php
    $is_edit = isset($surat_kontrak);
@endphp

<div class="modal-dialog modal-lg" role="document">
    <div class="modal-content">

        @if ($is_edit)
            {!! Form::model($surat_kontrak, [
                'url' => action([\Modules\Crm\Http\Controllers\SuratKontrakController::class, 'update'], $surat_kontrak->id),
                'method' => 'put',
                'id' => 'surat_kontrak_form',
            ]) !!}
        @else
            {!! Form::open([
                'url' => action([\Modules\Crm\Http\Controllers\SuratKontrakController::class, 'store']),
                'method' => 'post',
                'id' => 'surat_kontrak_form',
            ]) !!}
        @endif

        <div class="modal-header">
            <button type="button" class="close" data-dismiss="modal">
                <span>&times;</span>
            </button>
            <h4 class="modal-title">
                {{ $is_edit ? 'Edit Surat Kontrak' : 'Tambah Surat Kontrak' }}
            </h4>
        </div>

        <div class="modal-body">

            <div class="row">
                <div class="col-md-6">
                    <div class="form-group">
                        {!! Form::label('nomor', 'Nomor Kontrak:') !!}
                        {!! Form::text('nomor', null, [
                            'class' => 'form-control',
                            'placeholder' => 'Kosongkan jika ingin generate otomatis',
                        ]) !!}
                        @if (!$is_edit)
                            <small class="text-muted">
                                Kosongkan bila ingin di-generate otomatis oleh sistem.
                            </small>
                        @endif
                    </div>
                </div>

                <div class="col-md-6">
                    <div class="form-group">
                        {!! Form::label('contact_id', 'Pihak Kedua:*') !!}
                        {!! Form::select('contact_id', $contacts, null, [
                            'class' => 'form-control select2',
                            'required',
                            'placeholder' => __('messages.please_select'),
                        ]) !!}
                    </div>
                    <div class="form-group">
                        {!! Form::label('jabatan_pihak_2', 'Jabatan') !!}
                        {!! Form::text('jabatan_pihak_2', null, [
                            'class' => 'form-control ',
                            'required',
                            'placeholder' => __('Jabatan'),
                        ]) !!}
                    </div>
                </div>
            </div>

            <div class="form-group">
                {!! Form::label('pekerjaan', 'Jenis Pekerjaan:*') !!}
                {!! Form::text('pekerjaan', $is_edit ? null : 'Pengangkutan Cangkang', [
                    'class' => 'form-control',
                    'required',
                ]) !!}
            </div>

            <div class="row">
                <div class="col-md-6">
                    <div class="form-group">
                        {!! Form::label('mulai_kerja', 'Mulai Kerja:*') !!}
                        {!! Form::date('mulai_kerja', $is_edit && $surat_kontrak->mulai_kerja ? $surat_kontrak->mulai_kerja : null, [
                            'class' => 'form-control',
                            'required',
                            'id' => 'mulai_kerja',
                        ]) !!}
                    </div>
                </div>

                <div class="col-md-6">
                    <div class="form-group">
                        {!! Form::label('selesai', 'Selesai:*') !!}
                        {!! Form::date('selesai', $is_edit && $surat_kontrak->selesai ? $surat_kontrak->selesai : null, [
                            'class' => 'form-control',
                            'required',
                            'id' => 'selesai',
                        ]) !!}
                    </div>
                </div>
            </div>

            <div class="form-group">
                {!! Form::label('jangka_waktu', 'Jangka Waktu:') !!}
                {!! Form::text('jangka_waktu', null, [
                    'class' => 'form-control',
                    'readonly',
                    'id' => 'jangka_waktu',
                ]) !!}
            </div>

            <div class="form-group">
                {!! Form::label('notes', 'Notes:') !!}
                {!! Form::textarea('notes', null, [
                    'class' => 'form-control',
                    'rows' => 3,
                ]) !!}
            </div>

        </div>

        <div class="modal-footer">
            <button type="submit" class="tw-dw-btn tw-dw-btn-primary tw-text-white">
                @lang('messages.save')
            </button>
            <button type="button" class="tw-dw-btn tw-dw-btn-neutral tw-text-white" data-dismiss="modal">
                @lang('messages.close')
            </button>
        </div>

        {!! Form::close() !!}
    </div>
</div>
