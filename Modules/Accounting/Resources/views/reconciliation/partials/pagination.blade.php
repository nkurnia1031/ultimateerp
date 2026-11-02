@if($rows->lastPage() > 1)
    <nav aria-label="Halaman temuan rekonsiliasi">
        <ul class="pagination pagination-sm">
            <li class="{{ $rows->onFirstPage() ? 'disabled' : '' }}"><a href="#" data-finding-page="{{ max(1, $rows->currentPage() - 1) }}" aria-label="Sebelumnya">&laquo;</a></li>
            <li class="disabled"><span>Halaman {{ $rows->currentPage() }} dari {{ $rows->lastPage() }}</span></li>
            <li class="{{ $rows->hasMorePages() ? '' : 'disabled' }}"><a href="#" data-finding-page="{{ min($rows->lastPage(), $rows->currentPage() + 1) }}" aria-label="Berikutnya">&raquo;</a></li>
        </ul>
    </nav>
@endif
