# Shared import batch lifecycle

User-facing import flows need one consistent way to track progress, inspect row results, recover the uploaded workbook, and safely reverse changes, but several mature importers already own domain-specific batch state and rollback rules. Use a shared batch-history contract and registry as the user-facing lifecycle, while keeping parsing, preview rows, and reversible mutations behind importer-specific adapters; existing domain batch records remain authoritative for their detailed processing until migrated. Run file analysis and import work through queued jobs with AJAX progress. Do not retain uploaded workbook binaries permanently: retain a private, immutable snapshot of original sheet names, headings, and cell values, then rebuild a logical `.xlsx` download from that snapshot. Revert through the owning importer and refuse it when imported or updated records have since changed or acquired dependencies that make restoration unsafe.

**Status:** accepted for implementation, 2026-11-20.

**Consequences:** importer adapters must expose business-scoped history, details, workbook restoration where a file was uploaded, and safe revert behavior. The rebuilt workbook preserves source values and sheet structure, not original formatting, macros, or exact binary identity. Snapshots live as long as their import history entry and are not automatically purged.
