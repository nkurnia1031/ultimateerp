@extends('layouts.app')

@section('title', $release['title'])

@section('css')
<style>
    .change-log-detail .change-log-section { margin-bottom: 22px; }
    .change-log-detail .change-log-section h2 { margin-top: 0; font-size: 20px; }
    .change-log-detail .change-log-section h3 { font-size: 16px; margin-top: 18px; }
    .change-log-detail .change-log-steps { counter-reset: guide-step; padding-left: 0; list-style: none; }
    .change-log-detail .change-log-steps > li { counter-increment: guide-step; position: relative; padding: 0 0 14px 42px; }
    .change-log-detail .change-log-steps > li::before { content: counter(guide-step); position: absolute; left: 0; top: 0; width: 28px; height: 28px; border-radius: 50%; background: #1677d2; color: #fff; text-align: center; line-height: 28px; font-weight: 700; }
    .change-log-detail .change-log-callout { border-left: 4px solid #1677d2; background: #f4f8fc; padding: 13px 16px; margin: 12px 0; }
    .change-log-detail .change-log-term { margin-bottom: 10px; }
    .change-log-detail .change-log-term dt { color: #1677d2; }
    .change-log-detail .change-log-term dd { margin-left: 0; }
    .change-log-detail .change-log-links { padding-left: 20px; }
    .change-log-detail .change-log-guide-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 14px; }
    .change-log-detail .change-log-guide-card { display: flex; gap: 14px; align-items: flex-start; padding: 13px; background: #f7faf9; border: 1px solid #dce6e8; border-radius: 10px; }
    .change-log-detail .change-log-guide-card img { width: 112px; height: 76px; object-fit: cover; border-radius: 7px; }
    .change-log-detail .change-log-guide-card h3 { margin: 0 0 5px; font-size: 16px; }
    .change-log-detail .change-log-guide-card p { margin: 0 0 7px; font-size: 13px; }
    .change-log-detail .change-log-guide-card a { color: #0a7477; font-weight: 700; }
    @media (max-width: 767px) {
        .change-log-detail .content-header .btn { float: none !important; display: block; width: 100%; margin-bottom: 12px; }
        .change-log-detail .box-body { padding: 15px; }
        .change-log-detail .change-log-steps > li { padding-left: 38px; }
        .change-log-detail .change-log-guide-grid { grid-template-columns: 1fr; }
        .change-log-detail .change-log-guide-card img { width: 88px; height: 60px; }
    }
</style>
@endsection

@section('content')
<div class="change-log-detail">
    <section class="content-header" aria-labelledby="change-log-detail-title">
        <a class="btn btn-default pull-right" href="{{ route('change-log.index') }}">Kembali ke Change Log</a>
        <h1 id="change-log-detail-title">{{ $release['title'] }}</h1>
        <p class="text-muted">{{ $release['category'] }} · {{ Illuminate\Support\Carbon::parse($release['published_at'])->format('d-m-Y') }}</p>
    </section>
    <section class="content" aria-label="Panduan perubahan">
        <div class="box box-primary">
            <div class="box-body">
                <section class="change-log-section" aria-labelledby="change-log-benefit-title">
                    <h2 id="change-log-benefit-title">Apa yang berubah?</h2>
                    <p>{{ $release['summary'] }}</p>
                    <div class="change-log-callout"><strong>Manfaat untuk pekerjaan sehari-hari:</strong> {{ $release['benefit'] }}</div>
                    <p><strong>Siapa yang memakai:</strong> {{ $release['audience'] }}</p>
                </section>

                <section class="change-log-section" aria-labelledby="change-log-terms-title">
                    <h2 id="change-log-terms-title">Istilah penting</h2>
                    <dl>
                        @foreach ($release['terms'] as $term)
                            <div class="change-log-term">
                                <dt><strong>{{ $term['term'] }}</strong></dt>
                                <dd>{{ $term['description'] }}</dd>
                            </div>
                        @endforeach
                    </dl>
                </section>

                <section class="change-log-section" aria-labelledby="change-log-preparation-title">
                    <h2 id="change-log-preparation-title">Sebelum mulai</h2>
                    <ul>
                        @foreach ($release['preparation'] as $item)
                            <li>{{ $item }}</li>
                        @endforeach
                    </ul>
                </section>

                <section class="change-log-section" aria-labelledby="change-log-steps-title">
                    <h2 id="change-log-steps-title">Cara memakai alur angkutan</h2>
                    <ol class="change-log-steps">
                        @foreach ($release['steps'] as $step)
                            <li>
                                <h3>{{ $step['title'] }}</h3>
                            <p>{{ ($step['title'] === 'Selesaikan dan verifikasi' && !empty($release['verified_step_description'])) ? $release['verified_step_description'] : $step['description'] }}</p>
                            </li>
                        @endforeach
                    </ol>
                </section>

                <section class="change-log-section" aria-labelledby="change-log-example-title">
                    <h2 id="change-log-example-title">{{ $release['example']['title'] }}</h2>
                    <div class="change-log-callout">{{ $release['verified_example_body'] ?? $release['example']['body'] }}</div>
                    <p><strong>Pemisahan angka:</strong> {{ $release['separation_note'] }}</p>
                </section>

                <section class="change-log-section" aria-labelledby="change-log-results-title">
                    <h2 id="change-log-results-title">Tanda proses berhasil</h2>
                    <ul>
                        @foreach ($release['expected_results'] as $item)
                            <li>{{ $item }}</li>
                        @endforeach
                    </ul>
                </section>

                <section class="change-log-section" aria-labelledby="change-log-issues-title">
                    <h2 id="change-log-issues-title">Masalah yang sering terjadi</h2>
                    <dl>
                        @foreach ($release['common_issues'] as $issue)
                            <div class="change-log-term">
                                <dt><strong>{{ $issue['problem'] }}</strong></dt>
                                <dd>{{ $issue['solution'] }}</dd>
                            </div>
                        @endforeach
                    </dl>
                </section>

                <section class="change-log-section" aria-labelledby="change-log-guides-title">
                    <h2 id="change-log-guides-title">Panduan lengkap per topik</h2>
                    <p>Mulai dari topik yang sesuai dengan pekerjaan Anda. Setiap artikel berisi prasyarat, langkah bernomor, hasil yang diharapkan, masalah umum, dan screenshot yang sudah diberi penanda.</p>
                    @if($guides->isNotEmpty())
                        <div class="change-log-guide-grid">
                            @foreach($guides as $guide)
                                @php($guideImage = $guide['screenshots'][0]['file'] ?? null)
                                <article class="change-log-guide-card">
                                    @if($guideImage)<img src="{{ asset('images/change-log/release-2026-09-10-angkutan/'.$guideImage) }}" alt="{{ $guide['screenshots'][0]['alt'] }}" loading="lazy">@endif
                                    <div>
                                        <h3>{{ $guide['title'] }}</h3>
                                        <p>{{ $guide['summary'] }}</p>
                                        <a href="{{ route('guides.show', $guide['slug']) }}">Buka panduan ini <i class="fa fa-arrow-right" aria-hidden="true"></i></a>
                                    </div>
                                </article>
                            @endforeach
                        </div>
                    @else
                        <p>Belum ada panduan yang tersedia untuk akun Anda.</p>
                    @endif
                    <p><a class="btn btn-default" href="{{ route('guides.index') }}"><i class="fa fa-book-open" aria-hidden="true"></i> Buka semua Panduan</a></p>
                </section>

                <section class="change-log-section" aria-labelledby="change-log-related-title">
                    <h2 id="change-log-related-title">Buka fitur terkait</h2>
                    <ul class="change-log-links">
                        @forelse ($links as $link)
                            <li><a href="{{ $link['url'] }}">{{ $link['label'] }}</a></li>
                        @empty
                            <li>Belum ada tindakan yang tersedia untuk akun Anda.</li>
                        @endforelse
                    </ul>
                    <a class="btn btn-default" href="{{ route('change-log.index') }}">Kembali ke ringkasan perubahan</a>
                </section>
            </div>
        </div>
    </section>
</div>
@endsection
