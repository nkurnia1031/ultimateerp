# User initiated import lifecycle

## Problem Statement

Import flows behave differently across the application. Some already analyze or import in the background and have batch history; others run in one request, keep preview state only in cache, or expose no durable detail, restore, or revert action. Users cannot reliably tell where an upload went, what each source row changed, or whether an import can be safely undone.

The SPB workbook `06.  PT. JATIM PROPERTINDO JAYA CANGKANG (MV. EASTERN HOPE).xls` also exposes a matching failure: `Baginda Imom Nasution` and `Mr. Baginda Imom Nasution` are used in the same workbook. The local development database has duplicate active Users with the same canonical name in one business. The current resolver treats multiple matches as no match, and a period after `Mr.` prevents part of the workbook name from normalizing to the same identity.

## Solution

Every user initiated import gets a durable, business-scoped batch. File upload is submitted through AJAX; spreadsheet analysis and database writes run in a queue. The import page shows batch status and progress in a paginated history table, with row-level detail, a restored logical workbook download, and a safe revert action when the batch can still be reversed.

SPB driver matching normalizes honorifics, punctuation, spacing, and case, then presents ranked same-business User candidates with a name-similarity percentage. A unique exact match can be linked automatically. Fuzzy candidates and duplicate exact candidates remain unapproved until a user chooses one. Approving a candidate applies it to every row with the same canonical source name and recalculates readiness.

## User Stories

1. As an import user, I want an upload to return a batch immediately and show progress while analysis continues, so I know the workbook was accepted.
2. As an import user, I want file analysis and database writes to run in the background, so a large workbook does not wait on one long page request.
3. As an import user, I want every batch to remain in a history table after I leave the preview, so I can find and resume the work later.
4. As an import user, I want to search, filter, and paginate import history, so a long history remains usable.
5. As an import user, I want to open a batch and see row-level source positions, results, errors, and links to records created or updated by the import.
6. As an import user, I want progress, partial success, failure, cancellation, and revert states to be distinguishable in the history table.
7. As an import user, I want to download a workbook reconstructed from the original source rows after upload, so I can recover the imported data without permanently storing the uploaded binary.
8. As an authorized user, I want a batch download to be available only to users with permission in the same active business.
9. As an import user, I want to revert an imported batch and restore prior values when the affected records have not changed since import.
10. As an import user, I want revert to stop with a clear reason if another transaction now depends on or has changed an imported record.
11. As an import user, I want a failed queued batch to expose its reason and an idempotent retry action, so I can recover without creating duplicate records.
12. As an import user, I want to cancel work that has not committed, so an unwanted upload cannot continue silently.
13. As an SPB importer, I want name matching to ignore honorific punctuation and case, so `Mr. Baginda Imom Nasution` can match the same canonical User name.
14. As an SPB importer, I want candidate Users ranked with a visible similarity percentage, so I can distinguish a strong suggestion from a weak one.
15. As an SPB importer, I want any score below 60% marked for examination and never silently accepted, so a weak name match cannot attach a trip to the wrong driver.
16. As an SPB importer, I want duplicate exact Users shown as separate candidates with a distinguishing identifier, so I can choose the intended record.
17. As an SPB importer, I want one approved driver choice to apply to every row with the same canonical workbook name, so I do not repeat the same correction across the sheet.
18. As an SPB importer, I want group approval to update all affected row statuses immediately, so corrected rows become ready as soon as their other validation errors are resolved.
19. As an operator, I want every import flow to enforce active-business and permission checks on upload, preview, history, download, retry, and revert, so imports cannot cross business data.
20. As an operator, I want existing domain-specific validation, preview edits, and accounting rules preserved while each import adopts the common history and progress experience.

## Implementation Decisions

- **D1 — Scope:** include every user initiated data import, both file-based Excel/CSV flows and business-to-business copy imports. This includes Expense, Solar, Journal, SPB, Vendor Loading, Heavy Equipment rates, sales/purchase, products/opening stock, purchase products and price groups, Bonus Ayak, attendance, project workbooks, contact CSV, CRM contract workbooks, and cross-business contact/user/expense-category imports. Exclude CLI/maintenance jobs, security-audit report uploads, template downloads, and ordinary document attachments.
- **D2 — Batch contract:** use a shared history and lifecycle contract, but keep parsing, row details, and domain-specific rollback in importer adapters. Do not replace mature Expense, Solar, Journal, or Vendor batch processing with a second implementation.
- **D3 — Asynchronous work:** the browser uploads with AJAX and reports transfer progress; analysis, preview generation, and import writes run as Laravel queued jobs. Keep dedicated queues already used by importers; new queued flows use the configured imports queue.
- **D4 — Status:** expose `queued`, `analyzing`, `preview_ready`, `queued_import`, `importing`, `imported`, `partial`, `failed`, `cancelled`, and `reverted` states where applicable. Every transition is durable and jobs remain idempotent.
- **D5 — History/detail:** each importer page has a common DataTable history with search, status/date filters, pagination, progress, and actions. Batch detail shows source sheet/row, processing result, error, and related record reference.
- **D6 — Workbook restoration:** do not retain uploaded XLS/XLSX/CSV binaries permanently. Store a private immutable snapshot of source sheet names, headings, and cell values through the history lifetime, then generate a logical `.xlsx` on download. This preserves values and sheets, not original styling, macros, formulas, or binary identity. Do not auto-expire snapshots.
- **D7 — Safe revert:** each importer adapter deletes records created by the batch and restores captured prior values for updated records only if current fingerprints still match the post-import state. Block revert with a specific explanation if records changed or have downstream dependencies. Do not perform partial rollback.
- **D8 — Business scope:** batch list, details, restored workbook, preview, retry, and revert are restricted to the active business and existing import permissions. History is visible to users who have the relevant import-view permission, not only to the uploader.
- **D9 — SPB confidence:** scores represent name similarity, not a statistical probability. Normalize honorifics including punctuation (`Mr.`), case, whitespace, and name punctuation. Rank candidates by the greater of character-level similarity and token-set Dice similarity, rounded to an integer percentage. Show the five leading same-business active User candidates, and show every exact duplicate when more than five exist. Automatically link only a unique canonical exact match. Fuzzy and duplicate exact matches require explicit choice; scores below 60% are marked `Perlu pemeriksaan`. A user-approved choice propagates only to rows with the same canonical source name, not merely similar names.
- **D10 — Vendor identities:** Vendor unit and driver remain vendor-provided text snapshots; do not match them against internal Assets or Users. This follows the existing Vendor data model.
- **D11 — Existing contracts:** keep module-specific job queues, preview validation, financial posting rules, and detailed batch records as the source of business behavior. The shared contract normalizes history actions and state for the page; migrate one importer at a time.
- **D12 — Queue recovery:** upload confirmation and batch import requests use AJAX; progress polling reads durable batch state. Retry resumes only failed/unprocessed work and cannot duplicate successful rows.
- **D13 — Shared batch record:** add one `user_import_batches` record as the common history/status/download/revert envelope, keyed by importer and business. Domain adapters keep their own row validation and domain-specific batch tables when they already exist; they store the shared batch ID on records they create or update.
- **D14 — SPB revert:** SPB import creates draft trips. Revert is all-or-nothing and allowed only while every imported trip still has its post-import field fingerprint, remains editable, and has no invoice or payroll settlement dependency. If any trip changed or gained a dependency, block the whole revert with the affected trip numbers.
- **D15 — SPB staging:** keep the uploaded workbook only in private staging while the queued preview job reads it. Persist the immutable values snapshot and preview payload, then delete the staged binary after processing, including failed processing. The history download rebuilds `.xlsx` from the snapshot.

- D16 - SPB route recovery: tag a route created by an SPB batch with that batch ID in the same database transaction as its trip. Revert discovers routes from persistent provenance, including after a worker retry before result payload persistence. Revert blocks if a generated route has rate, trip, or Vendor Loading dependencies.
- D17 - SPB browser coverage: use an isolated CI business, unit, and driver with a real XLSX upload; start the imports queue worker inside Playwright so the browser test covers queued preview and import, source restoration, details, and safe revert without touching local or production records.
- D18 - SPB E2E workbook fixture: generate a genuine XLSX with PhpSpreadsheet. The virtual CSV upload was rejected by the existing MIME validator as `text/plain` before creating a batch; keep this ticket's browser scenario focused on the workbook path and leave application validation unchanged.
- D19 - SPB E2E confirmation dialog: attach the dialog listener, initiate the Revert click, accept the native confirmation, then await the click. Accepting only after awaiting the click deadlocks Playwright actionability while the browser dialog is open.
- D20 - SPB E2E revert response: assert the revert POST's HTTP status and JSON result before waiting for the history table, so a server refusal is distinguishable from a stale table refresh.
- D21 - SPB fingerprint source: calculate the post-import fingerprint from the persisted trip row after database defaults have been loaded. This keeps immediate revert comparisons consistent with the row later locked and read from the database.
- D22 - Vendor batch ownership: keep `heavy_equipment_vendor_loading_import_batches` authoritative for Vendor parsing, progress, and guarded reverse. Link each row to the shared `user_import_batches` envelope; document provenance and reverse continue to use the Vendor batch ID.
- D23 - Vendor workbook retention: snapshot each sheet's original cell values and headings into private immutable storage during queued analysis, then delete the staged workbook binary whether analysis succeeds or fails. Rebuild a logical `.xlsx` from that snapshot.
- D24 - Vendor history access: any user with Vendor import permission may search, inspect, and download batches in the active business; only users with Vendor manage permission may reverse them. Vendor unit and driver remain workbook text snapshots.
- D25 - Existing Vendor batches: preserve old domain batch rows in the shared history by creating a linked history envelope on first history read. Do not invent a source workbook snapshot when none was retained; show download only when a source snapshot exists.
- D26 - Vendor document links: Vendor import permission is sufficient to inspect the batch and see document/line identifiers. Include a clickable document URL only when the user also has an existing permission to view Vendor Loading records.
- D27 - Vendor staging cleanup: remove the original workbook after analysis in both normal and queue-failure paths, clear both domain and shared staging references, and keep any deletion failure visible in logs rather than silently claiming the binary was removed.
- D28 - Vendor history layout: use the shared responsive DataTable behavior and allow action controls and long workbook/error text to wrap, avoiding a forced minimum table width on narrow screens.
- D29 - Vendor route-test context: isolated route tests provide the active business session directly and bypass only `SetSessionData` and `AdminSidebarMenu`, which need unrelated Business/Currency and CRM menu tables. Authentication, importer permissions, controller business scoping, and cross-business denial remain exercised.
- D30 - Vendor history row projection: add legacy-batch identifiers and preview/reverse eligibility by mapping the already paginated shared-history rows. Keep the domain batch authoritative for Vendor actions and avoid relying on mutation by reference over a Collection.
- D31 - Vendor retry safety: allow retry only for a failed batch with a retained preview and no created documents; reset prior failed sheet results before queueing. Refuse retries when any batch document exists, preventing a worker failure between document creation and result persistence from duplicating records.
- D32 - SPB route dependency check: Vendor Loading lines no longer store `business_id` or `route_id`; both belong to their parent document. SPB route revert must join each line to its document and scope by the document's business and route, matching the normalized schema and avoiding a missing-column failure.
- D33 - Heavy Equipment rate history: keep `heavy_equipment_rate_batches` authoritative for rate records and their domain lifecycle, linked to one shared `user_import_batches` row for progress, history, detail, and downloads. Lazy-backfill existing batches without inventing a workbook snapshot.
- D34 - Heavy Equipment rate staging: accept uploads by AJAX, analyze them on the `imports` queue, snapshot all workbook sheets/cells, and remove the private staging binary after analysis succeeds or fails. Preserve the importer's existing first-sheet parsing behavior.
- D35 - Heavy Equipment rate preview: queued analysis creates the existing draft rate/unit records so the current Asset matching and preview table remain available. Match Asset names/codes only inside the active business.
- D36 - Heavy Equipment rate confirmation: activate draft rates in a queued job, guarded by a locked shared batch transition. Repeated confirmation is rejected once queued; retry is available only after failure while the domain batch remains draft and no rate was activated.
- D37 - Heavy Equipment rate revert: capture the post-import fingerprints of batch rates/units and of prior active rates changed by activation. Revert all-or-nothing only if imported rates are unchanged and unused and prior rates still match their captured post-import state; restore prior status/date and mark imported rates cancelled. Refuse active legacy batches lacking proof rather than guess which older rate state to restore.
- D38 - Heavy Equipment row errors: keep detailed source-row errors on the authoritative rate batch and mirror their searchable text into the shared history row. The detail endpoint maps each issue back to its workbook row.
- D39 - Expense history adapter: keep `expense_import_batches` and its preview rows authoritative for Expense validation and transaction processing, with a one-to-one link to the shared `user_import_batches` envelope. Use a stable source key so upload history and lazily backfilled legacy transaction batches remain idempotent; suppress a legacy row when it already belongs to a newer Expense batch. A unique filename/time fallback match is persisted on the Expense domain summary so reference search, detail, and revert stay attached to the visible batch after duplicate suppression. Serve the history table through the shared server-side DataTable contract and preserve reference search. Snapshot every workbook sheet before analysis, delete the staging binary after preview succeeds or fails, and rebuild later previews and downloads from the immutable snapshot. Keep existing Expense grouping, Journal, tax, payment, and guarded revert behavior; reflect a successful revert in both batch histories.

## Testing Decisions

- Test observable behavior through upload/status/preview/update/confirm/history/download/revert routes, not private scoring helpers or storage implementation.
- The SPB preview seam uses a synthetic workbook with punctuation/title variants and two same-business exact Users. It verifies candidate confidence, ambiguity, low-confidence review state, group approval, and readiness recalculation.
- The batch lifecycle seam verifies AJAX batch creation, durable progress/status, row detail, workbook restoration from immutable source values, same-business authorization, safe revert, and rejection when a dependent record or changed fingerprint exists.
- Each importer adapter gets a synthetic representative file and verifies it produces a complete batch lifecycle without adding real business workbooks to the repository or CI.
- Reuse existing feature tests for Expense, Solar, Journal, Vendor, SPB, and importer-specific transaction behavior. Add equivalent route-level feature tests for importers being migrated.
- CI PHPUnit runs on each push. Playwright E2E remains a separate user-directed workflow after the repository checkpoint.

## Out of Scope

- Storing the original uploaded binary indefinitely or reproducing its exact formatting, macros, or formulas.
- Matching Vendor-provided driver/unit names to internal Users or Assets.
- Changing financial posting authority, tax rules, or transaction validation while adding import lifecycle support.
- Deleting or rewriting existing business records during import rollback when the adapter cannot prove that restoration is safe.
- CLI and one-off maintenance imports, security-audit JSON uploads, template downloads, and transaction attachments.

## Further Notes

- The file named in the report has 159 non-empty driver/description labels and two raw variants of the same Baginda name (3 rows without `Mr.`, 10 rows with `Mr.`). Local business data has more than one exact same-name active User, so exact text alone cannot select a unique ID.
- Existing open Journal Import specifications (#8 and #16) already define resumable Journal processing and review rules. This work only adds the common history/download lifecycle around that implementation; it does not replace those rules.
- Proposed ticket breakdown and blockers are tracked in the child issues linked to the parent spec issue.
