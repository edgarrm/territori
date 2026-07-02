<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
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
import { data as capturadosData } from '@/routes/capturados';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: dashboard() },
            { title: 'Capturados', href: '/capturados' },
        ],
    },
});

type Capturado = {
    id: number;
    nombre: string;
    seccion_id: number | null;
    seccion_numero: number | null;
    modo_captura: string;
    origen: string;
    telefono: string | null;
    capturado_en: string | null;
};

type Seccion = { id: number; numero: number };
type Modo = { valor: string; etiqueta: string };

type Pagina = {
    data: Capturado[];
    current_page: number;
    last_page: number;
    total: number;
    next_page_url: string | null;
    prev_page_url: string | null;
};

const props = defineProps<{
    secciones: Seccion[];
    seccionesFiltro: Seccion[];
    modos: Modo[];
}>();

function modoLabel(valor: string): string {
    return props.modos.find((m) => m.valor === valor)?.etiqueta ?? valor;
}

// --- Lista paginada + filtros ---
const pagina = ref<Pagina | null>(null);
const cargando = ref(false);
const busqueda = ref('');
const seccionesSel = ref<number[]>([]);
const modosSel = ref<string[]>([]);

// Reasigna (no muta) para que el watcher de filtros se dispare.
function toggleSeccion(id: number) {
    seccionesSel.value = seccionesSel.value.includes(id)
        ? seccionesSel.value.filter((s) => s !== id)
        : [...seccionesSel.value, id];
}

function toggleModo(valor: string) {
    modosSel.value = modosSel.value.includes(valor)
        ? modosSel.value.filter((m) => m !== valor)
        : [...modosSel.value, valor];
}

async function cargar(url?: string) {
    cargando.value = true;

    const query: Record<string, string | number[] | string[]> = {};

    if (busqueda.value.trim()) {
        query.q = busqueda.value.trim();
    }

    if (modosSel.value.length) {
        query.modos = modosSel.value;
    }

    if (seccionesSel.value.length) {
        query.secciones = seccionesSel.value;
    }

    try {
        const resp = await fetch(url ?? capturadosData.url({ query }), {
            headers: { Accept: 'application/json' },
        });
        pagina.value = await resp.json();
    } finally {
        cargando.value = false;
    }
}

// Reinicia a la primera página al cambiar cualquier filtro (debounce para no
// disparar una petición por tecla).
watchDebounced([busqueda, seccionesSel, modosSel], () => cargar(), {
    debounce: 300,
});

onMounted(() => cargar());
</script>

<template>
    <Head title="Capturados" />

    <div class="flex h-full flex-1 flex-col gap-4 p-4">
        <h1 class="text-xl font-semibold">Capturados</h1>

        <!-- Filtros -->
        <div class="flex flex-wrap items-center gap-2">
            <Input
                v-model="busqueda"
                type="search"
                placeholder="Buscar por nombre…"
                class="w-full sm:w-64"
            />

            <!-- Tipo de captura (multiselección) -->
            <DropdownMenu>
                <DropdownMenuTrigger as-child>
                    <Button
                        variant="outline"
                        class="w-full justify-between sm:w-56"
                    >
                        <span class="truncate">
                            {{
                                modosSel.length
                                    ? `${modosSel.length} tipo(s)`
                                    : 'Filtrar por tipo'
                            }}
                        </span>
                        <ChevronDown class="size-4 opacity-60" />
                    </Button>
                </DropdownMenuTrigger>
                <DropdownMenuContent align="start" class="w-56">
                    <DropdownMenuCheckboxItem
                        v-for="m in modos"
                        :key="m.valor"
                        :model-value="modosSel.includes(m.valor)"
                        @update:model-value="toggleModo(m.valor)"
                        @select="(e: Event) => e.preventDefault()"
                    >
                        {{ m.etiqueta }}
                    </DropdownMenuCheckboxItem>
                    <template v-if="modosSel.length">
                        <DropdownMenuSeparator />
                        <DropdownMenuItem @select="modosSel = []">
                            Limpiar filtro
                        </DropdownMenuItem>
                    </template>
                </DropdownMenuContent>
            </DropdownMenu>

            <!-- Sección (multiselección) -->
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
                {{ pagina.total }} capturados
            </span>
        </div>

        <!-- Lista -->
        <p v-if="!pagina" class="text-sm text-muted-foreground">
            Cargando capturados…
        </p>

        <div
            v-else-if="pagina.total === 0"
            class="text-sm text-muted-foreground"
        >
            {{
                busqueda.trim() || modosSel.length || seccionesSel.length
                    ? 'Ningún capturado coincide con los filtros.'
                    : 'Aún no hay capturados.'
            }}
        </div>

        <template v-else>
            <div
                class="overflow-x-auto rounded-xl border border-sidebar-border/70 dark:border-sidebar-border"
            >
                <table class="w-full text-sm">
                    <thead
                        class="border-b border-sidebar-border/70 text-left text-muted-foreground dark:border-sidebar-border"
                    >
                        <tr>
                            <th class="p-3 font-medium">Nombre</th>
                            <th class="p-3 font-medium">Sección</th>
                            <th class="p-3 font-medium">Tipo</th>
                            <th class="p-3 font-medium">Origen</th>
                            <th class="p-3 font-medium">Teléfono</th>
                            <th class="p-3 font-medium">Capturado</th>
                            <th class="p-3 font-medium text-right">Ficha</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="c in pagina.data"
                            :key="c.id"
                            class="border-b border-sidebar-border/40 last:border-0 dark:border-sidebar-border/60"
                        >
                            <td class="p-3">
                                <a
                                    :href="`/electores/${c.id}`"
                                    class="font-medium hover:underline"
                                >
                                    {{ c.nombre }}
                                </a>
                            </td>
                            <td class="p-3 text-muted-foreground">
                                {{
                                    c.seccion_numero !== null
                                        ? `Sección ${c.seccion_numero}`
                                        : '—'
                                }}
                            </td>
                            <td class="p-3 text-muted-foreground">
                                {{ modoLabel(c.modo_captura) }}
                            </td>
                            <td class="p-3 text-muted-foreground">
                                {{ c.origen }}
                            </td>
                            <td class="p-3 text-muted-foreground">
                                {{ c.telefono ?? '—' }}
                            </td>
                            <td class="p-3 text-muted-foreground">
                                {{
                                    c.capturado_en
                                        ? new Date(
                                              c.capturado_en,
                                          ).toLocaleDateString()
                                        : '—'
                                }}
                            </td>
                            <td class="p-3 text-right">
                                <a
                                    :href="`/electores/${c.id}`"
                                    class="text-sm font-medium text-primary hover:underline"
                                >
                                    Ver ficha
                                </a>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

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
