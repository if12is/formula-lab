<script setup>
/**
 * The separate EDA path notice for disinfectants (N-12). The engine decides (disinfectionPathwayNotice): a disinfection claim, or a
 * disinfectant family, puts the product on the EDA path for disinfectant preparations. This only alerts: it never approves, and it is
 * kept apart from the compliance rule results. With `formulaId` it also edits the claims the product makes (saved on the formula, and the
 * notice follows the choice before it is saved); without it (a family page) it just shows the notice, or nothing when none applies.
 *
 *   <DisinfectionNotice :formula-id="formula.id" :family-group="formula.family_group" :saved-claims="formula.claims" />
 *   <DisinfectionNotice :family-group="family.group" />
 */
import { computed, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import { Alert, Button, FormField, MultiSelect } from '@kit';
import { path } from '../../lib/routes';
import { useI18n } from '../../composables/useI18n';
import { claimCodes, evaluateDisinfection } from '../../lib/disinfection';
import { reasonText } from '../../lib/compliance';

const props = defineProps({
    familyGroup: { type: String, default: null },
    formulaId: { type: String, default: null },
    savedClaims: { type: Array, default: () => [] },
});

const { t, has } = useI18n();
const claims = ref([...props.savedClaims]);
const saving = ref(false);
const editable = computed(() => props.formulaId !== null);

watch(() => props.savedClaims, (value) => { claims.value = [...value]; });

const options = computed(() => claimCodes.map((code) => ({ value: code, label: t(`disinfection.claims.${code}`) })));
const dirty = computed(() => [...claims.value].sort().join('|') !== [...props.savedClaims].sort().join('|'));
const evaluation = computed(() => evaluateDisinfection(props.familyGroup, claims.value));
const notice = computed(() => (evaluation.value.ok ? evaluation.value.notice : null));

const missingText = computed(() => (evaluation.value.ok ? '' : evaluation.value.missing.map((gap) => reasonText(t, has, gap.reason)).join(t('common.sentence_separator'))));
const guide = computed(() => notice.value?.pathway ?? null);
const triggerText = (trigger) => (trigger.kind === 'family_group'
    ? t('disinfection.trigger_family_group', { group: t(`families.groups.${trigger.code}`) })
    : t('disinfection.trigger_claim', { claim: t(`disinfection.claims.${trigger.code}`) }));
const reviewClaims = computed(() => (notice.value?.reviewClaims ?? []).map((code) => t(`disinfection.claims.${code}`)).join(t('common.list_separator')));

function save() {
    router.put(path('formulas.claims.update', props.formulaId), { claims: claims.value }, {
        preserveScroll: true,
        preserveState: true,
        onStart: () => { saving.value = true; },
        onFinish: () => { saving.value = false; },
    });
}
</script>

<template>
    <div v-if="editable || (notice && notice.status !== 'none') || !evaluation.ok" class="flex flex-col gap-3" data-testid="disinfection-block">
        <div v-if="editable" class="flex flex-col gap-1">
            <h3 class="type-h6 text-ink">{{ t('disinfection.title') }}</h3>
            <p class="max-w-prose text-label text-ink-3">{{ t('disinfection.description') }}</p>
        </div>

        <div v-if="editable" class="flex flex-wrap items-end gap-2">
            <FormField :label="t('disinfection.claims_label')" :hint="t('disinfection.claims_hint')" class="w-full sm:max-w-md">
                <MultiSelect v-model="claims" :options="options" :placeholder="t('disinfection.claims_placeholder')" :aria-label="t('disinfection.claims_label')" data-testid="claims-select" />
            </FormField>
            <Button variant="secondary" icon="ph:floppy-disk" :disabled="!dirty" :loading="saving" data-testid="claims-save" @click="save">{{ t('disinfection.save') }}</Button>
        </div>

        <Alert v-if="!evaluation.ok" tone="warning" icon="ph:warning" data-testid="disinfection-invalid">
            {{ missingText }}
        </Alert>
        <Alert v-else-if="notice.status === 'applies'" tone="warning" icon="ph:warning" :title="t('disinfection.applies_title')" data-testid="disinfection-notice" data-status="applies">
            <p>{{ t('disinfection.applies', { guide: guide.guideCode, edition: guide.guideEdition }) }}</p>
            <p class="mt-2 text-label">{{ t('disinfection.triggers') }}: {{ notice.triggers.map(triggerText).join(t('common.list_separator')) }}</p>
            <p class="mt-2 text-label">{{ t('disinfection.caveat') }}</p>
            <p class="mt-1 text-label text-ink-3">{{ t('disinfection.source') }}</p>
        </Alert>
        <Alert v-else-if="notice.status === 'review'" tone="info" icon="ph:info" :title="t('disinfection.review_title')" data-testid="disinfection-notice" data-status="review">
            <p>{{ t('disinfection.review', { claims: reviewClaims }) }}</p>
            <p class="mt-2 text-label">{{ t('disinfection.caveat') }}</p>
        </Alert>
        <p v-else class="text-label text-ink-3" data-testid="disinfection-none">{{ t('disinfection.none') }}</p>
    </div>
</template>
