<script setup>
/**
 * What a formula version's lab results cover: each required measurement as met or missing, and one line that says whether
 * a completed trial holds them all. Used on the trial page and in the approval panel of the editor, so both say the same.
 *
 *   <CoveragePanel :coverage="workflow.coverage" />
 */
import { computed } from 'vue';
import { Alert, Badge } from '@kit';
import { useI18n } from '../../composables/useI18n';

const props = defineProps({
    /** { required, measured, missing, completed_trials, covered } from App\Support\DTOs\LabCoverage. */
    coverage: { type: Object, required: true },
});

const { t } = useI18n();
const missingText = computed(() => props.coverage.missing.map((property) => t(`enums.lab_property.${property}`)).join(t('lab.list_separator')));
</script>

<template>
    <div class="flex flex-col gap-3" data-testid="coverage" :data-covered="coverage.covered">
        <p v-if="coverage.required.length === 0" class="text-label text-ink-3">{{ t('lab.coverage.none_required') }}</p>
        <div v-else class="flex flex-col gap-2">
            <p class="eyebrow text-ink-3">{{ t('lab.coverage.required') }}</p>
            <ul class="flex flex-wrap gap-2">
                <li v-for="property in coverage.required" :key="property">
                    <Badge
                        :tone="coverage.missing.includes(property) ? 'warning' : 'success'"
                        :icon="coverage.missing.includes(property) ? 'ph:hourglass' : 'ph:check-circle'"
                        :data-coverage-property="property"
                        :data-state="coverage.missing.includes(property) ? 'missing' : 'measured'"
                    >{{ t(`enums.lab_property.${property}`) }}</Badge>
                </li>
            </ul>
        </div>
        <Alert v-if="coverage.covered" tone="success" icon="ph:check-circle">{{ t('lab.coverage.satisfied') }}</Alert>
        <Alert v-else-if="coverage.completed_trials === 0" tone="warning" icon="ph:warning">{{ t('lab.coverage.no_trial') }}</Alert>
        <Alert v-else tone="warning" icon="ph:warning">
            {{ t('lab.coverage.missing') }}: {{ missingText }}
        </Alert>
    </div>
</template>
