<script setup>
/**
 * The tiers screen (S-05): a product, a tier (economy, mid, premium) and its target price in, the optimizer's cheapest formula out, with what it
 * depends on (the constraints that bind and what a unit more of each costs, the effect of a price change of every material, the target price
 * walked back to a maximum cost). The profile of a tier is typed here with the source of its figures and saved; nothing is pre-filled and an empty
 * figure means "no constraint". The solve is the engine's optimizer on the server; this page only sends what was typed and prints the answer.
 */
import { computed, reactive, ref } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import { Alert, Badge, Button, Card, EmptyState, FormField, IconButton, PageHeader, SegmentedControl, Switch } from '@kit';
import DecimalField from '../../components/form/DecimalField.vue';
import EntitySelect from '../../components/form/EntitySelect.vue';
import EngineMissing from '../../components/explain/EngineMissing.vue';
import EngineWarnings from '../../components/explain/EngineWarnings.vue';
import ConfidenceBadge from '../../components/materials/ConfidenceBadge.vue';
import SourceSelect from '../../components/materials/SourceSelect.vue';
import Num from '../../components/Num.vue';
import LadderRatesFields from '../../components/pricing/LadderRatesFields.vue';
import { useEngineText } from '../../composables/useEngineText';
import { useI18n } from '../../composables/useI18n';
import { warningOf } from '../../lib/calculators/common';
import { postJson } from '../../lib/http';
import { blankRates, ratesPayload } from '../../lib/ladder';
import { path } from '../../lib/routes';

const props = defineProps({
    product: { type: Object, default: null },
    tier: { type: String, required: true },
    tierOptions: { type: Array, required: true },
    profile: { type: Object, default: null },
    sourceKindOptions: { type: Array, required: true },
    urls: { type: Object, required: true },
});

const { t } = useI18n();
const { reasonOf } = useEngineText();

const form = useForm({
    formula_id: props.product?.id ?? null,
    tier: props.tier,
    total_active_min_pct: props.profile?.total_active_min_pct ?? '',
    total_active_max_pct: props.profile?.total_active_max_pct ?? '',
    max_cost_egp_per_kg: props.profile?.max_cost_egp_per_kg ?? '',
    target_per: props.profile?.target_per ?? 'kg',
    target_value: props.profile?.target_value ?? '',
    forbidden: (props.profile?.forbidden ?? []).map((item) => item.value),
    bounds: (props.profile?.bounds ?? []).map((bound) => ({ material_id: bound.material_id, min: bound.min, max: bound.max, required: bound.required })),
    source_id: props.profile?.source?.value ?? null,
});
const labels = reactive(Object.fromEntries([...(props.profile?.forbidden ?? []).map((item) => [item.value, item.label]), ...(props.profile?.bounds ?? []).map((bound) => [bound.material_id, bound.label])]));
const sourceLabel = ref(props.profile?.source?.label ?? '');
const addKey = ref(0);

const rates = reactive(blankRates());
const extra = reactive({ pack_fill_kg: '', non_formula_cost_per_unit: '', scenario_pct: '10', qs_material_id: null });
const qsLabel = ref('');
const state = reactive({ busy: false, failed: false, errors: {}, result: null });

const productLabel = computed(() => (props.product ? `${props.product.code} · ${props.product.name}` : ''));
const tierChoices = computed(() => props.tierOptions);
const perOptions = computed(() => ['kg', 'pack'].map((value) => ({ value, label: t(`tiers.profile.per.${value}`) })));
const hasTarget = computed(() => form.target_value !== '');

function choose(next) {
    router.get(path('tiers.index'), { formula: next.formula ?? props.product?.id, tier: next.tier ?? props.tier }, { preserveState: false });
}

function addForbidden(option) {
    labels[option.value] = option.label;
    if (!form.forbidden.includes(option.value)) {
        form.forbidden.push(option.value);
    }
    addKey.value += 1;
}

function addBound(option) {
    labels[option.value] = option.label;
    if (!form.bounds.some((bound) => bound.material_id === option.value)) {
        form.bounds.push({ material_id: option.value, min: '', max: '', required: false });
    }
    addKey.value += 1;
}

const removeForbidden = (id) => { form.forbidden = form.forbidden.filter((value) => value !== id); };
const removeBound = (id) => { form.bounds = form.bounds.filter((bound) => bound.material_id !== id); };
const save = () => form.put(path('tiers.profile.save'), { preserveScroll: true });

async function solve() {
    state.busy = true;
    state.failed = false;
    state.errors = {};

    try {
        const outcome = await postJson(props.urls.solve, {
            formula_id: props.product.id,
            tier: props.tier,
            qs_material_id: extra.qs_material_id,
            scenario_pct: extra.scenario_pct,
            reverse: { ...ratesPayload(rates), pack_fill_kg: extra.pack_fill_kg, non_formula_cost_per_unit: extra.non_formula_cost_per_unit },
        });

        if (outcome.ok) {
            state.result = outcome.data;
        } else {
            state.errors = outcome.errors;
        }
    } catch {
        state.failed = true;
    } finally {
        state.busy = false;
    }
}

const statusTone = { optimal: 'success', infeasible: 'danger', missing: 'warning', invalid: 'danger', error: 'danger' };
const warningTexts = computed(() => (state.result?.warnings ?? []).filter((w) => w.text === null).map((w) => warningOf(w.raw)));
const warningLines = computed(() => (state.result?.warnings ?? []).filter((w) => w.text !== null).map((w) => w.text));
const reasonText = (reason) => reasonOf({ reason });
const propertyText = (property) => t(`tiers.excluded.${property}`);
</script>

<template>
    <Head :title="t('tiers.title')" />
    <div class="kit-page" data-testid="tiers">
        <PageHeader :title="t('tiers.title')" :description="t('tiers.description')" />

        <div class="flex flex-col gap-4">
            <Card :title="t('tiers.pick.title')">
                <div class="flex flex-col gap-4">
                    <div class="grid gap-4 sm:grid-cols-2">
                        <EntitySelect :model-value="product?.id ?? null" label-key="tiers.pick.product" :search-url="urls.products" :selected-label="productLabel" @select="(option) => choose({ formula: option.value })" />
                        <FormField :label="t('tiers.pick.tier')">
                            <SegmentedControl :model-value="tier" :options="tierChoices" data-testid="tier-switch" @update:model-value="(value) => choose({ tier: value })" />
                        </FormField>
                    </div>
                    <p v-if="product" class="text-label text-ink-3">{{ t('tiers.pick.product_type', { type: product.product_type }) }}</p>
                </div>
            </Card>

            <EmptyState v-if="!product" icon="ph:stack" :title="t('tiers.empty_title')" :description="t('tiers.empty_description')" />

            <template v-else>
                <Card :title="t('tiers.profile.title')" :description="t('tiers.profile.description')" data-testid="tier-profile">
                    <form class="flex flex-col gap-5" novalidate @submit.prevent="save">
                        <Alert v-if="!profile.saved" tone="info" icon="ph:info" data-testid="profile-empty">{{ t('tiers.profile.not_saved') }}</Alert>
                        <div class="grid gap-4 sm:grid-cols-2">
                            <DecimalField v-model="form.total_active_min_pct" label-key="tiers.profile.active_min" suffix="%" :error="form.errors.total_active_min_pct" />
                            <DecimalField v-model="form.total_active_max_pct" label-key="tiers.profile.active_max" suffix="%" :error="form.errors.total_active_max_pct" />
                            <DecimalField v-model="form.max_cost_egp_per_kg" label-key="tiers.profile.max_cost" :suffix="t('price_ladder.units.egp_per_kg')" :error="form.errors.max_cost_egp_per_kg" />
                            <div class="grid grid-cols-[minmax(0,1fr)_auto] items-end gap-3">
                                <DecimalField v-model="form.target_value" label-key="tiers.profile.target_price" hint-key="tiers.profile.target_hint" :suffix="t('price_ladder.units.egp')" :error="form.errors.target_value" />
                                <FormField :label="t('tiers.profile.per.label')">
                                    <SegmentedControl v-model="form.target_per" :options="perOptions" data-testid="target-per" />
                                </FormField>
                            </div>
                        </div>

                        <div class="flex flex-col gap-3">
                            <h3 class="text-sm font-semibold text-ink">{{ t('tiers.profile.bounds_title') }}</h3>
                            <p class="text-label text-ink-3">{{ t('tiers.profile.bounds_hint') }}</p>
                            <ul v-if="form.bounds.length" class="flex flex-col gap-3" data-testid="bounds-list">
                                <li v-for="(bound, index) in form.bounds" :key="bound.material_id" class="grid gap-3 rounded-lg border border-line p-3 sm:grid-cols-[minmax(0,1.4fr)_minmax(0,1fr)_minmax(0,1fr)_auto_auto] sm:items-end" :data-bound="labels[bound.material_id]">
                                    <span class="font-semibold text-ink">{{ labels[bound.material_id] }}</span>
                                    <DecimalField v-model="bound.min" label-key="tiers.profile.bound_min" suffix="%" :error="form.errors[`bounds.${index}.min`]" />
                                    <DecimalField v-model="bound.max" label-key="tiers.profile.bound_max" suffix="%" :error="form.errors[`bounds.${index}.max`]" />
                                    <FormField :label="t('tiers.profile.required')"><Switch v-model="bound.required" :aria-label="t('tiers.profile.required')" /></FormField>
                                    <IconButton icon="ph:trash" :label="t('common.delete')" variant="ghost" @click="removeBound(bound.material_id)" />
                                </li>
                            </ul>
                            <EntitySelect :key="`b${addKey}`" :model-value="null" label-key="tiers.profile.add_bound" :search-url="urls.materials" data-testid="add-bound" @select="addBound" />
                        </div>

                        <div class="flex flex-col gap-3">
                            <h3 class="text-sm font-semibold text-ink">{{ t('tiers.profile.forbidden_title') }}</h3>
                            <ul v-if="form.forbidden.length" class="flex flex-wrap gap-2" data-testid="forbidden-list">
                                <li v-for="id in form.forbidden" :key="id" class="flex items-center gap-1 rounded-full border border-line px-3 py-1 text-ink">
                                    {{ labels[id] }}
                                    <IconButton icon="ph:x" size="sm" :label="t('common.delete')" variant="ghost" @click="removeForbidden(id)" />
                                </li>
                            </ul>
                            <EntitySelect :key="`f${addKey}`" :model-value="null" label-key="tiers.profile.add_forbidden" :search-url="urls.materials" data-testid="add-forbidden" @select="addForbidden" />
                        </div>

                        <SourceSelect v-model="form.source_id" :urls="{ sources: urls.sources, sourceStore: urls.sourceStore }" :kind-options="sourceKindOptions" :selected-label="sourceLabel" label-key="tiers.profile.source" :error="form.errors.source_id" @select="(option) => { sourceLabel = option.label; }" />
                        <p class="-mt-3 text-label text-ink-3">{{ t('tiers.profile.source_hint') }}</p>

                        <div class="flex flex-wrap items-center gap-3">
                            <Button type="submit" icon="ph:floppy-disk" :loading="form.processing" data-testid="profile-save">{{ t('tiers.profile.save') }}</Button>
                            <span v-if="form.isDirty" class="text-label text-warning-ink" data-testid="profile-dirty">{{ t('tiers.profile.unsaved') }}</span>
                        </div>
                    </form>
                </Card>

                <Card :title="t('tiers.solve.title')" :description="t('tiers.solve.description')" data-testid="tier-solve">
                    <div class="flex flex-col gap-5">
                        <EntitySelect v-model="extra.qs_material_id" label-key="tiers.solve.qs_material" hint-key="tiers.solve.qs_hint" :search-url="urls.materials" :selected-label="qsLabel" :error="state.errors.qs_material_id" data-testid="qs-material" @select="(option) => { qsLabel = option.label; }" />
                        <DecimalField v-model="extra.scenario_pct" label-key="tiers.solve.scenario_pct" hint-key="tiers.solve.scenario_hint" suffix="%" :error="state.errors.scenario_pct" />

                        <template v-if="hasTarget">
                            <h3 class="text-sm font-semibold text-ink">{{ t('tiers.solve.reverse_title') }}</h3>
                            <p class="-mt-3 text-label text-ink-3">{{ t('tiers.solve.reverse_hint') }}</p>
                            <LadderRatesFields v-model="rates" :errors="state.errors" />
                            <div class="grid gap-4 sm:grid-cols-2">
                                <DecimalField v-model="extra.non_formula_cost_per_unit" label-key="price_ladder.inputs.non_formula_cost" hint-key="price_ladder.inputs.non_formula_cost_hint" :suffix="t('price_ladder.units.egp_per_unit')" :error="state.errors['reverse.non_formula_cost_per_unit']" />
                                <DecimalField v-if="form.target_per === 'pack'" v-model="extra.pack_fill_kg" label-key="price_ladder.inputs.fill_kg" hint-key="price_ladder.inputs.fill_kg_hint" :suffix="t('price_ladder.units.kg')" :error="state.errors['reverse.pack_fill_kg']" />
                            </div>
                        </template>

                        <Alert v-if="state.failed" tone="danger" icon="ph:warning-circle">{{ t('tiers.solve.failed') }}</Alert>
                        <div class="flex flex-wrap items-center gap-3">
                            <Button icon="ph:magic-wand" :loading="state.busy" :disabled="form.isDirty" data-testid="solve" @click="solve">{{ t('tiers.solve.run') }}</Button>
                            <span v-if="form.isDirty" class="text-label text-ink-3">{{ t('tiers.solve.save_first') }}</span>
                        </div>
                    </div>
                </Card>

                <template v-if="state.result">
                    <Card :title="t('tiers.result.title')" data-testid="tier-result" :data-status="state.result.status">
                        <div class="flex flex-col gap-5">
                            <div class="flex flex-wrap items-baseline gap-3">
                                <Badge :tone="statusTone[state.result.status] ?? 'neutral'" dot data-testid="result-status">{{ t(`tiers.result.status.${state.result.status}`) }}</Badge>
                                <template v-if="state.result.cost_per_kg !== null">
                                    <span class="type-metric text-ink" data-testid="result-cost"><Num :value="state.result.cost_per_kg" :decimals="2" /></span>
                                    <span class="text-label text-ink-3">{{ t('price_ladder.units.egp_per_kg') }}</span>
                                </template>
                            </div>

                            <EngineMissing v-if="state.result.missing.length" :missing="state.result.missing" />
                            <Alert v-for="(text, index) in state.result.errors" :key="`e${index}`" tone="danger" icon="ph:warning-circle" data-testid="result-error">{{ text }}</Alert>
                            <Alert v-if="state.result.conflicts.length" tone="danger" icon="ph:warning-circle" :title="t('tiers.result.conflicts')" data-testid="result-conflicts">
                                <ul class="flex flex-col gap-1"><li v-for="conflict in state.result.conflicts" :key="conflict.id">{{ conflict.text }}</li></ul>
                            </Alert>

                            <div v-if="state.result.formula.length" class="flex flex-col gap-2" data-testid="result-formula">
                                <h3 class="text-sm font-semibold text-ink">{{ t('tiers.result.formula') }}</h3>
                                <div class="overflow-x-auto">
                                    <table class="w-full min-w-[30rem] text-start">
                                        <thead>
                                            <tr class="text-label text-ink-3">
                                                <th class="py-2 pe-3 text-start font-medium">{{ t('tiers.result.columns.material') }}</th>
                                                <th class="py-2 pe-3 text-start font-medium">{{ t('tiers.result.columns.percent') }}</th>
                                                <th class="py-2 pe-3 text-start font-medium">{{ t('tiers.result.columns.price') }}</th>
                                                <th class="py-2 pe-3 text-start font-medium">{{ t('tiers.result.columns.confidence') }}</th>
                                                <th class="py-2 text-start font-medium">{{ t('tiers.result.columns.cost') }}</th>
                                            </tr>
                                        </thead>
                                        <tbody class="divide-y divide-line">
                                            <tr v-for="line in state.result.formula" :key="line.materialId" :data-material="line.material">
                                                <td class="py-2 pe-3 font-semibold text-ink">{{ line.material }}</td>
                                                <td class="py-2 pe-3" data-testid="line-percent"><Num :value="line.percentWW" :decimals="3" /> %</td>
                                                <td class="py-2 pe-3"><Num :value="line.priceEGPperKg" :decimals="2" /></td>
                                                <td class="py-2 pe-3"><ConfidenceBadge :confidence="line.priceConfidence" /></td>
                                                <td class="py-2"><Num :value="line.costEGPperKg" :decimals="4" /></td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                            </div>

                            <div v-if="state.result.binding.length" class="flex flex-col gap-2" data-testid="result-binding">
                                <h3 class="text-sm font-semibold text-ink">{{ t('tiers.result.binding') }}</h3>
                                <p class="text-label text-ink-3">{{ t('tiers.result.binding_hint') }}</p>
                                <ul class="flex flex-col gap-2">
                                    <li v-for="row in state.result.binding" :key="row.id" class="flex flex-wrap items-center justify-between gap-2 rounded-lg border border-line px-3 py-2" :data-binding="row.id">
                                        <span class="text-ink">{{ row.text }}</span>
                                        <span class="text-label text-ink-3">{{ t('tiers.result.dual') }}: <Num :value="row.dual" /></span>
                                    </li>
                                </ul>
                            </div>

                            <div v-if="state.result.sensitivity.length" class="flex flex-col gap-2" data-testid="result-sensitivity">
                                <h3 class="text-sm font-semibold text-ink">{{ t('tiers.result.sensitivity') }}</h3>
                                <div class="overflow-x-auto">
                                    <table class="w-full min-w-[34rem] text-start">
                                        <thead>
                                            <tr class="text-label text-ink-3">
                                                <th class="py-2 pe-3 text-start font-medium">{{ t('tiers.result.columns.material') }}</th>
                                                <th class="py-2 pe-3 text-start font-medium">{{ t('tiers.result.columns.new_price') }}</th>
                                                <th class="py-2 pe-3 text-start font-medium">{{ t('tiers.result.columns.new_cost') }}</th>
                                                <th class="py-2 pe-3 text-start font-medium">{{ t('tiers.result.columns.delta') }}</th>
                                                <th class="py-2 text-start font-medium">{{ t('tiers.result.columns.unchanged') }}</th>
                                            </tr>
                                        </thead>
                                        <tbody class="divide-y divide-line">
                                            <tr v-for="row in state.result.sensitivity" :key="row.materialId" :data-scenario="row.material">
                                                <td class="py-2 pe-3 text-ink">{{ row.material }} <bdi dir="ltr" class="tnum text-ink-3">{{ row.changePct }}%</bdi></td>
                                                <td class="py-2 pe-3"><Num :value="row.scenarioPriceEGPperKg === '' ? null : row.scenarioPriceEGPperKg" :decimals="2" /></td>
                                                <td class="py-2 pe-3"><Num :value="row.scenarioCostPerKg" :decimals="2" /></td>
                                                <td class="py-2 pe-3" data-testid="scenario-delta"><Num :value="row.costDeltaPerKg" :decimals="2" /> (<Num :value="row.costDeltaPct" :decimals="2" />%)</td>
                                                <td class="py-2"><Num :value="row.costDeltaIfFormulaUnchangedPerKg" :decimals="2" /></td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                                <p class="text-label text-ink-3">{{ t('tiers.result.sensitivity_hint') }}</p>
                            </div>

                            <div v-if="state.result.reverse" class="flex flex-col gap-2" data-testid="result-reverse">
                                <h3 class="text-sm font-semibold text-ink">{{ t('tiers.result.reverse') }}</h3>
                                <ol class="flex flex-col divide-y divide-line rounded-lg border border-line">
                                    <li v-for="step in state.result.reverse.steps" :key="step.key" class="px-4 py-2 text-ink-2">{{ step.text }}</li>
                                </ol>
                            </div>

                            <div v-if="state.result.excluded.length" class="flex flex-col gap-2" data-testid="result-excluded">
                                <h3 class="text-sm font-semibold text-ink">{{ t('tiers.result.excluded') }}</h3>
                                <p class="text-label text-ink-3">{{ t('tiers.result.excluded_hint') }}</p>
                                <ul class="flex flex-col gap-1 text-label text-ink-2">
                                    <li v-for="row in state.result.excluded" :key="`${row.material}${row.property}`"><span class="font-semibold text-ink">{{ row.material }}</span>: {{ propertyText(row.property) }} · {{ reasonText(row.reason) }}</li>
                                </ul>
                            </div>

                            <ul v-if="warningLines.length" class="flex list-disc flex-col gap-1 ps-5 text-label text-ink-3" data-testid="result-warnings">
                                <li v-for="(text, index) in warningLines" :key="index">{{ text }}</li>
                            </ul>
                            <EngineWarnings :warnings="warningTexts" />
                            <p class="break-all text-label text-ink-3"><bdi dir="ltr">{{ state.result.engine.solver }} · {{ state.result.engine.output_hash }}</bdi></p>
                        </div>
                    </Card>
                </template>
            </template>
        </div>
    </div>
</template>
