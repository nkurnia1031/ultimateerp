# Full legacy recheck on resume

Status: accepted — 2026-11-07

When a legacy run is resumed, recheck all supported operational transactions for that business, including rows whose Journal batch is already complete. Keep the legacy Journal as the sole posting for uniquely linked transactions and remove only proven duplicate automatic operation postings; for operations without Journal evidence, use unique operational evidence, saved mappings, then a unique Journal pattern, and route unresolved cases to reconciliation instead of guessing. This keeps exports consistent while preserving manual journals and traceable exceptions.
