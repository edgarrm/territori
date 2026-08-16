<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import FormularioEncuesta from '@/components/FormularioEncuesta.vue';
import { useBreadcrumbs } from '@/composables/useBreadcrumbs';
import { store as respuestasStore } from '@/routes/encuestas/respuestas';

type Pregunta = {
    id: number;
    etiqueta: string;
    tipo: 'opcion_unica' | 'texto_libre' | 'numerica';
    opciones: string[] | null;
};

type Seccion = { id: number; numero: number };

const props = defineProps<{
    encuesta: {
        id: number;
        nombre: string;
        ambito_tipo: string;
        preguntas: Pregunta[];
    };
    secciones: Seccion[];
}>();

useBreadcrumbs([
    { title: 'Encuestas', href: '/encuestas' },
    { title: props.encuesta.nombre, href: `/encuestas/${props.encuesta.id}/captura` },
]);
</script>

<template>
    <Head :title="encuesta.nombre" />

    <FormularioEncuesta
        :nombre="encuesta.nombre"
        :ambito-tipo="encuesta.ambito_tipo"
        :preguntas="encuesta.preguntas"
        :secciones="secciones"
        :post-url="respuestasStore.url(encuesta.id)"
    />
</template>
