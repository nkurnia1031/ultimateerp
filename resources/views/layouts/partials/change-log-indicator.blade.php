@php
    $change_log_catalog = app(\App\Support\ChangeLogCatalog::class);
    $change_log_release_ids = $change_log_catalog->publishedReleaseIdsFor(auth()->user());
    $change_log_read_ids = $change_log_release_ids->isEmpty()
        ? collect()
        : \App\ChangeLogRead::query()
            ->where('user_id', auth()->id())
            ->where('business_id', (int) session('user.business_id'))
            ->whereIn('release_id', $change_log_release_ids->all())
            ->pluck('release_id');
    $change_log_has_unread = $change_log_release_ids->diff($change_log_read_ids)->isNotEmpty();
@endphp

<li class="change-log-indicator tw-list-none">
    <a href="{{ route('change-log.index') }}"
        id="change-log-indicator"
        class="change-log-indicator__link @if($change_log_has_unread)is-unread @endif tw-inline-flex tw-items-center tw-justify-center tw-text-sm tw-font-bold tw-text-white hover:tw-text-white tw-transition-all tw-duration-200 tw-bg-@if(!empty(session('business.theme_color'))){{session('business.theme_color')}}@else{{'primary'}}@endif-800 hover:tw-bg-@if(!empty(session('business.theme_color'))){{session('business.theme_color')}}@else{{'primary'}}@endif-700 tw-rounded-full tw-ring-1 tw-ring-white/10"
        title="Perubahan terbaru aplikasi"
        aria-label="Perubahan terbaru aplikasi"
        data-unread="{{ $change_log_has_unread ? '1' : '0' }}"
        data-read-url="{{ route('change-log.read') }}"
        data-release-ids='@json($change_log_release_ids->values())'>
        <span aria-hidden="true">!</span>
        <span class="tw-sr-only">Perubahan terbaru aplikasi</span>
    </a>
</li>

<style>
    .change-log-indicator { position: relative; display: inline-flex; }
    .change-log-indicator__link { position: relative; width: 44px; height: 44px; font-size: 22px; line-height: 1; text-decoration: none; }
    .change-log-indicator__link.is-unread::before { content: ''; position: absolute; inset: -4px; border: 2px solid rgba(255, 193, 7, .8); border-radius: 999px; pointer-events: none; animation: change-log-indicator-pulse 2.2s ease-out infinite; }
    .change-log-indicator__link:focus-visible { outline: 3px solid #ffd166; outline-offset: 3px; }
    @keyframes change-log-indicator-pulse {
        0% { transform: scale(.92); opacity: .9; }
        70%, 100% { transform: scale(1.18); opacity: 0; }
    }
    @media (prefers-reduced-motion: reduce) {
        .change-log-indicator__link.is-unread::before { animation: none; opacity: .75; }
    }
</style>
