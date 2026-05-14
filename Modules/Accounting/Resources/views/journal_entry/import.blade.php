@extends('layouts.app')

@section('title', 'Import Journal Entry')

@section('content')

@include('accounting::layouts.nav')

<section class="content-header">
    <h1>Import Journal Entry</h1>
</section>

<section class="content no-print">
    @component('components.widget', ['class' => 'box-primary', 'title' => 'Upload & Analisa XLSX'])
        {!! Form::open([
            'url' => action([\Modules\Accounting\Http\Controllers\JournalEntryController::class, 'analyzeImport']),
            'method' => 'post',
            'files' => true,
        ]) !!}
            <div class="row">
                <div class="col-md-6">
                    <div class="form-group">
                        {!! Form::label('journal_file', 'File Journal XLSX:*') !!}
                        {!! Form::file('journal_file', ['class' => 'form-control', 'required', 'accept' => '.xlsx,.xls,.csv']) !!}
                        <p class="help-block">Format yang dibaca: Nomor Akun, Akun, Deskripsi, Debit, Kredit dengan header transaksi seperti Journal Entry #7 | 01/05/2010.</p>
                    </div>
                </div>
                <div class="col-md-6">
                    <button type="submit" class="btn btn-primary" style="margin-top: 25px;">
                        <i class="fas fa-search"></i> Analisa File
                    </button>
                </div>
            </div>
        {!! Form::close() !!}
    @endcomponent

    @if(!empty($analysis))
        @component('components.widget', ['class' => 'box-solid', 'title' => 'Hasil Analisa'])
            <div class="row">
                <div class="col-md-3 col-sm-6">
                    <div class="info-box">
                        <span class="info-box-icon bg-aqua"><i class="fas fa-book"></i></span>
                        <div class="info-box-content">
                            <span class="info-box-text">Total Entry</span>
                            <span class="info-box-number">{{ $analysis['total_entries'] }}</span>
                        </div>
                    </div>
                </div>
                <div class="col-md-3 col-sm-6">
                    <div class="info-box">
                        <span class="info-box-icon bg-green"><i class="fas fa-check"></i></span>
                        <div class="info-box-content">
                            <span class="info-box-text">Journal Entry</span>
                            <span class="info-box-number">{{ $analysis['journal_entry_count'] }}</span>
                        </div>
                    </div>
                </div>
                <div class="col-md-3 col-sm-6">
                    <div class="info-box">
                        <span class="info-box-icon bg-yellow"><i class="fas fa-layer-group"></i></span>
                        <div class="info-box-content">
                            <span class="info-box-text">Di luar Journal</span>
                            <span class="info-box-number">{{ $analysis['non_journal_count'] }}</span>
                        </div>
                    </div>
                </div>
                <div class="col-md-3 col-sm-6">
                    <div class="info-box">
                        <span class="info-box-icon bg-red"><i class="fas fa-exclamation-triangle"></i></span>
                        <div class="info-box-content">
                            <span class="info-box-text">Missing Account</span>
                            <span class="info-box-number">{{ count($analysis['missing_accounts']) }}</span>
                        </div>
                    </div>
                </div>
            </div>

            <div class="row">
                <div class="col-md-6">
                    <table class="table table-bordered table-striped">
                        <tr>
                            <th>Periode file</th>
                            <td>{{ $analysis['source_date_range'] }}</td>
                        </tr>
                        <tr>
                            <th>Unbalanced</th>
                            <td>{{ $analysis['unbalanced_count'] }}</td>
                        </tr>
                        <tr>
                            <th>Duplikasi dalam file</th>
                            <td>{{ $analysis['duplicate_count'] }}</td>
                        </tr>
                        <tr>
                            <th>Sudah pernah diimport</th>
                            <td>{{ $analysis['existing_count'] }}</td>
                        </tr>
                    </table>
                </div>
                <div class="col-md-6">
                    <table class="table table-bordered table-striped">
                        <thead>
                            <tr>
                                <th>Jenis transaksi</th>
                                <th class="text-right">Jumlah</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($analysis['types'] as $type => $count)
                                <tr>
                                    <td>{{ $type }}</td>
                                    <td class="text-right">{{ $count }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>

            {!! Form::open([
                'url' => action([\Modules\Accounting\Http\Controllers\JournalEntryController::class, 'storeImport']),
                'method' => 'post',
                'id' => 'journal_import_form',
            ]) !!}
                {!! Form::hidden('import_id', $batch->id ?? '') !!}
                {!! Form::hidden('original_file_name', $original_file_name ?? '') !!}

            @if(!empty($analysis['missing_accounts']))
                <h4>Kurasi Mapping Akun</h4>
                <p class="help-block">
                    Pilih akun utama dulu dari tabel accounts, lalu pilih mapping Chart of Accounts. Saat import, transaksi akan masuk ke account_transactions dan accounting_accounts_transactions sekaligus.
                </p>
                <div class="table-responsive" style="max-height: 360px; overflow: auto;">
                    <table class="table table-bordered table-condensed">
                        <thead>
                            <tr>
                                <th>Row</th>
                                <th>Nomor Akun</th>
                                <th>Akun</th>
                                <th>Mapping ke Account Utama</th>
                                <th>Mapping ke Chart of Accounts</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach(array_slice($analysis['missing_accounts'], 0, 50) as $account)
                                @php
                                    $suggested = $account['suggested_accounting_accounts'][0]['id'] ?? null;
                                    $suggested_main = $account['suggested_payment_accounts'][0]['id'] ?? null;
                                @endphp
                                <tr>
                                    <td>{{ $account['sample_row'] }}</td>
                                    <td>{{ $account['account_no'] }}</td>
                                    <td>{{ $account['account_name'] }}</td>
                                    <td style="min-width: 320px;">
                                        {!! Form::select('main_account_mappings['.$account['key'].']', ['' => __('messages.please_select')] + $main_account_options, $suggested_main, ['class' => 'form-control select2', 'style' => 'width: 100%;']) !!}
                                        @if(!empty($account['suggested_payment_accounts']))
                                            <small class="text-muted">
                                                Saran:
                                                @foreach($account['suggested_payment_accounts'] as $suggestion)
                                                    {{ $suggestion['number'] }} - {{ $suggestion['name'] }} ({{ $suggestion['score'] }}%)@if(!$loop->last), @endif
                                                @endforeach
                                            </small>
                                        @endif
                                    </td>
                                    <td style="min-width: 320px;">
                                        {!! Form::select('account_mappings['.$account['key'].']', ['' => __('messages.please_select')] + $account_options, $suggested, ['class' => 'form-control select2', 'style' => 'width: 100%;']) !!}
                                        @if(!empty($account['suggested_accounting_accounts']))
                                            <small class="text-muted">
                                                Saran:
                                                @foreach($account['suggested_accounting_accounts'] as $suggestion)
                                                    {{ $suggestion['number'] }} - {{ $suggestion['name'] }} ({{ $suggestion['score'] }}%)@if(!$loop->last), @endif
                                                @endforeach
                                            </small>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                @if(count($analysis['missing_accounts']) > 50)
                    <p class="help-block">Menampilkan 50 dari {{ count($analysis['missing_accounts']) }} akun yang belum cocok.</p>
                @endif
            @endif

            @php
                $duplicate_entries = collect($analysis['entries'])->filter(function ($entry) {
                    return !empty($entry['duplicate_matches']);
                })->take(20);
            @endphp
            @if($duplicate_entries->isNotEmpty())
                <h4>Potensi Double Record di Database</h4>
                <div class="table-responsive" style="max-height: 260px; overflow: auto;">
                    <table class="table table-bordered table-condensed">
                        <thead>
                            <tr>
                                <th>Tanggal</th>
                                <th>Ref</th>
                                <th>Jumlah</th>
                                <th>Ditemukan di</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($duplicate_entries as $entry)
                                <tr>
                                    <td>{{ $entry['date'] }}</td>
                                    <td>{{ $entry['source_type'] }} #{{ $entry['source_number'] }}</td>
                                    <td>@format_currency($entry['debit_total'])</td>
                                    <td>{{ implode(', ', $entry['duplicate_matches']) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <p class="help-block">Entry yang cocok berdasarkan tanggal, no ref, dan jumlah akan otomatis dilewati saat import.</p>
            @endif

                <div class="checkbox">
                    <label>
                        {!! Form::checkbox('include_non_journal', 1, false) !!}
                        Ikut import tipe non Journal Entry yang tidak ditemukan di transaksi/payment sebagai manual journal
                    </label>
                    <p class="help-block">
                        Sales Invoice, Receive Payment, Expense, Reversal, dan Opening Balance akan dicek dulu berdasarkan tanggal, nomor referensi, dan jumlah pada tabel transactions, transaction_payments, account_transactions, dan journal accounting. Jika sudah ada, otomatis dilewati.
                    </p>
                </div>

                <div class="checkbox">
                    <label>
                        {!! Form::checkbox('create_missing_main_accounts', 1, false) !!}
                        Buat otomatis Account Utama yang belum ada berdasarkan Nomor Akun dan Akun dari file
                    </label>
                </div>

                <div class="checkbox">
                    <label>
                        {!! Form::checkbox('create_missing_accounts', 1, false) !!}
                        Buat otomatis Chart of Accounts yang belum ada dan hubungkan ke Account Utama
                    </label>
                </div>

                <button type="submit" class="btn btn-success">
                    <i class="fas fa-file-import"></i> Import Sekarang
                </button>
                <div id="journal_import_progress_box" class="hide" style="margin-top: 15px;">
                    <div class="progress">
                        <div id="journal_import_progress_bar" class="progress-bar progress-bar-striped active" role="progressbar" style="width: 0%;">
                            0%
                        </div>
                    </div>
                    <p id="journal_import_progress_text" class="help-block">Menyiapkan import...</p>
                </div>
            {!! Form::close() !!}
        @endcomponent
    @endif

    @component('components.widget', ['class' => 'box-solid', 'title' => 'Riwayat Import'])
        <div class="table-responsive">
            <table class="table table-bordered table-striped">
                <thead>
                    <tr>
                        <th>Tanggal</th>
                        <th>File</th>
                        <th>Periode</th>
                        <th>Imported</th>
                        <th>Skipped</th>
                        <th>Status</th>
                        <th>Oleh</th>
                        <th>@lang('messages.action')</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($imports as $import)
                        <tr>
                            <td>{{ @format_datetime($import->created_at) }}</td>
                            <td>{{ $import->file_name }}</td>
                            <td>{{ $import->source_date_range }}</td>
                            <td>{{ $import->imported_entries }}</td>
                            <td>{{ $import->skipped_entries }}</td>
                            <td>
                                @if(in_array($import->status, ['queued', 'analyzing', 'queued_import', 'importing', 'processing']))
                                    <span class="label bg-yellow">{{ $import->status }}</span>
                                @elseif($import->status == 'analyzed')
                                    <span class="label bg-aqua">analyzed</span>
                                @elseif($import->status == 'imported')
                                    <span class="label bg-green">imported</span>
                                @elseif($import->status == 'failed')
                                    <span class="label bg-red">failed</span>
                                @else
                                    <span class="label bg-gray">{{ $import->status }}</span>
                                @endif
                                @if(in_array($import->status, ['queued', 'analyzing', 'queued_import', 'importing', 'processing']))
                                    <div class="progress" style="margin-top: 5px; margin-bottom: 0;">
                                        @php
                                            $percent = !empty($import->total_entries) ? min(100, round(($import->processed_entries / $import->total_entries) * 100)) : 0;
                                        @endphp
                                        <div class="progress-bar progress-bar-striped active" style="width: {{ $percent }}%;">{{ $percent }}%</div>
                                    </div>
                                @endif
                            </td>
                            <td>{{ $import->added_by }}</td>
                            <td>
                                @if($import->status == 'analyzed')
                                    <a href="{{ action([\Modules\Accounting\Http\Controllers\JournalEntryController::class, 'reviewImport'], [$import->id]) }}" class="btn btn-primary btn-xs">
                                        <i class="fas fa-check"></i> Review & Import
                                    </a>
                                @elseif(in_array($import->status, ['queued', 'analyzing', 'queued_import', 'importing', 'processing']))
                                    <button type="button" class="btn btn-default btn-xs journal-import-watch" data-id="{{ $import->id }}">
                                        <i class="fas fa-sync"></i> Watch
                                    </button>
                                @elseif($import->status == 'imported')
                                    {!! Form::open([
                                        'url' => action([\Modules\Accounting\Http\Controllers\JournalEntryController::class, 'revertImport'], [$import->id]),
                                        'method' => 'post',
                                        'style' => 'display:inline;',
                                        'onsubmit' => 'return confirm("Revert import ini? Journal yang dibuat dari batch ini akan dihapus.")',
                                    ]) !!}
                                        <button type="submit" class="btn btn-danger btn-xs">
                                            <i class="fas fa-undo"></i> Revert
                                        </button>
                                    {!! Form::close() !!}
                                @else
                                    -
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="text-center">@lang('purchase.no_records_found')</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    @endcomponent
</section>

@endsection

@section('javascript')
<script type="text/javascript">
    $(document).on('submit', '#journal_import_form', function(e) {
        e.preventDefault();

        if (!confirm('Import journal dari file ini sekarang?')) {
            return false;
        }

        var form = $(this);
        var progressBox = $('#journal_import_progress_box');
        var progressBar = $('#journal_import_progress_bar');
        var progressText = $('#journal_import_progress_text');
        var submitButton = form.find('button[type="submit"]');
        var formData = form.serializeArray();

        submitButton.prop('disabled', true);
        progressBox.removeClass('hide');
        updateJournalImportProgress(0, 'Menyiapkan batch import...');

        $.ajax({
            method: 'POST',
            url: '{{ action([\Modules\Accounting\Http\Controllers\JournalEntryController::class, 'startImport']) }}',
            data: form.serialize(),
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    pollJournalImportStatus(response.import_id);
                } else {
                    submitButton.prop('disabled', false);
                    toastr.error(response.msg || '{{ __("messages.something_went_wrong") }}');
                }
            },
            error: function(xhr) {
                submitButton.prop('disabled', false);
                toastr.error(xhr.responseJSON && xhr.responseJSON.msg ? xhr.responseJSON.msg : '{{ __("messages.something_went_wrong") }}');
            }
        });

        function pollJournalImportStatus(importId) {
            $.ajax({
                method: 'GET',
                url: '{{ url('accounting/journal-entry/import') }}/' + importId + '/status',
                dataType: 'json',
                success: function(response) {
                    if (!response.success) {
                        submitButton.prop('disabled', false);
                        toastr.error(response.msg || '{{ __("messages.something_went_wrong") }}');
                        return;
                    }

                    var percent = response.total_entries > 0 ? Math.round((response.processed_entries / response.total_entries) * 100) : 100;
                    updateJournalImportProgress(percent, response.last_message || ('Diproses ' + response.processed_entries + ' / ' + response.total_entries + ' entry...'));

                    if (response.status == 'imported') {
                        updateJournalImportProgress(100, 'Import selesai. Halaman akan dimuat ulang...');
                        toastr.success('Import journal selesai.');
                        setTimeout(function() {
                            window.location = '{{ action([\Modules\Accounting\Http\Controllers\JournalEntryController::class, 'import']) }}';
                        }, 1200);
                    } else if (response.status == 'failed') {
                        submitButton.prop('disabled', false);
                        toastr.error(response.error_message || '{{ __("messages.something_went_wrong") }}');
                    } else {
                        setTimeout(function() {
                            pollJournalImportStatus(importId);
                        }, 2000);
                    }
                },
                error: function(xhr) {
                    submitButton.prop('disabled', false);
                    toastr.error(xhr.responseJSON && xhr.responseJSON.msg ? xhr.responseJSON.msg : '{{ __("messages.something_went_wrong") }}');
                }
            });
        }

        function updateJournalImportProgress(percent, text) {
            progressBar.css('width', percent + '%').text(percent + '%');
            progressText.text(text);
        }
    });

    $(document).on('click', '.journal-import-watch', function() {
        var importId = $(this).data('id');
        var button = $(this);
        button.prop('disabled', true);

        $.ajax({
            method: 'GET',
            url: '{{ url('accounting/journal-entry/import') }}/' + importId + '/status',
            dataType: 'json',
            success: function(response) {
                if (response.status == 'analyzed') {
                    window.location = '{{ url('accounting/journal-entry/import') }}/' + importId + '/review';
                } else if (response.status == 'imported' || response.status == 'failed') {
                    window.location.reload();
                } else {
                    toastr.info(response.last_message || 'Masih diproses di background.');
                    button.prop('disabled', false);
                }
            },
            error: function() {
                button.prop('disabled', false);
                toastr.error('{{ __("messages.something_went_wrong") }}');
            }
        });
    });
</script>
@endsection
