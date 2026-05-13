@extends('layouts.app')
@section('title', 'Tag Ledger')

@section('content')
    <section class="content no-print">
        <div class="row no-print">
            <div class="col-md-4">
                <h3>Tag Ledger</h3>
            </div>
            <div class="col-md-4 col-xs-12 mt-15 pull-right">
                {!! Form::select('tag_id', $tags, $tag->id, ['class' => 'form-control select2', 'id' => 'tag_id']); !!}
            </div>
        </div>
        <br>

        <div class="row">
            <div class="col-md-12">
                <div class="box box-solid">
                    <div class="box-body">
                        <strong>Tag:</strong> {{$tag->name}}
                    </div>
                </div>
            </div>
        </div>

        <div class="row">
            <div class="col-md-12">
                <div class="nav-tabs-custom">
                    <ul class="nav nav-tabs nav-justified">
                        <li class="@if(empty($view_type) || $view_type == 'ledger') active @endif">
                            <a href="#ledger_tab" data-toggle="tab" aria-expanded="true"><i class="fas fa-scroll" aria-hidden="true"></i> @lang('lang_v1.ledger')</a>
                        </li>
                    </ul>

                    <div class="tab-content">
                        <div class="tab-pane @if(empty($view_type) || $view_type == 'ledger') active @endif" id="ledger_tab">
                            @include('tags.partials.ledger_tab')
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>
@endsection

@section('javascript')
    <script type="text/javascript">
        $(document).ready(function() {
            $('#tag_id').change(function() {
                if ($(this).val()) {
                    window.location = "{{url('/tags')}}/" + $(this).val() + '?view=ledger';
                }
            });

            $('#tag_ledger_date_range').daterangepicker(
                dateRangeSettings,
                function(start, end) {
                    $('#tag_ledger_date_range').val(start.format(moment_date_format) + ' ~ ' + end.format(moment_date_format));
                }
            );

            $('#tag_ledger_date_range, #tag_ledger_location').change(function() {
                get_tag_ledger();
            });

            get_tag_ledger();
        });

        function get_tag_ledger() {
            var start_date = '';
            var end_date = '';
            var location_id = $('#tag_ledger_location').val();

            if ($('#tag_ledger_date_range').val()) {
                start_date = $('#tag_ledger_date_range').data('daterangepicker').startDate.format('YYYY-MM-DD');
                end_date = $('#tag_ledger_date_range').data('daterangepicker').endDate.format('YYYY-MM-DD');
            }

            $.ajax({
                url: "{{action([\App\Http\Controllers\TagController::class, 'getLedger'])}}?tag_id={{$tag->id}}",
                data: {
                    start_date: start_date,
                    end_date: end_date,
                    location_id: location_id
                },
                dataType: 'html',
                success: function(result) {
                    $('#tag_ledger_div').html(result);
                    __currency_convert_recursively($('#tag_ledger_div'));

                    $('#tag_ledger_table').DataTable({
                        searching: false,
                        ordering: false,
                        paging: false,
                        fixedHeader: false,
                        dom: 't'
                    });
                },
            });
        }
    </script>
@endsection
