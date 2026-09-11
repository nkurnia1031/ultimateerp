@extends('layouts.app')

@section('title', 'Change Log')

@section('css')
<style>
    .change-log-card .box-title { display: block; margin-top: 6px; }
    .change-log-card .change-log-action { white-space: normal; }
    .change-log-guides { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 12px; margin-top: 18px; }
    .change-log-guide { display: flex; gap: 12px; align-items: center; min-width: 0; padding: 10px; background: #f7faf9; border: 1px solid #dce6e8; border-radius: 9px; }
    .change-log-guide img { width: 78px; height: 52px; object-fit: cover; border-radius: 6px; }
    .change-log-guide__text { min-width: 0; }
    .change-log-guide__text strong { display: block; overflow: hidden; color: #1d3b48; font-size: 13px; line-height: 1.35; text-overflow: ellipsis; white-space: nowrap; }
    .change-log-guide__text a { color: #0a7477; font-size: 12px; font-weight: 700; }
    .change-log-guides-heading { margin: 22px 0 0; color: #1d3b48; font-size: 17px; }
    .change-log-more-guides { display: inline-block; margin-top: 14px; color: #0a7477; font-weight: 700; }
    @media (max-width: 767px) {
        .change-log-card .box-header,
        .change-log-card .box-body { padding: 12px; }
        .change-log-card .change-log-action { display: block; width: 100%; }
        .change-log-guides { grid-template-columns: 1fr; }
    }
</style>
@endsection

@section('content')
<section class="content-header" aria-labelledby="change-log-heading" data-change-log-overview="true">
    <h1 id="change-log-heading"><i class="fas fa-exclamation-circle" aria-hidden="true"></i> Change Log</h1>
    <p class="text-muted">Ringkasan perubahan terbaru yang membantu Anda memahami fitur baru di aplikasi.</p>
    <a class="btn btn-default" href="{{ route('guides.index') }}"><i class="fa fa-book-open" aria-hidden="true"></i> Semua Panduan</a>
</section>
<section class="content" aria-label="Daftar perubahan aplikasi">
    @forelse ($releases as $release)
        <article class="box box-primary change-log-card">
            <div class="box-header with-border">
                <small class="text-muted">{{ $release['category'] }} · {{ Illuminate\Support\Carbon::parse($release['published_at'])->format('d-m-Y') }}</small>
                <h2 class="box-title">{{ $release['title'] }}</h2>
            </div>
            <div class="box-body">
                <p>{{ $release['summary'] }}</p>
                <p><strong>Manfaat:</strong> {{ $release['benefit'] }}</p>
                <a class="btn btn-primary change-log-action" href="{{ route('change-log.show', $release['slug']) }}">Lihat penjelasan dan cara pakai</a>
                @php($releaseGuides = $guides->where('release_slug', $release['slug']))
                @if($releaseGuides->isNotEmpty())
                    <h3 class="change-log-guides-heading">Panduan per topik</h3>
                    <div class="change-log-guides" aria-label="Panduan angkutan">
                        @foreach($releaseGuides as $guide)
                            @php($guideImage = $guide['screenshots'][0]['file'] ?? null)
                            <article class="change-log-guide">
                                @if($guideImage)<img src="{{ asset('images/change-log/release-2026-09-10-angkutan/'.$guideImage) }}" alt="{{ $guide['screenshots'][0]['alt'] }}" loading="lazy">@endif
                                <div class="change-log-guide__text">
                                    <strong>{{ $guide['title'] }}</strong>
                                    <a href="{{ route('guides.show', $guide['slug']) }}">Buka panduan <i class="fa fa-arrow-right" aria-hidden="true"></i></a>
                                </div>
                            </article>
                        @endforeach
                    </div>
                    <a class="change-log-more-guides" href="{{ route('guides.index') }}">Lihat semua panduan lengkap <i class="fa fa-arrow-right" aria-hidden="true"></i></a>
                @endif
            </div>
        </article>
    @empty
        <div class="alert alert-info">Belum ada perubahan yang diterbitkan untuk akun Anda.</div>
    @endforelse
</section>
@endsection
