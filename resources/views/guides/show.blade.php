@extends('layouts.app')

@section('title', $guide['title'])

@section('css')
<style>
    .guide-article-shell { max-width: 1440px; margin: 0 auto; padding: 24px 30px 50px; }
    .guide-breadcrumb { margin-bottom: 16px; color: #73858d; font-size: 13px; }
    .guide-breadcrumb a { color: #0a7477; }
    .guide-article-layout { display: grid; grid-template-columns: minmax(0, 1fr) 250px; gap: 28px; align-items: start; }
    .guide-article { min-width: 0; padding: clamp(22px, 4vw, 46px); background: #fff; border: 1px solid #dce6e8; border-radius: 14px; box-shadow: 0 12px 32px rgba(22, 43, 58, .07); }
    .guide-article__eyebrow { margin: 0 0 10px; color: #0f8b8d; font-size: 12px; font-weight: 700; letter-spacing: .12em; text-transform: uppercase; }
    .guide-article h1 { margin: 0 0 12px; color: #162b3a; font-size: clamp(28px, 4vw, 46px); letter-spacing: -.04em; line-height: 1.08; text-wrap: balance; }
    .guide-article__summary { max-width: 720px; margin: 0; color: #536971; font-size: 17px; line-height: 1.7; }
    .guide-article__meta { display: flex; flex-wrap: wrap; gap: 8px 18px; margin-top: 18px; color: #73858d; font-size: 13px; }
    .guide-article__meta strong { color: #334e5b; }
    .guide-callout { margin: 28px 0; padding: 17px 19px; background: #f2f8f7; border-left: 4px solid #0f8b8d; border-radius: 0 10px 10px 0; color: #29434d; line-height: 1.7; }
    .guide-article h2 { margin: 38px 0 14px; padding-top: 8px; color: #162b3a; font-size: 25px; letter-spacing: -.025em; scroll-margin-top: 20px; }
    .guide-article h3 { margin: 0 0 7px; color: #1d3b48; font-size: 18px; }
    .guide-article p, .guide-article li, .guide-article dd { max-width: 74ch; color: #425862; line-height: 1.72; }
    .guide-article ul { padding-left: 22px; }
    .guide-steps { counter-reset: guide-step; padding: 0; list-style: none; }
    .guide-step { counter-increment: guide-step; position: relative; padding: 0 0 25px 50px; }
    .guide-step::before { content: counter(guide-step); position: absolute; top: 0; left: 0; display: grid; width: 34px; height: 34px; place-items: center; border-radius: 9px; background: #0f8b8d; color: #fff; font-weight: 700; }
    .guide-step__check { display: block; margin-top: 8px; color: #0a7477; font-size: 13px; font-weight: 700; }
    .guide-step__check::before { content: '✓'; margin-right: 6px; }
    .guide-toc { position: sticky; top: 18px; padding: 18px; background: #f7faf9; border: 1px solid #dce6e8; border-radius: 12px; }
    .guide-toc h2 { margin: 0 0 10px; color: #1d3b48; font-size: 16px; }
    .guide-toc ol { margin: 0; padding-left: 21px; }
    .guide-toc li { margin: 8px 0; color: #60717b; font-size: 13px; line-height: 1.4; }
    .guide-toc a { color: #0a7477; }
    .guide-figures { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 18px; margin-top: 14px; }
    .guide-figure { margin: 0; overflow: hidden; background: #f7faf9; border: 1px solid #dce6e8; border-radius: 10px; }
    .guide-figure a { display: block; background: #e9f1f0; }
    .guide-figure img { display: block; width: 100%; height: auto; }
    .guide-figure figcaption { padding: 12px 14px; color: #536971; font-size: 13px; line-height: 1.5; }
    .guide-figure figcaption strong { display: block; margin-bottom: 4px; color: #1d3b48; }
    .guide-definition { display: grid; grid-template-columns: minmax(110px, 180px) 1fr; gap: 8px 18px; margin: 0; }
    .guide-definition dt { color: #0a7477; font-weight: 700; }
    .guide-definition dd { margin: 0; }
    .guide-links { display: flex; flex-wrap: wrap; gap: 9px; padding: 0; list-style: none; }
    .guide-links a { display: inline-flex; gap: 7px; align-items: center; padding: 9px 12px; color: #0a7477; background: #f2f8f7; border: 1px solid #cde2df; border-radius: 8px; }
    .guide-links a:hover { background: #e3f1ef; }
    .guide-links a:focus-visible, .guide-toc a:focus-visible, .guide-figure a:focus-visible { outline: 3px solid #f0b85b; outline-offset: 3px; }
    .guide-article-footer { display: flex; flex-wrap: wrap; gap: 9px; margin-top: 42px; padding-top: 22px; border-top: 1px solid #dce6e8; }
    .guide-related { margin-top: 28px; padding-top: 24px; border-top: 1px solid #dce6e8; }
    .guide-related a { color: #0a7477; }
    @media (max-width: 900px) { .guide-article-layout { display: block; } .guide-toc { position: static; margin-bottom: 18px; } }
    @media (max-width: 620px) { .guide-article-shell { padding: 17px 12px 34px; } .guide-article { padding: 22px 17px; border-radius: 10px; } .guide-article h1 { font-size: 31px; } .guide-figures { grid-template-columns: 1fr; } .guide-definition { display: block; } .guide-definition dt { margin-top: 13px; } .guide-definition dd { margin-top: 3px; } .guide-step { padding-left: 45px; } }
</style>
@endsection

@section('content')
<main class="guide-article-shell" aria-labelledby="guide-article-title">
    <nav class="guide-breadcrumb" aria-label="Breadcrumb">
        <a href="{{ route('guides.index') }}">Panduan</a> <span aria-hidden="true">/</span> {{ $guide['category'] }}
    </nav>

    <div class="guide-article-layout">
        <article class="guide-article">
            <header>
                <p class="guide-article__eyebrow">{{ $guide['category'] }}</p>
                <h1 id="guide-article-title">{{ $guide['title'] }}</h1>
                <p class="guide-article__summary">{{ $guide['summary'] }}</p>
                <div class="guide-article__meta">
                    <span><strong>Untuk:</strong> {{ $guide['audience'] }}</span>
                    <span><strong>Diperbarui:</strong> {{ \Illuminate\Support\Carbon::parse($guide['updated_at'])->format('d-m-Y') }}</span>
                </div>
            </header>

            <div class="guide-callout"><strong>Sebelum mulai:</strong> {{ $guide['purpose'] }}</div>

            <section aria-labelledby="guide-prerequisites">
                <h2 id="guide-prerequisites">Prasyarat</h2>
                <ul>
                    @foreach($guide['prerequisites'] as $item)<li>{{ $item }}</li>@endforeach
                </ul>
            </section>

            <section aria-labelledby="guide-terms">
                <h2 id="guide-terms">Istilah yang dipakai</h2>
                <dl class="guide-definition">
                    @foreach($guide['terms'] as $term)
                        <dt>{{ $term['term'] }}</dt><dd>{{ $term['description'] }}</dd>
                    @endforeach
                </dl>
            </section>

            <section aria-labelledby="guide-steps">
                <h2 id="guide-steps">Langkah penggunaan</h2>
                <ol class="guide-steps">
                    @foreach($guide['steps'] as $step)
                        <li class="guide-step" id="guide-step-{{ $loop->iteration }}">
                            <h3>{{ $step['title'] }}</h3>
                            <p>{{ $step['body'] }}</p>
                            <span class="guide-step__check">Hasil yang diharapkan: {{ $step['check'] }}</span>
                        </li>
                    @endforeach
                </ol>
            </section>

            @if(!empty($guide['screenshots']))
                <section aria-labelledby="guide-screenshots">
                    <h2 id="guide-screenshots">Gambar dari langkah yang diuji</h2>
                    <p>Nomor, kotak, dan panah pada gambar menunjuk bagian layar yang perlu diperhatikan. Klik gambar untuk melihat ukuran penuh. Penjelasan teks di atas tetap menjadi panduan utama.</p>
                    <div class="guide-figures">
                        @foreach($guide['screenshots'] as $screenshot)
                            @php($imageUrl = asset('images/change-log/release-2026-09-10-angkutan/'.$screenshot['file']))
                            <figure class="guide-figure">
                                <a href="{{ $imageUrl }}" target="_blank" rel="noopener" aria-label="Buka gambar ukuran penuh: {{ $screenshot['alt'] }}">
                                    <img src="{{ $imageUrl }}" alt="{{ $screenshot['alt'] }}" loading="lazy">
                                </a>
                                <figcaption><strong>{{ $screenshot['step'] }}</strong>{{ $screenshot['caption'] }}</figcaption>
                            </figure>
                        @endforeach
                    </div>
                </section>
            @endif

            <section aria-labelledby="guide-success">
                <h2 id="guide-success">Tanda proses berhasil</h2>
                <ul>@foreach($guide['expected'] as $item)<li>{{ $item }}</li>@endforeach</ul>
            </section>

            <section aria-labelledby="guide-troubleshooting">
                <h2 id="guide-troubleshooting">Masalah yang sering terjadi</h2>
                @foreach($guide['troubleshooting'] as $issue)
                    <div class="guide-callout"><strong>{{ $issue['problem'] }}</strong><br>{{ $issue['solution'] }}</div>
                @endforeach
            </section>

            @if($links->isNotEmpty())
                <section aria-labelledby="guide-links">
                    <h2 id="guide-links">Buka fitur terkait</h2>
                    <ul class="guide-links">
                        @foreach($links as $link)
                            <li><a href="{{ $link['url'] }}">{{ $link['label'] }} <i class="fa fa-arrow-right" aria-hidden="true"></i></a></li>
                        @endforeach
                    </ul>
                </section>
            @endif

            @if($relatedGuides->isNotEmpty())
                <section class="guide-related" aria-labelledby="related-guides">
                    <h2 id="related-guides">Lanjut ke panduan terkait</h2>
                    <ul>@foreach($relatedGuides as $related)<li><a href="{{ route('guides.show', $related['slug']) }}">{{ $related['title'] }}</a></li>@endforeach</ul>
                </section>
            @endif

            <footer class="guide-article-footer">
                <a class="btn btn-default" href="{{ route('guides.index') }}"><i class="fa fa-arrow-left" aria-hidden="true"></i> Semua panduan</a>
                <a class="btn btn-default" href="{{ route('change-log.index') }}">Perubahan terbaru</a>
            </footer>
        </article>

        <aside class="guide-toc" aria-label="Daftar isi panduan">
            <h2>Di halaman ini</h2>
            <ol>
                <li><a href="#guide-prerequisites">Prasyarat</a></li>
                <li><a href="#guide-terms">Istilah</a></li>
                <li><a href="#guide-steps">Langkah penggunaan</a>
                    <ol>
                        @foreach($guide['steps'] as $step)<li><a href="#guide-step-{{ $loop->iteration }}">{{ $step['title'] }}</a></li>@endforeach
                    </ol>
                </li>
                @if(!empty($guide['screenshots']))<li><a href="#guide-screenshots">Gambar yang diuji</a></li>@endif
                <li><a href="#guide-success">Tanda berhasil</a></li>
                <li><a href="#guide-troubleshooting">Masalah umum</a></li>
            </ol>
        </aside>
    </div>
</main>
@endsection
