(function ($, window) {
    'use strict';

    var form = $('#business_contact_import_form');
    if (!form.length) return;

    $('#business_contact_import_preview_button').on('click', function () {
        var sourceId = $('#business_contact_import_source').val();
        if (!sourceId) {
            window.alert('Pilih bisnis sumber terlebih dahulu.');
            return;
        }

        var url = new URL(form.data('preview-page-url'), window.location.href);
        url.searchParams.set('type', form.find('[name="type"]').val());
        url.searchParams.set('source_business_id', sourceId);
        url.searchParams.set('skip_existing', form.find('[name="skip_existing"]').is(':checked') ? '1' : '0');
        window.location.assign(url.toString());
    });
})(window.jQuery, window);
