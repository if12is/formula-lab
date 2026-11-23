<script setup>
/**
 * The manufacturing steps of a version as one numbered list (Q-10): the kind, the phase, the ingredient an `add` step puts in, the
 * temperature, mixing speed and time when the formulator gave them (a missing figure reads "—", never 0), and the check points with
 * their limits ("no limit defined" is shown as such, never as a pass). Shared by the order page, the weighing sheet, the process
 * editor's preview and the batch page; the batch fills each check point's slot with the readings.
 *
 * `steps` is the snapshot shape: { step_no, kind, phase, line_no, material?, temperature_c, mixing_speed_rpm, duration_minutes, note, checks: [{ check_no, property, unit, limit_min, limit_max, confidence }] }.
 * `materials` maps a line number to the ingredient's name when a step has no `material` of its own.
 */
import { Badge } from '@kit';
import LimitText from '../lab/LimitText.vue';
import Num from '../Num.vue';
import ConfidenceBadge from '../materials/ConfidenceBadge.vue';
import { useI18n } from '../../composables/useI18n';

defineProps({
    steps: { type: Array, required: true },
    materials: { type: Object, default: () => ({}) },
});

const { t } = useI18n();
</script>

<template>
    <ol class="flex flex-col gap-3" data-testid="process-steps">
        <li v-for="step in steps" :key="step.step_no" class="flex flex-col gap-2 rounded-lg border border-line bg-surface p-3" :data-step="step.step_no">
            <div class="flex flex-wrap items-center gap-2">
                <Badge tone="neutral" mono>{{ step.step_no }}</Badge>
                <span class="font-semibold text-ink">{{ t(`enums.process_step_kind.${step.kind}`) }}</span>
                <Badge v-if="step.phase" tone="info" size="sm">{{ t(`enums.formula_phase.${step.phase}`) }}</Badge>
                <span v-if="step.material || materials[step.line_no]" class="text-ink-2">{{ step.material ?? materials[step.line_no] }}</span>
            </div>
            <dl class="grid grid-cols-3 gap-3 text-label">
                <div>
                    <dt class="text-ink-3">{{ t('production.steps.temperature') }}</dt>
                    <dd><Num :value="step.temperature_c" reason-key="production.steps.not_given"><template #suffix> °C</template></Num></dd>
                </div>
                <div>
                    <dt class="text-ink-3">{{ t('production.steps.speed') }}</dt>
                    <dd><Num :value="step.mixing_speed_rpm" reason-key="production.steps.not_given"><template #suffix> {{ t('production.steps.rpm') }}</template></Num></dd>
                </div>
                <div>
                    <dt class="text-ink-3">{{ t('production.steps.duration') }}</dt>
                    <dd><Num :value="step.duration_minutes" reason-key="production.steps.not_given"><template #suffix> {{ t('production.steps.minutes') }}</template></Num></dd>
                </div>
            </dl>
            <p v-if="step.note" class="text-label text-ink-2">{{ step.note }}</p>
            <ul v-if="step.checks?.length" class="flex flex-col gap-2 border-t border-line pt-2">
                <li v-for="check in step.checks" :key="check.check_no" class="flex flex-col gap-1" :data-check="`${step.step_no}/${check.check_no}`">
                    <div class="flex flex-wrap items-center gap-2">
                        <Badge tone="info" size="sm" icon="ph:crosshair">{{ t(`production.check_properties.${check.property}`) }}</Badge>
                        <span class="tnum text-ink-3"><bdi dir="ltr">{{ check.unit }}</bdi></span>
                        <LimitText :limits="{ min: check.limit_min, max: check.limit_max }" />
                        <ConfidenceBadge v-if="check.confidence" :confidence="check.confidence" />
                    </div>
                    <slot name="check" :step="step" :check="check" />
                </li>
            </ul>
        </li>
    </ol>
</template>
