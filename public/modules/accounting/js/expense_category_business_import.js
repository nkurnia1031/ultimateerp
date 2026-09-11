(function ($) {
    'use strict';

    var form = $('#business_expense_category_import_form');
    if (!form.length) {
        return;
    }

    var modal = $('#business_expense_category_import_modal');
    var source = $('#business_expense_category_import_source');
    var previewButton = $('#business_expense_category_import_preview_button');
    var submitButton = $('#business_expense_category_import_submit');
    var loading = $('#business_expense_category_import_loading');
    var error = $('#business_expense_category_import_error');
    var preview = $('#business_expense_category_import_preview');
    var previewBody = $('#business_expense_category_import_preview_table tbody');
    var csrf = form.find('input[name="_token"]').val();

    function escapeHtml(value) {
        return $('<div>').text(value === null || typeof value === 'undefined' ? '' : value).html();
    }

    function resetPreview() {
        submitButton.prop('disabled', true);
        preview.hide();
        error.hide().empty();
        previewBody.empty();
        $('[data-expense-category-import-count]').text('0');
        $('#business_expense_category_import_truncated').hide();
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
            headers: { Accept: 'application/json' },
            data: { _token: csrf, source_business_id: sourceId }
        }).done(function (result) {
            var counts = result.counts || {};
            $('[data-expense-category-import-count="total"]').text(counts.total || 0);
            $('[data-expense-category-import-count="new"]').text(counts.new || 0);
            $('[data-expense-category-import-count="existing"]').text(counts.existing || 0);
            (result.rows || []).forEach(function (row) {
                var isNew = row.status === 'new';
                var status = isNew ? '<span class="label label-success">Baru</span>' : '<span class="label label-warning">Sudah ada</span>';
                var matchedBy = row.matched_by ? escapeHtml(row.matched_by) : '—';
                previewBody.append('<tr><td>' + escapeHtml(row.path) + '</td><td>' + escapeHtml(row.name) + '</td><td>' + escapeHtml(row.code || '—') + '</td><td>' + status + '</td><td>' + matchedBy + '</td></tr>');
            });
            $('#business_expense_category_import_truncated').toggle(!!result.truncated);
            preview.show();
            submitButton.prop('disabled', Number(counts.new || 0) < 1);
        }).fail(function (xhr) {
            var message = xhr.responseJSON && (xhr.responseJSON.message || xhr.responseJSON.msg) ? (xhr.responseJSON.message || xhr.responseJSON.msg) : 'Cross-check kategori biaya gagal.';
            error.text(message).show();
        }).always(function () {
            loading.hide();
            previewButton.prop('disabled', false);
        });
    });

    form.on('submit', function (event) {
        event.preventDefault();
        if (submitButton.prop('disabled')) {
            return;
        }
        var originalText = submitButton.html();
        submitButton.prop('disabled', true).html('<i class="fa fa-spinner fa-spin"></i> Mengimpor...');
        error.hide().empty();
        $.ajax({
            url: form.attr('action'),
            method: 'POST',
            dataType: 'json',
            headers: { Accept: 'application/json' },
            data: form.serialize()
        }).done(function (result) {
            modal.modal('hide');
            if (window.toastr) {
                toastr.success(result.message || result.msg || 'Kategori biaya berhasil diimpor.');
            }
            if ($.fn.dataTable && $.fn.dataTable.isDataTable('#expense_category_table')) {
                $('#expense_category_table').DataTable().ajax.reload(null, false);
            }
        }).fail(function (xhr) {
            var message = xhr.responseJSON && (xhr.responseJSON.message || xhr.responseJSON.msg) ? (xhr.responseJSON.message || xhr.responseJSON.msg) : 'Import kategori biaya gagal.';
            error.text(message).show();
            submitButton.prop('disabled', false);
        }).always(function () {
            submitButton.html(originalText);
        });
    });
})(window.jQuery);
