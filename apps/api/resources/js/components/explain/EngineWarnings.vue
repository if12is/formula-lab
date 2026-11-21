<script setup>
/**
 * The engine's warnings (its `warnings[]`) as a short list, each in words. A warning is { key, details[], raw }
 * (lib/calculators/common.js warningOf); a key without text prints the engine's own string.
 *
 *   <EngineWarnings :warnings="outcome.warnings" />
 */
import { useEngineText } from '../../composables/useEngineText';

defineProps({
    warnings: { type: Array, required: true },
});

const { warningText } = useEngineText();
</script>

<template>
    <ul v-if="warnings.length" class="flex list-disc flex-col gap-1 ps-5 text-label text-ink-3" data-testid="warnings">
        <li v-for="(warning, index) in warnings" :key="index">{{ warningText(warning) }}</li>
    </ul>
</template>
