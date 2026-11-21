# تاسكات المرحلة B: المعمارية والريبو

## [ ] B-01 — قرار الستاك: Laravel 12 + Inertia + Vue 3 + Taif Kit (UI) + packages/engine TypeScript، وSanad مصدر الحقيقة لكل حاجة POS
- **المخرج:** docs/adr/000-stack.md
- **شرط القبول:** معتمد
- **يعتمد على:** —

## [ ] B-02 — إنشاء monorepo (pnpm workspaces): packages/engine, packages/optimizer, apps/api (Laravel), apps/web (Vue+Inertia), apps/desktop (Electron), apps/mobile (Capacitor)
- **المخرج:** الريبو
- **شرط القبول:** pnpm i && pnpm test يعدّي
- **يعتمد على:** —

## [ ] B-03 — كتابة ADR-001: المحرك TypeScript مشترك + Decimal
- **المخرج:** docs/adr/001.md
- **شرط القبول:** معتمد
- **يعتمد على:** —

## [ ] B-04 — كتابة ADR-002: الـ AI ما يحسبش؛ بيستدعي tools بس
- **المخرج:** docs/adr/002.md
- **شرط القبول:** معتمد
- **يعتمد على:** —

## [ ] B-05 — كتابة ADR-003: نسخ الداتا المرجعية immutable ومرقمة
- **المخرج:** docs/adr/003.md
- **شرط القبول:** معتمد
- **يعتمد على:** —

## [ ] B-06 — إعداد CI (GitHub Actions/Gitea): lint, typecheck, vitest, pest, playwright
- **المخرج:** ci.yml
- **شرط القبول:** pipeline أخضر
- **يعتمد على:** B-01

## [ ] B-07 — إعداد بيئة dev بـ Docker Compose (Postgres, Redis, Laravel, Vite)
- **المخرج:** docker-compose.yml
- **شرط القبول:** تشتغل بأمر واحد
- **يعتمد على:** B-01

## [ ] B-08 — تحديد عقد المحرك: Input/Output types + versioning (engineVersion في كل ناتج)
- **المخرج:** 02-specs/engine-contract.md
- **شرط القبول:** مراجَع
- **يعتمد على:** B-02

