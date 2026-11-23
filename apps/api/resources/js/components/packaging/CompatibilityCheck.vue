<script setup>
/**
 * Checks a container and a closure or pump against the declared neck finish pairs (H-02). The decision is the engine's: this card
 * only sends the two components and shows each pair's verdict, what is missing, and the pair's source when it passed.
 *
 *   <CompatibilityCheck :container-options="..." :closure-options="..." :url="urls.check" />
 */
import { computed, ref } from 'vue';
import { Alert, Button, Card, Combobox, FormField } from '@kit';
import { useEngineText } from '../../composables/useEngineText';
import { useI18n } from '../../composables/useI18n';
import { warningOf } from '../../lib/calculators/common';
import { postJson } from '../../lib/http';
import EngineWarnings from '../explain/EngineWarnings.vue';
import StatusBadge from '../StatusBadge.vue';

const props = defineProps({
    containerOptions: { type: Array, required: true },
    closureOptions: { type: Array, required: true },
    url: { type: String, required: true },
});

const { t } = useI18n();
const { reasonOf } = useEngineText();
const container = ref(null);
const closure = ref(null);
const busy = ref(false);
const failed = ref(false);
const answer = ref(null);

const ready = computed(() => Boolean(container.value && closure.value));
const warnings = computed(() => (answer.value?.warnings ?? []).map(warningOf));

async function run() {
    if (!ready.value) {
        return;
    }

    busy.value = true;
    failed.value = false;

    try {
        const result = await postJson(props.url, { item_ids: [container.value, closure.value] });
        answer.value = result.ok ? result.data : null;
        failed.value = !result.ok;
    } catch {
        answer.value = null;
        failed.value = true;
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <Card :title="t('packaging.compat.check.title')" :description="t('packaging.compat.check.description')" data-testid="compat-check">
        <div class="grid gap-4 sm:grid-cols-[minmax(0,1fr)_minmax(0,1fr)_auto] sm:items-end">
            <FormField :label="t('packaging.compat.check.container')">
                <Combobox v-model="container" :options="containerOptions" :placeholder="t('packaging.compat.check.container')" data-testid="check-container" />
            </FormField>
            <FormField :label="t('packaging.compat.check.closure')">
                <Combobox v-model="closure" :options="closureOptions" :placeholder="t('packaging.compat.check.closure')" data-testid="check-closure" />
            </FormField>
            <Button icon="ph:seal-check" :disabled="!ready" :loading="busy" data-testid="check-run" @click="run">{{ t('packaging.compat.check.run') }}</Button>
        </div>

        <Alert v-if="failed" tone="danger" class="mt-4">{{ t('packaging.compat.check.failed') }}</Alert>

        <div v-if="answer" class="mt-4 flex flex-col gap-3" data-testid="check-result" :data-status="answer.status">
            <div class="flex items-center gap-2">
                <StatusBadge kind="check_status" :status="answer.status" />
                <span class="type-body text-ink">{{ t(`packaging.compat.check.result_${answer.status.toLowerCase()}`) }}</span>
            </div>
            <ul v-if="answer.checks.length" class="flex flex-col gap-2">
                <li v-for="(check, index) in answer.checks" :key="index" class="flex flex-col gap-1 rounded-lg border border-line bg-surface-2 p-3">
                    <span class="flex items-center gap-2">
                        <StatusBadge kind="check_status" :status="check.status" />
                        <span class="font-semibold text-ink">{{ t('packaging.compat.check.pair_line', { closure: check.closure.name, container: check.container.name }) }}</span>
                    </span>
                    <span class="text-label text-ink-3">
                        <bdi dir="ltr">{{ check.container.neck_finish ?? t('packaging.compat.check.no_neck') }}</bdi>
                        ·
                        <bdi dir="ltr">{{ check.closure.neck_finish ?? t('packaging.compat.check.no_neck') }}</bdi>
                    </span>
                    <span v-if="check.status === 'PASS'" class="text-label text-ink-3">{{ t('packaging.compat.check.declared_by', { source: check.source_title ?? '', confidence: t(`enums.confidence.${check.confidence}`) }) }}</span>
                    <span v-else-if="check.status === 'FAIL'" class="text-label text-danger-ink">{{ t('packaging.compat.check.no_pair') }}</span>
                </li>
            </ul>
            <div v-if="answer.missing.length" class="flex flex-col gap-1">
                <h4 class="type-h6 text-ink">{{ t('packaging.compat.check.missing_title') }}</h4>
                <ul class="flex list-disc flex-col gap-1 ps-5 text-label text-ink-3" data-testid="check-missing">
                    <li v-for="(gap, index) in answer.missing" :key="index">{{ [gap.item, reasonOf(gap)].filter(Boolean).join(' · ') }}</li>
                </ul>
            </div>
            <EngineWarnings :warnings="warnings" />
        </div>
    </Card>
</template>
