(function ($) {
    'use strict';

    var form = $('#business_contact_import_form');
    if (!form.length) {
        return;
    }

    var modal = $('#business_contact_import_modal');
    var source = $('#business_contact_import_source');
    var previewButton = $('#business_contact_import_preview_button');
    var submitButton = $('#business_contact_import_submit');
    var loading = $('#business_contact_import_loading');
    var error = $('#business_contact_import_error');
    var preview = $('#business_contact_import_preview');
    var previewBody = $('#business_contact_import_preview_table tbody');
    var csrf = form.find('input[name="_token"]').val();
    var checked = false;

    function escapeHtml(value) {
        return $('<div>').text(value === null || typeof value === 'undefined' ? '' : value).html();
    }

    function resetPreview() {
        checked = false;
        submitButton.prop('disabled', true);
        preview.hide();
        error.hide().empty();
        previewBody.empty();
        $('[data-import-count]').text('0');
        $('#business_contact_import_truncated').hide();
    }

    source.on('change', resetPreview);
    modal.on('shown.bs.modal', resetPreview);

    previewButton.on('click', function () {
        var sourceId = source.val();
        if (!sourceId) {
            error.text('Pilih bisnis sumber terlebih dahulu.').show();
            return;
        }

        resetPreview();
        loading.show();
        previewButton.prop('disabled', true);
        $.ajax({
            url: form.data('preview-url'),
            method: 'POST',
            dataType: 'json',
            data: {
                _token: csrf,
                type: form.find('input[name="type"]').val(),
                source_business_id: sourceId
            }
        }).done(function (result) {
            var counts = result.counts || {};
            $('[data-import-count="total"]').text(counts.total || 0);
            $('[data-import-count="new"]').text(counts.new || 0);
            $('[data-import-count="existing"]').text(counts.existing || 0);
            (result.rows || []).forEach(function (row) {
                var isNew = row.status === 'new';
                var status = isNew ? '<span class="label label-success">Baru</span>' : '<span class="label label-warning">Sudah ada</span>';
                var matchedBy = row.matched_by ? ' (' + escapeHtml(row.matched_by) + ')' : '';
                previewBody.append('<tr><td>' + escapeHtml(row.name) + '</td><td>' + escapeHtml(row.business_name) + '</td><td>' + escapeHtml(row.contact_id) + '</td><td>' + escapeHtml(row.email) + '</td><td>' + escapeHtml(row.mobile) + '</td><td>' + status + matchedBy + '</td></tr>');
            });
            $('#business_contact_import_truncated').toggle(!!result.truncated);
            preview.show();
            checked = true;
            submitButton.prop('disabled', Number(counts.new || 0) < 1);
        }).fail(function (xhr) {
            var message = xhr.responseJSON && xhr.responseJSON.message ? xhr.responseJSON.message : 'Cross-check kontak gagal.';
            error.text(message).show();
        }).always(function () {
            loading.hide();
            previewButton.prop('disabled', false);
        });
    });

    form.on('submit', function (event) {
        event.preventDefault();
        if (!checked || submitButton.prop('disabled')) {
            return;
        }

        submitButton.prop('disabled', true);
        previewButton.prop('disabled', true);
        loading.text('Kontak sedang diimpor...').show();
        $.ajax({
            url: form.attr('action'),
            method: 'POST',
            dataType: 'json',
            data: form.serialize(),
            headers: { Accept: 'application/json' }
        }).done(function (result) {
            modal.modal('hide');
            if (window.toastr) {
                toastr.success(result.message || 'Kontak berhasil diimpor.');
            }
            if (typeof contact_table !== 'undefined' && contact_table) {
                contact_table.ajax.reload(null, false);
            } else {
                window.location.reload();
            }
        }).fail(function (xhr) {
            var message = xhr.responseJSON && xhr.responseJSON.message ? xhr.responseJSON.message : 'Import kontak gagal.';
            error.text(message).show();
            submitButton.prop('disabled', false);
            previewButton.prop('disabled', false);
        }).always(function () {
            loading.hide();
        });
    });
})(window.jQuery);
