<script setup>
/**
 * One way to show a number. Always inside <bdi dir="ltr" class="tnum">, so figures keep their
 * left-to-right order and tabular digits inside Arabic text, formatted with the active locale
 * (Latin digits unless the locale shares another numbering system).
 *
 *   <Num :value="12.5" :decimals="2" />            12.50
 *   <Num :value="item.cost" :decimals="2" />       a decimal string stays exact: it is never turned into a float
 *   <Num :value="null" reason-key="dashboard.no_data_reason" />    "—" with the reason as a tooltip
 *
 * A missing value prints "—", never 0, and says why (CLAUDE.md rule 2).
 */
import { computed } from 'vue';
import { Tooltip } from '@kit';
import { useI18n } from '../composables/useI18n';

const props = defineProps({
    /** A number, or a decimal string from the server (kept as text so no precision is lost). */
    value: { type: [Number, String], default: null },
    /** Fixed number of decimal places; omit to show the value as it is. */
    decimals: { type: Number, default: undefined },
    /** Any Intl.NumberFormat options (style, currency, notation...). */
    options: { type: Object, default: () => ({}) },
    /** Lang key for the reason shown when the value is missing. */
    reasonKey: { type: String, default: 'common.no_data_yet' },
});

const { t, locale, numbering } = useI18n();

const text = computed(() => {
    if (props.value === null || props.value === undefined || props.value === '') {
        return null;
    }

    const fixed = props.decimals === undefined ? {} : { minimumFractionDigits: props.decimals, maximumFractionDigits: props.decimals };

    // Intl accepts a numeric string and formats it exactly, so decimals from the engine never pass through float.
    return new Intl.NumberFormat(locale.value, { numberingSystem: numbering.value, ...fixed, ...props.options }).format(props.value);
});
</script>

<template>
    <bdi v-if="text !== null" dir="ltr" class="tnum"><slot name="prefix" />{{ text }}<slot name="suffix" /></bdi>
    <Tooltip v-else :content="t(reasonKey)">
        <bdi dir="ltr" class="tnum cursor-help text-ink-3" tabindex="0" :aria-label="`${t('common.no_data')} ${t(reasonKey)}`">{{ t('common.no_data') }}</bdi>
    </Tooltip>
</template>
