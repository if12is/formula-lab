<script setup>
/**
 * The approval workflow of one formula version on the editor: its status, the moves it can make (send to the lab, approve,
 * reject), the lab results the approval needs, the trials recorded for it and every signed move (who, when, comment,
 * the hash of the lines). Approval is also refused by the server while no completed trial holds the required
 * measurements; the button is disabled here only to say so early. Approved and rejected versions are final.
 */
import { computed, ref } from 'vue';
import { useForm } from '@inertiajs/vue3';
import { Alert, Badge, Button, Card, CopyButton, Modal, FormField, Textarea, Timeline } from '@kit';
import { useDateTime } from '../../composables/useDateTime';
import { useI18n } from '../../composables/useI18n';
import DecisionCard from '../decisions/DecisionCard.vue';
import CoveragePanel from '../lab/CoveragePanel.vue';
import StatusBadge from '../StatusBadge.vue';

const props = defineProps({
    /** App\Services\Formula\FormulaWorkflowService::panel(). */
    workflow: { type: Object, required: true },
});

const { t } = useI18n();
const { when } = useDateTime();

/** The move being signed in the modal: 'send_to_lab' | 'approve' | 'reject'. */
const move = ref(null);
const form = useForm({ text: '' });

const canSend = computed(() => props.workflow.next.includes('lab_testing'));
const canDecide = computed(() => props.workflow.next.includes('approved'));
const open = computed({ get: () => move.value !== null, set: (value) => { if (!value) { move.value = null; } } });
const rejecting = computed(() => move.value === 'reject');
const key = computed(() => ({ send_to_lab: 'sendToLab', approve: 'approve', reject: 'reject' })[move.value]);
const showCoverage = computed(() => ['draft', 'lab_testing'].includes(props.workflow.status));
const blocked = computed(() => props.workflow.status === 'lab_testing' && !props.workflow.coverage.covered);

function begin(next) {
    form.reset();
    form.clearErrors();
    move.value = next;
}

function sign() {
    form
        .transform((data) => ({ [rejecting.value ? 'reason' : 'comment']: data.text }))
        .post(props.workflow.urls[key.value], { preserveScroll: true, onSuccess: () => { move.value = null; } });
}

const items = computed(() => props.workflow.signatures.map((row) => ({
    id: row.id,
    icon: { lab_testing: 'ph:test-tube', approved: 'ph:seal-check', rejected: 'ph:x-circle' }[row.to],
    tone: { lab_testing: 'info', approved: 'success', rejected: 'danger' }[row.to],
    title: `${t('formulas.workflow.move', { from: t(`enums.formula_status.${row.from}`), to: t(`enums.formula_status.${row.to}`) })} · ${row.by ? t('formulas.workflow.signed_by', { name: row.by }) : t('formulas.workflow.unsigned_user')}`,
    datetime: row.at,
    time: when(row.at),
    row,
})));
</script>

<template>
    <Card :title="t('formulas.workflow.title')" :description="t('formulas.workflow.description')" data-testid="workflow-panel" :data-status="workflow.status">
        <div class="flex flex-col gap-4">
            <div class="flex flex-wrap items-center gap-3">
                <span class="text-label text-ink-3">{{ t('formulas.workflow.status') }}</span>
                <StatusBadge kind="formula_status" :status="workflow.status" />
                <span class="flex flex-wrap gap-2 ms-auto">
                    <Button v-if="canSend" icon="ph:test-tube" data-testid="workflow-send" @click="begin('send_to_lab')">{{ t('formulas.workflow.send_to_lab') }}</Button>
                    <Button v-if="canDecide" icon="ph:seal-check" :disabled="blocked" data-testid="workflow-approve" @click="begin('approve')">{{ t('formulas.workflow.approve') }}</Button>
                    <Button v-if="canDecide" variant="danger" icon="ph:x-circle" data-testid="workflow-reject" @click="begin('reject')">{{ t('formulas.workflow.reject') }}</Button>
                </span>
            </div>

            <Alert v-if="workflow.status === 'approved'" tone="success" icon="ph:lock-simple" :title="t('formulas.workflow.approved_title')">{{ t('formulas.workflow.approved_description') }}</Alert>
            <Alert v-else-if="workflow.status === 'rejected'" tone="danger" icon="ph:x-circle" :title="t('formulas.workflow.rejected_title')">{{ t('formulas.workflow.rejected_description') }}</Alert>

            <DecisionCard
                v-if="blocked"
                :decision="workflow.decision"
                :title="t('formulas.workflow.blocked_title')"
                :description="t('lab.coverage.description')"
                :blocked-text="t('formulas.workflow.blocked_verdict')"
                data-testid="workflow-blocked"
            />

            <CoveragePanel v-if="showCoverage" :coverage="workflow.coverage" />

            <div class="flex flex-col gap-2 border-t border-line pt-4" data-testid="workflow-trials">
                <div class="flex flex-wrap items-center gap-2">
                    <h3 class="type-h6 text-ink">{{ t('lab.coverage.trials') }}</h3>
                    <Button size="sm" variant="ghost" icon="ph:chart-line" :href="workflow.urls.saltCurve" class="ms-auto" data-testid="workflow-salt-curve">{{ t('lab.salt_curve.open') }}</Button>
                    <Button size="sm" variant="secondary" icon="ph:plus" :href="workflow.urls.newTrial" data-testid="workflow-new-trial">{{ t('formulas.workflow.record_trial') }}</Button>
                </div>
                <p v-if="workflow.trials.length === 0" class="text-label text-ink-3">{{ t('lab.coverage.no_trials') }}</p>
                <ul v-else class="flex flex-col gap-2">
                    <li v-for="trial in workflow.trials" :key="trial.id" class="flex flex-wrap items-center gap-2">
                        <a :href="trial.href" class="text-primary-ink underline underline-offset-2"><bdi dir="ltr">{{ trial.code }}</bdi></a>
                        <StatusBadge kind="lab_trial_status" :status="trial.status" />
                        <Badge tone="neutral" size="sm">{{ t('lab.coverage.measured_n', { count: trial.measurements }) }}</Badge>
                        <bdi dir="ltr" class="tnum text-label text-ink-3">{{ trial.date }}</bdi>
                    </li>
                </ul>
            </div>

            <div class="flex flex-col gap-3 border-t border-line pt-4" data-testid="workflow-signatures">
                <h3 class="type-h6 text-ink">{{ t('formulas.workflow.signatures') }}</h3>
                <p v-if="items.length === 0" class="text-label text-ink-3">{{ t('formulas.workflow.no_signatures') }}</p>
                <Timeline v-else :items="items" compact>
                    <template #body="{ item }">
                        <p v-if="item.row.comment" class="mb-2 text-ink">{{ item.row.comment }}</p>
                        <p class="flex items-center gap-2">
                            <span class="text-ink-3">{{ t('formulas.workflow.hash') }}</span>
                            <bdi dir="ltr" class="min-w-0 break-all font-mono text-label text-ink-2" data-testid="signature-hash">{{ item.row.hash }}</bdi>
                            <CopyButton :value="item.row.hash" :label="t('formulas.workflow.hash')" />
                        </p>
                    </template>
                </Timeline>
            </div>
        </div>

        <Modal v-model:open="open" :title="t(`formulas.workflow.${move ?? 'approve'}_title`)" :description="t(`formulas.workflow.${move ?? 'approve'}_description`)" size="md">
            <form id="workflow-form" novalidate @submit.prevent="sign">
                <FormField
                    :label="rejecting ? t('formulas.workflow.reason') : t('formulas.workflow.comment')"
                    :hint="rejecting ? t('formulas.workflow.reason_hint') : t('formulas.workflow.comment_hint')"
                    :error="form.errors.reason ?? form.errors.comment"
                    :required="rejecting"
                >
                    <Textarea v-model="form.text" :rows="3" maxlength="1000" data-testid="workflow-text" />
                </FormField>
            </form>
            <template #footer>
                <Button variant="ghost" @click="open = false">{{ t('common.cancel') }}</Button>
                <Button type="submit" form="workflow-form" :variant="rejecting ? 'danger' : 'primary'" :loading="form.processing" data-testid="workflow-confirm">{{ t('formulas.workflow.confirm') }}</Button>
            </template>
        </Modal>
    </Card>
</template>
