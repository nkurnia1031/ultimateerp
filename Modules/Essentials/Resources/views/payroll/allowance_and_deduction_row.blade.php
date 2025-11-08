@php
    if ($type == 'allowance') {
        $name_col = 'payrolls[' . $employee . '][allowance_names]';
        $unit_col = 'payrolls[' . $employee . '][allowance_unit]';
        $tgl_col = 'payrolls[' . $employee . '][allowance_tgl]';
        $qty_col = 'payrolls[' . $employee . '][allowance_qty]';
        $amountper_col = 'payrolls[' . $employee . '][allowance_amountper]';
        $val_col = 'payrolls[' . $employee . '][allowance_amounts]';
        $val_class = 'allowance';
        $type_col = 'payrolls[' . $employee . '][allowance_types]';
        $percent_col = 'payrolls[' . $employee . '][allowance_percent]';
    } elseif ($type == 'deduction') {
        $unit_col = 'payrolls[' . $employee . '][deduction_unit]';
        $tgl_col = 'payrolls[' . $employee . '][deduction_tgl]';
        $qty_col = 'payrolls[' . $employee . '][deduction_qty]';
        $amountper_col = 'payrolls[' . $employee . '][deduction_amountper]';
        $name_col = 'payrolls[' . $employee . '][deduction_names]';
        $val_col = 'payrolls[' . $employee . '][deduction_amounts]';
        $val_class = 'deduction';
        $type_col = 'payrolls[' . $employee . '][deduction_types]';
        $percent_col = 'payrolls[' . $employee . '][deduction_percent]';
    }

    $amount_type = !empty($amount_type) ? $amount_type : 'fixed';
    $percent = $amount_type == 'percent' && !empty($percent) ? $percent : 0;
@endphp
<tr>
    <td>
        {!! Form::text($tgl_col . '[]', !empty($tgl) ? $tgl : null, [
            'placeholder' => __('lang_v1.select_a_date_range'),
            'class' => 'form-control purchase_list_filter_date_range',
            'readonly',
        ]) !!}
    </td>
    <td>
        {!! Form::text($name_col . '[]', !empty($name) ? $name : null, ['class' => 'form-control input-sm']) !!}
    </td>
    <td>
        {!! Form::select(
            $type_col . '[]',
            ['fixed' => __('lang_v1.fixed'), 'percent' => __('lang_v1.percentage')],
            $amount_type,
            ['class' => 'form-control input-sm amount_type'],
        ) !!}
        <div class="input-group percent_field @if ($amount_type != 'percent') hide @endif">
            {!! Form::text($percent_col . '[]', @num_format($percent), [
                'class' => 'form-control input-sm input_number percent',
            ]) !!}
            <span class="input-group-addon"><i class="fa fa-percent"></i></span>
        </div>
    </td>
    <td>
        {!! Form::text($unit_col . '[]', !empty($unit) ? $unit : null, [
            'class' => 'form-control input-sm unit',
            'placeholder' => 'cth: Jam',
        ]) !!}

    </td>
    <td>
        {!! Form::number($qty_col . '[]', !empty($qty) ? $qty : null, [
            'type' => 'number',
            'step' => 'any',
            'class' => 'form-control qty input-sm',
        ]) !!}

    </td>
    <td>
        {!! Form::text($amountper_col . '[]', !empty($amountper) ? $amountper : null, [
            'class' => 'form-control input-sm amountper  input_number ',
            'type' => 'number',
            'step' => 'any',
        ]) !!}

    </td>
    <td>
        @php
            $readonly = 'readonly';
        @endphp
        {!! Form::text($val_col . '[]', !empty($value) ? @num_format((float) $value) : 0, [
            'class' => 'form-control input-sm value_field input_number ' . $val_class,
            $readonly,
        ]) !!}
    </td>
    <td>
        @if (!empty($add_button))
            <button type="button"
                class="btn btn-primary btn-xs @if ($type == 'allowance') add_allowance @elseif($type == 'deduction') add_deduction @endif">
                <i class="fa fa-plus"></i>
            @else
                <button type="button" class="btn btn-danger btn-xs remove_tr"><i class="fa fa-minus"></i></button>
        @endif
        </button>
    </td>
</tr>
