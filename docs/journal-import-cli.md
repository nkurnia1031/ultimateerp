# Journal Import CLI

Run a large journal workbook directly from Artisan on a dedicated CLI worker host:

```sh
php artisan accounting:import-journal /path/to/journal.xlsx --business-id=2 --user-id=7
```

The command accepts `.xlsx`, `.xls`, and `.csv` files. `--business-id` scopes the batch, and `--user-id` records the existing user who authorized the import. The command copies the workbook into private Journal Import storage, creates a persistent batch, then runs the shared analyzer and import processor directly without waiting for the `imports` queue worker.

The CLI and web hosts must use the same database and the same shared filesystem path for Journal Import files. Set `JOURNAL_IMPORTS_SHARED_PATH` to that mounted directory on both hosts, and give both the web process and CLI user read/write access. The default is `storage/app/journal-imports`, which is only shared automatically when both processes use the same filesystem. These workbooks contain financial data and are kept outside the public document root.

Use a dedicated worker host with enough CLI memory and execution time for the workbook. The command removes PHP's per-request execution-time limit for its process. CLI compute is separate from PHP-FPM, but the database is shared with web traffic. Processing stays sequential and uses bounded preparation, row, and tax-reconciliation chunks of 25 rows. It waits 2 seconds only when more work remains in the same phase; adjust with `--chunk-delay-ms=0..30000` after checking database capacity. Each business row remains transactional. Avoid running concurrent CLI imports against the same source workbook.

The command prints the persistent batch ID and final counts. Open **Accounting → Journal Import** to review the same batch. Incomplete, conflicting, failed, or cancelled rows remain available there for correction and retry. A web terminate request is checked between rows/chunks; the current row is allowed to finish before the remaining rows are returned to the batch.

Legacy five-column Laporan Jurnal exports use the same parser and source identity rules as web imports. Identical overlapping Journal Entries are linked to the existing posting and are not posted twice. Conflicting content under the same journal number/date remains pending for review. Source descriptions and account codes remain in the draft; report labels such as HPP, Operasional, Asset Lancar, and Pembelian come from linked operational sources or categories, not account-name guesses.
