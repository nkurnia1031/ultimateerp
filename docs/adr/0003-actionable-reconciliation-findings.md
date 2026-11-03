# Actionable reconciliation findings

**Status:** accepted — 2026-11-03

Annual legacy audits remain read-only evidence; their numeric variances are persisted as business-scoped findings in a separate, actionable reconciliation inbox so review decisions do not mutate the report or legacy Journal. The inbox may also contain an amount-bearing classification decision when the amount is known but its accounting treatment is not supported by evidence; such findings remain separate from numeric variance totals and do not offer automatic operational or journal correction.

Corrections require a before/after preview and confirmation, update an operational component only when uniquely supported by evidence, and otherwise use a linked Accounting adjustment; a finding closes only after a fresh comparison clears the variance or an authorized user accepts it with a required reason. Rounding journals are limited to differences below the business currency precision and require an explicitly mapped CoA account.
