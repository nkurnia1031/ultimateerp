{{ $row->additional_notes }}
@if (!empty($row->asset_name))
    <br>
    <small class="text-muted"><b>Penggunaan untuk</b>: <a href="javascript:;"
            class="badge badge-dark">{{ $row->asset_name }}
            ({{ $row->asset_code }})</a></small>
@endif
