<style>
    .general-ledger-table { width: 100%; border-collapse: collapse; }
    .general-ledger-table th, .general-ledger-table td { border: 1px solid #333; padding: 4px 6px; vertical-align: top; }
    .general-ledger-table .text-right { text-align: right; }
    .general-ledger-table .text-center, .general-ledger-table .report-title, .general-ledger-table .report-period { text-align: center; }
    .general-ledger-table .report-title { font-size: 16px; font-weight: bold; }
    .general-ledger-table .report-period { font-size: 13px; font-weight: bold; }
    .general-ledger-table .account-heading th { background-color: #e9eef4; font-weight: bold; }
</style>

@include('accounting::report.partials.general_ledger_table', ['isExport' => true])
