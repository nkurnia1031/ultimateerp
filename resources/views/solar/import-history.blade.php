@extends('layouts.app')

@section('title', 'Riwayat Import Solar')

@section('content')
<section class="content-header">
    <h1>Riwayat Import Solar <small>kelola dan rollback batch</small></h1>
</section>

<section class="content" id="solar-import-history" data-status-url-template="{{ route('solar.imports.status', ['batchId' => '__BATCH__']) }}">
    @if (session('status'))
        @php $status = session('status'); @endphp
        <div class="alert alert-{{ ($status['success'] ?? false) ? 'success' : 'danger' }}">{{ $status['msg'] ?? '' }}</div>
    @endif

    <div class="box box-primary">
        <div class="box-header with-border">
            <h3 class="box-title">Daftar Batch Import</h3>
            <div class="box-tools">
                <a href="{{ route('solar.index') }}" class="btn btn-default btn-sm">
                    <i class="fa fa-arrow-left"></i> Kembali ke Solar
                </a>
            </div>
        </div>
        <div class="box-body table-responsive no-padding">
            <table class="table table-striped table-bordered">
                <thead><tr><th>File</th><th>Dibuat</th><th>Status</th><th>Proses</th><th>Stock</th><th>Pool</th><th>Mess</th><th>Pemakaian alat</th><th>Aksi</th></tr></thead>
                <tbody>
                    @forelse ($batches as $batch)
                        @php
                            $counts = json_decode((string) $batch->counts, true) ?: [];
                            $ledgerCounts = ['stock' => 0, 'pool' => 0, 'mess' => 0, 'usage' => 0];
                            if (! empty($counts['sheets']) && is_array($counts['sheets'])) {
                                foreach ($counts['sheets'] as $sheet) {
                                    $ledger = strtolower((string) ($sheet['ledger'] ?? ''));
                                    if (array_key_exists($ledger, $ledgerCounts)) $ledgerCounts[$ledger] += (int) ($sheet['total'] ?? 0);
                                }
                            } else {
                                foreach ($ledgerCounts as $ledger => $value) $ledgerCounts[$ledger] = (int) ($counts[$ledger] ?? 0);
                            }
                            $processState = $batch->process_state ?: 'completed';
                            $isPreviewBatch = $batch->status === 'preview' && ($batch->operation ?: 'preview') === 'preview';
                        @endphp
                        <tr data-import-batch="{{ $batch->id }}" data-status-url="{{ route('solar.imports.status', $batch->id) }}" data-process-state="{{ $processState }}">
                            <td>
                                @if ($isPreviewBatch)
                                    <a href="{{ route('solar.imports.reconcile', $batch->id) }}">{{ $batch->file_name ?: 'Workbook Solar' }}</a>
                                @else
                                    <a href="{{ route('solar.imports.detail', $batch->id) }}">{{ $batch->file_name ?: 'Workbook Solar' }}</a>
                                @endif
                            </td>
                            <td>{{ $batch->created_at ? \Carbon\Carbon::parse($batch->created_at)->format('d/m/Y H:i') : '—' }}</td>
                            <td><span data-domain-status class="label label-{{ $batch->status === 'imported' ? 'success' : ($batch->status === 'rolled_back' ? 'default' : 'warning') }}">{{ ucfirst($batch->status) }}</span></td>
                            <td data-process-cell>
                                <span class="label label-{{ $processState === 'failed' ? 'danger' : ($processState === 'completed' ? 'success' : ($processState === 'cancelled' ? 'default' : 'info')) }}">{{ ucfirst($processState) }}</span>
                                @if (in_array($processState, ['queued', 'processing'], true))
                                    <div class="progress progress-xs" style="margin:5px 0 0"><div class="progress-bar" data-process-progress style="width:{{ (int) ($batch->progress_percent ?? 0) }}%"></div></div>
                                    <small class="text-muted" data-process-message>{{ (int) ($batch->progress_percent ?? 0) }}% · {{ $batch->progress_message }}</small>
                                @endif
                            </td>
                            <td>{{ $ledgerCounts['stock'] }}</td>
                            <td>{{ $ledgerCounts['pool'] }}</td>
                            <td>{{ $ledgerCounts['mess'] }}</td>
                            <td>{{ $ledgerCounts['usage'] }}</td>
                            <td>
                                @if ($batch->status === 'imported')
                                    <form method="post" action="{{ route('solar.imports.rollback', $batch->id) }}" class="solar-rollback-form">
                                        @csrf
                                        <button class="btn btn-xs btn-danger" type="submit"><i class="fa fa-undo"></i> Rollback</button>
                                    </form>
                                @elseif ($isPreviewBatch)
                                    <a class="btn btn-xs btn-primary" href="{{ route('solar.imports.reconcile', $batch->id) }}"><i class="fa fa-eye"></i> Tinjau</a>
                                @else
                                    <span class="text-muted">Tidak ada aksi</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="9" class="text-center text-muted" style="padding:30px">Belum ada riwayat import.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="box-footer">{{ $batches->links() }}</div>
    </div>
    <div id="solar-history-feedback" class="alert" hidden></div>
</section>
@endsection

@section('javascript')
<script src="{{ asset('js/solar.js?v=' . $asset_v) }}"></script>
@endsection
