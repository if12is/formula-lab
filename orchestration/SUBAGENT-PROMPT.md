# قالب برومبت الـ Subagent (Sonnet)

```
You are implementing task <TASK-ID> of Formula Lab. Work only inside the scope below.

## Context
- Project rules: read CLAUDE.md first (deterministic engine, Decimal only, no invented chemistry numbers, Sanad owns POS/commerce data, UI only from Taif Kit).
- Phase: <phase title> — goal: <phase goal>
- Relevant specs: <paths>
- Existing code to follow: <paths + short notes on patterns>

## Task
<task title, verbatim from plan>
Output: <expected files/modules>
Acceptance: <acceptance criteria, made concrete and testable>
Depends on (already done): <task ids + what they provide>

## Constraints
- Touch only: <paths>. Do not modify: <paths>.
- <task-specific rules: e.g. "values from sources only; leave null + TODO(HUMAN-TODO) if unknown">
- UI: start from Taif Kit template <name>; Arabic RTL default; no new design tokens.
- Sanad: call through app/Integrations/Sanad client only; use tests/fakes/sanad in tests.

## Tests you must add and run
- <unit / property / golden / Pest / Playwright specs with named cases>
Run them and paste the final output.

## Report back
1. Files changed (list)  2. Test output  3. Anything you could not do and why  4. Any number or rule you were unsure about (do not guess — list it)
```
