# Taif Kit integration brief (v1.8.0, tag v1.8.0 exists on remote)

Source of truth: /home/user/taif-kit (read each component's top doc comment in kit/components/** before use).

## Install
- Dep: `"taif-kit": "github:if12is/taif-kit#v1.8.0"` (private repo; if the git dep can't be fetched in an environment, fall back to `link:../../../taif-kit` locally and record it).
- Peers: vue ^3.5, tailwindcss ^4.1 (Tailwind 4 only), reka-ui ^2.11, @inertiajs/vue3, vue-router ^4.5 (optional). App needs vite, @vitejs/plugin-vue, @tailwindcss/vite. Never bump apexcharts past 5.0.0.
- vite.config: `plugins: [laravel({input:['resources/css/app.css','resources/js/app.js']}), tailwindcss(), vue({...}), kit({ iconDirs: ['resources/js'] })]` with `import kit from 'taif-kit/vite'`. `kit()` aliases `@kit` → kit folder and prevents double-loading.
- CSS: `@import "tailwindcss"; @import "@kit/styles/kit.css";`
- Fonts: Google Fonts link in Blade head (Almarai + Tajawal for Arabic; Exo 2, Lato, Geist Mono, Audiowide, Atkinson Hyperlegible Next).
- TS paths if needed: `"@kit": ["./node_modules/taif-kit/kit"], "@kit/*": ["./node_modules/taif-kit/kit/*"]`; typings in kit/index.d.ts.

## Blade root
```blade
<html lang="ar" dir="rtl" data-theme-source="server" data-mode="{{ $theme === 'system' ? 'light' : $theme }}"
      data-theme-preference="{{ $theme }}" data-accent="leaf" data-reading="standard">
<head><script>(function(){var d=document.documentElement;if(d.dataset.themePreference==='system'){d.dataset.mode=matchMedia('(prefers-color-scheme: dark)').matches?'dark':'light';}})();</script>
```
(Or, without server-stored theme, inline `node_modules/taif-kit/kit/theme-boot.js` before @vite.)

## createKit (Inertia v2: use `setup({el, App, props, plugin})`, page = props.initialPage)
```js
import { createKit, arLabels } from '@kit';
import { inertiaKit } from '@kit/adapters/inertia';
app.use(createKit({
  ...inertiaKit({ loading: true, locale: p => ({ code: p.props.locale?.current ?? 'ar', dir: p.props.locale?.direction ?? 'rtl' }),
                  labels: { ar: arLabels }, theme: { prop: 'theme.preference', save: pref => router.put('/settings/theme', {preference: pref}, {preserveState:true, preserveScroll:true, showProgress:false}) } }),
  brand: { name: 'Formula Lab', product: '…', logo: Mark, legal: '…' }, origin: page.props.appUrl,
}));
```
- createInertiaApp `progress: false` (kit shows its own). Share `appUrl` in HandleInertiaRequests.

## Dark mode / RTL
- NO `dark:` variant, NO `.dark` class. Dark = `data-mode="dark"` on `<html>`. `useTheme()` (setPreference/setAccent/setDensity); `ThemeMenu` in topbar. localStorage keys `kit:theme`.
- RTL by `<html dir="rtl">`; logical classes only (ms-/me-/ps-/pe-/start-/end-/text-start). Wrap number pairs in `<bdi dir="ltr">`.

## Shell
`AppShell` (props appName, product, commands, see-all, loading) with slots `#nav` (`SidebarSection label` + `NavItem href icon="ph:*" label badge exact`), `#sidebar-footer` (UserCard), `#topbar-start` (Breadcrumbs), `#topbar-end`. Use as persistent layout. Page root: `<div class="kit-page">` (+ `is-full` tables, `is-narrow` forms, `is-bleed` records). `PageHeader`, `EntityHeader`. Shell is 100dvh; don't use min-h-screen.

## Components (selection)
Layout: AppShell, PageHeader, EntityHeader, Card, Tabs/TabPanel, SectionNav, Steps, Breadcrumbs, Pagination.
Base: Button, IconButton, Icon (`ph:*`, size xs..xl), Badge, Tag, StatusDot, Spinner, Skeleton, Tooltip.
Forms: FormField(label hint :error required), Input(ltr), Textarea, Select, Combobox, Typeahead, MultiSelect, Checkbox, RadioGroup, Switch, SegmentedControl, NumberInput, CurrencyAmountField, DatePicker, FileUploader, Repeater, FormWizard.
Data: DataTable(:mobile-columns, v-model:sort), FilterBar, DescriptionList, TotalsList, Timeline, ActivityFeed, TreeView, Accordion, StatCard, Progress, SegmentedBar, EmptyState, Alert.
Charts: LineChart, BarChart, BarList, DonutChart, Sparkline, Gauge (≤6 series colours, legend for ≥2 series).
Overlays: Modal(#footer), Drawer, Dropdown, Popover, HoverCard, CommandPalette.
Composables: useTheme, useForm (alias `useForm as useKitForm` — clashes with Inertia's), useConfirm, toast, useQueryState, useUnsavedChanges, useDraft. Rules: required, min, max, numeric, pattern…, `laravelErrors`.

## Templates (demo/pages) — start every screen from the nearest one
| Need | Template |
| --- | --- |
| CRUD list | Projects.vue (also money/Invoices.vue, people/Customers.vue) |
| Record/detail | ProjectDetail.vue (tabs), work/TaskShow.vue (RecordLayout) |
| Editor with lines + totals bar | money/InvoiceForm.vue (formula editor base) |
| Dashboard KPIs | Overview.vue, dashboards/Sales.vue, Reports.vue |
| Calculator page | Blank.vue + Card/NumberInput/SegmentedControl/TotalsList/StatCard |
| Settings | Settings.vue, ops/settings/* |
| Sign-in | auth/SignIn.vue |
| Errors | errors/ErrorPage.vue |
Copy page + demo/partials/<area> + replace demo/data mock data; wire `tr()` to app i18n or strip.

## Rules (must)
- Tokens only (bg-canvas|surface|surface-2, text-ink|ink-2|ink-3, border-line, bg-primary, bg-{success,warning,danger,info}-soft, text-*-ink; type-h1..h6, type-body, type-metric, tnum). No hex, no `text-[Npx]`.
- Every control inside FormField; one primary button per form; errors say how to fix; show "—" with reason for a missing figure, never 0.
- Every list: filtered-empty + truly-empty states. List state in URL (useQueryState). Destructive → useConfirm tone danger.
- Each page sets its title via Inertia `<Head>`. Phosphor icons only. Icon-only buttons need aria-label.
- Missing component ⇒ note in orchestration/HUMAN-TODO.md as kit suggestion and build locally with the same tokens.
