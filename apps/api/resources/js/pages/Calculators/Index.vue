<script setup>
/**
 * The calculators, as cards grouped by what they do. The list, the groups and the addresses come from
 * config/calculators.php; titles and descriptions from lang (calculators.items.<key>.*).
 */
import { Head, Link } from '@inertiajs/vue3';
import { Card, IconTile, PageHeader } from '@kit';
import { useI18n } from '../../composables/useI18n';
import { calculatorIcons, fallbackCalculatorIcon } from '../../config/calculators';

defineProps({
    groups: { type: Array, required: true },
});

const { t } = useI18n();
</script>

<template>
    <Head :title="t('calculators.title')" />
    <div class="kit-page">
        <PageHeader :title="t('calculators.title')" :description="t('calculators.description')" />

        <section v-for="group in groups" :key="group.group" class="mb-8" :data-group="group.group">
            <h2 class="type-h5 mb-3 text-ink">{{ t(`calculators.groups.${group.group}`) }}</h2>
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                <Card v-for="item in group.items" :key="item.key" :as="Link" :href="item.href" interactive :data-calculator="item.key">
                    <div class="flex items-start gap-3">
                        <IconTile :icon="calculatorIcons[item.key] ?? fallbackCalculatorIcon" />
                        <div class="min-w-0">
                            <h3 class="type-h6 text-ink">{{ t(`calculators.items.${item.key}.title`) }}</h3>
                            <p class="mt-1 text-label text-ink-3">{{ t(`calculators.items.${item.key}.description`) }}</p>
                        </div>
                    </div>
                </Card>
            </div>
        </section>
    </div>
</template>
