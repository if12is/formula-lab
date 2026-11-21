<script setup>
/**
 * The display-currency section of the kit's Display menu (AppShell #theme-extra -> ThemeMenu #extra): choose the currency
 * shown beside EGP figures (EGP stays primary). Saved in a cookie by the server (PUT /settings/display-currency), like the
 * theme and the language. It renders menu content only; the kit draws the menu and the separator above it.
 */
import { computed } from 'vue';
import { router } from '@inertiajs/vue3';
import { DropdownLabel, DropdownRadioGroup, DropdownRadioItem } from '@kit';
import { useDisplayCurrency } from '../../composables/useDisplayCurrency';
import { useI18n } from '../../composables/useI18n';
import { path } from '../../lib/routes';

const { t } = useI18n();
const { code, options } = useDisplayCurrency();
const choices = computed(() => options.value.map((option) => ({ value: option.value, label: option.label })));

function choose(currency) {
    if (currency !== code.value) {
        router.put(path('settings.display-currency'), { currency }, { preserveState: true, preserveScroll: true, showProgress: false });
    }
}
</script>

<template>
    <DropdownLabel>{{ t('exchange_rates.display.menu') }}</DropdownLabel>
    <DropdownRadioGroup :model-value="code" data-testid="display-currency-menu" @update:model-value="choose">
        <DropdownRadioItem v-for="choice in choices" :key="choice.value" :value="choice.value">{{ choice.label }}</DropdownRadioItem>
    </DropdownRadioGroup>
    <p class="px-3 pb-2 pt-1 text-label text-ink-3">{{ t('exchange_rates.display.hint') }}</p>
</template>
