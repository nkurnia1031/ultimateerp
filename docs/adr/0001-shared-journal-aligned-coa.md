# Shared journal-aligned Chart of Accounts

## Decision

Provision the 70-code Journal catalog for existing and future businesses so Journal imports have a consistent CoA target. Keep the four BM-specific bank codes (1-10004, 1-10005, 1-10006, and 1-10007) and the legacy 8-80005 Bank Loan expense account scoped to business #2. Code 1-10001 Cash is shared; other businesses use the generic name Cash and cash equivalents for it. Other businesses map their own cash and bank accounts.

Classify codes by the Journal workbook: 1 asset, 2 liability, 3 equity, 4 income, and 5, 6, and 8 expense. In particular, 5-xxxxx is cost of sales/expense, including in Journal import fallback classification.

Reuse an existing account only when its GL code, explicit operational crosswalk, or exact unique name identifies it and its primary type is compatible. Set an operational-account crosswalk only inside CV BM TRANSPORT and only for one exact name with no competing CoA link. Leave missing or ambiguous links for manual mapping. Do not fuzzy-match names, change account names, or overwrite conflicting codes/types.

Provisioning creates account definitions and safe crosswalks only. It does not post journals or change operational transactions. Keep migration rollback forward-only because provisioned accounts may acquire postings after deployment.

Keep 8-80005 Bank Loan as an expense for business #2 to preserve compatibility with the legacy Journal source. Its installment descriptions may include loan principal; audit that classification separately and do not reclassify historical postings in this migration.

## Consequences

- Existing businesses receive the shared catalog from an idempotent data migration.
- New businesses receive it through the Eloquent business-created path once Accounting tables exist.
- Business #2 keeps its imported account codes and safe operational links; other businesses do not receive BM-specific bank names or mappings.
- Conflicts and ambiguous operational names are skipped and logged for review rather than guessed.
- The migration can be retried safely and can be deployed without writing financial journal entries.

## Related issues

- #27 provisions the catalog.
- #19 consumes it for the consolidated cash/bank ledger.
- #25 audits operational-to-accounting mappings.
- The 8-80005 classification remains a separate audit item in #28, under #25.
