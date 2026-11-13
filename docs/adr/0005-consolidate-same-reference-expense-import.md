# Consolidate same-reference Expense imports

**Status:** accepted — 2026-11-14

When one ready Journal posting matches a unique same-business, same-reference Expense group by final total, the Expense importer consolidates the source records into one Expense so one operational record represents the Journal entry. It preserves payment history and supported attachments, removes only source GL rows proven to duplicate the selected Journal, and records VAT only from Journal VAT components in the Expense tax fields. A unique group with matching source dates can be recommended automatically. If source dates differ, the preview requires the user to select the group; the resulting Expense uses the Journal/workbook date. Conflicting or unsupported relationships stop consolidation for review.
