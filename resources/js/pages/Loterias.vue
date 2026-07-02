<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { dashboard } from '@/routes';
import {
    electores as loteriaElectores,
    store as loteriaStore,
} from '@/routes/loterias';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: dashboard() },
            { title: 'Loterías', href: '/loterias' },
        ],
    },
});

type Loteria = {
    id: number;
    nombre: string;
    fecha: string;
    seccion_id: number;
    encargado: string | null;
    capturados_count: number;
};

type Seccion = { id: number; numero: number };

const props = defineProps<{ loterias: Loteria[]; secciones: Seccion[] }>();

function seccionLabel(seccionId: number): string {
    const numero = props.secciones.find((s) => s.id === seccionId)?.numero;

    return numero !== undefined ? `Sección ${numero}` : 'Sección';
}

const form = useForm({
    nombre: '',
    fecha: '',
    seccion_id: props.secciones[0]?.id ?? (null as number | null),
});

function crear() {
    form.post(loteriaStore.url(), {
        preserveScroll: true,
        onSuccess: () => form.reset('nombre', 'fecha'),
    });
}

// Capturados bajo demanda por lotería.
const capturados = ref<
    Record<
        number,
        Array<{ id: number; nombre: string; telefono: string | null }>
    >
>({});

async function verCapturados(id: number) {
    if (capturados.value[id]) {
        delete capturados.value[id];

        return;
    }

    const res = await fetch(loteriaElectores.url(id), {
        headers: { Accept: 'application/json' },
    });

    if (res.ok) {
        capturados.value[id] = (await res.json()).electores;
    }
}
</script>

<template>
    <Head title="Loterías" />

    <div class="flex h-full flex-1 flex-col gap-4 p-4">
        <h1 class="text-xl font-semibold">Loterías</h1>

        <!-- Alta -->
        <div
            class="flex flex-col gap-2 rounded-xl border border-sidebar-border/70 p-4 md:flex-row md:items-end dark:border-sidebar-border"
        >
            <div class="flex flex-1 flex-col gap-1">
                <label class="text-sm font-medium">Nombre</label>
                <Input
                    v-model="form.nombre"
                    placeholder="Lotería Zona Centro"
                    dusk="loteria-nombre"
                />
            </div>
            <div class="flex flex-col gap-1">
                <label class="text-sm font-medium">Fecha</label>
                <input
                    v-model="form.fecha"
                    type="date"
                    class="rounded border bg-background p-2"
                    dusk="loteria-fecha"
                />
            </div>
            <div class="flex flex-col gap-1">
                <label class="text-sm font-medium">Sección</label>
                <select
                    v-model.number="form.seccion_id"
                    class="rounded border bg-background p-2"
                    dusk="loteria-seccion"
                >
                    <option
                        v-for="seccion in secciones"
                        :key="seccion.id"
                        :value="seccion.id"
                    >
                        Sección {{ seccion.numero }}
                    </option>
                </select>
            </div>
            <Button
                :disabled="form.processing"
                @click="crear"
                dusk="loteria-crear"
                >Crear</Button
            >
        </div>

        <p v-if="form.errors.seccion_id" class="text-sm text-destructive">
            {{ form.errors.seccion_id }}
        </p>
        <p v-if="form.errors.nombre" class="text-sm text-destructive">
            {{ form.errors.nombre }}
        </p>
        <p v-if="form.errors.fecha" class="text-sm text-destructive">
            {{ form.errors.fecha }}
        </p>

        <!-- Lista -->
        <div v-if="loterias.length === 0" class="text-sm text-muted-foreground">
            Aún no hay loterías.
        </div>
        <ul v-else class="flex flex-col gap-2">
            <li
                v-for="loteria in loterias"
                :key="loteria.id"
                class="rounded-xl border border-sidebar-border/70 p-3 dark:border-sidebar-border"
            >
                <div class="flex items-center justify-between gap-3">
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="font-medium">{{ loteria.nombre }}</span>
                        <span
                            class="rounded-full bg-sky-100 px-2 py-0.5 text-xs font-medium text-sky-800 dark:bg-sky-900/40 dark:text-sky-200"
                        >
                            {{ seccionLabel(loteria.seccion_id) }}
                        </span>
                        <span class="text-xs text-muted-foreground">
                            · {{ new Date(loteria.fecha).toLocaleDateString() }}
                            <template v-if="loteria.encargado">
                                · {{ loteria.encargado }}</template
                            >
                        </span>
                    </div>
                    <Button
                        size="sm"
                        variant="outline"
                        @click="verCapturados(loteria.id)"
                    >
                        {{ loteria.capturados_count }} capturados
                    </Button>
                </div>
                <ul
                    v-if="capturados[loteria.id]"
                    class="mt-2 flex flex-col gap-1 border-t pt-2 text-sm"
                >
                    <li
                        v-for="c in capturados[loteria.id]"
                        :key="c.id"
                        class="flex justify-between text-muted-foreground"
                    >
                        <a :href="`/electores/${c.id}`" class="hover:underline">
                            {{ c.nombre }}
                        </a>
                        <span v-if="c.telefono">{{ c.telefono }}</span>
                    </li>
                    <li
                        v-if="capturados[loteria.id].length === 0"
                        class="text-muted-foreground"
                    >
                        Sin capturados todavía.
                    </li>
                </ul>
            </li>
        </ul>
    </div>
</template>
