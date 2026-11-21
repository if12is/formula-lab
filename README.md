# Formula Lab

Deterministic formulation platform (see `CLAUDE.md` and `plan/README.md`).

## Prerequisites
Node 22 (`.nvmrc`), pnpm 10, PHP 8.3+ + Composer 2 (Laravel 13, Inertia 3, Vite 8), PostgreSQL 16 (databases `formula_lab` and `formula_lab_test`), Redis.

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
php artisan test # in apps/api (PHPUnit, PostgreSQL) -- or: pnpm test:php
pnpm e2e         # Playwright (builds the frontend, serves apps/api)
```

## Docker (dev)
```bash
docker compose up   # postgres, redis, app on :8000, vite on :5173
```

## Layout
`packages/engine`, `packages/optimizer`, `apps/api` (Laravel + Inertia + Vue; the web UI lives here), `apps/desktop` and `apps/mobile` (placeholders).

## Frontend (apps/api/resources/js)
- **UI kit:** Taif Kit, installed as a git dependency pinned to a tag (`"taif-kit": "github:if12is/taif-kit#v1.8.0"`). The repo is private: every machine that runs `pnpm i` (CI included) needs read access, i.e. a deploy key or `gh auth setup-git`. `reka-ui` and `@iconify/vue` are direct dependencies because pnpm does not hoist the kit's own dependencies, and the kit's Vite plugin imports `@iconify/vue` from the app. Update the kit by changing the tag and reading `docs/CHANGES.md` in the kit.
- **Text:** every UI string lives in `apps/api/lang/{ar,en}/*.php`. The groups listed in `config/ui.php` (`shared_groups`) are shared to the page as the Inertia prop `translations` (once per locale) and read with `t('group.key', params)` from `composables/useI18n.js`. Chosen over `laravel-vue-i18n`: no extra dependency or build step, one dictionary for server and client, and a locale switch is a normal Inertia visit. `pnpm lint` runs `scripts/check-i18n.mjs`, which fails on Arabic characters or sentence literals in `.vue` templates. A PHPUnit test keeps `ar` and `en` keys identical.
- **Theme and language** are owned by the server: cookies `fl_theme` / `fl_locale` (until the users table stores settings), `PUT /settings/theme` and `PUT /settings/locale`, rendered on `<html>` in `resources/views/app.blade.php`. Arabic RTL is the default.
- **Layout and navigation:** `layouts/AppLayout.vue` (persistent, from the Inertia `layout` option) and the single sidebar config `config/navigation.js`.
- **Shared list building blocks (use them for every list page):** server `App\Http\Filters\ListQuery` (search, filters, sort, pagination from a whitelist), client `composables/useListQuery.js` (state in the URL), `components/list/ListFilters.vue`, `components/form/EntitySelect.vue`, `components/Num.vue`. The test-only playground is `/__dev/list-demo` (local and testing environments only).
