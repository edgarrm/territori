<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import campana from '@/routes/campana';

type Partido = { id: number; siglas: string; nombre: string; color: string };
type Indicadores = {
    competitividad: boolean;
    tipo_seccion: boolean;
    indice_neutral: boolean;
    oportunidad: boolean;
};
type Configuracion = {
    umbral_ganada_franca: number;
    umbral_alfa: number;
    umbral_beta: number;
    indicadores: Indicadores;
};

const props = defineProps<{
    partidos: Partido[];
    partidoId: number | null;
    configuracion: Configuracion;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Campaña',
                href: campana.edit(),
            },
        ],
    },
});

const partidoSeleccionado = computed(() =>
    props.partidos.find((p) => p.id === form.partido_id),
);

const indicadoresLista: { key: keyof Indicadores; label: string }[] = [
    { key: 'competitividad', label: 'Competitividad por partido' },
    { key: 'tipo_seccion', label: 'Tipo de sección (Alfa/Beta/Gama)' },
    { key: 'indice_neutral', label: 'Índice de prioridad neutral' },
    { key: 'oportunidad', label: 'Oportunidad demográfica' },
];

const form = useForm({
    partido_id: props.partidoId as number | null,
    umbral_ganada_franca: props.configuracion.umbral_ganada_franca,
    umbral_alfa: props.configuracion.umbral_alfa,
    umbral_beta: props.configuracion.umbral_beta,
    indicadores: { ...props.configuracion.indicadores },
});

function guardar() {
    form.patch(campana.update.url());
}
</script>

<template>
    <Head title="Campaña" />

    <h1 class="sr-only">Configuración de campaña</h1>

    <div class="flex flex-col space-y-6">
        <Heading
            variant="small"
            title="Campaña"
            description="Configura el partido, los umbrales y los indicadores del análisis electoral"
        />

        <form class="space-y-6" @submit.prevent="guardar">
            <div class="grid gap-1.5">
                <Label>Partido de la campaña</Label>
                <Select
                    :model-value="
                        form.partido_id ? String(form.partido_id) : ''
                    "
                    @update:model-value="
                        (v) => (form.partido_id = v ? Number(v) : null)
                    "
                >
                    <SelectTrigger>
                        <SelectValue placeholder="Sin partido configurado">
                            <span
                                v-if="partidoSeleccionado"
                                class="flex items-center gap-2"
                            >
                                <span
                                    class="inline-block size-3 rounded-full"
                                    :style="{
                                        backgroundColor:
                                            partidoSeleccionado.color,
                                    }"
                                />
                                {{ partidoSeleccionado.siglas }} —
                                {{ partidoSeleccionado.nombre }}
                            </span>
                        </SelectValue>
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem
                            v-for="partido in partidos"
                            :key="partido.id"
                            :value="String(partido.id)"
                        >
                            <span class="flex items-center gap-2">
                                <span
                                    class="inline-block size-3 rounded-full"
                                    :style="{ backgroundColor: partido.color }"
                                />
                                {{ partido.siglas }} — {{ partido.nombre }}
                            </span>
                        </SelectItem>
                    </SelectContent>
                </Select>
                <InputError :message="form.errors.partido_id" />
            </div>

            <div class="grid gap-4 sm:grid-cols-3">
                <div class="grid gap-1.5">
                    <Label for="umbral_ganada_franca"
                        >Umbral ganada franca</Label
                    >
                    <Input
                        id="umbral_ganada_franca"
                        v-model.number="form.umbral_ganada_franca"
                        type="number"
                        min="1"
                    />
                    <InputError :message="form.errors.umbral_ganada_franca" />
                </div>

                <div class="grid gap-1.5">
                    <Label for="umbral_alfa">Umbral Alfa</Label>
                    <Input
                        id="umbral_alfa"
                        v-model.number="form.umbral_alfa"
                        type="number"
                        min="1"
                    />
                    <InputError :message="form.errors.umbral_alfa" />
                </div>

                <div class="grid gap-1.5">
                    <Label for="umbral_beta">Umbral Beta</Label>
                    <Input
                        id="umbral_beta"
                        v-model.number="form.umbral_beta"
                        type="number"
                        min="1"
                    />
                    <InputError :message="form.errors.umbral_beta" />
                </div>
            </div>

            <div class="grid gap-3">
                <Label>Indicadores visibles</Label>
                <label
                    v-for="indicador in indicadoresLista"
                    :key="indicador.key"
                    class="flex items-center gap-2 text-sm"
                >
                    <input
                        v-model="form.indicadores[indicador.key]"
                        type="checkbox"
                        class="size-4 rounded border-input"
                    />
                    {{ indicador.label }}
                </label>
            </div>

            <div class="flex items-center gap-4">
                <Button type="submit" :disabled="form.processing"
                    >Guardar</Button
                >
            </div>
        </form>
    </div>
</template>
