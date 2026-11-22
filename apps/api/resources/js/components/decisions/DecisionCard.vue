<script setup>
/**
 * The one card that answers "why was this blocked?" for every engine decision (Y-08): the verdict, EVERY failed condition with the step
 * that fixes it, the rules and hash it was judged by, and (Y-04) the signed override of a blocked decision. The publish page and the
 * approval panel both render it; what a decision means stays on the server, this only shows it. Shape of `decision`:
 * { state: 'allowed'|'blocked'|'undecidable', reasons: [{ rule, message, remedy }], detail?, rule_set?: { id, version }, decision_hash? }.
 * Shape of `override` (optional): { available, why, url, active: { by, role, reason, at } | null }.
 *
 * Slots: `extra` (more lines under the conditions) and `actions` (the screen's own button, e.g. publish).
 */
import { computed, ref } from 'vue';
import { useForm } from '@inertiajs/vue3';
import { Alert, Badge, Button, Card, FormField, Input, Modal, Textarea } from '@kit';
import { useDateTime } from '../../composables/useDateTime';
import { useI18n } from '../../composables/useI18n';

const props = defineProps({
    decision: { type: Object, required: true },
    title: { type: String, required: true },
    description: { type: String, default: undefined },
    /** Verdict wording specific to the screen; defaults to the generic text. */
    allowedText: { type: String, default: undefined },
    blockedText: { type: String, default: undefined },
    override: { type: Object, default: null },
    /** Extra fields posted with the override (the inputs the decision was judged on). */
    overrideData: { type: Object, default: () => ({}) },
});

const { t } = useI18n();
const { when } = useDateTime();

const blocked = computed(() => props.decision.state === 'blocked');
const allowed = computed(() => props.decision.state === 'allowed');
const reasons = computed(() => props.decision.reasons ?? []);

const signing = ref(false);
const form = useForm({ reason: '', password: '' });

function begin() {
    form.reset();
    form.clearErrors();
    signing.value = true;
}

function closeSigning() {
    signing.value = false;
}

function clearPassword() {
    form.reset('password');
}

function sign() {
    form
        .transform((data) => ({ ...data, ...props.overrideData }))
        .post(props.override.url, {
            preserveScroll: true,
            onSuccess: closeSigning,
            onFinish: clearPassword,
        });
}
</script>

<template>
    <Card :title="title" :description="description" data-testid="decision-card" :data-state="decision.state">
        <div class="flex flex-col gap-3">
            <Alert v-if="decision.state === 'undecidable'" tone="danger" icon="ph:warning" data-testid="decision-undecidable">{{ t('decision_ui.card.undecidable', { detail: decision.detail }) }}</Alert>
            <template v-else>
                <Alert :tone="allowed ? 'success' : 'warning'" :icon="allowed ? 'ph:check-circle' : 'ph:warning'" data-testid="decision-verdict">
                    {{ allowed ? (allowedText ?? t('decision_ui.card.allowed')) : (blockedText ?? t('decision_ui.card.blocked')) }}
                </Alert>
                <ul v-if="reasons.length" class="flex flex-col gap-3" data-testid="decision-reasons">
                    <li v-for="reason in reasons" :key="reason.rule" class="flex flex-col gap-1 rounded-lg border border-line p-3" :data-rule="reason.rule">
                        <span class="flex flex-wrap items-center gap-2"><Badge tone="danger" size="sm" mono>{{ reason.rule }}</Badge><span class="text-ink">{{ reason.message }}</span></span>
                        <span class="text-label text-ink-3"><span class="text-ink-2">{{ t('decision_ui.card.step') }}:</span> {{ reason.remedy }}</span>
                    </li>
                </ul>
                <slot name="extra" />
                <p v-if="decision.rule_set" class="text-label text-ink-3">
                    {{ t('decision_ui.card.rule_set', { id: decision.rule_set.id, version: decision.rule_set.version }) }}
                    <template v-if="decision.decision_hash"> · {{ t('decision_ui.card.hash') }}: <bdi dir="ltr" class="font-mono">{{ decision.decision_hash.slice(0, 16) }}</bdi></template>
                </p>
            </template>

            <Alert v-if="override?.active" tone="info" icon="ph:seal-warning" :title="t('decision_ui.override.active_title')" data-testid="override-active">
                <p>{{ t('decision_ui.override.active_description') }}</p>
                <p class="mt-1 text-label text-ink-2">
                    {{ t('decision_ui.override.by') }}: {{ override.active.by ?? t('decision_ui.override.unknown_user') }} · {{ t('decision_ui.override.role') }}: <bdi dir="ltr">{{ override.active.role }}</bdi> · {{ t('decision_ui.override.at') }}: {{ when(override.active.at) }}
                </p>
                <p class="text-label text-ink-2">{{ t('decision_ui.override.reason_label') }}: {{ override.active.reason }}</p>
            </Alert>
            <div v-else-if="override && blocked" class="flex flex-wrap items-center gap-2" data-testid="override-area">
                <Button v-if="override.available" variant="secondary" icon="ph:seal-warning" data-testid="override-open" @click="begin">{{ t('decision_ui.override.button') }}</Button>
                <p v-else class="text-label text-ink-3" data-testid="override-unavailable">{{ override.why }}</p>
            </div>

            <slot name="actions" />
        </div>

        <Modal v-if="override" v-model:open="signing" :title="t('decision_ui.override.title')" :description="t('decision_ui.override.description')" size="md">
            <form id="override-form" class="flex flex-col gap-4" novalidate @submit.prevent="sign">
                <FormField :label="t('decision_ui.override.reason')" :hint="t('decision_ui.override.reason_hint')" :error="form.errors.reason" required>
                    <Textarea v-model="form.reason" :rows="3" :maxlength="1000" data-testid="override-reason" />
                </FormField>
                <FormField :label="t('decision_ui.override.password')" :hint="t('decision_ui.override.password_hint')" :error="form.errors.password ?? form.errors.override" required>
                    <Input v-model="form.password" type="password" autocomplete="current-password" ltr data-testid="override-password" />
                </FormField>
            </form>
            <template #footer>
                <Button variant="ghost" @click="signing = false">{{ t('common.cancel') }}</Button>
                <Button type="submit" form="override-form" variant="danger" :loading="form.processing" data-testid="override-confirm">{{ t('decision_ui.override.confirm') }}</Button>
            </template>
        </Modal>
    </Card>
</template>
