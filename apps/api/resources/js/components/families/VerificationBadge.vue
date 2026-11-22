<script setup>
/**
 * How far a registry row or a template has been proven, in one badge so the same word always wears the same colour.
 * `template` statuses: theoretical / lab_tested / chemist_approved. `data` statuses (a registry row): pending / verified.
 * Labels come from lang families.verification.* and families.data_verification.*.
 *
 *   <VerificationBadge kind="template" :status="row.status" />
 */
import { computed } from 'vue';
import { Badge } from '@kit';
import { useI18n } from '../../composables/useI18n';

const props = defineProps({
    kind: { type: String, default: 'template', validator: (value) => ['template', 'data'].includes(value) },
    status: { type: String, required: true },
});

const TONES = {
    template: { theoretical: 'warning', lab_tested: 'info', chemist_approved: 'success' },
    data: { pending: 'warning', verified: 'success' },
};

const { t } = useI18n();
const tone = computed(() => TONES[props.kind]?.[props.status] ?? 'neutral');
const label = computed(() => t(`families.${props.kind === 'template' ? 'verification' : 'data_verification'}.${props.status}`));
</script>

<template>
    <Badge :tone="tone" dot>{{ label }}</Badge>
</template>
