<script setup>
/** U-07: a screen that exists only to show costs, prices or the recipe, asked for by a role that is not sent them. The server says why (a translated sentence). */
import { Head } from '@inertiajs/vue3';
import { Button, EmptyState } from '@kit';
import { useI18n } from '../../composables/useI18n';

defineProps({ message: { type: String, required: true } });

const { t } = useI18n();
</script>

<template>
    <Head :title="t('errors.forbidden.title')" />
    <div class="kit-page is-narrow" data-testid="forbidden">
        <EmptyState icon="ph:lock-key" :title="t('errors.forbidden.title')" :description="message" variant="card" heading-tag="h1">
            <template #actions>
                <Button href="/" icon="ph:squares-four">{{ t('common.back_home') }}</Button>
            </template>
        </EmptyState>
    </div>
</template>
