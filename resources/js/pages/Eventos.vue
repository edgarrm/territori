<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { dashboard } from '@/routes';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: dashboard() },
            { title: 'Eventos', href: '/eventos' },
        ],
    },
});

type Evento = {
    id: number;
    nombre: string;
    tipo: string;
    fecha: string;
    lugar: string | null;
    seccion_id: number | null;
    asistentes_count: number | null;
};

type Seccion = { id: number; numero: number };

const props = defineProps<{ eventos: Evento[]; secciones: Seccion[] }>();

function seccionLabel(seccionId: number | null): string {
    if (seccionId === null) {
        return 'Multisección';
    }
    const numero = props.secciones.find((s) => s.id === seccionId)?.numero;
    return numero !== undefined ? `Sección ${numero}` : 'Multisección';
}

const form = useForm({
    nombre: '',
    tipo: 'mitin',
    fecha: '',
    lugar: '',
    seccion_id: null as number | null,
});

function crear() {
    form.post('/eventos', {
        preserveScroll: true,
        onSuccess: () => form.reset(),
    });
}

// Asistentes bajo demanda por evento.
const asistentes = ref<Record<number, Array<{ id: number; nombre: string; seccion_id: number }>>>({});

async function verAsistentes(id: number) {
    if (asistentes.value[id]) {
        delete asistentes.value[id];
        return;
    }
    const res = await fetch(`/api/eventos/${id}/asistentes`, {
        headers: { Accept: 'application/json' },
    });
    if (res.ok) {
        asistentes.value[id] = (await res.json()).asistentes;
    }
}
</script>

<template>
    <Head title="Eventos" />

    <div class="flex h-full flex-1 flex-col gap-4 p-4">
        <h1 class="text-xl font-semibold">Eventos</h1>

        <!-- Alta -->
        <div
            class="flex flex-col gap-2 rounded-xl border border-sidebar-border/70 p-4 dark:border-sidebar-border md:flex-row md:items-end"
        >
            <div class="flex flex-1 flex-col gap-1">
                <label class="text-sm font-medium">Nombre</label>
                <Input v-model="form.nombre" placeholder="Mitin centro" dusk="evento-nombre" />
            </div>
            <div class="flex flex-col gap-1">
                <label class="text-sm font-medium">Tipo</label>
                <select
                    v-model="form.tipo"
                    class="rounded border bg-background p-2"
                    dusk="evento-tipo"
                >
                    <option value="mitin">Mitin</option>
                    <option value="reunion">Reunión</option>
                    <option value="recorrido">Recorrido</option>
                    <option value="asamblea">Asamblea</option>
                </select>
            </div>
            <div class="flex flex-col gap-1">
                <label class="text-sm font-medium">Fecha</label>
                <input
                    v-model="form.fecha"
                    type="date"
                    class="rounded border bg-background p-2"
                    dusk="evento-fecha"
                />
            </div>
            <div class="flex flex-1 flex-col gap-1">
                <label class="text-sm font-medium">Lugar</label>
                <Input v-model="form.lugar" placeholder="Plaza principal" dusk="evento-lugar" />
            </div>
            <div class="flex flex-col gap-1">
                <label class="text-sm font-medium">Sección (opcional)</label>
                <select
                    v-model.number="form.seccion_id"
                    class="rounded border bg-background p-2"
                    dusk="evento-seccion"
                >
                    <option :value="null">Sin sección (varias secciones)</option>
                    <option
                        v-for="seccion in secciones"
                        :key="seccion.id"
                        :value="seccion.id"
                    >
                        Sección {{ seccion.numero }}
                    </option>
                </select>
            </div>
            <Button :disabled="form.processing" @click="crear" dusk="evento-crear">Crear</Button>
        </div>

        <p v-if="form.errors.seccion_id" class="text-sm text-destructive">
            {{ form.errors.seccion_id }}
        </p>

        <!-- Lista -->
        <div
            v-if="eventos.length === 0"
            class="text-sm text-muted-foreground"
        >
            Aún no hay eventos.
        </div>
        <ul v-else class="flex flex-col gap-2">
            <li
                v-for="evento in eventos"
                :key="evento.id"
                class="rounded-xl border border-sidebar-border/70 p-3 dark:border-sidebar-border"
            >
                <div class="flex items-center justify-between gap-3">
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="font-medium">{{ evento.nombre }}</span>
                        <span
                            class="rounded-full px-2 py-0.5 text-xs font-medium"
                            :class="
                                evento.seccion_id === null
                                    ? 'bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-200'
                                    : 'bg-sky-100 text-sky-800 dark:bg-sky-900/40 dark:text-sky-200'
                            "
                        >
                            {{ seccionLabel(evento.seccion_id) }}
                        </span>
                        <span class="text-xs text-muted-foreground">
                            · {{ evento.tipo }} ·
                            {{ new Date(evento.fecha).toLocaleDateString() }}
                            <template v-if="evento.lugar">
                                · {{ evento.lugar }}</template
                            >
                        </span>
                    </div>
                    <Button
                        size="sm"
                        variant="outline"
                        @click="verAsistentes(evento.id)"
                    >
                        {{ evento.asistentes_count ?? 0 }} asistentes
                    </Button>
                </div>
                <ul
                    v-if="asistentes[evento.id]"
                    class="mt-2 flex flex-col gap-1 border-t pt-2 text-sm"
                >
                    <li
                        v-for="a in asistentes[evento.id]"
                        :key="a.id"
                        class="flex justify-between text-muted-foreground"
                    >
                        <a :href="`/electores/${a.id}`" class="hover:underline">
                            {{ a.nombre }}
                        </a>
                        <span>Sección {{ a.seccion_id }}</span>
                    </li>
                    <li
                        v-if="asistentes[evento.id].length === 0"
                        class="text-muted-foreground"
                    >
                        Sin asistentes capturados.
                    </li>
                </ul>
            </li>
        </ul>
    </div>
</template>
