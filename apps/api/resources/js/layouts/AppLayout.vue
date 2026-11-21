<script setup>
/**
 * The persistent layout for every page: the kit's AppShell with the sidebar from config/navigation.js,
 * breadcrumbs derived from it, and the theme, language and user controls in the topbar. Pages render
 * only their own `<div class="kit-page">`.
 */
import { computed } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import { AppShell, Breadcrumbs, DropdownItem, LocaleSwitcher, NavItem, SidebarSection, UserMenu } from '@kit';
import { useI18n } from '../composables/useI18n';
import { findNavigationItem, homeItem, navigation } from '../config/navigation';

const page = usePage();
const { t, locale } = useI18n();

const locales = computed(() => page.props.locale.supported);

const crumbs = computed(() => {
    const current = findNavigationItem(page.url);

    if (!current || current.id === homeItem.id) {
        return [{ label: t(homeItem.labelKey), icon: homeItem.icon }];
    }

    return [
        { label: t(homeItem.labelKey), icon: homeItem.icon, href: homeItem.href },
        { label: t(current.labelKey) },
    ];
});

function switchLocale(code) {
    if (code !== locale.value) {
        router.put('/settings/locale', { locale: code }, { preserveState: true, preserveScroll: true, showProgress: false });
    }
}
</script>

<template>
    <AppShell :app-name="t('app.name')">
        <template #nav>
            <SidebarSection v-for="group in navigation" :key="group.id" :label="t(group.labelKey)">
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

        <!-- Placeholder until sign-in exists (the users table and its settings come later). -->
        <template #user>
            <UserMenu :name="t('nav.user.name')" :email="t('nav.user.role')">
                <DropdownItem icon="ph:gear" href="/settings">{{ t('nav.user.profile') }}</DropdownItem>
            </UserMenu>
        </template>

        <slot />
    </AppShell>
</template>
