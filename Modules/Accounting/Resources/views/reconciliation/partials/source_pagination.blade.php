@if($rows->total() > 0)
    <div class="row">
        <div class="col-sm-6"><span class="text-muted">Menampilkan {{ $rows->firstItem() }}–{{ $rows->lastItem() }} dari {{ $rows->total() }} kelompok sumber</span></div>
        <div class="col-sm-6 text-right">
            <button type="button" class="btn btn-default btn-sm js-source-finding-page" data-page="{{ max(1, $rows->currentPage() - 1) }}" @if($rows->currentPage() <= 1) disabled @endif>Sebelumnya</button>
            <span class="text-muted">Halaman {{ $rows->currentPage() }} / {{ max(1, $rows->lastPage()) }}</span>
            <button type="button" class="btn btn-default btn-sm js-source-finding-page" data-page="{{ min($rows->lastPage(), $rows->currentPage() + 1) }}" @if($rows->currentPage() >= $rows->lastPage()) disabled @endif>Berikutnya</button>
        </div>
    </div>
@endif
