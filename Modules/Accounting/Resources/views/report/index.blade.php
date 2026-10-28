@extends('layouts.app')

@section('title', __('accounting::lang.journal_entry'))

@section('content')

@include('accounting::layouts.nav')

<!-- Content Header (Page header) -->
<section class="content-header">
    <h1>@lang( 'accounting::lang.reports' )</h1>
</section>

<section class="content">
    <div class="row">
        <div class="col-md-6">
            <div class="box box-warning">
                <div class="box-header with-border">
                    <h3 class="box-title">@lang( 'accounting::lang.trial_balance')</h3>
                </div>

                <div class="box-body">
                    @lang( 'accounting::lang.trial_balance_description')
                    <br/>
                    <a href="{{route('accounting.trialBalance')}}" class="btn btn-primary btn-sm pt-2">@lang( 'accounting::lang.view_report')</a>
                </div>

            </div>
        </div>

        <div class="col-md-6">
            <div class="box box-warning">
                <div class="box-header with-border">
                    <h3 class="box-title">@lang( 'accounting::lang.ledger_report')</h3>
                </div>

                <div class="box-body">
                    @lang( 'accounting::lang.ledger_report_description')
                    <br/>
                    <a href="{{ route('accounting.generalLedger') }}" class="btn btn-primary btn-sm pt-2">@lang( 'accounting::lang.view_report')</a>
                </div>

            </div>
        </div>

        <div class="col-md-6">
            <div class="box box-warning">
                <div class="box-header with-border">
                    <h3 class="box-title">Audit Buku Besar Kas/Bank</h3>
                </div>
                <div class="box-body">
                    Cocokkan movement rekening operasional dengan posting Accounting, termasuk selisih, sumber ganda, VAT, dan label transaksi.
                    <br/>
                    <a href="{{ route('accounting.generalLedger.audit') }}" class="btn btn-primary btn-sm m-t-10">Buka audit</a>
                </div>
            </div>
        </div>

        <div class="col-md-6">
            <div class="box box-warning">
                <div class="box-header with-border">
                    <h3 class="box-title">@lang( 'accounting::lang.balance_sheet')</h3>
                </div>

                <div class="box-body">
                    @lang( 'accounting::lang.balance_sheet_description')
                    <br/>
                    <a href="{{route('accounting.balanceSheet')}}" class="btn btn-primary btn-sm pt-2">@lang( 'accounting::lang.view_report')</a>
                </div>

            </div>
        </div>

        <div class="col-md-6">
            <div class="box box-warning">
                <div class="box-header with-border">
                    <h3 class="box-title">@lang( 'accounting::lang.account_recievable_ageing_report')</h3>
                </div>
                <div class="box-body">
                    @lang( 'accounting::lang.account_recievable_ageing_report_description')
                    <br/>
                    <a href="{{route('accounting.account_receivable_ageing_report')}}" 
                    class="btn btn-primary btn-sm pt-2">@lang( 'accounting::lang.view_report')</a>
                </div>
            </div>
        </div>

        <div class="col-md-6">
            <div class="box box-warning">
                <div class="box-header with-border">
                    <h3 class="box-title">@lang( 'accounting::lang.account_payable_ageing_report')</h3>
                </div>
                <div class="box-body">
                    @lang( 'accounting::lang.account_payable_ageing_report_description')
                    <br/>
                    <a href="{{route('accounting.account_payable_ageing_report')}}" 
                    class="btn btn-primary btn-sm pt-2">@lang( 'accounting::lang.view_report')</a>
                </div>
            </div>
        </div>

        <div class="col-md-6">
            <div class="box box-warning">
                <div class="box-header with-border">
                    <h3 class="box-title">@lang( 'accounting::lang.account_receivable_ageing_details')</h3>
                </div>
                <div class="box-body">
                    @lang( 'accounting::lang.account_receivable_ageing_details_description')
                    <br/>
                    <a href="{{route('accounting.account_receivable_ageing_details')}}" 
                    class="btn btn-primary btn-sm pt-2">@lang( 'accounting::lang.view_report')</a>
                </div>
            </div>
        </div>

        <div class="col-md-6">
            <div class="box box-warning">
                <div class="box-header with-border">
                    <h3 class="box-title">@lang( 'accounting::lang.account_payable_ageing_details')</h3>
                </div>
                <div class="box-body">
                    @lang( 'accounting::lang.account_payable_ageing_details_description')
                    <br/>
                    <a href="{{route('accounting.account_payable_ageing_details')}}" 
                    class="btn btn-primary btn-sm pt-2">@lang( 'accounting::lang.view_report')</a>
                </div>
            </div>
        </div>

    </div>
</section>

@stop
