<script setup>
/**
 * The persistent layout for every page: the kit's AppShell with the navigation from config/navigation.js (one definition: a sidebar or the top bar, the kit decides),
 * breadcrumbs derived from it, and the theme, language and user controls in the topbar. Pages render
 * only their own `<div class="kit-page">`.
 */
import { computed, watch } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import { AppShell, Breadcrumbs, DropdownItem, LocaleSwitcher, NavItem, SidebarSection, UserMenu, toast } from '@kit';
import { useI18n } from '../composables/useI18n';
import DisplayCurrencyMenu from '../components/currency/DisplayCurrencyMenu.vue';
import { findNavigationItem, homeItem, navigation, navigationItems } from '../config/navigation';

const page = usePage();
const { t, locale } = useI18n();

const locales = computed(() => page.props.locale.supported);

const crumbs = computed(() => {
    const current = findNavigationItem(page.url);

    if (!current || current.id === homeItem.id) {
        return [{ label: t(homeItem.labelKey), icon: homeItem.icon }];
    }

    const parent = navigationItems.find((item) => item.id === current.parentId);

    return [
        { label: t(homeItem.labelKey), icon: homeItem.icon, href: homeItem.href },
        ...(parent ? [{ label: t(parent.labelKey), href: parent.href }] : []),
        { label: t(current.labelKey) },
    ];
});

// Server flash messages ({ success, error, warning }) arrive already translated; each one becomes a kit toast.
watch(
    () => page.props.flash,
    (flash) => {
        for (const type of ['success', 'error', 'warning']) {
            if (flash?.[type]) {
                toast[type](flash[type]);
            }
        }
    },
    { immediate: true },
);

function switchLocale(code) {
    if (code !== locale.value) {
        router.put('/settings/locale', { locale: code }, { preserveState: true, preserveScroll: true, showProgress: false });
    }
}
</script>

<template>
    <AppShell :app-name="t('app.name')">
        <template #nav>
            <SidebarSection v-for="group in navigation" :key="group.id" :label="group.labelKey ? t(group.labelKey) : undefined">
                <NavItem
                    v-for="item in group.items"
                    :key="item.id"
                    :href="item.href"
                    :icon="item.icon"
                    :label="t(item.labelKey)"
                    :exact="item.exact"
                />
            </SidebarSection>
        </template>

        <template #topbar-start><Breadcrumbs :items="crumbs" /></template>

        <template #topbar-end>
            <LocaleSwitcher :model-value="locale" :locales="locales" compact @update:model-value="switchLocale" />
        </template>

        <!-- The display currency is one more section of the kit's Display menu. -->
        <template #theme-extra><DisplayCurrencyMenu /></template>

        <!-- Placeholder until sign-in exists (the users table and its settings come later). -->
        <template #user>
            <UserMenu :name="t('nav.user.name')" :email="t('nav.user.role')">
                <DropdownItem icon="ph:gear" href="/settings">{{ t('nav.user.profile') }}</DropdownItem>
            </UserMenu>
        </template>

        <slot />
    </AppShell>
</template>
