<script setup>
/** The state of a COA extraction as one coloured badge: extracted (waiting for a person), confirmed, rejected. */
import { computed } from 'vue';
import { Badge } from '@kit';
import { useI18n } from '../../composables/useI18n';

const props = defineProps({
    status: { type: String, required: true },
});

const TONES = { extracted: 'warning', confirmed: 'success', rejected: 'neutral' };

const { t } = useI18n();
const tone = computed(() => TONES[props.status] ?? 'neutral');
</script>

<template>
    <Badge :tone="tone" dot>{{ t(`coa.enums.status.${status}`) }}</Badge>
</template>
