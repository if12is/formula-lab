# Progress Log
| التاريخ | التاسك | الحالة | اللي اتعمل | الأدلة |
| --- | --- | --- | --- | --- |
| 2026-11-21 | B-01 | done | ADR-000: الستاك + الـ monorepo (UI جوه apps/api بسبب Inertia، مفيش apps/web) + ربط Sanad | docs/adr/000-stack.md |
| 2026-11-21 | B-03 | done | ADR-001: محرك TS واحد + decimal.js + Node sidecar للسيرفر | docs/adr/001-shared-ts-engine-decimal.md |
| 2026-11-21 | B-04 | done | ADR-002: الـ AI ما يحسبش + number guard | docs/adr/002-ai-never-calculates.md |
| 2026-11-21 | B-05 | done | ADR-003: داتا مرجعية immutable بـ hash + audit append-only | docs/adr/003-immutable-versioned-reference-data.md |
| 2026-11-21 | G-01 | done | مراجعة كود Sanad وتصحيح spec الربط (store API للبورتال بس، المزامنة DB-level) + عقد HTTP v1 | plan/02-specs/sanad-integration.md, orchestration/context/sanad-contract-v1.md |
| 2026-11-21 | G-02 | done | مصدر الحقيقة لكل كيان + شيل جداول الموردين/المشتريات من الـ schema واستبدالها بـ sanad_refs وcache | plan/02-specs/data-model.sql |
| 2026-11-21 | B-02 | done | monorepo pnpm: packages/engine + optimizer، apps/api (Laravel 12 + Inertia 2 + Vue 3 + Tailwind 4 + Pest + pgsql)، ESLint بيمنع Date.now/Math.random/Number في المحرك | pnpm test/typecheck/lint ✓، php artisan test 2 ✓، e2e 1 ✓؛ evidence/B-02 |
| 2026-11-21 | B-06 | partial-verified | CI: js + php (postgres) + e2e | .github/workflows/ci.yml (YAML صالح؛ هيتأكد أول run على GitHub) |
| 2026-11-21 | B-07 | done | docker compose للتطوير (postgres, redis, app, vite) | docker compose config ✓ (الـ containers ما اتشغلتش في البيئة دي) |
| 2026-11-21 | B-08 | done | عقد المحرك كـ types + canonical JSON + SHA-256 (noble) + قسم versioning | hash.test.ts |
| 2026-11-21 | C-01 | done | core/decimal.ts | vitest ✓ + coverage 100% للملف + فحص يدوي مستقل (evidence/C) |
| 2026-11-21 | C-07 | done | core/result.ts | vitest ✓ + coverage 100% للملف + فحص يدوي مستقل (evidence/C) |
| 2026-11-21 | C-02 | done | core/quantity.ts | vitest ✓ + coverage 100% للملف + فحص يدوي مستقل (evidence/C) |
| 2026-11-21 | C-03 | done | core/convert.ts | vitest ✓ + coverage 100% للملف + فحص يدوي مستقل (evidence/C) |
| 2026-11-21 | C-04 | done | core/round.ts | vitest ✓ + coverage 100% للملف + فحص يدوي مستقل (evidence/C) |
| 2026-11-21 | C-05 | done | core/fx.ts | vitest ✓ + coverage 100% للملف + فحص يدوي مستقل (evidence/C) |
| 2026-11-21 | C-09 | done | core/count-units.ts | vitest ✓ + coverage 100% للملف + فحص يدوي مستقل (evidence/C) |
| 2026-11-21 | C-06 | done | property tests fast-check 5×10,000 (seed 42) | tests/core.prop.test.ts |
| 2026-11-21 | C-08 | done | جداول OIML R22 عند 20°C من الصيغة الرسمية (A1..A12) + interpolation بدون extrapolation | 50 نقطة من الجدول المطبوع (Table IIIa/IIIb) ✓، coverage 100% |
| 2026-11-21 | F-08 | done | HLB: required (oil phase) + blend + Griffin + Davies (group numbers مدخلات بمصدر) | فحص يدوي: rHLB=8، f_high=0.62617، Griffin=14 ✓ |
| 2026-11-21 | F-09 | done | منحنى الملح: interpolation على القياسات بس، بره المدى = missing | فحص يدوي: 1.5% ⇒ 1250 mPa·s، 2.5% ⇒ out_of_measured_range ✓ |
| 2026-11-21 | F-07 | partial | خواص الصابون كمؤشر من profile الأحماض بخريطة خصائص بمصدر (مفيش خريطة افتراضية) | الخريطة نفسها في HUMAN-TODO |
