# ADR 002: The AI never calculates

- Status: Accepted (2026-11-21, owner decision recorded in CLAUDE.md)
- Task: B-04

## Context
Claude Haiku is used as a formulation assistant: it understands Arabic colloquial requests, calls engine tools, explains results, and extracts structured data from COA/TDS documents. Language models can produce plausible but wrong numbers, which is unacceptable for formulas, costs and regulatory statements.

## Decision
1. Haiku only orchestrates. Any figure it presents must come from a tool that executes the engine (`packages/engine`).
2. Number guard (`plan/04-ai-haiku/guard.md`): every number in a reply is normalized (Arabic to Latin digits, separators) and must match a number in a tool result of the same session, or be in the user's own message, or be an allowed token (standard IDs such as ES numbers, dates, list step numbers). Otherwise the reply is rejected and Haiku is told to call the right tool or drop the number. After two attempts the user sees a safe reply without numbers plus a "calculate" action.
3. The Anthropic API key exists only on the server. Clients, Electron and mobile never hold it and never call the API directly.
4. Temperature 0 for all calls. Prompt caching is used for the system prompt and tool definitions to control cost.
5. Formulas (the factory's intellectual property) are sent to the AI only with explicit per-session user consent. Without consent the assistant works with non-formula context and says so.
6. The AI never writes reference data (materials, specs, limits, COA values) on its own. Extractions are returned as proposed JSON; a human confirms before anything is saved, and the confirming user is recorded in the audit log.
7. The assistant does not invent chemical or regulatory numbers. If a tool returns `missing[]`, the reply reports what is missing.
8. Tool calls, results, guard decisions and consent state are logged per session for audit and evals.

## Consequences
- Replies may be slower or terse when the guard forces a retry; this is accepted.
- A tool must exist for every figure the assistant may need; gaps are tool backlog items, not prompt tweaks.
- Guard behavior needs tests (rejection of invented numbers, acceptance of user-supplied and Arabic-digit matches) and eval sets in `plan/04-ai-haiku/evals.md`.
- Consent UI and logging are required before any formula leaves the server boundary.
- Offline editions have no assistant unless the server is reachable.

## Alternatives rejected
- Letting the model compute with a code interpreter: non-deterministic, unauditable.
- Prompt-only instructions ("do not invent numbers"): not enforceable without a guard.
- Client-side API key or direct client calls: key exposure.
- Auto-saving AI-extracted COA data: a single misread value would pollute reference data.
- Always sending formulas for convenience: breaches factory confidentiality.
