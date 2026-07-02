<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
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
import { dashboard } from '@/routes';
import { data as agendaData } from '@/routes/agenda';

type Seccion = { id: number; numero: number };

defineProps<{ secciones: Seccion[] }>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: dashboard() },
            { title: 'Agenda', href: '/agenda' },
        ],
    },
});

type Pendiente = {
    id: number;
    tipo: string;
    nota: string | null;
    proximo_seguimiento: string | null;
    elector: {
        id: number;
        nombre: string;
        seccion_id: number;
        seccion_numero: number | null;
    };
};

type Pagina = {
    data: Pendiente[];
    current_page: number;
    last_page: number;
    total: number;
    next_page_url: string | null;
    prev_page_url: string | null;
};

const pagina = ref<Pagina | null>(null);
const cargando = ref(false);
const marcando = ref<number | null>(null);
const busqueda = ref('');
const seccionesSel = ref<number[]>([]);

function csrf(): string {
    return (
        document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')
            ?.content ?? ''
    );
}

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
        const resp = await fetch(url ?? agendaData.url({ query }), {
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

async function marcarAtendido(item: Pendiente) {
    if (!pagina.value) {
        return;
    }

    marcando.value = item.id;
    const previo = pagina.value;
    // Optimista: lo sacamos de inmediato de la página actual.
    pagina.value = {
        ...pagina.value,
        data: pagina.value.data.filter((p) => p.id !== item.id),
        total: pagina.value.total - 1,
    };

    try {
        const res = await fetch(`/api/interacciones/${item.id}/atendido`, {
            method: 'PUT',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrf(),
            },
        });

        if (!res.ok) {
            pagina.value = previo; // rollback
        }
    } catch {
        pagina.value = previo;
    } finally {
        marcando.value = null;
    }
}
</script>

<template>
    <Head title="Agenda" />

    <div class="flex h-full flex-1 flex-col gap-4 p-4">
        <h1 class="text-xl font-semibold">Agenda del día</h1>
        <p class="text-sm text-muted-foreground">
            Seguimientos vencidos pendientes de atender.
        </p>

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
                        v-for="s in secciones"
                        :key="s.id"
                        :model-value="seccionesSel.includes(s.id)"
                        @update:model-value="toggleSeccion(s.id)"
                        @select="(e: Event) => e.preventDefault()"
                    >
                        Sección {{ s.numero }}
                    </DropdownMenuCheckboxItem>
                    <p
                        v-if="secciones.length === 0"
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
                {{ pagina.total }} pendientes
            </span>
        </div>

        <p v-if="!pagina" class="text-sm text-muted-foreground">
            Cargando seguimientos…
        </p>

        <div
            v-else-if="pagina.total === 0"
            class="rounded-xl border border-dashed border-sidebar-border/70 p-8 text-center text-sm text-muted-foreground dark:border-sidebar-border"
        >
            {{
                busqueda.trim() || seccionesSel.length
                    ? 'Ningún seguimiento coincide con la búsqueda.'
                    : 'No tienes seguimientos pendientes. 🎉'
            }}
        </div>

        <template v-else>
            <ul class="flex flex-col gap-2">
                <li
                    v-for="item in pagina.data"
                    :key="item.id"
                    class="flex items-center justify-between gap-3 rounded-xl border border-sidebar-border/70 p-3 dark:border-sidebar-border"
                >
                    <div class="min-w-0">
                        <Link
                            :href="`/electores/${item.elector.id}`"
                            class="font-medium hover:underline"
                        >
                            {{ item.elector.nombre }}
                        </Link>
                        <div class="text-xs text-muted-foreground">
                            Sección
                            {{
                                item.elector.seccion_numero ??
                                item.elector.seccion_id
                            }}
                            ·
                            {{ item.tipo }} ·
                            {{ item.proximo_seguimiento }}
                        </div>
                        <p
                            v-if="item.nota"
                            class="truncate text-xs text-muted-foreground"
                        >
                            {{ item.nota }}
                        </p>
                    </div>
                    <Button
                        size="sm"
                        variant="outline"
                        :disabled="marcando === item.id"
                        @click="marcarAtendido(item)"
                    >
                        Atendido
                    </Button>
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
