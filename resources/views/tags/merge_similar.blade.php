@extends('layouts.app')
@section('title', 'Merge Similar Tags')

@section('content')
    <section class="content-header">
        <h1 class="tw-text-xl md:tw-text-3xl tw-font-bold tw-text-black">
            Merge Similar Tags
            <small class="tw-text-sm md:tw-text-base tw-text-gray-700 tw-font-semibold">{{$tag->name}}</small>
        </h1>
    </section>

    <section class="content">
        @component('components.widget', ['class' => 'box-primary', 'title' => 'Tag utama'])
            <div class="row">
                <div class="col-md-8">
                    <p>
                        Semua transaksi dari tag yang dipilih akan dipindahkan ke:
                        <strong>{{$tag->name}}</strong>
                    </p>
                    <p class="text-muted">
                        Tag yang digabung akan dihapus setelah relasinya dipindahkan.
                    </p>
                </div>
                <div class="col-md-4 text-right">
                    <a href="{{action([\App\Http\Controllers\TagController::class, 'index'])}}" class="btn btn-default">
                        Back
                    </a>
                </div>
            </div>
        @endcomponent

        @component('components.widget', ['class' => 'box-primary', 'title' => 'Tag mirip 50% ke atas'])
            @if($similar_tags->count() > 0)
                {!! Form::open(['url' => action([\App\Http\Controllers\TagController::class, 'merge'], [$tag->id]), 'method' => 'post', 'id' => 'merge_similar_tags_form']) !!}
                    <div class="table-responsive">
                        <table class="table table-bordered table-striped">
                            <thead>
                                <tr>
                                    <th style="width: 40px;">
                                        <input type="checkbox" id="select_all_similar_tags">
                                    </th>
                                    <th>Tag</th>
                                    <th>Similarity</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($similar_tags as $similar_tag)
                                    <tr>
                                        <td>
                                            <input type="checkbox" name="merge_tag_ids[]" value="{{$similar_tag->id}}" class="similar_tag_checkbox">
                                        </td>
                                        <td>{{$similar_tag->name}}</td>
                                        <td>{{$similar_tag->similarity}}%</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    <button type="submit" class="tw-dw-btn tw-dw-btn-primary tw-text-white" id="merge_selected_tags">
                        <i class="fas fa-code-branch"></i> Gabungkan Tag Terpilih
                    </button>
                {!! Form::close() !!}
            @else
                <p class="text-muted">Tidak ada tag dengan tingkat kemiripan 50% ke atas.</p>
            @endif
        @endcomponent
    </section>
@endsection

@section('javascript')
    <script type="text/javascript">
        $(document).ready(function() {
            $('#select_all_similar_tags').on('change', function() {
                $('.similar_tag_checkbox').prop('checked', $(this).is(':checked'));
            });

            $('#merge_similar_tags_form').on('submit', function(e) {
                if ($('.similar_tag_checkbox:checked').length == 0) {
                    e.preventDefault();
                    toastr.error('Pilih minimal satu tag untuk digabung.');
                    return false;
                }

                if (! confirm('Tag terpilih akan digabung ke tag utama dan dihapus. Lanjutkan?')) {
                    e.preventDefault();
                    return false;
                }
            });
        });
    </script>
@endsection
