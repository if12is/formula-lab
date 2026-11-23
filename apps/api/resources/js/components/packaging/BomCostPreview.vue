<script setup>
/**
 * The packaging cost per selling unit of a BOM (S-12), as the engine worked it out for the saved lines (packagingCostPerUnit, H-03, with the
 * declared closure pairs of H-02). This card only asks for it and shows it: nothing is added, divided or rounded here beyond the display of
 * the engine's decimal. When the BOM lacks something the card lists every gap and shows no total, never a zero. `refresh-key` changes when
 * the lines or the packing change, which asks again.
 *
 *   <BomCostPreview :url="urls.cost" :refresh-key="signature" />
 */
import { onMounted, ref, watch } from 'vue';
import { Alert, Button, Card, Skeleton } from '@kit';
import { useI18n } from '../../composables/useI18n';
import { warningOf } from '../../lib/calculators/common';
import { getJson } from '../../lib/http';
import EngineMissing from '../explain/EngineMissing.vue';
import EngineWarnings from '../explain/EngineWarnings.vue';
import ExplainableNum from '../explain/ExplainableNum.vue';

const props = defineProps({
    url: { type: String, required: true },
    refreshKey: { type: String, default: '' },
});

const { t } = useI18n();
const busy = ref(true);
const failed = ref(false);
const answer = ref(null);
let ticket = 0;

async function load() {
    const mine = ++ticket;
    busy.value = true;
    failed.value = false;

    try {
        const result = await getJson(props.url);

        if (mine === ticket) {
            answer.value = result;
        }
    } catch {
        if (mine === ticket) {
            answer.value = null;
            failed.value = true;
        }
    } finally {
        if (mine === ticket) {
            busy.value = false;
        }
    }
}

onMounted(load);
watch(() => props.refreshKey, load);
</script>

<template>
    <Card :title="t('packaging.bom.cost.title')" :description="t('packaging.bom.cost.description')" data-testid="bom-cost" :data-state="busy ? 'loading' : failed ? 'failed' : answer?.ok ? 'ok' : 'incomplete'">
        <div v-if="busy && !answer" class="flex flex-col gap-2" role="status" :aria-label="t('packaging.bom.cost.loading')">
            <Skeleton class="h-8 w-40" />
            <Skeleton class="h-4 w-full" />
        </div>

        <Alert v-else-if="failed" tone="danger" :title="t('packaging.bom.cost.failed')">
            <Button variant="secondary" size="sm" icon="ph:arrows-clockwise" class="mt-2" @click="load">{{ t('packaging.bom.cost.retry') }}</Button>
        </Alert>

        <div v-else-if="answer" class="flex flex-col gap-4" :aria-busy="busy">
            <div v-if="answer.ok" class="flex flex-col gap-4">
                <div class="flex flex-col gap-1" data-testid="bom-cost-total">
                    <span class="type-label text-ink-3">{{ t('packaging.bom.cost.total') }}</span>
                    <span class="type-metric text-ink">
                        <ExplainableNum :traced="answer.total" :decimals="4" :label="t('packaging.bom.cost.total')" :entities="answer.entities" :sources="answer.sources">
                            <template #suffix> {{ t('packaging.bom.cost.currency') }}</template>
                        </ExplainableNum>
                    </span>
                </div>
                <div class="flex flex-col gap-2">
                    <h4 class="type-h6 text-ink">{{ t('packaging.bom.cost.lines_title') }}</h4>
                    <ul class="flex flex-col divide-y divide-line rounded-lg border border-line">
                        <li v-for="line in answer.lines" :key="line.line_id" class="flex items-center justify-between gap-3 px-3 py-2" :data-testid="`bom-cost-line-${line.line_id}`">
                            <span class="min-w-0 truncate text-ink">{{ line.name }}</span>
                            <ExplainableNum :traced="line.per_unit" :decimals="4" :label="line.name" :entities="answer.entities" :sources="answer.sources" />
                        </li>
                    </ul>
                </div>
            </div>
            <div v-else-if="answer.missing.length" class="flex flex-col gap-2" data-testid="bom-cost-incomplete">
                <EngineMissing :missing="answer.missing" />
                <p class="text-label text-ink-3">{{ t('packaging.bom.cost.incomplete_description') }}</p>
            </div>
            <p v-else class="text-ink-3" data-testid="bom-cost-empty">{{ t('packaging.bom.cost.no_lines') }}</p>

            <EngineWarnings :warnings="answer.warnings.map(warningOf)" />
        </div>
    </Card>
</template>
