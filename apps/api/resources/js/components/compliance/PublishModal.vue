<script setup>
/**
 * Confirms publishing a draft rule set. The browser has already validated the rule set as it would be published, with the
 * engine's own validator (validateRuleSet), and shows its hash (hashRuleSet): the server validates again and refuses to
 * publish if the rules no longer hash to what was reviewed here. Publishing freezes the rule set for good.
 */
import { computed, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import { path } from '../../lib/routes';
import { Alert, Badge, Button, CopyButton, Modal } from '@kit';
import { useI18n } from '../../composables/useI18n';
import { publishPreview } from '../../lib/compliance';

const props = defineProps({
    ruleSet: { type: Object, required: true },
    assembled: { type: Object, required: true },
});

const open = defineModel('open', { type: Boolean, default: false });
const { t } = useI18n();
const processing = ref(false);

const preview = computed(() => publishPreview(props.assembled));
const empty = computed(() => props.assembled.rules.length === 0);

function publish() {
    router.post(path('compliance.publish', props.ruleSet.id), { expected_hash: preview.value.hash }, {
        preserveScroll: true,
        onStart: () => { processing.value = true; },
        onFinish: () => { processing.value = false; open.value = false; },
    });
}
</script>

<template>
    <Modal v-model:open="open" :title="t('compliance.publish.title')" :description="t('compliance.publish.description', { code: ruleSet.code, version: ruleSet.version })" size="md">
        <div class="flex flex-col gap-4" data-testid="publish-preview">
            <Alert tone="warning" icon="ph:lock-simple" :title="t('compliance.publish.irreversible_title')">{{ t('compliance.publish.irreversible') }}</Alert>

            <Alert v-if="empty" tone="danger" icon="ph:warning">{{ t('compliance.publish.no_rules') }}</Alert>
            <Alert v-else-if="!preview.valid" tone="danger" icon="ph:warning" :title="t('compliance.publish.invalid_title', { count: preview.issues.length })">
                <ul class="list-disc ps-5">
                    <li v-for="issue in preview.issues" :key="`${issue.property}-${issue.reason}`">
                        {{ t(`engine.${issue.reason}`) }} <bdi dir="ltr" class="font-mono text-label">{{ issue.property }}</bdi>
                    </li>
                </ul>
            </Alert>
            <template v-else>
                <p class="flex flex-wrap items-center gap-2 text-sm text-ink-2">
                    <Badge tone="success" icon="ph:check-circle">{{ t('compliance.publish.valid') }}</Badge>
                    {{ t('compliance.publish.rules_count', { count: assembled.rules.length }) }}
                </p>
                <div>
                    <p class="eyebrow mb-1 text-ink-3">{{ t('compliance.publish.hash') }}</p>
                    <p class="flex items-center gap-2">
                        <bdi dir="ltr" class="min-w-0 break-all font-mono text-label text-ink" data-testid="publish-hash">{{ preview.hash }}</bdi>
                        <CopyButton :value="preview.hash" :label="t('compliance.publish.copy_hash')" />
                    </p>
                    <p class="mt-1 text-label text-ink-3">{{ t('compliance.publish.hash_hint') }}</p>
                </div>
            </template>
        </div>
        <template #footer>
            <Button variant="ghost" @click="open = false">{{ t('common.cancel') }}</Button>
            <Button icon="ph:seal-check" :disabled="empty || !preview.valid" :loading="processing" data-testid="publish-confirm" @click="publish">{{ t('compliance.publish.confirm') }}</Button>
        </template>
    </Modal>
</template>
