/**
 * The navigation (sidebar or top bar), defined once. Labels are lang keys (lang/<locale>/nav.php); icons are Phosphor.
 * AppLayout renders it and derives the breadcrumbs from it. Sections not built yet point at the
 * ComingSoon placeholder (config/ui.php `placeholder_sections`); a section leaves that list when
 * it gets its own route. A group without `labelKey` is unlabelled: its items sit in the top bar as plain
 * links (and at the top of the sidebar); a labelled group is a dropdown in the top bar and a section in the sidebar.
 * The same definition serves both layouts. An item with a `parentId` is listed in the sidebar and shows that item before it in the breadcrumbs.
 */
export const navigation = [
    {
        id: 'overview',
        items: [{ id: 'dashboard', href: '/', icon: 'ph:squares-four', labelKey: 'nav.dashboard', exact: true }],
    },
    {
        id: 'formulation',
        labelKey: 'nav.groups.formulation',
        items: [
            { id: 'formulas', href: '/formulas', icon: 'ph:flask', labelKey: 'nav.formulas' },
            { id: 'materials', href: '/materials', icon: 'ph:cube', labelKey: 'nav.materials' },
            { id: 'families', href: '/families', icon: 'ph:tree-structure', labelKey: 'nav.families' },
            { id: 'templates', href: '/templates', icon: 'ph:files', labelKey: 'nav.templates' },
            { id: 'packaging', href: '/packaging', icon: 'ph:package', labelKey: 'nav.packaging' },
            { id: 'calculators', href: '/calculators', icon: 'ph:calculator', labelKey: 'nav.calculators' },
        ],
    },
    {
        id: 'quality',
        labelKey: 'nav.groups.quality',
        items: [
            { id: 'compliance', href: '/compliance', icon: 'ph:seal-check', labelKey: 'nav.compliance' },
            { id: 'lab', href: '/lab', icon: 'ph:test-tube', labelKey: 'nav.lab' },
            { id: 'lab-protocols', href: '/lab/protocols', icon: 'ph:list-checks', labelKey: 'nav.lab_protocols', parentId: 'lab' },
        ],
    },
    {
        id: 'operations',
        labelKey: 'nav.groups.operations',
        items: [
            { id: 'price-lists', href: '/price-lists', icon: 'ph:tag', labelKey: 'nav.price_lists' },
            { id: 'production', href: '/production', icon: 'ph:factory', labelKey: 'nav.production' },
            { id: 'production-trace', href: '/production/trace', icon: 'ph:path', labelKey: 'nav.production_trace', parentId: 'production' },
        ],
    },
    {
        id: 'system',
        labelKey: 'nav.groups.system',
        items: [
            { id: 'settings', href: '/settings', icon: 'ph:gear', labelKey: 'nav.settings', exact: true },
            { id: 'exchange-rates', href: '/settings/exchange-rates', icon: 'ph:currency-circle-dollar', labelKey: 'nav.exchange_rates', parentId: 'settings' },
        ],
    },
];

export const homeItem = navigation[0].items[0];

/** The flat list of destinations, for the breadcrumbs and the command palette. */
export const navigationItems = navigation.flatMap((group) => group.items.map((item) => ({ ...item, groupId: group.id })));

/** The destination a path belongs to (the longest href that prefixes it), or null. */
export function findNavigationItem(path) {
    const clean = path.split('?')[0];

    return navigationItems
        .filter((item) => (item.exact ? clean === item.href : clean === item.href || clean.startsWith(`${item.href}/`)))
        .sort((a, b) => b.href.length - a.href.length)[0] ?? null;
}
