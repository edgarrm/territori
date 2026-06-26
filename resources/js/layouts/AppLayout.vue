<script setup lang="ts">
import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';
import AppLayout from '@/layouts/app/AppSidebarLayout.vue';
import type { BreadcrumbItem } from '@/types';

const { breadcrumbs = [] } = defineProps<{
    breadcrumbs?: BreadcrumbItem[];
}>();

const marca = computed(() => usePage().props.marca as { nombre: string; color: string; logo_url: string | null } | undefined);
const brandStyle = computed(() => ({ '--brand': marca.value?.color ?? '#1d4ed8' }));
</script>

<template>
    <div :style="brandStyle">
        <AppLayout :breadcrumbs="breadcrumbs">
            <slot />
        </AppLayout>
    </div>
</template>
