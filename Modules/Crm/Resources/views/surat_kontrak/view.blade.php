<div class="modal-dialog modal-lg" role="document">
    <div class="modal-content">
        <div class="modal-header">
            <button type="button" class="close" data-dismiss="modal" aria-label="Tutup"><span>&times;</span></button>
            <h4 class="modal-title">Detail Surat Perjanjian Kerja (SPK)</h4>
        </div>

        <div class="modal-body">
            @include('crm::surat_kontrak.partials.document', [
                'surat_kontrak' => $surat_kontrak,
                'rendered_components' => $rendered_components,
                'template' => $template,
                'business' => $business,
                'is_preview' => false,
            ])
        </div>

        <div class="modal-footer">
            <button type="button" class="btn btn-primary" onclick="$('#print_area').print()">
                <i class="fa fa-print"></i> Cetak
            </button>
            <button type="button" class="btn btn-default" data-dismiss="modal">Tutup</button>
        </div>
    </div>
</div>
