<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import ListaCapturados from '@/components/ListaCapturados.vue';
import { dashboard } from '@/routes';
import { data as capturadosData } from '@/routes/capturados';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: dashboard() },
            { title: 'Capturados', href: '/capturados' },
        ],
    },
});

type Seccion = { id: number; numero: number };
type Modo = { valor: string; etiqueta: string };

defineProps<{
    secciones: Seccion[];
    seccionesFiltro: Seccion[];
    modos: Modo[];
}>();
</script>

<template>
    <Head title="Capturados" />

    <div class="flex h-full flex-1 flex-col gap-4 p-4">
        <h1 class="text-xl font-semibold">Capturados</h1>

        <ListaCapturados
            :build-url="(query) => capturadosData.url({ query })"
            :secciones-catalogo="secciones"
            :secciones-filtro="seccionesFiltro"
            :modos="modos"
        />
    </div>
</template>
