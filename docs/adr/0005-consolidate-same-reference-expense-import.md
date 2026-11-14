# Consolidate same-reference Expense imports

**Status:** accepted — 2026-11-14

When one ready Journal posting matches a unique same-business, same-reference Expense group by final total, the Expense importer consolidates the source records into one Expense so one operational record represents the Journal entry. It preserves payment history and supported attachments, removes only source GL rows proven to duplicate the selected Journal, and records VAT only from Journal VAT components in the Expense tax fields. A unique group with matching source dates can be recommended automatically. If source dates differ, the preview requires the user to select the group; the resulting Expense uses the Journal/workbook date. Conflicting or unsupported relationships stop consolidation for review.

Workbook `EXPENSE NOTE` is the primary source for `expense_lines.description`; workbook `DESCRIPTION` is the fallback when `EXPENSE NOTE` is empty. Neither workbook column is appended to `transactions.additional_notes`. Existing parent transaction notes remain on the parent Expense and are shown separately in preview.

`expense_lines.note` remains a separate field for manual notes. When an existing Expense is updated and its lines are replaced, preserve each existing line note and document only when the old line can be matched unambiguously by category, amount, and description. If a note or document cannot be matched safely, stop the import before deleting source data and explain the mismatch.

`ATTACH DOCUMENT` is stored on its corresponding `expense_lines.document`. A filename missing from `public/uploads/documents` produces a non-blocking preview warning; the importer retains the workbook filename because the workbook does not contain the file bytes. Existing parent documents are not changed by this field.

For import batch `213` from the legacy workbook `export_biaya 2024.xlsx 1 - 100.xlsx`, copy a non-empty line `note` into a blank `description` once, while retaining the original `note`. The migration is scoped to expense transactions with that exact file name and batch number.

The upload page keeps parsing/import progress in the history table. A ready preview opens on its own page. After confirmation, return to the same history row so import progress remains visible. If an import fails while the batch remains in preview, let the user re-read the saved workbook and reopen the refreshed preview before retrying.
