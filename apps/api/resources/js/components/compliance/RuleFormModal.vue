<script setup>
/**
 * Adds or edits one rule of a draft rule set with plain fields (no JSON): where it applies, what it measures, the
 * comparison and its limit, and the source it comes from. Before anything is sent the browser builds the rule in the
 * engine's rules DSL and validates it with the engine (validateRuleSet); the server checks the same JSON again and the
 * engine re-validates the whole rule set when it is published. A rule needs a source: only a draft may say "source pending".
 *
 *   <RuleFormModal v-model:open="open" :rule-set-id="ruleSet.id" :row="row" :defaults="ruleDefaults" :version="ruleSet.version" :urls="urls" :source-kind-options="sourceKindOptions" />
 *
 * `row` is a rule of the table (null to add one). The limit stays text: the browser never turns a decimal into a float.
 */
import { computed, reactive, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import { path } from '../../lib/routes';
import { Alert, Button, FormField, Input, Modal, SegmentedControl, Select, Switch, TagsInput, Textarea } from '@kit';
import { useI18n } from '../../composables/useI18n';
import { useSuggestions } from '../../composables/useSuggestions';
import { TARGET_TYPES, UNITS, blankRuleForm, buildRule, ruleToForm, takesLimit, validateRuleDraft, OPERATORS } from '../../lib/compliance';
import DecimalField from '../form/DecimalField.vue';
import EntitySelect from '../form/EntitySelect.vue';
import SourceSelect from '../materials/SourceSelect.vue';

const props = defineProps({
    ruleSetId: { type: String, required: true },
    version: { type: String, required: true },
    row: { type: Object, default: null },
    defaults: { type: Object, required: true },
    urls: { type: Object, required: true },
    sourceKindOptions: { type: Array, required: true },
});

const open = defineModel('open', { type: Boolean, default: false });
const { t } = useI18n();
const { suggestions: productTypes, load: loadProductTypes } = useSuggestions(() => props.urls.productTypes);

const form = reactive(blankRuleForm());
const clientErrors = ref({});
const serverErrors = ref({});
const processing = ref(false);

const editing = computed(() => props.row !== null);
const concentration = computed(() => form.targetType !== 'field');
const limited = computed(() => takesLimit(form.operator));
const hasSource = computed(() => form.sourceId !== null && form.sourceId !== '');

watch(open, (isOpen) => {
    if (isOpen) {
        Object.assign(form, props.row ? ruleToForm(props.row.rule, { message: props.row.message, active: props.row.active }) : blankRuleForm());
        clientErrors.value = {};
        serverErrors.value = {};
        loadProductTypes();
    }
});

// A concentration is always % w/w (the engine's rule); a product field may use any unit.
watch(concentration, (isConcentration) => {
    if (isConcentration) {
        form.unit = '%w/w';
    }
});
watch(hasSource, (present) => {
    if (present) {
        form.pendingSource = false;
    }
});

const targetOptions = computed(() => TARGET_TYPES.map((type) => ({ value: type, label: t(`enums.rule_target_type.${type}`) })));
const operatorOptions = computed(() => OPERATORS.map((operator) => ({ value: operator, label: t(`enums.rule_operator.${operator}`) })));
const unitOptions = computed(() => UNITS.map((unit) => ({ value: unit, label: unit })));
const rinseOptions = computed(() => ['any', 'yes', 'no'].map((value) => ({ value, label: t(`compliance.rules.form.rinse_off_${value}`) })));

/** Server error keys that belong to each field (the form posts the DSL rule under `rule`). */
const SERVER_KEYS = {
    id: ['rule.id'],
    productTypes: ['rule.scope'],
    rinseOff: ['rule.scope.rinseOff'],
    ifraCategory: ['rule.scope.ifraCategory'],
    targetValue: ['rule.target'],
    operator: ['rule.operator'],
    limit: ['rule.limit'],
    unit: ['rule.unit'],
    group: ['rule.group'],
    source: ['rule.source', 'rule.pendingSource'],
};

/** The first message for a field: the engine's reasons from the browser check, else what the server said. */
function errorOf(field) {
    const client = clientErrors.value[field]?.[0];

    if (client) {
        return t(`engine.${client}`);
    }

    const key = Object.keys(serverErrors.value).find((candidate) => (SERVER_KEYS[field] ?? []).some((prefix) => candidate === prefix || candidate.startsWith(`${prefix}.`)));

    return key ? serverErrors.value[key] : null;
}

function submit() {
    const rule = buildRule(form, { version: props.version, messageKey: props.defaults.messageKey });
    const check = validateRuleDraft(rule);
    clientErrors.value = check.errors;
    serverErrors.value = {};

    if (!check.valid) {
        return;
    }

    const url = editing.value ? path('compliance.rules.update', [props.ruleSetId, props.row.id]) : path('compliance.rules.store', props.ruleSetId);
    const options = {
        preserveScroll: true,
        onStart: () => { processing.value = true; },
        onFinish: () => { processing.value = false; },
        onError: (errors) => { serverErrors.value = errors; },
        onSuccess: () => { open.value = false; },
    };
    const data = { rule, message: form.message, active: form.active };

    if (editing.value) {
        router.put(url, data, options);
    } else {
        router.post(url, data, options);
    }
}

const otherErrors = computed(() => (clientErrors.value._other ?? []).map((reason) => t(`engine.${reason}`)));
</script>

<template>
    <Modal v-model:open="open" :title="editing ? t('compliance.rules.form.title_edit') : t('compliance.rules.form.title_new')" :description="t('compliance.rules.form.description')" size="lg">
        <form id="rule-form" class="grid gap-4 sm:grid-cols-2" novalidate @submit.prevent="submit">
            <Alert v-if="otherErrors.length" tone="danger" class="sm:col-span-2">
                <ul class="list-disc ps-5"><li v-for="text in otherErrors" :key="text">{{ text }}</li></ul>
            </Alert>

            <FormField :label="t('compliance.rules.form.id')" :hint="t('compliance.rules.form.id_hint')" :error="errorOf('id')" required class="sm:col-span-2">
                <Input v-model="form.id" ltr maxlength="100" />
            </FormField>

            <FormField :label="t('compliance.rules.form.product_types')" :hint="t('compliance.rules.form.product_types_hint')" :error="errorOf('productTypes')" required class="sm:col-span-2">
                <TagsInput v-model="form.productTypes" list="rule-product-types" :placeholder="t('compliance.rules.form.product_types_placeholder')" />
                <datalist id="rule-product-types"><option v-for="type in productTypes" :key="type" :value="type" /></datalist>
            </FormField>
            <FormField :label="t('compliance.rules.form.rinse_off')" :hint="t('compliance.rules.form.rinse_off_hint')" :error="errorOf('rinseOff')">
                <SegmentedControl v-model="form.rinseOff" :options="rinseOptions" />
            </FormField>
            <FormField :label="t('compliance.rules.form.ifra_category')" :hint="t('compliance.rules.form.ifra_category_hint')" :error="errorOf('ifraCategory')">
                <Input v-model="form.ifraCategory" ltr maxlength="50" />
            </FormField>

            <FormField :label="t('compliance.rules.form.target_type')" :hint="t('compliance.rules.form.target_type_hint')" required>
                <Select v-model="form.targetType" :options="targetOptions" />
            </FormField>
            <EntitySelect
                v-if="form.targetType === 'materialId'"
                v-model="form.targetValue"
                label-key="compliance.rules.form.target_material"
                :search-url="urls.materials"
                :selected-label="row ? form.targetValue : ''"
                :error="errorOf('targetValue')"
                required
            />
            <FormField v-else :label="t(`compliance.rules.form.target_value_${form.targetType}`)" :hint="t(`compliance.rules.form.target_hint_${form.targetType}`)" :error="errorOf('targetValue')" required>
                <Input v-model="form.targetValue" ltr maxlength="255" />
            </FormField>

            <FormField :label="t('compliance.rules.form.operator')" :error="errorOf('operator')" required>
                <Select v-model="form.operator" :options="operatorOptions" />
            </FormField>
            <DecimalField
                v-if="limited"
                v-model="form.limit"
                label-key="compliance.rules.form.limit"
                hint-key="compliance.rules.form.limit_hint"
                :suffix="form.unit"
                :error="errorOf('limit')"
                required
            />
            <p v-else class="self-end pb-2 text-label text-ink-3">{{ t('compliance.rules.form.no_limit') }}</p>

            <FormField :label="t('compliance.rules.form.unit')" :hint="concentration ? t('compliance.rules.form.unit_fixed_hint') : t('compliance.rules.form.unit_hint')" :error="errorOf('unit')" required>
                <Select v-model="form.unit" :options="unitOptions" :disabled="concentration" />
            </FormField>
            <FormField :label="t('compliance.rules.form.group')" :hint="t('compliance.rules.form.group_hint')" :error="errorOf('group')">
                <Input v-model="form.group" ltr maxlength="100" />
            </FormField>

            <div class="sm:col-span-2">
                <SourceSelect
                    v-model="form.sourceId"
                    :urls="urls"
                    :kind-options="sourceKindOptions"
                    label-key="compliance.rules.form.source"
                    :selected-label="form.sourceTitle"
                    :error="errorOf('source')"
                    @select="(option) => { form.sourceTitle = option.label; }"
                />
            </div>
            <FormField v-if="hasSource" :label="t('compliance.rules.form.source_ref')" :hint="t('compliance.rules.form.source_ref_hint')" class="sm:col-span-2">
                <Input v-model="form.sourceRef" maxlength="255" />
            </FormField>
            <div v-else class="sm:col-span-2">
                <Switch v-model="form.pendingSource" :label="t('compliance.rules.form.pending_source')" :description="t('compliance.rules.form.pending_source_hint')" />
            </div>

            <FormField :label="t('compliance.rules.form.message')" :hint="t('compliance.rules.form.message_hint')" class="sm:col-span-2">
                <Textarea v-model="form.message" :rows="2" maxlength="500" />
            </FormField>
            <div class="sm:col-span-2">
                <Switch v-model="form.active" :label="t('compliance.rules.form.active')" :description="t('compliance.rules.form.active_hint')" />
            </div>
        </form>
        <template #footer>
            <Button variant="ghost" @click="open = false">{{ t('common.cancel') }}</Button>
            <Button type="submit" form="rule-form" :loading="processing" icon="ph:floppy-disk">{{ editing ? t('compliance.rules.form.submit_edit') : t('compliance.rules.form.submit_new') }}</Button>
        </template>
    </Modal>
</template>
