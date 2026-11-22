(function ($, window) {
    'use strict';

    var page = $('#business-copy-preview-page');
    if (!page.length) return;

    var kind = page.data('kind');
    var type = page.data('type') || '';
    var sourceBusinessId = page.data('source-business-id');
    var loading = $('#business-copy-preview-loading');
    var error = $('#business-copy-preview-error');
    var success = $('#business-copy-preview-success');
    var content = $('#business-copy-preview-content');
    var importButton = $('#business-copy-preview-import');
    var tbody = $('#business-copy-preview-table tbody');
    var token = $('meta[name="csrf-token"]').attr('content');
    var hasPreview = false;

    function escapeHtml(value) {
        return $('<div>').text(value == null ? '' : String(value)).html();
    }

    function statusCell(row) {
        var isNew = row.status === 'new';
        var label = '<span class="label ' + (isNew ? 'label-success">Baru' : 'label-warning">Sudah ada') + '</span>';
        return kind === 'contacts' && row.matched_by
            ? label + ' <small class="text-muted">(' + escapeHtml(row.matched_by) + ')</small>'
            : label;
    }

    function renderContact(row) {
        return '<tr><td>' + escapeHtml(row.name) + '</td><td>' + escapeHtml(row.business_name)
            + '</td><td>' + escapeHtml(row.contact_id) + '</td><td>' + escapeHtml(row.email)
            + '</td><td>' + escapeHtml(row.mobile) + '</td><td>' + statusCell(row) + '</td></tr>';
    }

    function renderExpenseCategory(row) {
        return '<tr><td>' + escapeHtml(row.path) + '</td><td>' + escapeHtml(row.name)
            + '</td><td>' + escapeHtml(row.code || '—') + '</td><td>' + statusCell(row)
            + '</td><td>' + escapeHtml(row.matched_by || '—') + '</td></tr>';
    }

    function loadPreview() {
        loading.prop('hidden', false).show();
        error.prop('hidden', true).empty();
        content.prop('hidden', true);
        importButton.prop('disabled', true);
        var data = { _token: token, source_business_id: sourceBusinessId };
        if (type) data.type = type;

        $.ajax({
            url: page.data('preview-url'),
            method: 'POST',
            dataType: 'json',
            headers: { Accept: 'application/json' },
            data: data
        }).done(function (result) {
            var counts = result.counts || {};
            $('[data-business-copy-count="total"]').text(counts.total || 0);
            $('[data-business-copy-count="new"]').text(counts.new || 0);
            $('[data-business-copy-count="existing"]').text(counts.existing || 0);
            tbody.empty();
            (result.rows || []).forEach(function (row) {
                tbody.append(kind === 'contacts' ? renderContact(row) : renderExpenseCategory(row));
            });
            $('[data-business-copy-truncated]').prop('hidden', !result.truncated).toggle(!!result.truncated);
            content.prop('hidden', false).show();
            hasPreview = true;
            importButton.prop('disabled', Number(counts.new || 0) < 1);
        }).fail(function (xhr) {
            var response = xhr.responseJSON || {};
            error.text(response.message || response.msg || 'Cross-check import gagal.').prop('hidden', false).show();
        }).always(function () {
            loading.prop('hidden', true).hide();
        });
    }

    importButton.on('click', function () {
        if (!hasPreview || importButton.prop('disabled')) return;
        var payload = {
            _token: token,
            source_business_id: sourceBusinessId,
            skip_existing: $('#business-copy-preview-skip-existing').is(':checked') ? 1 : 0
        };
        if (type) payload.type = type;

        importButton.prop('disabled', true).html('<i class="fa fa-spinner fa-spin"></i> Mengantrekan...');
        error.prop('hidden', true).empty();
        $.ajax({
            url: page.data('import-url'),
            method: 'POST',
            dataType: 'json',
            headers: { Accept: 'application/json' },
            data: payload
        }).done(function (result) {
            hasPreview = false;
            content.prop('hidden', true).hide();
            success.text(result.message || result.msg || 'Import masuk antrean.').prop('hidden', false).show();
            importButton.html('<i class="fa fa-check"></i> Import masuk antrean');
        }).fail(function (xhr) {
            var response = xhr.responseJSON || {};
            error.text(response.message || response.msg || 'Import gagal.').prop('hidden', false).show();
            importButton.prop('disabled', false).html('<i class="fa fa-upload"></i> Import ' + (kind === 'contacts' ? 'kontak' : 'kategori') + ' baru');
        });
    });

    loadPreview();
})(window.jQuery, window);
