(function ($) {
    'use strict';

    function messageFrom(xhr, fallback) {
        var response = xhr && xhr.responseJSON ? xhr.responseJSON : {};
        if (response.message) {
            return response.message;
        }
        if (response.errors) {
            var keys = Object.keys(response.errors);
            if (keys.length && response.errors[keys[0]].length) {
                return response.errors[keys[0]][0];
            }
        }
        return fallback;
    }

    function showError($form, message) {
        $form.find('.security-form-error').text(message).show();
    }

    function setBusy($form, busy, busyText) {
        var $button = $form.find('button[type="submit"]');
        if (!$button.data('original-text')) {
            $button.data('original-text', $button.html());
        }
        $button.prop('disabled', busy).html(busy ? '<i class="fas fa-circle-notch fa-spin"></i> ' + busyText : $button.data('original-text'));
        $form.find('button[data-dismiss="modal"], .close').prop('disabled', busy);
    }

    function notify(type, message) {
        if (window.toastr && typeof window.toastr[type] === 'function') {
            window.toastr[type](message);
        } else {
            window.alert(message);
        }
    }

    $(function () {
        var $page = $('#security-audit-page');
        if (!$page.length) {
            return;
        }

        $('#security-report-selector').on('change', function () {
            if (this.value) {
                window.location.href = this.value;
            }
        });

        var findingsTable = null;
        if ($('#security-findings-table').length && $.fn.DataTable) {
            findingsTable = $('#security-findings-table').DataTable({
                pageLength: 25,
                lengthMenu: [[25, 50, 100, -1], [25, 50, 100, 'Semua']],
                order: [[0, 'asc'], [4, 'desc']],
                autoWidth: false,
                columnDefs: [{ targets: [5], orderable: false }]
            });
            $('#security-severity-filter').on('change', function () {
                findingsTable.column(0).search(this.value ? '^' + this.value + '$' : '', true, false).draw();
            });
            $('#security-scope-filter').on('change', function () {
                findingsTable.column(1).search(this.value ? '^' + this.value + '$' : '', true, false).draw();
            });
        }

        $('#security-upload-form').on('submit', function (event) {
            event.preventDefault();
            var $form = $(this);
            $form.find('.security-form-error').hide();
            setBusy($form, true, 'Membaca…');

            $.ajax({
                url: $page.data('upload-url'),
                method: 'POST',
                data: new FormData(this),
                processData: false,
                contentType: false,
                headers: { Accept: 'application/json' }
            }).done(function (response) {
                notify('success', response.message);
                window.location.href = response.redirect;
            }).fail(function (xhr) {
                showError($form, messageFrom(xhr, 'Laporan gagal dibaca.'));
                setBusy($form, false);
            });
        });

        $('#security-run-form').on('submit', function (event) {
            event.preventDefault();
            var $form = $(this);
            var startedAt = Date.now();
            var timer;
            $form.find('.security-form-error').hide();
            $form.find('.security-progress').show();
            setBusy($form, true, 'Audit berjalan…');
            timer = window.setInterval(function () {
                var seconds = Math.floor((Date.now() - startedAt) / 1000);
                $form.find('.security-progress-message').text('Memeriksa file aplikasi… ' + seconds + ' detik. Jangan tutup halaman ini.');
            }, 1000);

            $.ajax({
                url: $page.data('run-url'),
                method: 'POST',
                data: $form.serialize(),
                timeout: 0,
                headers: { Accept: 'application/json' }
            }).done(function (response) {
                window.clearInterval(timer);
                $form.find('.security-progress-message').text('Audit selesai. Membuka laporan…');
                notify('success', response.message);
                window.location.href = response.redirect;
            }).fail(function (xhr) {
                window.clearInterval(timer);
                $form.find('.security-progress').hide();
                showError($form, messageFrom(xhr, 'Audit gagal dijalankan.'));
                setBusy($form, false);
            });
        });

        $('#security-cleanup-form').on('submit', function (event) {
            event.preventDefault();
            var $form = $(this);
            $form.find('.security-form-error').hide();
            setBusy($form, true, 'Memverifikasi & membersihkan…');

            $.ajax({
                url: $form.data('url'),
                method: 'POST',
                data: $form.serialize(),
                headers: { Accept: 'application/json' }
            }).done(function (response) {
                notify('success', response.message);
                window.location.reload();
            }).fail(function (xhr) {
                showError($form, messageFrom(xhr, 'Pembersihan gagal. Tidak ada file yang dihapus tanpa verifikasi.'));
                setBusy($form, false);
            });
        });
    });
})(window.jQuery);
