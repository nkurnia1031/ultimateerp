# User initiated import lifecycle — ticket map

Parent specification: [#56](https://github.com/nkurnia1031/ultimateerp/issues/56).

1. [#57 SPB driver confidence and group approval](https://github.com/nkurnia1031/ultimateerp/issues/57) — no blocker. Ranked same-business candidate Users, confidence display, explicit approval of ambiguous/fuzzy candidates, canonical group propagation, and row readiness update. **Implementation complete; CI pending after push.**
2. [#58 SPB queued batches with history, restore, and revert](https://github.com/nkurnia1031/ultimateerp/issues/58) — blocked by #57. Add shared batch contract as the first complete adapter: AJAX upload, queued analysis/import, durable progress, DataTable history, detail, reconstructed workbook, and safe revert.
3. [#59 Vendor Loading history and workbook restore](https://github.com/nkurnia1031/ultimateerp/issues/59) — blocked by #58. Preserve queue and reverse behavior; add common history, row details, DataTable paging, and reconstructed workbook download.
4. [#60 Heavy Equipment rate import lifecycle](https://github.com/nkurnia1031/ultimateerp/issues/60) — blocked by #58. Queue rate analysis/import; show batch detail/history; restore the source workbook; safely revert rate changes.
5. [#61 Expense batch lifecycle alignment](https://github.com/nkurnia1031/ultimateerp/issues/61) — blocked by #58. Keep queue, preview, restore, rollback, and financial rules; align history/detail and restored workbook with the shared contract.
6. [#62 Solar batch lifecycle alignment](https://github.com/nkurnia1031/ultimateerp/issues/62) — blocked by #58. Keep queue, preview, rollback, and ledger rules; align history/detail and restored workbook with the shared contract.
7. [#63 Journal Import lifecycle alignment](https://github.com/nkurnia1031/ultimateerp/issues/63) — blocked by #58. Preserve Journal batch semantics and GL authority; expose common progress/history/detail/download/revert while keeping review/idempotency.
8. [#64 Legacy Sales/Purchase/Expense imports](https://github.com/nkurnia1031/ultimateerp/issues/64) — blocked by #58. Move synchronous file processing to queued AJAX batches with history, details, restore, and safe revert.
9. [#65 Product and Opening Stock imports](https://github.com/nkurnia1031/ultimateerp/issues/65) — blocked by #58. Add queued batch lifecycle, row results, restored workbook, and dependency-aware revert.
10. [#66 Purchase product and price-group imports](https://github.com/nkurnia1031/ultimateerp/issues/66) — blocked by #58. Add queued batch lifecycle, row results, restored workbook, and safe revert.
11. [#67 Bonus Ayak import](https://github.com/nkurnia1031/ultimateerp/issues/67) — blocked by #58. Persist preview/commit runs as queued batches with progress/history/detail/restore/revert.
12. [#68 Attendance import](https://github.com/nkurnia1031/ultimateerp/issues/68) — blocked by #58. Queue workbook processing with history, detail, source restoration, and safe revert.
13. [#69 Project workbook import](https://github.com/nkurnia1031/ultimateerp/issues/69) — blocked by #58. Queue workbook stages and expose batch history, detail, restored workbook, and safe revert.
14. [#70 Contact CSV import](https://github.com/nkurnia1031/ultimateerp/issues/70) — blocked by #58. Add durable history, details, reconstructed source, and safe revert.
15. [#71 CRM contract workbook import](https://github.com/nkurnia1031/ultimateerp/issues/71) — blocked by #58. Queue workbook processing and expose batch history, detail, restore, and safe revert.
16. [#72 Cross-business Contact/User/Expense Category imports](https://github.com/nkurnia1031/ultimateerp/issues/72) — blocked by #58. Queue copy operations and provide durable history/detail/revert without a workbook action.

Execution begins with #57. Existing Expense/Solar/Journal/Vendor jobs, review rows, and rollback logic remain authoritative while adapters are added.
