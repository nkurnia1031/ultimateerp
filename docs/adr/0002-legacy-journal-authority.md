# Legacy Journal is authoritative for GL import

## Status

Accepted — 2026-11-02

## Context

Legacy accounting data is distributed across Journal exports, operational transaction exports, detail JSON, and annual control workbooks. Requiring a person to review every Journal row with missing or ambiguous operational detail leaves valid accounting postings unimported and does not scale. The Journal workbook contains the grouped debit and credit lines needed for the General Ledger; operational files provide supporting transaction details but may be incomplete or inconsistent.

## Decision

- Use the selected legacy Journal group as the source of truth for GL amounts and account lines. For conflicting copies of one source identity, select the copy from the workbook with the newest covered period end; retain every copy and the selection rationale in provenance.
- Link or update an operational transaction only when its identity is reliable and required fields can be validated. Create a missing operational transaction only when the legacy files contain its required details.
- If an operation cannot be linked or constructed safely, post the balanced Journal group to GL without an operational relation. Record the unresolved identity and reason in the legacy crosswalk and annual audit; do not block the Journal row for manual review.
- Treat Journal VAT as authoritative for a uniquely linked Sales, Purchase, or Expense transaction. Preserve the operational taxable base and recalculate tax fields/items when available detail and a business TaxRate support an exact calculation. Record any irreconcilable variance in the annual audit.
- Never invent products, quantities, stock movements, contacts, bank/payment accounts, or CoA mappings. A Journal group that is unbalanced or lacks valid mapped accounts cannot be posted; report it as a non-postable audit exception.
- A source key already posted is completed idempotently and is not posted again.

## Consequences

- A completed Journal Import row can have no operational source relation; its posting still appears in GL and the source gap remains traceable in the audit.
- Operational/source matching quality is reported separately from posting completion.
- Unmatched operational-only records remain audit findings because they do not have authoritative Journal lines to post.
- Annual audits must distinguish Journal-only postings, linked operations, and truly non-postable Journal exceptions.

## Related issues

- #41 coordinates the full legacy import and yearly audit.
- #8 and #10–#15 provide Journal Import handlers and operational source links.
