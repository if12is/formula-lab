# Formula Lab

Deterministic formulation platform (see `CLAUDE.md` and `plan/README.md`).

## Prerequisites
Node 22 (`.nvmrc`), pnpm 10, PHP 8.3 + Composer 2, PostgreSQL 16 (databases `formula_lab` and `formula_lab_test`), Redis.

## Setup
```bash
pnpm i
cd apps/api
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate
```

## Commands
```bash
pnpm test        # engine/optimizer unit tests (Vitest)
pnpm typecheck
pnpm lint
php artisan test # in apps/api (Pest, PostgreSQL) -- or: pnpm test:php
pnpm e2e         # Playwright (builds the frontend, serves apps/api)
```

## Docker (dev)
```bash
docker compose up   # postgres, redis, app on :8000, vite on :5173
```

## Layout
`packages/engine`, `packages/optimizer`, `apps/api` (Laravel + Inertia + Vue; the web UI lives here), `apps/desktop` and `apps/mobile` (placeholders).
