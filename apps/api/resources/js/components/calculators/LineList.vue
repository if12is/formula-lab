<script setup>
/**
 * The repeated lines of a calculator (oils, blend components, emulsifiers, Davies groups): a bordered row per line with a
 * remove button, and "add" under the list. The row's controls are the #line slot; the page owns the lines and handles
 * `add` and `remove`. At least one line stays (removing the last asks for nothing: the button is disabled).
 *
 *   <LineList :lines="state.lines" add-label-key="calculators.soap.add_oil" remove-label-key="calculators.soap.remove_oil" @add="add" @remove="remove">
 *       (the controls of one line go in the #line slot: { line, index })
 *   </LineList>
 */
import { Button, IconButton } from '@kit';
import { useI18n } from '../../composables/useI18n';

defineProps({
    lines: { type: Array, required: true },
    addLabelKey: { type: String, required: true },
    /** Names the remove button of line {n}. */
    removeLabelKey: { type: String, required: true },
    minLines: { type: Number, default: 1 },
});

defineEmits(['add', 'remove']);
const { t } = useI18n();
</script>

<template>
    <div class="flex flex-col gap-3">
        <ol class="flex flex-col gap-3" data-testid="lines">
            <li v-for="(line, index) in lines" :key="line.key" data-testid="line" class="flex flex-col gap-4 rounded-lg border border-line p-3">
                <div class="flex flex-col gap-4">
                    <slot name="line" :line="line" :index="index" />
                </div>
                <div class="flex justify-end">
                    <IconButton
                        icon="ph:trash"
                        variant="danger-soft"
                        size="sm"
                        :label="t(removeLabelKey, { n: index + 1 })"
                        :disabled="lines.length <= minLines"
                        @click="$emit('remove', line.key)"
                    />
                </div>
            </li>
        </ol>
        <div>
            <Button type="button" variant="secondary" size="sm" icon="ph:plus" @click="$emit('add')">{{ t(addLabelKey) }}</Button>
        </div>
    </div>
</template>
