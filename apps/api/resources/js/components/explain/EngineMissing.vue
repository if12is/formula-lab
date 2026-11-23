<script setup>
/**
 * What the engine says it lacks (its `missing[]`), each gap as the thing and the reason in words, never a zero. The one
 * place that turns an engine gap into text: the calculators' results card and the lab screens both use it.
 *
 *   <EngineMissing :missing="outcome.missing" />
 *
 * A gap is { materialId?, property, reason, scope? }. The reason is the engine's key (engine.<key>, or engine.<scope>.<key>).
 * A gap the server already worded (App\Support\Engine\GapText: the optimizer's own texts, the subject a screen names) carries `subject` and/or
 * `text`, which win over the lookup in the engine's lang group.
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
                <span class="font-semibold">{{ gap.subject ? [gap.materialId, gap.subject].filter(Boolean).join(' · ') : subjectOf(gap) }}</span>: {{ gap.text ?? reasonOf(gap) }}
            </li>
        </ul>
    </Alert>
</template>
