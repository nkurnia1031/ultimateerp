@extends('layouts.app')

@section('title', 'Panduan')

@section('css')
<style>
    .guide-shell { max-width: 1440px; margin: 0 auto; padding: 28px 30px 46px; }
    .guide-hero { display: flex; gap: 24px; justify-content: space-between; align-items: flex-end; margin-bottom: 24px; }
    .guide-eyebrow { margin: 0 0 8px; color: #0f6b78; font-size: 12px; font-weight: 700; letter-spacing: .12em; text-transform: uppercase; }
    .guide-hero h1 { margin: 0 0 8px; color: #162b3a; font-size: clamp(26px, 3vw, 40px); letter-spacing: -.035em; }
    .guide-hero p { max-width: 680px; margin: 0; color: #60717b; line-height: 1.65; }
    .guide-hero__actions { display: flex; flex-wrap: wrap; gap: 8px; }
    .guide-search { display: flex; gap: 10px; align-items: center; padding: 14px 16px; margin-bottom: 28px; background: #fff; border: 1px solid #dce6e8; border-radius: 12px; box-shadow: 0 8px 24px rgba(22, 43, 58, .06); }
    .guide-search .form-control { min-height: 42px; border-color: #d6e1e4; box-shadow: none; }
    .guide-search .form-control:focus { border-color: #0f8b8d; box-shadow: 0 0 0 3px rgba(15, 139, 141, .13); }
    .guide-section { margin: 30px 0; }
    .guide-section__heading { display: flex; align-items: baseline; gap: 12px; border-bottom: 1px solid #dce6e8; padding-bottom: 10px; }
    .guide-section__heading h2 { margin: 0; color: #162b3a; font-size: 20px; }
    .guide-section__heading span { color: #82929a; font-size: 13px; }
    .guide-grid { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 16px; margin-top: 16px; }
    .guide-card { display: flex; min-height: 218px; flex-direction: column; overflow: hidden; background: #fff; border: 1px solid #dce6e8; border-radius: 12px; box-shadow: 0 8px 22px rgba(22, 43, 58, .05); transition: transform .2s ease, box-shadow .2s ease, border-color .2s ease; }
    .guide-card__figure { display: block; height: 128px; overflow: hidden; background: #e9f1f0; }
    .guide-card__figure img { display: block; width: 100%; height: 100%; object-fit: cover; }
    .guide-card:hover { border-color: #74b8b8; box-shadow: 0 14px 30px rgba(22, 43, 58, .1); transform: translateY(-2px); }
    .guide-card__body { display: flex; flex: 1; flex-direction: column; padding: 18px; }
    .guide-card__number { color: #0f8b8d; font-size: 12px; font-weight: 700; letter-spacing: .06em; }
    .guide-card h3 { margin: 8px 0 8px; color: #162b3a; font-size: 18px; line-height: 1.3; }
    .guide-card p { flex: 1; margin: 0 0 14px; color: #60717b; font-size: 14px; line-height: 1.6; }
    .guide-card__meta { display: flex; justify-content: space-between; gap: 12px; align-items: center; color: #82929a; font-size: 12px; }
    .guide-card__link { color: #0a7477; font-weight: 700; }
    .guide-card__link:focus-visible, .guide-search button:focus-visible, .guide-hero a:focus-visible { outline: 3px solid #f0b85b; outline-offset: 3px; }
    .guide-empty { padding: 26px; color: #60717b; background: #fff; border: 1px dashed #b9cacc; border-radius: 12px; }
    @media (max-width: 1050px) { .guide-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
    @media (max-width: 680px) { .guide-shell { padding: 20px 14px 36px; } .guide-hero { display: block; } .guide-hero__actions { margin-top: 18px; } .guide-search { display: block; } .guide-search button { width: 100%; margin-top: 10px; } .guide-grid { grid-template-columns: 1fr; } }
</style>
@endsection

@section('content')
<main class="guide-shell" aria-labelledby="guide-index-title">
    <header class="guide-hero">
        <div>
            <p class="guide-eyebrow">Pusat bantuan aplikasi</p>
            <h1 id="guide-index-title">Panduan kerja</h1>
            <p>Ikuti langkah yang sesuai dengan pekerjaan Anda. Setiap panduan menjelaskan persiapan, tombol yang dipakai, hasil yang harus terlihat, dan cara mengatasi masalah umum.</p>
        </div>
        <nav class="guide-hero__actions" aria-label="Navigasi bantuan">
            <a class="btn btn-default" href="{{ route('change-log.index') }}"><i class="fa fa-history" aria-hidden="true"></i> Perubahan terbaru</a>
        </nav>
    </header>

    <form class="guide-search" method="GET" action="{{ route('guides.index') }}" role="search">
        <label class="sr-only" for="guide-search-input">Cari panduan</label>
        <input id="guide-search-input" class="form-control" name="q" value="{{ $query }}" placeholder="Cari: SPB, Bonus Ayak, invoice, payroll..." autocomplete="off">
        <button class="btn btn-primary" type="submit"><i class="fa fa-search" aria-hidden="true"></i> Cari panduan</button>
        @if($query !== '')
            <a class="btn btn-link" href="{{ route('guides.index') }}">Hapus pencarian</a>
        @endif
    </form>

    @forelse($guides->groupBy('category') as $category => $categoryGuides)
        <section class="guide-section" aria-labelledby="guide-category-{{ \Illuminate\Support\Str::slug($category) }}">
            <div class="guide-section__heading">
                <h2 id="guide-category-{{ \Illuminate\Support\Str::slug($category) }}">{{ $category }}</h2>
                <span>{{ $categoryGuides->count() }} panduan</span>
            </div>
            <div class="guide-grid">
                @foreach($categoryGuides as $guide)
                    <article class="guide-card">
                        @if(!empty($guide['screenshots'][0]['file']))
                            @php($guideImage = $guide['screenshots'][0])
                            <a class="guide-card__figure" href="{{ route('guides.show', $guide['slug']) }}" aria-label="Buka panduan {{ $guide['title'] }}">
                                <img src="{{ asset('images/change-log/release-2026-09-10-angkutan/'.$guideImage['file']) }}" alt="{{ $guideImage['alt'] }}" loading="lazy">
                            </a>
                        @endif
                        <div class="guide-card__body">
                            <span class="guide-card__number">PANDUAN {{ str_pad((string) (int) (($guide['order'] ?? ($loop->iteration * 10)) / 10), 2, '0', STR_PAD_LEFT) }}</span>
                            <h3>{{ $guide['title'] }}</h3>
                            <p>{{ $guide['summary'] }}</p>
                            <div class="guide-card__meta">
                                <span>{{ $guide['audience'] }}</span>
                                <a class="guide-card__link" href="{{ route('guides.show', $guide['slug']) }}">Buka <span class="sr-only">{{ $guide['title'] }}</span> <i class="fa fa-arrow-right" aria-hidden="true"></i></a>
                            </div>
                        </div>
                    </article>
                @endforeach
            </div>
        </section>
    @empty
        <div class="guide-empty" role="status">Tidak ada panduan yang cocok dengan pencarian Anda. Coba istilah lain atau <a href="{{ route('guides.index') }}">lihat semua panduan</a>.</div>
    @endforelse
</main>
@endsection
