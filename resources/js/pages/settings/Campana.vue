<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { ArrowDown, ArrowUp, Plus, Trash2 } from '@lucide/vue';
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
type Categoria = { nombre: string; color: string; umbral: number | null };
type ModoCalculo = 'votos' | 'porcentaje';
type Configuracion = {
    umbral_alfa: number;
    umbral_beta: number;
    modo_calculo_competitividad: ModoCalculo;
    categorias_competitividad: Categoria[];
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
    umbral_alfa: props.configuracion.umbral_alfa,
    umbral_beta: props.configuracion.umbral_beta,
    modo_calculo_competitividad:
        props.configuracion.modo_calculo_competitividad,
    categorias_competitividad:
        props.configuracion.categorias_competitividad.map(
            ({ nombre, color, umbral }) => ({ nombre, color, umbral }),
        ),
    indicadores: { ...props.configuracion.indicadores },
});

function esCatchAll(indice: number): boolean {
    return indice === form.categorias_competitividad.length - 1;
}

function agregarCategoria() {
    form.categorias_competitividad.splice(
        form.categorias_competitividad.length - 1,
        0,
        { nombre: '', color: '#3b82f6', umbral: 0 },
    );
}

function eliminarCategoria(indice: number) {
    if (form.categorias_competitividad.length <= 1) {
        return;
    }

    form.categorias_competitividad.splice(indice, 1);
}

function moverCategoria(indice: number, direccion: -1 | 1) {
    const destino = indice + direccion;

    if (destino < 0 || destino >= form.categorias_competitividad.length) {
        return;
    }

    const lista = form.categorias_competitividad;
    [lista[indice], lista[destino]] = [lista[destino], lista[indice]];
}

function errorCategoria(indice: number, campo: 'nombre' | 'color' | 'umbral') {
    return (form.errors as Record<string, string | undefined>)[
        `categorias_competitividad.${indice}.${campo}`
    ];
}

function actualizarUmbral(indice: number, valor: string | number) {
    form.categorias_competitividad[indice].umbral =
        valor === '' ? null : Number(valor);
}

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
            description="Configura el partido, las categorías de competitividad y los indicadores del análisis electoral"
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

            <div class="grid gap-1.5">
                <Label>Modo de cálculo de competitividad</Label>
                <Select
                    :model-value="form.modo_calculo_competitividad"
                    @update:model-value="
                        (v) =>
                            (form.modo_calculo_competitividad =
                                v as ModoCalculo)
                    "
                >
                    <SelectTrigger>
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem value="votos">Votos absolutos</SelectItem>
                        <SelectItem value="porcentaje"
                            >% de votos de la sección</SelectItem
                        >
                    </SelectContent>
                </Select>
                <InputError
                    :message="form.errors.modo_calculo_competitividad"
                />
            </div>

            <div class="grid gap-3">
                <div class="flex items-center justify-between">
                    <Label>Categorías de competitividad</Label>
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        @click="agregarCategoria"
                    >
                        <Plus class="size-4" /> Agregar categoría
                    </Button>
                </div>
                <p class="text-sm text-muted-foreground">
                    Se evalúan de arriba hacia abajo: cada sección recibe la
                    primera categoría cuyo umbral alcance. La última fila es el
                    "resto de los casos" (sin umbral).
                </p>

                <div
                    v-for="(
                        categoria, indice
                    ) in form.categorias_competitividad"
                    :key="indice"
                    class="grid grid-cols-[auto_1fr_auto_auto_auto] items-start gap-2 rounded-md border p-3"
                >
                    <div class="flex flex-col gap-1 pt-1.5">
                        <button
                            type="button"
                            class="text-muted-foreground hover:text-foreground disabled:opacity-30"
                            :disabled="indice === 0"
                            @click="moverCategoria(indice, -1)"
                        >
                            <ArrowUp class="size-4" />
                        </button>
                        <button
                            type="button"
                            class="text-muted-foreground hover:text-foreground disabled:opacity-30"
                            :disabled="esCatchAll(indice)"
                            @click="moverCategoria(indice, 1)"
                        >
                            <ArrowDown class="size-4" />
                        </button>
                    </div>

                    <div class="grid gap-1.5">
                        <Input
                            v-model="categoria.nombre"
                            placeholder="Nombre de la categoría"
                        />
                        <InputError
                            :message="errorCategoria(indice, 'nombre')"
                        />
                    </div>

                    <div class="grid gap-1.5">
                        <div class="flex items-center gap-2">
                            <input
                                v-model="categoria.color"
                                type="color"
                                class="size-9 cursor-pointer rounded border border-input"
                            />
                            <Input
                                v-model="categoria.color"
                                class="w-24"
                                maxlength="7"
                            />
                        </div>
                        <InputError
                            :message="errorCategoria(indice, 'color')"
                        />
                    </div>

                    <div class="grid gap-1.5">
                        <Input
                            v-if="!esCatchAll(indice)"
                            :model-value="categoria.umbral ?? ''"
                            type="number"
                            class="w-28"
                            @update:model-value="
                                (v) => actualizarUmbral(indice, v)
                            "
                        />
                        <Input
                            v-else
                            class="w-28"
                            model-value="Resto de secciones"
                            disabled
                        />
                        <InputError
                            :message="errorCategoria(indice, 'umbral')"
                        />
                    </div>

                    <button
                        type="button"
                        class="text-muted-foreground hover:text-destructive disabled:opacity-30"
                        :disabled="form.categorias_competitividad.length <= 1"
                        @click="eliminarCategoria(indice)"
                    >
                        <Trash2 class="size-4" />
                    </button>
                </div>
                <InputError :message="form.errors.categorias_competitividad" />
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
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
