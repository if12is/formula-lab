<script setup>
/**
 * The one wrapper of every printable page (S-17, U-03). It shows the page as usual with a "Print preview" button; the preview shows the
 * same content as the paper copy: a Daylight page with the watermark, the document header (what it is, what it concerns, the hash printed on it)
 * and the footer. "Print" first writes the print to the export log (who, what, when, hash: App\Services\Exports\ExportLogService), then opens the
 * browser's print window, where "Save as PDF" gives the PDF. If the log call fails nothing is sent to the printer.
 * The watermark, header and footer are print-media styles too, so the browser's own print menu also gets them (without an export reference,
 * which the page then says on paper).
 *
 * Use: PreviewShell with the `document` prop, the page's own title / description / back link, a named `actions` slot for page buttons
 * (they are hidden in the preview) and the page body as the default slot.
 *
 * `document` is the PrintDocument the server builds (App\Services\Exports\PrintDocumentService); null hides the preview (nothing to print yet).
 * Page controls that must not reach paper carry Tailwind's `print:hidden`; in the preview the same controls are hidden too.
 */
import { computed, nextTick, ref } from 'vue';
import { Badge, Button, PageHeader, toast } from '@kit';
import { useDateTime } from '../../composables/useDateTime';
import { useI18n } from '../../composables/useI18n';
import { postJson } from '../../lib/http';

const props = defineProps({
    document: { type: Object, default: null },
    title: { type: String, required: true },
    description: { type: String, default: null },
    back: { type: String, default: null },
    backLabel: { type: String, default: null },
    /** `kit-page is-full` for wide tables. */
    full: { type: Boolean, default: false },
});

const { t } = useI18n();
const { when } = useDateTime();

const WATERMARK_COPIES = 6;
// Class lists as constants: the paper in the preview, and "shown on screen only in the preview, always in print".
const PAPER_CLASSES = 'mx-auto max-w-5xl overflow-hidden rounded-lg border border-line bg-surface p-4 text-ink sm:p-8 print:max-w-none print:rounded-none print:border-0 print:p-0';
const PRINT_ONLY = 'hidden print:block';

// `?preview=1` opens the preview directly (a link to the printable copy).
const previewing = ref(props.document !== null && new URLSearchParams(window.location.search).get('preview') === '1');
const busy = ref(false);
const printed = ref(null);

const hashLabel = computed(() => t(props.document?.kind === 'lot_trace' ? 'print.hash_label_trace' : 'print.hash_label_version'));
const watermarkText = computed(() => t(`print.watermark.${props.document?.watermark ?? 'neutral'}`));
const paperNote = computed(() => {
    if (printed.value) {
        return null;
    }

    return t(previewing.value ? 'print.not_printed_yet' : 'print.unlogged');
});

async function print() {
    busy.value = true;

    try {
        const { document } = props;
        const result = await postJson(document.log_url, { kind: document.kind, subject_id: document.subject_id, lot: document.lot, material_id: document.material_id });

        if (!result.ok) {
            throw new Error('rejected');
        }

        printed.value = result.data;
        await nextTick();
        window.print();
    } catch {
        toast.error(t('print.log_failed'));
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <div class="kit-page" :class="{ 'is-full': full }">
        <!-- The preview toolbar: it is the only thing around the paper. -->
        <div v-if="previewing" class="z-10 mb-4 flex flex-wrap items-center gap-3 rounded-lg border border-line bg-surface p-3 sm:sticky sm:top-0 print:hidden" data-testid="preview-toolbar">
            <Badge tone="info" icon="ph:eye">{{ t('print.preview') }}</Badge>
            <p class="w-full min-w-0 text-label text-ink-2 sm:w-auto sm:flex-1">{{ t('print.preview_hint') }} {{ t('print.save_pdf_hint') }}</p>
            <Button variant="ghost" icon="ph:arrow-right" data-testid="close-preview" @click="previewing = false">{{ t('print.close') }}</Button>
            <Button icon="ph:printer" :loading="busy" data-testid="print-document" @click="print">{{ busy ? t('print.printing') : t('print.print') }}</Button>
        </div>

        <!-- Daylight inks on the paper whatever the theme (the kit's data-mode="light"), exactly what the printer gets. -->
        <div
            class="fl-paper relative"
            :class="previewing ? PAPER_CLASSES : ''"
            :data-mode="previewing ? 'light' : undefined"
            :data-previewing="previewing"
            data-testid="print-paper"
        >
            <!-- Absolute on screen, fixed in print so it repeats on every printed page. -->
            <div
                v-if="document"
                class="pointer-events-none absolute inset-0 z-0 flex-wrap content-around items-center justify-around gap-y-24 overflow-hidden print:fixed print:flex"
                :class="previewing ? 'flex' : 'hidden'"
                aria-hidden="true"
                data-testid="watermark"
                :data-watermark="document.watermark"
            >
                <span v-for="n in WATERMARK_COPIES" :key="n" class="type-h2 -rotate-25 whitespace-nowrap font-bold text-ink-3 opacity-25">{{ watermarkText }}</span>
            </div>

            <div class="relative z-10 flex flex-col">
                <section
                    v-if="document"
                    class="mb-4 rounded-lg border border-line p-3 text-label"
                    :class="previewing ? '' : PRINT_ONLY"
                    data-testid="paper-header"
                >
                    <dl class="grid gap-x-6 gap-y-2 sm:grid-cols-2">
                        <div><dt class="text-ink-3">{{ t('print.document') }}</dt><dd class="font-semibold text-ink">{{ t(`enums.export_kind.${document.kind}`) }}</dd></div>
                        <div><dt class="text-ink-3">{{ t('print.subject') }}</dt><dd class="font-semibold text-ink"><bdi dir="ltr">{{ document.subject_label }}</bdi></dd></div>
                        <div class="sm:col-span-2"><dt class="text-ink-3">{{ hashLabel }}</dt><dd class="break-all font-mono text-ink" data-testid="paper-hash"><bdi dir="ltr">{{ document.hash }}</bdi></dd></div>
                        <template v-if="printed">
                            <div><dt class="text-ink-3">{{ t('print.printed_at') }}</dt><dd class="text-ink" data-testid="paper-printed-at"><bdi>{{ when(printed.exported_at) }}</bdi></dd></div>
                            <div><dt class="text-ink-3">{{ t('print.reference') }}</dt><dd class="font-mono text-ink" data-testid="paper-reference"><bdi dir="ltr">{{ printed.reference }}</bdi></dd></div>
                        </template>
                        <p v-else class="text-ink-3 sm:col-span-2" data-testid="paper-note">{{ paperNote }}</p>
                    </dl>
                </section>

                <PageHeader :title="title" :description="description" :back="previewing ? null : back" :back-label="backLabel">
                    <template v-if="!previewing" #actions>
                        <span class="flex flex-wrap gap-2 print:hidden">
                            <slot name="actions" />
                            <Button v-if="document" variant="secondary" icon="ph:eye" data-testid="open-preview" @click="previewing = true">{{ t('print.preview') }}</Button>
                        </span>
                    </template>
                </PageHeader>

                <slot />

                <footer v-if="document" class="mt-6 text-label text-ink-3" :class="previewing ? '' : PRINT_ONLY" data-testid="paper-footer">
                    {{ t('print.footer') }} <bdi dir="ltr" class="break-all font-mono">{{ document.hash }}</bdi>
                </footer>
            </div>
        </div>
    </div>
</template>

<style>
/* In the preview the controls that print:hidden removes from paper are removed too, so the preview is the copy. */
.fl-paper[data-previewing='true'] .print\:hidden {
    display: none !important;
}
</style>
