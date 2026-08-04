@extends('layouts.app')

@section('title', 'Detail Import Solar')

@section('content')
<section class="content-header">
    <h1>Detail Import Solar <small>{{ $batch->file_name ?: 'Workbook Solar' }}</small></h1>
</section>
<section class="content">
    <div class="box box-primary">
        <div class="box-header with-border">
            <a href="{{ route('solar.imports') }}" class="btn btn-default btn-sm"><i class="fa fa-arrow-left"></i> Kembali</a>
            <span class="label label-{{ $batch->status === 'imported' ? 'success' : ($batch->status === 'rolled_back' ? 'default' : 'warning') }} pull-right">{{ ucfirst($batch->status) }}</span>
        </div>
        <div class="box-body">
            <p><strong>Batch:</strong> {{ $batch->id }}</p>
            <p><strong>File hash:</strong> <code>{{ $batch->file_hash }}</code></p>
            @foreach ($rows as $ledger => $records)
                <h4>{{ strtoupper($ledger) }} ({{ $records->count() }} baris)</h4>
                @if ($records->isEmpty())
                    <p class="text-muted">Tidak ada transaksi dari batch ini.</p>
                @else
                    <div class="table-responsive">
                        <table class="table table-condensed table-bordered">
                            <thead><tr><th>ID</th><th>Tanggal</th><th>Source row</th><th>Keterangan</th></tr></thead>
                            <tbody>
                                @foreach ($records as $record)
                                    <tr>
                                        <td>{{ $record->id }}</td>
                                        <td>{{ $record->tanggal }}</td>
                                        <td>{{ $record->source_row ?: '—' }}</td>
                                        <td>{{ $record->keterangan ?: '—' }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            @endforeach
        </div>
    </div>
</section>
@endsection
