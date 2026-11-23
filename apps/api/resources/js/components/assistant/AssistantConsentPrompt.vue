<script setup>
/**
 * The consent prompt (P-10): formula content, costs and supplier certificates reach the Claude service only after the user allows each
 * category for this session. Used by the assistant panel and by the COA upload, so the wording and the rules live here once.
 * Nothing is ticked beforehand: consent is an act, not a default.
 */
import { computed, onMounted, ref } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { Alert, Button, Checkbox, FormField } from '@kit';
import { useI18n } from '../../composables/useI18n';
import { useAssistantConsent } from '../../composables/useAssistantConsent';
import { useDateTime } from '../../composables/useDateTime';

const props = defineProps({
    /** Limit the choices to these category values (the COA upload only needs `supplier_certificate`). Empty = all. */
    only: { type: Array, default: () => [] },
    /** A sentence that says why consent is needed here, shown above the choices. */
    reasonKey: { type: String, default: null },
});

const emit = defineEmits(['granted', 'revoked']);
const { t } = useI18n();
const page = usePage();
const consent = useAssistantConsent();
const dateTime = useDateTime();

const chosen = ref([]);
const missingChoice = ref(false);

const categories = computed(() => (page.props.assistant?.categories ?? []).filter((category) => !props.only.length || props.only.includes(category.value)));
const allowedLabels = computed(() => categories.value.filter((category) => consent.state.categories.includes(category.value)).map((category) => category.label).join(t('common.list_separator')));

onMounted(() => {
    if (!consent.state.loaded) {
        consent.load();
    }
});

async function allow() {
    missingChoice.value = chosen.value.length === 0;
    if (missingChoice.value) {
        return;
    }
    if (await consent.grant(chosen.value)) {
        chosen.value = [];
        emit('granted');
    }
}

async function revoke() {
    if (await consent.revoke()) {
        emit('revoked');
    }
}
</script>

<template>
    <div data-testid="consent-prompt" :data-granted="consent.state.granted ? 'yes' : 'no'">
        <Alert v-if="consent.state.granted" tone="success" icon="ph:shield-check" :title="t('assistant_ui.consent.allowed_title')" data-testid="consent-granted">
            {{ t('assistant_ui.consent.allowed_until', { categories: allowedLabels || consent.state.categories.join(t('common.list_separator')), time: consent.state.expiresAt ? dateTime.time(consent.state.expiresAt) : t('common.no_data') }) }}
            <template #actions>
                <Button size="sm" variant="secondary" icon="ph:hand-palm" :loading="consent.state.busy" data-testid="consent-revoke" @click="revoke">{{ t('assistant_ui.consent.revoke') }}</Button>
            </template>
        </Alert>

        <form v-else class="flex flex-col gap-3" novalidate @submit.prevent="allow">
            <div class="flex flex-col gap-1">
                <h3 class="type-h6 text-ink">{{ t('assistant_ui.consent.title') }}</h3>
                <p class="type-body text-ink-2">{{ reasonKey ? t(reasonKey) : t('assistant_ui.consent.intro') }}</p>
            </div>
            <FormField :label="t('assistant_ui.consent.legend')" :error="missingChoice ? t('assistant_ui.consent.pick_one') : null">
                <div class="flex flex-col gap-2">
                    <Checkbox
                        v-for="category in categories"
                        :key="category.value"
                        v-model="chosen"
                        :value="category.value"
                        :label="category.label"
                        :description="t(`assistant_ui.consent.category_hint.${category.value}`)"
                        :data-testid="`consent-category-${category.value}`"
                    />
                </div>
            </FormField>
            <p class="type-caption text-ink-3">{{ t('assistant_ui.consent.always_sent') }}</p>
            <Alert v-if="consent.state.failed" tone="danger">{{ t('assistant_ui.consent.failed') }}</Alert>
            <div>
                <Button type="submit" icon="ph:shield-check" :loading="consent.state.busy" data-testid="consent-allow">{{ t('assistant_ui.consent.allow') }}</Button>
            </div>
        </form>
    </div>
</template>
