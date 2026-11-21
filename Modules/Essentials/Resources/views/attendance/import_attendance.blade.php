<div class="row">
    <div class="col-sm-12">
        {!! Form::open(['url' => action([\Modules\Essentials\Http\Controllers\AttendanceController::class, 'importAttendance']), 'method' => 'post', 'enctype' => 'multipart/form-data', 'id' => 'attendance_import_form' ]) !!}
            <div class="row">
                <div class="col-sm-6">
                <div class="col-sm-8">
                    <div class="form-group">
                        {!! Form::label('name', __( 'product.file_to_import' ) . ':') !!}
                        {!! Form::file('attendance', ['accept'=> '.xls,.xlsx', 'required' => 'required']); !!}
                      </div>
                </div>
                <div class="col-sm-4">
                <br>
                    <button type="submit" class="btn btn-primary">@lang('messages.submit')</button>
                </div>
                </div>
            </div>
            <div class="row">
                <div class="col-sm-6">
                    <div id="attendance_import_upload_progress" class="progress hidden" aria-live="polite">
                        <div class="progress-bar progress-bar-primary" role="progressbar" style="width:0%">0%</div>
                    </div>
                    <div id="attendance_import_upload_message" class="help-block" aria-live="polite"></div>
                </div>
            </div>

        {!! Form::close() !!}
        <br><br>
        <div class="row">
            <div class="col-sm-4">
                <a href="{{ asset('modules/essentials/files/import_attendance_template.xls') }}" class="btn btn-success" download><i class="fa fa-download"></i> @lang('lang_v1.download_template_file')</a>
            </div>
        </div>
        <div class="row">
            <div class="col-md-12">
                <h4>Riwayat Import Attendance</h4>
                <div class="form-group" style="max-width:220px">
                    <label for="attendance_import_status">Status</label>
                    <select id="attendance_import_status" class="form-control input-sm">
                        <option value="">Semua status</option>
                        <option value="queued_preview">Menunggu</option>
                        <option value="analyzing">Diproses</option>
                        <option value="imported">Selesai</option>
                        <option value="failed">Gagal</option>
                        <option value="reverted">Direvert</option>
                    </select>
                </div>
                <div class="table-responsive">
                    <table class="table table-bordered table-striped" id="attendance_import_history_table" width="100%">
                        <thead><tr><th>File</th><th>Status</th><th>Progress</th><th>Pesan</th><th>Dibuat</th><th>Aksi</th></tr></thead>
                    </table>
                </div>
            </div>
        </div>
        <div class="modal fade" id="attendance_import_detail_modal" tabindex="-1" role="dialog" aria-labelledby="attendance_import_detail_title">
            <div class="modal-dialog modal-lg" role="document"><div class="modal-content">
                <div class="modal-header"><button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button><h4 class="modal-title" id="attendance_import_detail_title">Detail Import Attendance</h4></div>
                <div class="modal-body"><pre id="attendance_import_detail_body" style="max-height:60vh;overflow:auto;white-space:pre-wrap"></pre></div>
            </div></div>
        </div>

        <div class="row">
            <div class="col-md-12">
                <table class="table" width="100%">
                    <tr>
                        <th>@lang('lang_v1.col_no')</th>
                        <th>@lang('lang_v1.col_name')</th>
                        <th>@lang('lang_v1.instruction')</th>
                    </tr>
                    <tr>
                        <td>1</td>
                        <td>@lang('business.email') <small class="text-muted">(@lang('lang_v1.required'))</small></td>
                        <td>{!! __('essentials::lang.email_ins') !!}</td>
                    </tr>
                    <tr>
                        <td>2</td>
                        <td>@lang('essentials::lang.clock_in_time') <small class="text-muted">(@lang('lang_v1.required'))</small></td>
                        <td>{!! __('essentials::lang.clock_in_time_ins') !!} ({{\Carbon::now()->toDateTimeString()}})</td>
                    </tr>
                    <tr>
                        <td>3</td>
                        <td>@lang('essentials::lang.clock_out_time') <small class="text-muted">(@lang('lang_v1.optional'))</small></td>
                        <td>{!! __('essentials::lang.clock_out_time_ins') !!} ({{\Carbon::now()->toDateTimeString()}})</td>
                    </tr>
                    <tr>
                        <td>4</td>
                        <td>@lang('essentials::lang.clock_in_note') <small class="text-muted">(@lang('lang_v1.optional'))</small></td>
                        <td>&nbsp;</td>
                    </tr>
                    <tr>
                        <td>5</td>
                        <td>@lang('essentials::lang.clock_out_note') <small class="text-muted">(@lang('lang_v1.optional'))</small></td>
                        <td>&nbsp;</td>
                    </tr>
                    <tr>
                        <td>6</td>
                        <td>@lang('essentials::lang.ip_address') <small class="text-muted">(@lang('lang_v1.optional'))</small></td>
                        <td>&nbsp;</td>
                    </tr>
                </table>
            </div>
        </div>
    </div>
</div>
