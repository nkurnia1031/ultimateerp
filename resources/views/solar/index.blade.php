@extends('layouts.app')

@section('content')
<div class="panel panel-default">
    <div class="panel-heading">
        <h3 class="panel-title">LAPORAN SOLAR</h3>
        <div class="pull-right">
            <a href="#" class="btn btn-sm btn-info">
                <i class="fa fa-download"></i> Template
            </a>
            <a href="#" class="btn btn-sm btn-warning">
                <i class="fa fa-file-excel-o"></i> Export Excel
            </a>
            <button class="btn btn-sm btn-success" data-toggle="modal" data-target="#uploadCSV">
                <i class="fa fa-upload"></i> Upload CSV
            </button>
        </div>
    </div>

    <div class="panel-body">
        {{-- Filter Form --}}
        <form class="form-inline" method="get">
            <label>Dari: 
                <input type="date" name="date1" value="{{ request('date1') }}" class="form-control">
            </label>
            <label>Sampai: 
                <input type="date" name="date2" value="{{ request('date2') }}" class="form-control">
            </label>
            <button type="submit" class="btn btn-primary">Filter</button>
        </form>

        <hr>

        {{-- Session Status --}}
        @if(session('status'))
            <div class="alert alert-{{ session('status')['alert'] }}">
                {{ session('status')['msg'] }}
            </div>
        @endif

        {{-- Tab Navigation --}}
        <ul class="nav nav-tabs">
            <li class="active"><a data-toggle="tab" href="#stock">Stock</a></li>
            <li><a data-toggle="tab" href="#pool">Pool</a></li>
            <li><a data-toggle="tab" href="#mess">Mess</a></li>
        </ul>

        <div class="tab-content" style="margin-top:15px">

            {{-- STOCK TAB --}}
            <div id="stock" class="tab-pane fade in active">
                <h4>Stock Solar</h4>
                <input type="text" class="form-control input-sm search-stock" placeholder="Cari Tanggal / Keterangan ..." style="margin-bottom:10px; width: 300px;">
                <form method="post" action="{{ route('solar.stock.save') }}">
                    @csrf
                    <table class="table table-bordered" id="table-stock">
                        <thead>
                            <tr>
                                <th>Tanggal</th>
                                <th>In</th>
                                <th>Out ACT</th>
                                <th>Stock ACT</th>
                                <th>Out Meteran</th>
                                <th>Stock Meteran</th>
                                <th>Keterangan</th>
                                <th>Aksi</th>
                            </tr>
                        </thead>
                       <tbody id="table-stock-body">
                            @foreach ($solar_stock as $row)
                                <tr>
                                    <input type="hidden" name="id[]" value="{{ $row->id }}">
                                    <td><input type="date" name="tanggal[]" class="form-control" value="{{ $row->tanggal }}"></td>
                                    <td><input type="number" name="stock_in[]" class="form-control stock-in" value="{{ $row->stock_in }}"></td>
                                    <td><input type="number" name="out_actual[]" class="form-control out-actual" value="{{ $row->out_actual }}"></td>
                                    <td><input type="number" name="stock_actual[]" class="form-control stock-actual" value="{{ $row->stock_actual }}" readonly></td>
                                    <td><input type="number" name="out_meteran[]" class="form-control out-meteran" value="{{ $row->out_meteran }}"></td>
                                    <td><input type="number" name="stock_meteran[]" class="form-control stock-meteran" value="{{ $row->stock_meteran }}" readonly></td>
                                    <td><input type="text" name="keterangan[]" class="form-control" value="{{ $row->keterangan }}"></td>
                                    <td>
                                        <a href="{{ route('solar.stock.delete',$row->id) }}" class="btn btn-danger btn-sm">
                                            <i class="fa fa-trash"></i>
                                        </a>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                    <button type="button" class="btn btn-success" onclick="addRowStock()"><i class="fa fa-plus"></i> Tambah Baris</button>
                    <button type="submit" class="btn btn-primary">Simpan</button>
                </form>
            </div>

            {{-- POOL TAB --}}
            <div id="pool" class="tab-pane fade">
                <h4>Solar Pool</h4>
                <form method="post" action="{{ route('solar.pool.save') }}#pool">
                    @csrf
                    <table class="table table-bordered" id="table-pool">
                        <thead>
                            <tr> 
                                <th>Tanggal</th>
                                <th>IN</th>
                                <th>OUT</th>
                                <th>Current</th>
                                <th>Keterangan</th>
                                <th>Unit</th>
                                <th>Operator</th>
                                <th>Aksi</th>
                            </tr>
                        </thead>
                        <tbody id="table-pool-body">
                            @foreach ($solar_pool as $row)
                                <tr>
                                    <input type="hidden" name="id[]" value="{{ $row->id }}">
                                    <td><input type="date" name="tanggal[]" class="form-control" value="{{ $row->tanggal }}"></td>
                                    <td><input type="number" name="qty_in[]" class="form-control pool-in" value="{{ $row->qty_in }}"></td>
                                    <td><input type="number" name="qty_out[]" class="form-control pool-out" value="{{ $row->qty_out }}"></td>
                                    <td><input type="number" name="current_stock[]" class="form-control pool-current" value="{{ $row->current_stock }}" readonly></td>
                                    <td><input type="text" name="keterangan[]" class="form-control" value="{{ $row->keterangan }}"></td>
                                    <td><input type="text" name="unit[]" class="form-control" value="{{ $row->unit }}"></td>
                                    <td><input type="text" name="operator[]" class="form-control" value="{{ $row->operator }}"></td>
                                    <td>
                                        <a href="{{ route('solar.pool.delete',$row->id) }}" class="btn btn-danger btn-sm">
                                            <i class="fa fa-trash"></i>
                                        </a>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                    <button type="button" class="btn btn-success" onclick="addRowPool()"><i class="fa fa-plus"></i> Tambah Baris</button>
                    <button type="submit" class="btn btn-primary">Simpan</button>
                </form>
            </div>

            {{-- MESS TAB --}}
            <div id="mess" class="tab-pane fade">
                <h4>Solar Mess</h4>
                <form method="post" action="{{ route('solar.mess.save') }}#mess">
                    @csrf
                    <table class="table table-bordered" id="table-mess">
                        <thead>
                            <tr>
                                <th>Tanggal</th>
                                <th>IN</th>
                                <th>OUT</th>
                                <th>Current</th>
                                <th>Keterangan</th>
                                <th>Unit</th>
                                <th>Operator</th>
                                <th>Aksi</th>
                            </tr>
                        </thead>
                        <tbody id="table-mess-body">
                            @foreach ($solar_mess as $row)
                                <tr>
                                    <input type="hidden" name="id[]" value="{{ $row->id }}">
                                    <td><input type="date" name="tanggal[]" class="form-control" value="{{ $row->tanggal }}"></td>
                                    <td><input type="number" name="qty_in[]" class="form-control mess-in" value="{{ $row->qty_in }}"></td>
                                    <td><input type="number" name="qty_out[]" class="form-control mess-out" value="{{ $row->qty_out }}"></td>
                                    <td><input type="number" name="current_stock[]" class="form-control mess-current" value="{{ $row->current_stock }}" readonly></td>
                                    <td><input type="text" name="keterangan[]" class="form-control" value="{{ $row->keterangan }}"></td>
                                    <td><input type="text" name="unit[]" class="form-control" value="{{ $row->unit }}"></td>
                                    <td><input type="text" name="operator[]" class="form-control" value="{{ $row->operator }}"></td>
                                    <td>
                                        <a href="{{ route('solar.mess.delete',$row->id) }}" class="btn btn-danger btn-sm">
                                            <i class="fa fa-trash"></i>
                                        </a>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                    <button type="button" class="btn btn-success" onclick="addRowMess()"><i class="fa fa-plus"></i> Tambah Baris</button>
                    <button type="submit" class="btn btn-primary">Simpan</button>
                </form>
            </div>
        </div>
    </div>
</div>


@endsection
