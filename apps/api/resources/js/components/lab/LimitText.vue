<script setup>
/**
 * An acceptance limit of a measured property: "from a to b", a one-sided limit, or "No limit defined" when the owner has
 * not supplied one (config/lab.php). A missing limit is never read as a pass: the value simply is not evaluated.
 *
 *   <LimitText :limits="{ min: null, max: null }" />
 */
import { computed } from 'vue';
import { useI18n } from '../../composables/useI18n';

const props = defineProps({
    limits: { type: Object, required: true },
});

const { t } = useI18n();
const text = computed(() => {
    const { min, max } = props.limits;

    if (min !== null && max !== null) {
        return t('lab.limits.range', { min, max });
    }
    if (min !== null) {
        return t('lab.limits.min_only', { min });
    }

    return max !== null ? t('lab.limits.max_only', { max }) : t('lab.limits.none');
});
</script>

<template>
    <span class="text-ink-3"><bdi>{{ text }}</bdi></span>
</template>
