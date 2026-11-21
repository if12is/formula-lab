<script setup>
/**
 * The "tested on formula X" tag of a value (O-07): shown when the lab recorded this very dose or required HLB on a formula
 * whose stability result stood. It names the newest formula and counts the others; the hover says it is a local record (L1)
 * that changes nothing in the calculation. Renders nothing for a value without a record.
 *
 *   <TestedOnBadge :tags="tagsFor(line.material, 'required_hlb', line.hlb.value)" />
 */
import { computed } from 'vue';
import { Badge, Tooltip } from '@kit';
import { useI18n } from '../../composables/useI18n';

const props = defineProps({
    /** The engine's testedOnTags() for the value: [{ kind, materialId, value, marks }]. */
    tags: { type: Array, default: () => [] },
});

const { t } = useI18n();
const marks = computed(() => props.tags[0]?.marks ?? []);
const newest = computed(() => marks.value[0] ?? null);
</script>

<template>
    <Tooltip v-if="newest" :content="t('lab.tested.tag_hint')">
        <Badge tone="success" icon="ph:seal-check" size="sm" data-testid="tested-on" :data-formula="newest.formulaCode">
            {{ t('lab.tested.tag_prefix') }} <bdi dir="ltr">{{ newest.formulaCode }}</bdi> <bdi>{{ t('formulas.version_short', { version: newest.version }) }}</bdi>
            <template v-if="marks.length > 1"> · {{ t('lab.tested.tag_more', { count: marks.length - 1 }) }}</template>
        </Badge>
    </Tooltip>
</template>
