<script setup>
/**
 * A stored property value: a single figure, or a range (both ends, or one open end). Figures stay exact decimal
 * text through <Num>; a missing value prints "—".
 */
import Num from '../Num.vue';
import { useI18n } from '../../composables/useI18n';

defineProps({
    value: { type: String, default: null },
    min: { type: String, default: null },
    max: { type: String, default: null },
});

const { t } = useI18n();
</script>

<template>
    <Num v-if="value !== null" :value="value" />
    <span v-else-if="min !== null && max !== null" class="inline-flex items-baseline gap-1"><Num :value="min" /><span aria-hidden="true">–</span><span class="sr-only">{{ t('materials.property.to') }}</span><Num :value="max" /></span>
    <span v-else-if="min !== null" class="inline-flex items-baseline gap-1">{{ t('materials.property.at_least') }} <Num :value="min" /></span>
    <span v-else-if="max !== null" class="inline-flex items-baseline gap-1">{{ t('materials.property.at_most') }} <Num :value="max" /></span>
    <Num v-else :value="null" />
</template>
