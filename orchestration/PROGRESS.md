# Progress Log
| التاريخ | التاسك | الحالة | اللي اتعمل | الأدلة |
| --- | --- | --- | --- | --- |
| 2026-11-21 | B-01 | done | ADR-000: الستاك + الـ monorepo (UI جوه apps/api بسبب Inertia، مفيش apps/web) + ربط Sanad | docs/adr/000-stack.md |
| 2026-11-21 | B-03 | done | ADR-001: محرك TS واحد + decimal.js + Node sidecar للسيرفر | docs/adr/001-shared-ts-engine-decimal.md |
| 2026-11-21 | B-04 | done | ADR-002: الـ AI ما يحسبش + number guard | docs/adr/002-ai-never-calculates.md |
| 2026-11-21 | B-05 | done | ADR-003: داتا مرجعية immutable بـ hash + audit append-only | docs/adr/003-immutable-versioned-reference-data.md |
| 2026-11-21 | G-01 | done | مراجعة كود Sanad وتصحيح spec الربط (store API للبورتال بس، المزامنة DB-level) + عقد HTTP v1 | plan/02-specs/sanad-integration.md, orchestration/context/sanad-contract-v1.md |
| 2026-11-21 | G-02 | done | مصدر الحقيقة لكل كيان + شيل جداول الموردين/المشتريات من الـ schema واستبدالها بـ sanad_refs وcache | plan/02-specs/data-model.sql |
