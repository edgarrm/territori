<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import ListaCapturados from '@/components/ListaCapturados.vue';
import { data as misCapturadosData } from '@/routes/mis-capturados';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Mis capturados', href: '/mis-capturados' }],
    },
});

type Seccion = { id: number; numero: number };
type Modo = { valor: string; etiqueta: string };

defineProps<{
    seccionesFiltro: Seccion[];
    modos: Modo[];
}>();
</script>

<template>
    <Head title="Mis capturados" />

    <div class="flex h-full flex-1 flex-col gap-4 p-4">
        <h1 class="text-xl font-semibold">Mis capturados</h1>

        <ListaCapturados
            :build-url="(query) => misCapturadosData.url({ query })"
            :secciones-filtro="seccionesFiltro"
            :modos="modos"
            dusk="mis-capturados"
        />
    </div>
</template>
