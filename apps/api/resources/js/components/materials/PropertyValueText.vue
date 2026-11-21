<script setup>
/**
 * A stored property value: a single figure, or a range (both ends, or one open end). Figures stay exact decimal
 * text through <Num>; a missing value prints "—". A range and its unit are one left-to-right <bdi> (Num), so
 * "68 – 72 %" never reads reversed in an Arabic row. `unit`, when given, is printed inside that same unit.
 */
import Num from '../Num.vue';
import { useI18n } from '../../composables/useI18n';

defineProps({
    value: { type: String, default: null },
    min: { type: String, default: null },
    max: { type: String, default: null },
    unit: { type: String, default: null },
});

const { t } = useI18n();
</script>

<template>
    <Num v-if="value !== null" :value="value"><template v-if="unit" #suffix>{{ ' ' + unit }}</template></Num>
    <Num v-else-if="min !== null && max !== null" :value="min" :up-to="max"><template v-if="unit" #suffix>{{ ' ' + unit }}</template></Num>
    <span v-else-if="min !== null" class="inline-flex items-baseline gap-1">{{ t('materials.property.at_least') }} <Num :value="min"><template v-if="unit" #suffix>{{ ' ' + unit }}</template></Num></span>
    <span v-else-if="max !== null" class="inline-flex items-baseline gap-1">{{ t('materials.property.at_most') }} <Num :value="max"><template v-if="unit" #suffix>{{ ' ' + unit }}</template></Num></span>
    <Num v-else :value="null" />
</template>
