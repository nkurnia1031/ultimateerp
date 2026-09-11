<li class="guide-nav-indicator tw-list-none">
    <a href="{{ route('guides.index') }}"
        id="guide-nav-link"
        class="guide-nav-indicator__link tw-inline-flex tw-items-center tw-justify-center tw-gap-1 tw-text-sm tw-font-medium tw-text-white hover:tw-text-white tw-transition-all tw-duration-200 tw-bg-@if(!empty(session('business.theme_color'))){{session('business.theme_color')}}@else{{'primary'}}@endif-800 hover:tw-bg-@if(!empty(session('business.theme_color'))){{session('business.theme_color')}}@else{{'primary'}}@endif-700 tw-rounded-lg tw-ring-1 tw-ring-white/10"
        title="Buka Panduan"
        aria-label="Buka Panduan">
        <i class="fa fa-book-open" aria-hidden="true"></i>
        <span class="tw-hidden xl:tw-inline">Panduan</span>
    </a>
</li>
<style>
    .guide-nav-indicator { display: inline-flex; }
    .guide-nav-indicator__link { min-width: 44px; min-height: 44px; padding: 7px 10px; }
    .guide-nav-indicator__link:focus-visible { outline: 3px solid #ffd166; outline-offset: 3px; }
</style>
