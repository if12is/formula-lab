<script setup>
/**
 * The assistant panel (phase P): a drawer with the privacy consent prompt (P-10) and the conversation. The assistant never calculates: the
 * server runs it against the engine and every number in a reply was checked there. The history sent with a question is what is shown here, so
 * "new conversation" really starts from nothing.
 */
import { computed, nextTick, ref, watch } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { Alert, Badge, Button, Drawer, EmptyState, Textarea } from '@kit';
import AssistantConsentPrompt from './AssistantConsentPrompt.vue';
import { useI18n } from '../../composables/useI18n';

const open = defineModel('open', { type: Boolean, default: false });
const { t } = useI18n();
const page = usePage();

const messages = ref([]);
const question = ref('');
const sending = ref(false);
const error = ref(null);
const scroller = ref(null);

const settings = computed(() => page.props.assistant ?? {});
const tooLong = computed(() => question.value.length > settings.value.maxMessageChars);
const canSend = computed(() => question.value.trim() !== '' && !tooLong.value && !sending.value);

watch(messages, async () => {
    await nextTick();
    scroller.value?.scrollTo({ top: scroller.value.scrollHeight });
}, { deep: true });

async function send() {
    if (!canSend.value) {
        return;
    }

    const asked = question.value.trim();
    const history = messages.value.map(({ role, text }) => ({ role, text }));
    messages.value.push({ role: 'user', text: asked });
    question.value = '';
    error.value = null;
    sending.value = true;

    try {
        const response = await window.axios.post(settings.value.askUrl, { question: asked, history }, { headers: { Accept: 'application/json' } });
        messages.value.push({ role: 'assistant', text: response.data.reply, tools: response.data.tools ?? [], status: response.data.status });
    } catch (failure) {
        error.value = failure.response?.data?.message ?? t('assistant_ui.chat.failed');
    } finally {
        sending.value = false;
    }
}

function restart() {
    messages.value = [];
    error.value = null;
}

function onKeydown(event) {
    if (event.key === 'Enter' && (event.ctrlKey || event.metaKey)) {
        event.preventDefault();
        send();
    }
}
</script>

<template>
    <Drawer v-model:open="open" :title="t('assistant_ui.panel.title')" :description="t('assistant_ui.panel.description')" size="md">
        <div class="flex min-h-0 flex-1 flex-col gap-4" data-testid="assistant-panel">
            <AssistantConsentPrompt />

            <div ref="scroller" class="flex min-h-40 flex-1 flex-col gap-3 overflow-y-auto" data-testid="assistant-messages" aria-live="polite">
                <EmptyState v-if="messages.length === 0" compact icon="ph:chats-circle" :title="t('assistant_ui.chat.empty_title')" :description="t('assistant_ui.chat.empty_text')" />

                <div
                    v-for="(message, index) in messages"
                    :key="index"
                    class="flex flex-col gap-1 rounded-lg border border-line p-3"
                    :class="message.role === 'user' ? 'bg-surface-2' : 'bg-surface'"
                    :data-testid="`assistant-message-${message.role}`"
                >
                    <span class="type-caption text-ink-3">{{ message.role === 'user' ? t('assistant_ui.chat.you') : t('assistant_ui.chat.assistant') }}</span>
                    <p class="type-body whitespace-pre-wrap text-ink" dir="auto">{{ message.text }}</p>
                    <div v-if="message.tools?.length" class="flex flex-wrap gap-1">
                        <Badge v-for="tool in [...new Set(message.tools)]" :key="tool" size="sm" tone="neutral"><bdi dir="ltr">{{ tool }}</bdi></Badge>
                    </div>
                    <Alert v-if="message.status === 'fallback'" tone="warning" icon="ph:warning">{{ t('assistant_ui.chat.fallback') }}</Alert>
                </div>

                <Alert v-if="error" tone="danger" data-testid="assistant-error">{{ error }}</Alert>
            </div>
        </div>

        <template #footer>
            <div class="flex w-full flex-col gap-2">
                <Textarea
                    v-model="question"
                    :rows="2"
                    :max-rows="5"
                    :aria-label="t('assistant_ui.chat.label')"
                    :placeholder="t('assistant_ui.chat.placeholder')"
                    :error="tooLong ? t('assistant_ui.chat.too_long', { max: settings.maxMessageChars }) : null"
                    data-testid="assistant-input"
                    @keydown="onKeydown"
                />
                <div class="flex items-center justify-between gap-2">
                    <Button variant="ghost" size="sm" icon="ph:arrow-counter-clockwise" :disabled="messages.length === 0 || sending" data-testid="assistant-new" @click="restart">{{ t('assistant_ui.chat.new') }}</Button>
                    <Button icon="ph:paper-plane-tilt" :loading="sending" :disabled="!canSend" data-testid="assistant-send" @click="send">{{ sending ? t('assistant_ui.chat.sending') : t('assistant_ui.chat.send') }}</Button>
                </div>
            </div>
        </template>
    </Drawer>
</template>
