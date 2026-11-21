<script setup>
/**
 * One badge for the statuses a record can have (formula version, trade material), so the same word always
 * wears the same colour. Labels come from the enum lang files: enums.<kind>.<status>.
 *
 *   <StatusBadge kind="formula_status" :status="version.status" />
 */
import { computed } from 'vue';
import { Badge } from '@kit';
import { useI18n } from '../composables/useI18n';

const props = defineProps({
    kind: { type: String, required: true },
    status: { type: String, required: true },
});

const TONES = {
    formula_status: { draft: 'neutral', lab_testing: 'info', approved: 'success', rejected: 'danger' },
    trade_material_status: { draft: 'warning', active: 'success' },
};

const { t } = useI18n();
const tone = computed(() => TONES[props.kind]?.[props.status] ?? 'neutral');
</script>

<template>
    <Badge :tone="tone" dot>{{ t(`enums.${kind}.${status}`) }}</Badge>
</template>
