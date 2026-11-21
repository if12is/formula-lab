<script setup>
/**
 * The IFRA check of a formula: each fragrance oil in it is held against ITS OWN IFRA certificate, for the product's IFRA
 * category (engine checkIfraCompliance). The category is free text as the certificate names it (the app keeps no list of
 * categories) and is saved on the formula; the limits are the material's `ifra_limit` values, each with a category and a
 * source. No category, no certificate or no limit for the category is UNKNOWN, never PASS.
 */
import { computed, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import { path } from '../../lib/routes';
import { Alert, Button, Input, FormField } from '@kit';
import { useI18n } from '../../composables/useI18n';
import { reasonText } from '../../lib/compliance';
import ExplainableNum from '../explain/ExplainableNum.vue';
import StatusBadge from '../StatusBadge.vue';

const props = defineProps({
    formulaId: { type: String, required: true },
    savedCategory: { type: String, default: null },
    /** The result of evaluateIfra (lib/compliance.js): null when no fragrance oil is in the formula. */
    ifra: { type: Object, default: null },
    labelOf: { type: Function, required: true },
    entities: { type: Object, default: () => ({}) },
    sources: { type: Object, default: () => ({}) },
});

/** The category as typed, so the panel's overall status follows it before it is saved. */
const category = defineModel('category', { type: String, default: '' });
const { t, has } = useI18n();
const saving = ref(false);

watch(() => props.savedCategory, (value) => { category.value = value ?? ''; });

const dirty = computed(() => category.value.trim() !== (props.savedCategory ?? ''));

function save() {
    router.put(path('formulas.ifra-category.update', props.formulaId), { ifra_category: category.value.trim() || null }, {
        preserveScroll: true,
        preserveState: true,
        onStart: () => { saving.value = true; },
        onFinish: () => { saving.value = false; },
    });
}

const gapsOf = (result) => result.missing.map((gap) => reasonText(t, has, gap.reason));
const linkOf = (materialId) => path('materials.show', { material: materialId, tab: 'properties' });
</script>

<template>
    <div class="flex flex-col gap-3" data-testid="ifra-block">
        <div class="flex flex-wrap items-center gap-2">
            <h3 class="type-h6 text-ink">{{ t('compliance.ifra.title') }}</h3>
            <span v-if="ifra" class="ms-auto"><StatusBadge kind="check_status" :status="ifra.report.overall" data-testid="ifra-overall" /></span>
        </div>
        <p class="max-w-prose text-label text-ink-3">{{ t('compliance.ifra.description') }}</p>

        <div class="flex flex-wrap items-end gap-2">
            <FormField :label="t('compliance.ifra.category')" :hint="t('compliance.ifra.category_hint')" class="w-full sm:max-w-xs">
                <Input v-model="category" ltr maxlength="50" data-testid="ifra-category" @keydown.enter.prevent="save" />
            </FormField>
            <Button variant="secondary" icon="ph:floppy-disk" :disabled="!dirty" :loading="saving" data-testid="ifra-save" @click="save">{{ t('compliance.ifra.save') }}</Button>
        </div>

        <Alert v-if="!ifra" tone="info" icon="ph:info" data-testid="ifra-none">{{ t('compliance.ifra.no_fragrance') }}</Alert>
        <ul v-else class="flex flex-col divide-y divide-line rounded-lg border border-line">
            <li v-for="result in ifra.report.results" :key="result.materialId" class="flex flex-wrap items-center gap-x-4 gap-y-2 px-3 py-2" :data-ifra-result="result.materialId" :data-status="result.status">
                <span class="min-w-40 flex-1 font-semibold text-ink">{{ labelOf(result.materialId) }}</span>
                <StatusBadge kind="check_status" :status="result.status" />
                <span v-if="result.check?.limitPct" class="flex items-baseline gap-1 text-label text-ink-2" data-testid="ifra-value">
                    <ExplainableNum :traced="result.check.limitPct" :label="t('compliance.ifra.limit')" :entities="entities" :sources="sources" /> %
                    <span class="text-ink-3">{{ t('compliance.ifra.limit') }}</span>
                </span>
                <span v-if="result.check?.marginPct" class="flex items-baseline gap-1 text-label text-ink-2">
                    <ExplainableNum :traced="result.check.marginPct" :label="t('compliance.ifra.margin')" :entities="entities" :sources="sources" /> %
                    <span class="text-ink-3">{{ t('compliance.ifra.margin') }}</span>
                </span>
                <span v-if="result.missing.length" class="flex min-w-0 basis-full flex-wrap items-baseline gap-x-2 text-label text-ink-2" data-testid="ifra-gaps">
                    <span>{{ gapsOf(result).join(' ') }}</span>
                    <a :href="linkOf(result.materialId)" class="text-primary-ink underline underline-offset-2">{{ t('compliance.ifra.fill_limits') }}</a>
                </span>
            </li>
        </ul>
    </div>
</template>
