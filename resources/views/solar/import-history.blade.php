@extends('layouts.app')

@section('title', 'Riwayat Import Solar')

@section('content')
<section class="content-header">
    <h1>Riwayat Import Solar <small>kelola dan rollback batch</small></h1>
</section>

<section class="content">
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
                <thead><tr><th>File</th><th>Dibuat</th><th>Status</th><th>Proses</th><th>Jumlah Stock</th><th>Pool</th><th>Mess</th><th>Aksi</th></tr></thead>
                <tbody>
                    @forelse ($batches as $batch)
                        @php $counts = json_decode((string) $batch->counts, true) ?: []; @endphp
                        <tr>
                            <td><a href="{{ route('solar.imports.detail', $batch->id) }}">{{ $batch->file_name ?: 'Workbook Solar' }}</a></td>
                            <td>{{ $batch->created_at ? \Carbon\Carbon::parse($batch->created_at)->format('d/m/Y H:i') : '—' }}</td>
                            <td><span class="label label-{{ $batch->status === 'imported' ? 'success' : ($batch->status === 'rolled_back' ? 'default' : 'warning') }}">{{ ucfirst($batch->status) }}</span></td>
                            <td>
                                @php $processState = $batch->process_state ?: 'completed'; @endphp
                                <span class="label label-{{ $processState === 'failed' ? 'danger' : ($processState === 'completed' ? 'success' : ($processState === 'cancelled' ? 'default' : 'info')) }}">{{ ucfirst($processState) }}</span>
                                @if (in_array($processState, ['queued', 'processing'], true))
                                    <div class="progress progress-xs" style="margin:5px 0 0"><div class="progress-bar" style="width:{{ (int) ($batch->progress_percent ?? 0) }}%"></div></div>
                                    <small class="text-muted">{{ (int) ($batch->progress_percent ?? 0) }}%</small>
                                @endif
                            </td>
                            <td>{{ $counts['stock'] ?? 0 }}</td>
                            <td>{{ $counts['pool'] ?? 0 }}</td>
                            <td>{{ $counts['mess'] ?? 0 }}</td>
                            <td>
                                @if ($batch->status === 'imported')
                                    <form method="post" action="{{ route('solar.imports.rollback', $batch->id) }}" onsubmit="return confirm('Rollback batch import ini? Semua transaksi yang dibuat batch ini akan dihapus dan saldo dihitung ulang.')">
                                        @csrf
                                        <button class="btn btn-xs btn-danger"><i class="fa fa-undo"></i> Rollback</button>
                                    </form>
                                @else
                                    <span class="text-muted">Tidak ada aksi</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="8" class="text-center text-muted" style="padding:30px">Belum ada riwayat import.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="box-footer">{{ $batches->links() }}</div>
    </div>
</section>
@endsection
