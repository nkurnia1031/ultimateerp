# Shared import batch lifecycle

User-facing import flows need one consistent way to track progress, inspect row results, recover the uploaded workbook, and safely reverse changes, but several mature importers already own domain-specific batch state and rollback rules. Use a shared batch-history contract and registry as the user-facing lifecycle, while keeping parsing, preview rows, and reversible mutations behind importer-specific adapters; existing domain batch records remain authoritative for their detailed processing until migrated. Run file analysis and import work through queued jobs with AJAX progress. Do not retain uploaded workbook binaries permanently: retain a private, immutable snapshot of original sheet names, headings, and cell values, then rebuild a logical `.xlsx` download from that snapshot. Revert through the owning importer and refuse it when imported or updated records have since changed or acquired dependencies that make restoration unsafe.


SPB batches persist provenance on generated routes in the same database transaction as each trip. This lets a retry recover safely if the worker stopped before the result snapshot was saved, and lets revert find and remove only routes created by that batch after checking for rate, trip, and Vendor Loading dependencies. Post-import fingerprints are calculated from refreshed database rows so database defaults are included and immediate revert compares the same stored values.

Vendor Loading retains its module batch as the authority for parsing, progress, and safe reverse, with a one-to-one link to the shared user batch used by searchable history, detail, and workbook restoration. Analysis snapshots source sheet cell values in private storage and deletes the uploaded binary after parsing. Older Vendor batches are surfaced in history without fabricating a source snapshot.

Vendor batch details show document and line identifiers to import-authorized users, but only expose clickable document links when the user also has Vendor record-view permission. Analysis cleanup removes staging paths in both the normal `finally` path and the queue's terminal failure callback; cleanup failures are reported rather than hidden.

Vendor history uses the shared responsive DataTable layout with wrapping action controls and content so the page does not force a wide horizontal table on narrow screens.

**Status:** accepted for implementation, 2026-11-20.

**Consequences:** importer adapters must expose business-scoped history, details, workbook restoration where a file was uploaded, and safe revert behavior. The rebuilt workbook preserves source values and sheet structure, not original formatting, macros, or exact binary identity. Snapshots live as long as their import history entry and are not automatically purged.
