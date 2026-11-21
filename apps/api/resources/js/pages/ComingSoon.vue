<script setup>
import { Head } from '@inertiajs/vue3';
import { Button, EmptyState } from '@kit';
import { findNavigationItem } from '../config/navigation';
import { useI18n } from '../composables/useI18n';

const props = defineProps({ section: { type: String, required: true } });

const { t } = useI18n();
const item = findNavigationItem(`/${props.section}`);
</script>

<template>
    <Head :title="item ? t(item.labelKey) : t('errors.coming_soon.title')" />
    <div class="kit-page is-narrow">
        <EmptyState
            :icon="item?.icon ?? 'ph:wrench'"
            :title="t('errors.coming_soon.title')"
            :description="t('errors.coming_soon.description')"
            variant="card"
            heading-tag="h1"
        >
            <template #actions>
                <Button href="/" icon="ph:squares-four">{{ t('common.back_home') }}</Button>
            </template>
        </EmptyState>
    </div>
</template>
