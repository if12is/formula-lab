<script setup>
/**
 * The topbar's display-currency menu, next to the kit's Display menu: choose the currency shown beside EGP figures
 * (EGP stays primary). Saved in a cookie by the server (PUT /settings/display-currency), like the theme and the language.
 * The kit's Display menu has no slot for extra settings, so this sits beside it (kit suggestion: HUMAN-TODO.md).
 */
import { computed } from 'vue';
import { router } from '@inertiajs/vue3';
import { Dropdown, DropdownLabel, DropdownRadioGroup, DropdownRadioItem, IconButton } from '@kit';
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
    <Dropdown align="end" content-class="w-64">
        <template #trigger>
            <IconButton icon="ph:currency-circle-dollar" :label="t('exchange_rates.display.menu')" data-testid="display-currency-menu" />
        </template>
        <DropdownLabel>{{ t('exchange_rates.display.menu') }}</DropdownLabel>
        <DropdownRadioGroup :model-value="code" @update:model-value="choose">
            <DropdownRadioItem v-for="choice in choices" :key="choice.value" :value="choice.value">{{ choice.label }}</DropdownRadioItem>
        </DropdownRadioGroup>
        <p class="px-3 pb-2 pt-1 text-label text-ink-3">{{ t('exchange_rates.display.hint') }}</p>
    </Dropdown>
</template>
