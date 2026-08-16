<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import FormularioEncuesta from '@/components/FormularioEncuesta.vue';
import { store as respuestaPublicaStore } from '@/routes/encuestas/publica';

type Pregunta = {
    id: number;
    etiqueta: string;
    tipo: 'opcion_unica' | 'texto_libre' | 'numerica';
    opciones: string[] | null;
};

type Seccion = { id: number; numero: number };

const props = defineProps<{
    token: string;
    nombre: string;
    ambito_tipo: string;
    secciones: Seccion[];
    preguntas: Pregunta[];
}>();
</script>

<template>
    <Head :title="props.nombre" />

    <div class="flex min-h-screen flex-col bg-background">
        <FormularioEncuesta
            :nombre="props.nombre"
            :ambito-tipo="props.ambito_tipo"
            :preguntas="props.preguntas"
            :secciones="props.secciones"
            :post-url="respuestaPublicaStore.url(props.token)"
            :aviso-transparencia="true"
        />
    </div>
</template>
