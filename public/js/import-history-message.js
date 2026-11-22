(function ($, window, document) {
    'use strict';

    var previewLimit = 40;
    var modalId = 'import-history-message-modal';

    function escapeHtml(value) {
        var element = document.createElement('div');
        element.textContent = value == null ? '' : String(value);
        return element.innerHTML;
    }

    function escapeAttribute(value) {
        return escapeHtml(value).replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }

    window.importHistoryMessageCell = function (value, type, fallback) {
        var message = value == null ? '' : String(value);
        if (message === '' && fallback != null) message = String(fallback);
        if (type && type !== 'display') return message;
        if (message === '') return '';

        var characters = Array.from(message);
        if (characters.length <= previewLimit) {
            return '<span class="import-history-message-preview">' + escapeHtml(message) + '</span>';
        }

        var preview = characters.slice(0, previewLimit).join('') + '…';
        return '<span class="import-history-message-preview">' + escapeHtml(preview) + '</span> '
            + '<button type="button" class="btn btn-link btn-xs import-history-message-open" '
            + 'aria-haspopup="dialog" data-import-history-message="' + escapeAttribute(message) + '">'
            + 'Lihat lengkap</button>';
    };

    function ensureModal() {
        var existing = document.getElementById(modalId);
        if (existing) return $(existing);

        var modal = $('<div>', {
            id: modalId,
            'class': 'modal fade',
            tabindex: '-1',
            role: 'dialog',
            'aria-labelledby': modalId + '-title',
            'aria-modal': 'true'
        }).append(
            $('<div>', { 'class': 'modal-dialog modal-lg', role: 'document' }).append(
                $('<div>', { 'class': 'modal-content' }).append(
                    $('<div>', { 'class': 'modal-header' }).append(
                        $('<button>', {
                            type: 'button',
                            'class': 'close',
                            'data-dismiss': 'modal',
                            'aria-label': 'Tutup'
                        }).append($('<span>', { 'aria-hidden': 'true' }).text('×')),
                        $('<h4>', { 'class': 'modal-title', id: modalId + '-title' }).text('Pesan import lengkap')
                    ),
                    $('<div>', { 'class': 'modal-body' }).append(
                        $('<pre>', {
                            'class': 'import-history-message-body',
                            css: {
                                maxHeight: '60vh',
                                overflow: 'auto',
                                whiteSpace: 'pre-wrap',
                                overflowWrap: 'anywhere',
                                wordBreak: 'break-word'
                            }
                        })
                    )
                )
            )
        );

        $('body').append(modal);
        return modal;
    }

    $(document).on('click', '.import-history-message-open', function () {
        var message = $(this).attr('data-import-history-message') || '';
        var modal = ensureModal();
        modal.find('.import-history-message-body').text(message);
        modal.modal('show');
    });

    $(function () {
        $('[data-import-history-message-source]').each(function () {
            var source = this;
            source.innerHTML = window.importHistoryMessageCell(source.textContent || '', 'display');
        });
    });
})(window.jQuery, window, document);
