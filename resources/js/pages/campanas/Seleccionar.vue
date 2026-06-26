<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';

defineProps<{
    campanas: { tenant_id: number; nombre: string; rol: string }[];
}>();

const form = useForm({ tenant_id: null as number | null });

function seleccionar(tenantId: number) {
    form.tenant_id = tenantId;
    form.post('/campanas/seleccionar');
}
</script>

<template>
    <Head title="Selecciona una campaña" />

    <div class="mx-auto flex max-w-md flex-col gap-4 p-6">
        <h1 class="text-xl font-semibold">Selecciona una campaña</h1>
        <button
            v-for="campana in campanas"
            :key="campana.tenant_id"
            type="button"
            class="rounded-lg border p-4 text-left hover:bg-muted"
            @click="seleccionar(campana.tenant_id)"
        >
            <div class="font-medium">{{ campana.nombre }}</div>
            <div class="text-sm text-muted-foreground">{{ campana.rol }}</div>
        </button>
    </div>
</template>
