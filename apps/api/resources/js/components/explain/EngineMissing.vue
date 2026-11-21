<script setup>
/**
 * What the engine says it lacks (its `missing[]`), each gap as the thing and the reason in words, never a zero. The one
 * place that turns an engine gap into text: the calculators' results card and the lab screens both use it.
 *
 *   <EngineMissing :missing="outcome.missing" />
 *
 * A gap is { materialId?, property, reason, scope? }. The reason is the engine's key (engine.<key>, or engine.<scope>.<key>).
 */
import { useI18n } from '../../composables/useI18n';
import { useEngineText } from '../../composables/useEngineText';
import { Alert } from '@kit';

defineProps({
    missing: { type: Array, required: true },
});

const { t } = useI18n();
const { subjectOf, reasonOf } = useEngineText();
</script>

<template>
    <Alert tone="warning" icon="ph:warning" :title="t('calculators.missing.title')" data-testid="missing">
        <p class="mb-2">{{ t('calculators.missing.description') }}</p>
        <ul class="flex flex-col gap-1">
            <li v-for="(gap, index) in missing" :key="index" data-testid="missing-item">
                <span class="font-semibold">{{ subjectOf(gap) }}</span>: {{ reasonOf(gap) }}
            </li>
        </ul>
    </Alert>
</template>
