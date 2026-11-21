# ADR 003: Immutable, versioned reference data

- Status: Accepted (2026-11-21, owner decision recorded in CLAUDE.md)
- Task: B-05

## Context
Results must be reproducible years later: the same formula with the same data must give the same numbers, and an auditor must see who changed what and when. Reference data (materials, properties, regulatory lists such as ES, CosIng, IFRA, price and factor tables) changes over time and has per-value sources and confidence (L1 to L7).

## Decision
1. Reference datasets are immutable snapshots. Each snapshot is identified by a content hash (SHA-256 of canonical JSON) and registered in `dataset_versions`. Changing any value produces a new snapshot and a new hash; existing snapshots are never updated or deleted.
2. Every calculation records the `datasetVersion` it used (it is also part of the engine input and output, see ADR 001), together with `engineVersion`, `inputHash` and `outputHash`.
3. Approved `formula_versions` are immutable. An edit creates a new version (draft, then approval); the previous approved version stays intact and referenceable. Production orders and batches point to a specific formula version.
4. Values keep their `sourceId`, `confidence` and `validFrom`. Corrections are new records with a new validity, not overwrites.
5. The audit log is append-only and follows ALCOA+: attributable (user), legible, contemporaneous (server timestamp), original, accurate, plus complete, consistent, enduring and available. No update or delete is permitted, enforced at the database level (no UPDATE/DELETE privilege, or triggers that reject them).
6. Approvals, AI-proposed data confirmations (ADR 002) and dataset publications are audit events.
7. Missing or unknown reference values stay `null` and are tracked in `orchestration/HUMAN-TODO.md`; they are never filled with guessed numbers.

## Consequences
- Old results can be re-run exactly by pairing the stored input, `engineVersion` and `datasetVersion`.
- Storage grows with snapshots; sharing of unchanged rows or compact diffs between snapshots can be considered later without breaking identity by hash.
- Updating data means publishing a new dataset version and choosing when formulas adopt it; adoption is an explicit, logged action.
- UI must show which dataset and formula version a figure came from, and offer "recalculate with latest data" as a new calculation, not an overwrite.
- Offline editions carry a dataset snapshot and report its hash when syncing.

## Alternatives rejected
- Mutable tables with an `updated_at` column: cannot reproduce past results.
- Snapshots identified by incrementing numbers only: hashes make integrity and cross-site equality checkable.
- Soft-delete or editable audit rows: not defensible under ALCOA+.
- Live reads of the newest data at calculation time: results change silently.
