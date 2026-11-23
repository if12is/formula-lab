# Golden tests (T-01)

A golden case is a **hand-computed** expected value. The arithmetic is written in the comment above the case, so a reviewer
(the chemist, the accountant) can redo it on paper. The engine is never the source of the expected value.

## Rules

1. Inputs are fictional and chosen so the hand arithmetic is exact (round factors, `TEST-FIXTURE` sources). No real market price,
   spec limit or category percentage goes in a golden case (CLAUDE.md rule 5). Where the engine divides by a non-terminating
   constant (a molar-mass ratio) pick inputs where the result is a clean mole, or compare with `r9()` (9 decimals) and say so.
2. Every `describe` starts with a marker `golden[<id>]`. `<id>` is a calculation id from
   `packages/engine/data/product-families.json` (`vocabulary.calculations`) or one of the engine-level families listed in
   `coverage.golden.test.ts` (`cost`, `price_waterfall`, ...).
3. Expected values are strings (decimal strings, as the engine returns them). No JS numbers.
4. A missing input is part of the contract: add the "missing is reported, never zero" case next to the happy case.
5. A golden case that disagrees with the engine is a finding, not a reason to edit the expectation: re-check the arithmetic, then
   report a bug (HUMAN-TODO / queue) and fix only if it is clearly a bug, with a test.

## Adding a golden case for a new product (or calculator)

1. New product family in the registry: list its `requiredCalculations`. Each must already be `available` with a golden case. If
   the family needs a calculation that has none, write it first (steps 2 to 4).
2. New calculator: add `describe('golden[<calculation id>] ...')` in the file of its family
   (`alkali-active`, `hlb-fragrance`, `cost-price`, `formula-process`) or a new `<name>.golden.test.ts`.
3. Write at least: one exact happy case with the arithmetic in the comment, one boundary case (zero, limit, 100 %), one missing
   or refused case (`missing[]`, FAIL/UNKNOWN status).
4. If it is a new engine-level family (not in the registry), add its id to `ENGINE_LEVEL` in `coverage.golden.test.ts`.
5. Run `pnpm --filter @fl/engine test`. `coverage.golden.test.ts` fails when an `available` registry calculation has no
   `golden[...]` marker, when a family requires an uncovered calculation, or when a marker has a typo.
6. A product whose cost shape is new (a new packaging layout, a new tax treatment) also gets a `calculate()`/`computeUnitCost` case
   in `cost-price.golden.test.ts` (or a new file), with the unit cost worked out in the comment line by line.

## Files

- `support.ts`: `must()`, `r9()`, and a three-row hand OIML table (`HAND_TABLE`, fictional, interpolable by hand).
- `cost-hand.test.ts`: the 20 F-02 `calculate()` cases (kept as they were).
- `alkali-active.golden.test.ts`, `hlb-fragrance.golden.test.ts`, `cost-price.golden.test.ts`, `formula-process.golden.test.ts`.
- `coverage.golden.test.ts`: the convention guard.
