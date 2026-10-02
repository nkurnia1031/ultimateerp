@extends('layouts.app')
@section('title', 'Impor user dari business lain')

@section('content')
<section class="content-header">
    <div class="tw-flex tw-flex-wrap tw-items-center tw-justify-between tw-gap-3">
        <div>
            <h1 class="tw-text-xl md:tw-text-3xl tw-font-bold tw-text-black">Impor user dari business lain</h1>
            <p class="tw-mb-0 tw-text-gray-600">Tujuan: <strong>{{ $targetBusiness->name }}</strong>. Salin satu user, lalu tinjau username dan pemetaan akses.</p>
        </div>
        <a href="{{ action([\App\Http\Controllers\ManageUserController::class, 'index']) }}" class="tw-dw-btn tw-dw-btn-outline">Kembali ke User Management</a>
    </div>
</section>

<section class="content">
    <div class="alert alert-info" role="note">
        Password akun sumber akan dipertahankan. Role, lokasi, dan batasan kontak dipetakan ke business tujuan. Business tujuan mengikuti business yang sedang aktif.
    </div>
    @if($sourceBusinesses->isEmpty())
        <div class="alert alert-warning" role="status">Belum ada business lain yang dapat dijadikan sumber impor.</div>
    @endif

    <div class="box box-primary">
        <div class="box-header with-border"><h3 class="box-title">Pilih user sumber</h3></div>
        <div class="box-body">
            <div class="row">
                <div class="col-sm-6">
                    <div class="form-group">
                        <label for="source_business_id">Business sumber <span class="text-danger">*</span></label>
                        <select id="source_business_id" class="form-control select2" style="width:100%" @if($sourceBusinesses->isEmpty()) disabled @endif>
                            <option value="">Pilih business sumber</option>
                            @foreach($sourceBusinesses as $business)
                                <option value="{{ $business->id }}">{{ $business->name }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>
                <div class="col-sm-6">
                    <div class="form-group">
                        <label for="source_user_id">User sumber <span class="text-danger">*</span></label>
                        <select id="source_user_id" class="form-control" style="width:100%" disabled>
                            <option value="">Pilih business terlebih dahulu</option>
                        </select>
                        <p class="help-block">Cari berdasarkan nama, email, atau username. Hanya user biasa yang ditampilkan.</p>
                    </div>
                </div>
            </div>

            <div id="import_error" class="alert alert-danger hide" role="alert" aria-live="assertive"></div>
            <div id="import_success" class="alert alert-success hide" role="status" aria-live="polite"></div>

            <div id="source_user_summary" class="hide">
                <hr>
                <h4>Identitas dan username tujuan</h4>
                <div class="row">
                    <div class="col-sm-6">
                        <div class="form-group">
                            <label for="source_username">Username di business sumber</label>
                            <input id="source_username" class="form-control" type="text" readonly>
                        </div>
                    </div>
                    <div class="col-sm-6">
                        <div class="form-group">
                            <label for="destination_username">Username di business tujuan</label>
                            <input id="destination_username" class="form-control" type="text" maxlength="191" autocomplete="off">
                            <p class="help-block">Suffix business sumber diganti dengan suffix business tujuan. Nilai ini dapat diedit.</p>
                            <p id="import_username_feedback" class="help-block" aria-live="polite"></p>
                        </div>
                    </div>
                </div>
                <div id="source_profile_summary" class="tw-mb-4"></div>

                <hr>
                <h4>Pemetaan akses business tujuan</h4>
                <p class="text-muted">Permission fitur mengikuti role tujuan yang dipilih. Role, lokasi, dan kontak yang belum memiliki padanan wajib dipetakan sebelum impor.</p>
                <div id="role_mappings"></div>
                <div id="location_mappings"></div>
                <div id="contact_mappings"></div>

                <div class="tw-mt-5 tw-flex tw-flex-wrap tw-items-center tw-gap-3">
                    <button type="button" id="submit_user_import" class="tw-dw-btn tw-dw-btn-primary" disabled>
                        <i class="fa fa-copy" aria-hidden="true"></i> Impor user
                    </button>
                    <span id="import_submit_hint" class="text-muted">Pilih user sumber untuk melihat ringkasan dan pemetaan.</span>
                </div>
            </div>
        </div>
    </div>
</section>
@endsection

@section('javascript')
<script>
    $(function () {
        const csrfToken = @json(csrf_token());
        const urls = {
            sourceUsers: @json(route('users.import-business.source-users')),
            preview: @json(route('users.import-business.preview')),
            targetContacts: @json(route('users.import-business.target-contacts')),
            store: @json(route('users.import-business.store')),
            username: @json(route('users.check-username-details'))
        };
        let currentPreview = null;
        let usernameTimer = null;
        let usernameSequence = 0;
        let usernameAvailable = false;
        let isSubmitting = false;

        $('#source_business_id').select2({ placeholder: 'Pilih business sumber', allowClear: true });

        $('#source_business_id').on('change', function () {
            currentPreview = null;
            $('#source_user_summary').addClass('hide');
            $('#submit_user_import').prop('disabled', true);
            if ($('#source_user_id').data('select2')) $('#source_user_id').select2('destroy');
            $('#source_user_id').prop('disabled', !this.value).val(null).empty().append(
                $('<option>', { value: '', text: this.value ? 'Cari user sumber' : 'Pilih business terlebih dahulu' })
            ).trigger('change');
            if (!this.value) return;

            $('#source_user_id').select2({
                placeholder: 'Cari user dari business terpilih',
                minimumInputLength: 2,
                ajax: {
                    url: urls.sourceUsers,
                    type: 'POST',
                    delay: 250,
                    dataType: 'json',
                    data: function (params) {
                        return {
                            _token: csrfToken,
                            source_business_id: $('#source_business_id').val(),
                            q: params.term || ''
                        };
                    },
                    processResults: function (items) { return { results: items }; }
                }
            });
        });

        $('#source_user_id').on('select2:select', function (event) {
            loadPreview(event.params.data.id);
        });
        $('#source_user_id').on('select2:clear', function () {
            currentPreview = null;
            usernameAvailable = false;
            $('#submit_user_import').prop('disabled', true);
            $('#source_user_summary').addClass('hide');
        });

        function escapeHtml(value) {
            return $('<div>').text(value == null ? '' : String(value)).html();
        }

        function showError(message) {
            $('#import_error').removeClass('hide').text(message || 'Permintaan gagal. Periksa data dan coba lagi.');
            $('#import_success').addClass('hide').text('');
        }

        function clearMessages() {
            $('#import_error, #import_success').addClass('hide').text('');
        }

        function loadPreview(sourceUserId) {
            clearMessages();
            currentPreview = null;
            usernameAvailable = false;
            $('#source_user_summary').removeClass('hide');
            $('#submit_user_import').prop('disabled', true);
            $('#import_submit_hint').text('Memuat data sumber dan padanan akses…');
            $.ajax({
                method: 'POST',
                url: urls.preview,
                dataType: 'json',
                data: {
                    _token: csrfToken,
                    source_business_id: $('#source_business_id').val(),
                    source_user_id: sourceUserId
                }
            }).done(function (preview) {
                currentPreview = preview;
                renderPreview(preview);
                checkUsername();
            }).fail(function (xhr) {
                currentPreview = null;
                $('#source_user_summary').addClass('hide');
                showError(xhr.responseJSON && xhr.responseJSON.message ? xhr.responseJSON.message : 'Data user sumber tidak dapat dimuat.');
            });
        }

        function renderPreview(preview) {
            const user = preview.source_user;
            usernameAvailable = !user.allow_login;
            $('#source_username').val(user.username || '—');
            $('#destination_username').val(preview.destination_username || '').prop('disabled', !user.allow_login);
            if (!user.allow_login) {
                $('#import_username_feedback').text('Login user sumber nonaktif; username tujuan tidak diperlukan.');
            } else {
                $('#import_username_feedback').text('Memeriksa username tujuan…');
            }

            $('#source_profile_summary').html(
                '<div class="well well-sm"><strong>' + escapeHtml(user.name) + '</strong>' +
                '<div>Email: ' + escapeHtml(user.email || '—') + '</div>' +
                '<div>Status: ' + escapeHtml(user.status || '—') + ' · ' + (user.allow_login ? 'Login aktif' : 'Login nonaktif') + '</div>' +
                '<div>Telepon: ' + escapeHtml(user.profile_summary.contact_number || '—') + '</div></div>'
            );

            renderRoleMappings(preview.roles || [], preview.target_role_options || []);
            renderLocationMappings(preview);
            renderContactMappings(preview);
            updateImportButton();
        }

        function renderRoleMappings(roles, targetRoleOptions) {
            const container = $('#role_mappings').empty();
            if (!roles.length) {
                container.append('<p class="text-muted">User sumber belum memiliki role. Tidak ada pemetaan role untuk disalin.</p>');
                return;
            }
            const section = $('<div class="well well-sm"><strong>Role</strong></div>');
            roles.forEach(function (item) {
                const selectId = 'target_role_' + item.source_role_id;
                const label = $('<label>', { for: selectId }).text('Role sumber: ' + item.source_role_name);
                const select = $('<select class="form-control import-role-map" required></select>')
                    .attr('id', selectId)
                    .attr('data-source-role-id', item.source_role_id)
                    .append($('<option>', { value: '', text: 'Pilih role business tujuan' }));
                targetRoleOptions.forEach(function (match) {
                    select.append($('<option>', { value: match.id, text: match.name + (match.has_all_locations ? ' · semua lokasi' : '') }));
                });
                if (item.suggested_target_role_id) select.val(String(item.suggested_target_role_id));
                section.append($('<div class="form-group">').append(label, select));
            });
            container.append(section);
        }

        function renderLocationMappings(preview) {
            const container = $('#location_mappings').empty();
            if (preview.all_locations) {
                container.append('<div class="alert alert-warning">User sumber memiliki akses ke semua lokasi. Hak akses semua lokasi akan diberikan di business tujuan.</div>');
                return;
            }
            const locations = preview.locations || [];
            if (!locations.length) {
                container.append('<p class="text-muted">User sumber tidak memiliki izin lokasi eksplisit.</p>');
                return;
            }
            const section = $('<div class="well well-sm"><strong>Lokasi yang dapat diakses</strong></div>');
            locations.forEach(function (item) {
                const selectId = 'target_location_' + item.id;
                const select = $('<select class="form-control import-location-map" required></select>')
                    .attr('id', selectId)
                    .attr('data-source-location-id', item.id)
                    .append($('<option>', { value: '', text: 'Pilih lokasi tujuan' }));
                (preview.target_location_options || []).forEach(function (match) {
                    const suffix = match.location_id ? ' (' + match.location_id + ')' : '';
                    select.append($('<option>', { value: match.id, text: match.name + suffix }));
                });
                if (item.suggested_target_location_id) select.val(String(item.suggested_target_location_id));
                section.append($('<div class="form-group">')
                    .append($('<label>', { for: selectId }).text('Lokasi sumber: ' + item.name), select));
            });
            container.append(section);
        }

        function renderContactMappings(preview) {
            const container = $('#contact_mappings').empty();
            if (!preview.selected_contacts) {
                container.append('<p class="text-muted">User sumber tidak dibatasi ke daftar kontak tertentu.</p>');
                return;
            }
            const contacts = preview.contacts || [];
            if (!contacts.length) {
                container.append('<p class="text-muted">Daftar kontak terbatas sumber kosong; batasan kosong akan dipertahankan.</p>');
                return;
            }
            const section = $('<div class="well well-sm"><strong>Kontak yang diizinkan</strong></div>');
            contacts.forEach(function (item) {
                const selectId = 'target_contact_' + item.source_contact_id;
                const select = $('<select class="form-control import-contact-map" required></select>')
                    .attr('id', selectId)
                    .attr('data-source-contact-id', item.source_contact_id)
                    .append($('<option>', { value: '', text: 'Pilih kontak tujuan' }));
                item.matches.forEach(function (match) {
                    const details = [match.email, match.mobile].filter(Boolean).join(' · ');
                    select.append($('<option>', { value: match.id, text: match.name + (details ? ' · ' + details : '') }));
                });
                if (item.suggested_target_contact_id) select.val(String(item.suggested_target_contact_id));
                select.select2({
                    placeholder: 'Cari atau pilih kontak tujuan',
                    minimumInputLength: 1,
                    ajax: {
                        url: urls.targetContacts,
                        type: 'POST',
                        delay: 250,
                        dataType: 'json',
                        data: function (params) { return { _token: csrfToken, q: params.term || '' }; },
                        processResults: function (items) { return { results: items }; }
                    }
                });
                section.append($('<div class="form-group">')
                    .append($('<label>', { for: selectId }).text('Kontak sumber: ' + item.source_contact_name), select));
            });
            section.append('<p class="help-block">Jika belum ada kontak tujuan yang cocok, buat kontak tersebut terlebih dahulu lalu muat ulang preview.</p>');
            container.append(section);
        }

        function checkUsername() {
            window.clearTimeout(usernameTimer);
            const sequence = ++usernameSequence;
            if (!currentPreview || !currentPreview.source_user.allow_login) return;
            const username = $('#destination_username').val().trim();
            const feedback = $('#import_username_feedback');
            usernameAvailable = false;
            if (!username) {
                feedback.removeClass('text-success text-danger').text('Username tujuan wajib diisi.');
                updateImportButton();
                return;
            }
            feedback.removeClass('text-success text-danger').text('Memeriksa username tujuan…');
            updateImportButton();
            usernameTimer = window.setTimeout(function () {
                $.ajax({
                    method: 'POST',
                    url: urls.username,
                    dataType: 'json',
                    data: { _token: csrfToken, username: username }
                }).done(function (result) {
                    if (sequence !== usernameSequence) return;
                    if (result.available) {
                        feedback.removeClass('text-danger').addClass('text-success').text('Username tersedia: ' + result.username);
                        usernameAvailable = true;
                    } else {
                        const matches = (result.matches || []).map(function (match) {
                            return match.name + ' — ' + match.business + ' (' + match.status + ')';
                        }).join('; ');
                        feedback.removeClass('text-success').addClass('text-danger')
                            .text((result.message || 'Username sudah digunakan.') + (matches ? ' Dipakai oleh: ' + matches : ''));
                        usernameAvailable = false;
                    }
                    updateImportButton();
                }).fail(function () {
                    if (sequence === usernameSequence) {
                        usernameAvailable = false;
                        feedback.removeClass('text-success').addClass('text-danger').text('Pemeriksaan username gagal.');
                        updateImportButton();
                    }
                });
            }, 300);
        }

        function updateImportButton() {
            const mappingsComplete = $('.import-role-map, .import-location-map, .import-contact-map').toArray()
                .every(function (select) { return Boolean($(select).val()); });
            const loginReady = Boolean(currentPreview)
                && (!currentPreview.source_user.allow_login || usernameAvailable);
            const ready = Boolean(currentPreview) && mappingsComplete && loginReady && !isSubmitting;
            $('#submit_user_import').prop('disabled', !ready);
            $('#import_submit_hint').text(ready
                ? 'Username dan pemetaan siap ditinjau. Impor akan membuat user di business tujuan.'
                : 'Lengkapi pemetaan dan pastikan username tujuan tersedia sebelum menyimpan.');
        }

        $('#destination_username').on('input change', checkUsername);
        $(document).on('change', '.import-role-map, .import-location-map, .import-contact-map', updateImportButton);

        $('#submit_user_import').on('click', function () {
            if (!currentPreview) return;
            clearMessages();
            const button = $(this);
            const originalText = button.html();
            const payload = {
                _token: csrfToken,
                source_business_id: $('#source_business_id').val(),
                source_user_id: $('#source_user_id').val(),
                username: $('#destination_username').val().trim(),
                role_map: {},
                location_map: {},
                contact_map: {}
            };
            $('.import-role-map').each(function () {
                payload.role_map[$(this).data('source-role-id')] = $(this).val();
            });
            $('.import-location-map').each(function () {
                payload.location_map[$(this).data('source-location-id')] = $(this).val();
            });
            $('.import-contact-map').each(function () {
                payload.contact_map[$(this).data('source-contact-id')] = $(this).val();
            });

            isSubmitting = true;
            button.prop('disabled', true).html('<i class="fa fa-spinner fa-spin" aria-hidden="true"></i> Menyimpan user…');
            $.ajax({ method: 'POST', url: urls.store, dataType: 'json', data: payload })
                .done(function (result) {
                    $('#import_success').removeClass('hide').text(result.message || 'User berhasil diimpor.');
                    if (window.toastr) toastr.success(result.message || 'User berhasil diimpor.');
                    window.setTimeout(function () { window.location.href = result.redirect; }, 700);
                })
                .fail(function (xhr) {
                    const response = xhr.responseJSON || {};
                    const errors = response.errors ? Object.values(response.errors).flat().join(' ') : '';
                    showError(errors || response.message || 'User tidak dapat diimpor. Periksa pemetaan dan coba lagi.');
                    isSubmitting = false;
                    button.html(originalText);
                    updateImportButton();
                });
        });
    });
</script>
@endsection
