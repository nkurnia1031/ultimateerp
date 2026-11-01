# Legacy import coordinator

`accounting:import-legacy` inventories `import-legacy/` and coordinates legacy Journal and operational exports for one business. The folder manifest is saved with the run so an interrupted import can resume without re-posting completed Journal entries.

## Preview first

Run the read-only analysis on the target environment after deploying the code and migration:

```sh
php artisan migrate --force
php artisan accounting:import-legacy --business-id=2 --user-id=5
```

The preview reports Journal overlap/conflicts, source coverage, operating records that can be linked or safely created, and items held for review. It does not write application data or copy source files. It also writes one read-only annual reconciliation XLSX and JSON for each year represented by the source periods.

Annual reports are stored privately under storage/app/legacy-import-reports/business-{business-id}/. They include account opening/debit/credit/closing balances, Journal-to-operational base and VAT In/VAT Out comparisons, source file/row provenance, coverage gaps, and comparisons to the General Ledger and Buku Besar control workbooks. Those control workbooks remain comparison evidence; they are never imported as postings.

Use --year=2022 to emit only the 2022 report. The audit still reads Journal history before 2022 to calculate carried balances, while the import preview itself remains limited to 2022. To use a different source folder, pass --source-dir=/path/to/folder; the default is import-legacy/.

Attachment metadata in `json/lampiran.json` is handled after operational crosswalks are saved. Attachments link only through a unique legacy transaction ID or a unique source type/number in that run. Unmatched, unsupported, or ambiguous attachment rows remain reviewable in `accounting_legacy_import_attachments` and do not block other records.

## Apply and resume

Only after reviewing the preview, start the persisted import:

```sh
php artisan accounting:import-legacy --business-id=2 --user-id=5 --apply --chunk-delay-ms=2000
```

If the process stops, use the run ID printed by the command:

```sh
php artisan accounting:import-legacy --business-id=2 --user-id=5 --resume-run=RUN_ID --apply --chunk-delay-ms=2000
```

The command prints linked/review/failed/rejected attachment counts and writes a private CSV mapping report under `storage/app/legacy-import-reports/`. The report lists source attachment and transaction IDs, original/destination filenames, matched transaction, Media ID, status, and reason. Re-running the same run updates the same attachment rows and Media records.

On non-production environments the importer reuses a file already present in `public/uploads/documents` and never makes an HTTP request. In production it checks the deterministic destination first, then downloads a missing file from an HTTPS host in `LEGACY_ATTACHMENT_ALLOWED_HOSTS` (default `jurnal-quickbook.jurnal.id`). `LEGACY_ATTACHMENT_MAX_BYTES`, `LEGACY_ATTACHMENT_TIMEOUT_SECONDS`, and `LEGACY_ATTACHMENT_CONNECT_TIMEOUT_SECONDS` set download limits. Files are restricted to the configured PDF, image, ZIP, and XLSX types and are installed without overwriting an existing destination.

Use the same `import-legacy/` files and year filter when resuming. The manifest guard rejects changed source files. To scope the run to a calendar year, add the same `--year=2022` option to preview, apply, and resume.

## Posting and review rules

- Journal groups are posted only through the existing Journal Import command and handlers. Identical overlap is collapsed; conflicting Journal content is held for review.
- Operational data complements matching Journal entries. Product and category mapping must be unique and exact before the coordinator fills invoice or Expense detail.
- A complete operational-only invoice or Expense may be created or linked. It remains visible as a review crosswalk because it has no Journal source; the coordinator does not invent a GL posting or payment.
- Missing, ambiguous, conflicting, unsupported, or amount-inconsistent details remain in the persistent crosswalk review state with a reason.
- CoA, General Ledger, Buku Besar, templates, PDFs, and error reports are inventoried as controls or references; they are not posted as transactions.
- `--apply` requires explicit business and user IDs. `--resume-run` is scoped to that same business and the original file manifest.
