<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ChevronDown } from '@lucide/vue';
import { watchDebounced } from '@vueuse/core';
import { onMounted, ref } from 'vue';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuCheckboxItem,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { dashboard, mapa } from '@/routes';
import { data as eventosData } from '@/routes/eventos';

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

type Pagina = {
    data: Evento[];
    current_page: number;
    last_page: number;
    total: number;
    next_page_url: string | null;
    prev_page_url: string | null;
};

const props = defineProps<{
    secciones: Seccion[];
    seccionesFiltro: Seccion[];
}>();

function seccionLabel(seccionId: number | null): string {
    if (seccionId === null) {
        return 'Multisección';
    }

    const numero = props.secciones.find((s) => s.id === seccionId)?.numero;

    return numero !== undefined ? `Sección ${numero}` : 'Multisección';
}

// --- Lista paginada + filtros ---
const pagina = ref<Pagina | null>(null);
const cargando = ref(false);
const busqueda = ref('');
const seccionesSel = ref<number[]>([]);

// Reasigna (no muta) para que el watcher de filtros se dispare.
function toggleSeccion(id: number) {
    seccionesSel.value = seccionesSel.value.includes(id)
        ? seccionesSel.value.filter((s) => s !== id)
        : [...seccionesSel.value, id];
}

async function cargar(url?: string) {
    cargando.value = true;

    const query: Record<string, string | number[]> = {};

    if (busqueda.value.trim()) {
        query.q = busqueda.value.trim();
    }

    if (seccionesSel.value.length) {
        query.secciones = seccionesSel.value;
    }

    try {
        const resp = await fetch(url ?? eventosData.url({ query }), {
            headers: { Accept: 'application/json' },
        });
        pagina.value = await resp.json();
    } finally {
        cargando.value = false;
    }
}

// Reinicia a la primera página al cambiar cualquiera de los filtros (debounce
// para no disparar una petición por tecla).
watchDebounced([busqueda, seccionesSel], () => cargar(), { debounce: 300 });

onMounted(() => cargar());

// --- Alta de evento ---
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
        onSuccess: () => {
            form.reset();
            cargar();
        },
    });
}

// --- Asistentes bajo demanda por evento ---
const asistentes = ref<
    Record<number, Array<{ id: number; nombre: string; seccion_id: number }>>
>({});

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
            class="flex flex-col gap-2 rounded-xl border border-sidebar-border/70 p-4 md:flex-row md:items-end dark:border-sidebar-border"
        >
            <div class="flex flex-1 flex-col gap-1">
                <label class="text-sm font-medium">Nombre</label>
                <Input
                    v-model="form.nombre"
                    placeholder="Mitin centro"
                    dusk="evento-nombre"
                />
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
                <Input
                    v-model="form.lugar"
                    placeholder="Plaza principal"
                    dusk="evento-lugar"
                />
            </div>
            <div class="flex flex-col gap-1">
                <label class="text-sm font-medium">Sección (opcional)</label>
                <select
                    v-model.number="form.seccion_id"
                    class="rounded border bg-background p-2"
                    dusk="evento-seccion"
                >
                    <option :value="null">
                        Sin sección (varias secciones)
                    </option>
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
                dusk="evento-crear"
                @click="crear"
            >
                Crear
            </Button>
        </div>

        <p v-if="form.errors.seccion_id" class="text-sm text-destructive">
            {{ form.errors.seccion_id }}
        </p>

        <!-- Filtros -->
        <div class="flex flex-wrap items-center gap-2">
            <Input
                v-model="busqueda"
                type="search"
                placeholder="Buscar por nombre…"
                class="w-full sm:w-64"
            />
            <DropdownMenu>
                <DropdownMenuTrigger as-child>
                    <Button
                        variant="outline"
                        class="w-full justify-between sm:w-56"
                    >
                        <span class="truncate">
                            {{
                                seccionesSel.length
                                    ? `${seccionesSel.length} sección(es)`
                                    : 'Filtrar por sección'
                            }}
                        </span>
                        <ChevronDown class="size-4 opacity-60" />
                    </Button>
                </DropdownMenuTrigger>
                <DropdownMenuContent
                    align="start"
                    class="max-h-72 w-56 overflow-y-auto"
                >
                    <DropdownMenuCheckboxItem
                        v-for="s in seccionesFiltro"
                        :key="s.id"
                        :model-value="seccionesSel.includes(s.id)"
                        @update:model-value="toggleSeccion(s.id)"
                        @select="(e: Event) => e.preventDefault()"
                    >
                        Sección {{ s.numero }}
                    </DropdownMenuCheckboxItem>
                    <p
                        v-if="seccionesFiltro.length === 0"
                        class="px-2 py-1.5 text-sm text-muted-foreground"
                    >
                        Sin secciones asignadas.
                    </p>
                    <template v-if="seccionesSel.length">
                        <DropdownMenuSeparator />
                        <DropdownMenuItem @select="seccionesSel = []">
                            Limpiar filtro
                        </DropdownMenuItem>
                    </template>
                </DropdownMenuContent>
            </DropdownMenu>
            <span v-if="pagina" class="text-sm text-muted-foreground">
                {{ pagina.total }} eventos
            </span>
        </div>

        <!-- Lista -->
        <p v-if="!pagina" class="text-sm text-muted-foreground">
            Cargando eventos…
        </p>

        <div
            v-else-if="pagina.total === 0"
            class="text-sm text-muted-foreground"
        >
            {{
                busqueda.trim() || seccionesSel.length
                    ? 'Ningún evento coincide con la búsqueda.'
                    : 'Aún no hay eventos.'
            }}
        </div>

        <template v-else>
            <ul class="flex flex-col gap-2">
                <li
                    v-for="evento in pagina.data"
                    :key="evento.id"
                    class="rounded-xl border border-sidebar-border/70 p-3 dark:border-sidebar-border"
                >
                    <div class="flex items-center justify-between gap-3">
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="font-medium">{{ evento.nombre }}</span>
                            <Link
                                v-if="evento.seccion_id !== null"
                                :href="
                                    mapa.url({
                                        query: { seccion: evento.seccion_id },
                                    })
                                "
                                class="rounded-full bg-sky-100 px-2 py-0.5 text-xs font-medium text-sky-800 hover:underline dark:bg-sky-900/40 dark:text-sky-200"
                            >
                                {{ seccionLabel(evento.seccion_id) }}
                            </Link>
                            <span
                                v-else
                                class="rounded-full bg-amber-100 px-2 py-0.5 text-xs font-medium text-amber-800 dark:bg-amber-900/40 dark:text-amber-200"
                            >
                                {{ seccionLabel(evento.seccion_id) }}
                            </span>
                            <span class="text-xs text-muted-foreground">
                                · {{ evento.tipo }} ·
                                {{
                                    new Date(evento.fecha).toLocaleDateString()
                                }}
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
                            <a
                                :href="`/electores/${a.id}`"
                                class="hover:underline"
                            >
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

            <div
                v-if="pagina.last_page > 1"
                class="flex items-center justify-between text-sm"
            >
                <Button
                    variant="outline"
                    size="sm"
                    :disabled="!pagina.prev_page_url || cargando"
                    @click="cargar(pagina.prev_page_url!)"
                >
                    Anterior
                </Button>
                <span class="text-muted-foreground">
                    Página {{ pagina.current_page }} de {{ pagina.last_page }}
                </span>
                <Button
                    variant="outline"
                    size="sm"
                    :disabled="!pagina.next_page_url || cargando"
                    @click="cargar(pagina.next_page_url!)"
                >
                    Siguiente
                </Button>
            </div>
        </template>
    </div>
</template>
