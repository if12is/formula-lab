<script setup>
/**
 * The confidence level (L1 best ... L7 estimate) of a value, as a short coloured badge with the full name on hover
 * and for screen readers. L7 is danger: the engine refuses to cost with it unless a signed override exists.
 *
 *   <ConfidenceBadge confidence="L7_estimate" />
 */
import { computed } from 'vue';
import { Badge, Tooltip } from '@kit';
import { useI18n } from '../../composables/useI18n';

const props = defineProps({
    confidence: { type: String, required: true },
});

const TONES = { L1: 'success', L2: 'success', L3: 'info', L4: 'info', L5: 'neutral', L6: 'warning', L7: 'danger' };

const { t } = useI18n();
const code = computed(() => props.confidence.slice(0, 2));
const label = computed(() => t(`enums.confidence.${props.confidence}`));
</script>

<template>
    <Tooltip :content="label">
        <Badge :tone="TONES[code] ?? 'neutral'" mono :aria-label="label" tabindex="0">{{ code }}</Badge>
    </Tooltip>
</template>
