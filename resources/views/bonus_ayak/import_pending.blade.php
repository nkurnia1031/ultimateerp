@extends('layouts.app')
@section('title', 'Memproses Import Bonus Ayak')

@section('content')
<section class="content-header"><h1>Memproses Import Bonus Ayak</h1></section>
<section class="content">
    <div class="box box-default">
        <div class="box-body">
            <p>File <strong>{{ $fileName }}</strong> sudah diterima. Proses tetap berjalan di antrean walau halaman ditutup.</p>
            <div class="progress"><div id="bonus-import-progress" class="progress-bar progress-bar-info" role="progressbar" style="width:1%">1%</div></div>
            <p id="bonus-import-message" aria-live="polite">Menunggu worker…</p>
            <div id="bonus-import-error" class="alert alert-danger hidden"></div>
            <a class="btn btn-default" href="{{ route('bonus-ayak.index') }}">Kembali ke Bonus Ayak</a>
        </div>
    </div>
</section>
@endsection

@section('javascript')
<script>
$(function () {
    var statusUrl = @json($statusUrl);
    var poll = function () {
        $.get(statusUrl).done(function (batch) {
            var percent = Math.max(1, Math.min(100, parseInt(batch.progress_percent || 0, 10)));
            $('#bonus-import-progress').css('width', percent + '%').text(percent + '%');
            $('#bonus-import-message').text(batch.last_message || batch.status);
            if (batch.status === 'preview_ready' && batch.preview_url) {
                window.location = batch.preview_url;
            } else if (batch.status === 'imported' || batch.status === 'partial' || batch.status === 'reverted' || batch.status === 'cancelled') {
                window.location = @json(route('bonus-ayak.index'));
            } else if (batch.status === 'failed') {
                $('#bonus-import-error').removeClass('hidden').text(batch.error_message || batch.last_message || 'Import gagal.');
                if (batch.preview_url) window.location = batch.preview_url;
            } else {
                window.setTimeout(poll, 2000);
            }
        }).fail(function () {
            $('#bonus-import-message').text('Status belum dapat dimuat. Mencoba lagi…');
            window.setTimeout(poll, 5000);
        });
    };
    window.setTimeout(poll, 1000);
});
</script>
@endsection
