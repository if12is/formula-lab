import './bootstrap';
import '../css/app.css';
import { createInertiaApp, router } from '@inertiajs/vue3';
import { ZiggyVue } from 'ziggy-js';
import { arLabels, createKit } from '@kit';
import { inertiaKit } from '@kit/adapters/inertia';
import AppLayout from './layouts/AppLayout.vue';
import { inertiaReplaceQuery } from './composables/useListQuery';

const pages = import.meta.glob('./pages/**/*.vue');

// The "- Formula Lab" suffix of every page title comes from lang (app.title_suffix); it follows the locale.
let titleSuffix = '';
router.on('navigate', (event) => {
    titleSuffix = event.detail.page.props.translations?.['app.title_suffix'] ?? titleSuffix;
});

createInertiaApp({
    title: (title) => (title ? `${title} - ${titleSuffix}` : titleSuffix),
    resolve: async (name) => {
        const page = pages[`./pages/${name}.vue`];
        if (!page) {
            throw new Error(`Inertia page not found: ${name}`);
        }
        return (await page()).default;
    },
    // Every page shares one persistent AppLayout, so the shell, sidebar and theme survive visits.
    layout: () => AppLayout,
    withApp(app, { page }) {
        // A missing figure prints "—" everywhere the kit says "None" (StatCard, DescriptionList...), never 0 (CLAUDE.md rule 2).
        const kitLabels = { none: page.props.translations?.['common.no_data'] ?? '—' };
        titleSuffix = page.props.translations?.['app.title_suffix'] ?? titleSuffix;
        app.use(ZiggyVue).use(
            createKit({
                ...inertiaKit({
                    loading: true,
                    locale: (current) => ({ code: current.props.locale?.current ?? 'ar', dir: current.props.locale?.direction ?? 'rtl' }),
                    labels: { ar: { ...arLabels, ...kitLabels } },
                    theme: {
                        prop: 'theme.preference',
                        save: (preference) => router.put('/settings/theme', { preference }, { preserveState: true, preserveScroll: true, showProgress: false }),
                    },
                }),
                labels: kitLabels,
                // The server applies list filters, so the kit writes list state through an Inertia GET.
                replaceQuery: inertiaReplaceQuery,
                brand: { name: page.props.translations?.['app.name'] ?? '', legal: page.props.translations?.['app.legal'] },
                origin: page.props.appUrl,
            }),
        );
    },
    // The kit draws its own loading pill; Inertia's bar would be a second one.
    progress: false,
});
