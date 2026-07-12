<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { formatoDiferenciaCompetitividad } from '@/lib/formato';
import { dashboard, mapa } from '@/routes';
import { detalle as detalleRoute } from '@/routes/secciones';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: dashboard() },
            { title: 'Prioridades', href: '/prioridades' },
        ],
    },
});

type SeccionPrioridad = {
    seccion_id: number;
    numero: number;
    lista_nominal: number | null;
    capturados: number;
    meta: number;
    cobertura: number;
    ganador_bloque: string | null;
    margen_pp: number | null;
    indice_oportunidad: number | null;
    nivel_oportunidad: string | null;
    potencial_movilizacion: number | null;
    grupo_dominante: string | null;
    prioridad: number | null;
    // F2: competitividad y tipo de sección desde la perspectiva del partido del
    // tenant. Label/color ya vienen resueltos por el backend según las
    // categorías configurables de la campaña.
    estatus_slug: string;
    estatus_label: string;
    estatus_color: string;
    diferencia_votos: number | null;
    diferencia_pct: number | null;
    tipo_seccion: string | null;
};

const props = defineProps<{
    secciones: SeccionPrioridad[];
    mostrarCompetitividad: boolean;
    mostrarTipoSeccion: boolean;
    mostrarIndiceNeutral: boolean;
    mostrarOportunidad: boolean;
}>();

type CategoriaCompetitividad = {
    nombre: string;
    color: string;
    umbral: number | null;
    slug: string;
};

const page = usePage<{
    campana: {
        categorias_competitividad: CategoriaCompetitividad[];
        modo_calculo_competitividad: 'votos' | 'porcentaje';
    } | null;
}>();

const SIN_DATOS = { slug: 'sin_datos', label: 'Sin datos', color: '#9ca3af' };

// Categorías configuradas por el tenant (F2 configurable) + "Sin datos" al
// final, en el orden definido en /settings/campana.
const categoriasCompetitividad = computed(() => [
    ...(page.props.campana?.categorias_competitividad ?? []).map((c) => ({
        slug: c.slug,
        label: c.nombre,
        color: c.color,
    })),
    SIN_DATOS,
]);

const ordenEstatusPorSlug = computed(() => {
    const orden: Record<string, number> = {};
    categoriasCompetitividad.value.forEach((c, indice) => {
        orden[c.slug] = indice;
    });

    return orden;
});

const TIPOS_SECCION: Record<string, { label: string; color: string }> = {
    alfa: { label: 'α Alfa', color: '#7c3aed' },
    beta: { label: 'β Beta', color: '#2563eb' },
    gama: { label: 'γ Gama', color: '#0d9488' },
};

// Orden por defecto pedido en el spec: tipo (Alfa→Beta→Gama), dentro estatus
// (en el orden configurado por el tenant).
const ORDEN_TIPO: Record<string, number> = { alfa: 0, beta: 1, gama: 2 };

function ordenTipo(tipo: string | null): number {
    return tipo !== null ? (ORDEN_TIPO[tipo] ?? 99) : 99;
}

function ordenEstatus(estatus: string): number {
    return ordenEstatusPorSlug.value[estatus] ?? 99;
}

// Colores consistentes con las capas del Mapa.
const COLORES_BLOQUE: { contiene: string; color: string }[] = [
    { contiene: 'morena', color: '#7c2d12' },
    { contiene: 'fuerza', color: '#db2777' },
];

function colorBloque(bloque: string): string {
    return (
        COLORES_BLOQUE.find((b) => bloque.toLowerCase().includes(b.contiene))
            ?.color ?? '#f59e0b'
    );
}

const COLORES_OPORTUNIDAD: Record<string, string> = {
    Alta: '#ef4444',
    Media: '#f59e0b',
    Baja: '#84cc16',
    Seguimiento: '#0ea5e9',
};

function colorPrioridad(valor: number): string {
    if (valor >= 80) {
        return '#ef4444';
    }

    if (valor >= 65) {
        return '#f97316';
    }

    if (valor >= 50) {
        return '#f59e0b';
    }

    if (valor >= 35) {
        return '#84cc16';
    }

    return '#0ea5e9';
}

type CampoOrden =
    | 'prioridad'
    | 'numero'
    | 'margen_pp'
    | 'indice_oportunidad'
    | 'potencial_movilizacion'
    | 'cobertura'
    | 'tipo_seccion';

// Default: tipo (Alfa→Beta→Gama) y dentro, estatus (GanadaFranca→...→SinDatos).
const orden = ref<CampoOrden>('tipo_seccion');
const descendente = ref(false);
const filtroNivel = ref('');
const filtroGanador = ref('');
const filtroTipo = ref('');
const filtroEstatus = ref('');
const busqueda = ref('');

function ordenarPor(campo: CampoOrden) {
    if (orden.value === campo) {
        descendente.value = !descendente.value;
    } else {
        orden.value = campo;
        // El margen se explora de menor a mayor (cerradas primero); tipo/estatus
        // de menor a mayor (Alfa/GanadaFranca primero); el resto, de mayor a menor.
        descendente.value =
            campo !== 'margen_pp' &&
            campo !== 'numero' &&
            campo !== 'tipo_seccion';
    }
}

const ganadores = computed(() => [
    ...new Set(
        props.secciones
            .map((s) => s.ganador_bloque)
            .filter((g): g is string => g !== null),
    ),
]);

const seccionesVisibles = computed(() => {
    let lista = props.secciones;

    if (filtroNivel.value) {
        lista = lista.filter((s) => s.nivel_oportunidad === filtroNivel.value);
    }

    if (filtroGanador.value) {
        lista = lista.filter((s) => s.ganador_bloque === filtroGanador.value);
    }

    if (filtroTipo.value) {
        lista = lista.filter((s) => s.tipo_seccion === filtroTipo.value);
    }

    if (filtroEstatus.value) {
        lista = lista.filter((s) => s.estatus_slug === filtroEstatus.value);
    }

    const numero = Number.parseInt(busqueda.value.trim(), 10);

    if (!Number.isNaN(numero)) {
        lista = lista.filter((s) => String(s.numero).includes(String(numero)));
    }

    const campo = orden.value;
    const factor = descendente.value ? -1 : 1;

    if (campo === 'tipo_seccion') {
        return [...lista].sort((a, b) => {
            const cmp =
                ordenTipo(a.tipo_seccion) - ordenTipo(b.tipo_seccion) ||
                ordenEstatus(a.estatus_slug) - ordenEstatus(b.estatus_slug);

            return cmp * factor;
        });
    }

    return [...lista].sort((a, b) => {
        const va = a[campo];
        const vb = b[campo];

        // Secciones sin dato siempre al final, sin importar la dirección.
        if (va === null && vb === null) {
            return 0;
        }

        if (va === null) {
            return 1;
        }

        if (vb === null) {
            return -1;
        }

        return (Number(va) - Number(vb)) * factor;
    });
});

function fmt(n: number): string {
    return n.toLocaleString('es-MX');
}

function pct(v: number): string {
    return `${Math.round(v * 100)}%`;
}

function flecha(campo: CampoOrden): string {
    if (orden.value !== campo) {
        return '';
    }

    return descendente.value ? ' ↓' : ' ↑';
}
</script>

<template>
    <Head title="Prioridades por sección" />

    <div class="flex h-full flex-1 flex-col gap-4 p-4">
        <div class="flex flex-wrap items-end justify-between gap-3">
            <div>
                <h1 class="text-xl font-semibold">Prioridades por sección</h1>
                <p class="mt-1 max-w-3xl text-sm text-muted-foreground">
                    Índice neutral 0-100 para enfocar esfuerzos: 40%
                    competitividad 2024 (margen chico = cada voto vale más), 40%
                    oportunidad de movilización por edad y 20% cobertura
                    pendiente de la campaña.
                </p>
            </div>

            <div class="flex flex-wrap items-end gap-2">
                <input
                    v-model="busqueda"
                    type="search"
                    inputmode="numeric"
                    placeholder="Sección…"
                    class="w-28 rounded-lg border bg-background px-3 py-1.5 text-sm outline-none focus:ring-2 focus:ring-ring"
                />
                <select
                    v-model="filtroNivel"
                    class="rounded-lg border bg-background px-2 py-1.5 text-sm"
                >
                    <option value="">Oportunidad: todas</option>
                    <option value="Alta">Alta</option>
                    <option value="Media">Media</option>
                    <option value="Baja">Baja</option>
                    <option value="Seguimiento">Seguimiento</option>
                </select>
                <select
                    v-model="filtroGanador"
                    class="rounded-lg border bg-background px-2 py-1.5 text-sm"
                >
                    <option value="">Ganador 2024: todos</option>
                    <option v-for="g in ganadores" :key="g" :value="g">
                        {{ g }}
                    </option>
                </select>
                <select
                    v-if="mostrarTipoSeccion"
                    v-model="filtroTipo"
                    class="rounded-lg border bg-background px-2 py-1.5 text-sm"
                >
                    <option value="">Tipo: todos</option>
                    <option value="alfa">Alfa</option>
                    <option value="beta">Beta</option>
                    <option value="gama">Gama</option>
                </select>
                <select
                    v-if="mostrarCompetitividad"
                    v-model="filtroEstatus"
                    class="rounded-lg border bg-background px-2 py-1.5 text-sm"
                >
                    <option value="">Estatus: todos</option>
                    <option
                        v-for="info in categoriasCompetitividad"
                        :key="info.slug"
                        :value="info.slug"
                    >
                        {{ info.label }}
                    </option>
                </select>
                <span class="text-sm whitespace-nowrap text-muted-foreground">
                    {{ seccionesVisibles.length }} de {{ secciones.length }}
                </span>
            </div>
        </div>

        <div
            v-if="secciones.length === 0"
            class="text-sm text-muted-foreground"
        >
            No hay secciones cargadas para esta campaña.
        </div>

        <div
            v-else
            class="overflow-auto rounded-xl border border-sidebar-border/70 dark:border-sidebar-border"
        >
            <table class="w-full text-sm">
                <thead class="bg-muted/50">
                    <tr>
                        <th
                            class="cursor-pointer p-2 text-left select-none"
                            @click="ordenarPor('numero')"
                        >
                            Sección{{ flecha('numero') }}
                        </th>
                        <th
                            v-if="mostrarTipoSeccion"
                            class="cursor-pointer p-2 text-left select-none"
                            @click="ordenarPor('tipo_seccion')"
                        >
                            Tipo{{ flecha('tipo_seccion') }}
                        </th>
                        <th v-if="mostrarCompetitividad" class="p-2 text-left">
                            Estatus
                        </th>
                        <th
                            v-if="mostrarIndiceNeutral"
                            class="cursor-pointer p-2 text-left select-none"
                            @click="ordenarPor('prioridad')"
                        >
                            Prioridad{{ flecha('prioridad') }}
                        </th>
                        <th class="p-2 text-left">Ganador 2024</th>
                        <th
                            class="cursor-pointer p-2 text-right select-none"
                            @click="ordenarPor('margen_pp')"
                        >
                            Margen (pp){{ flecha('margen_pp') }}
                        </th>
                        <th
                            v-if="mostrarOportunidad"
                            class="cursor-pointer p-2 text-right select-none"
                            @click="ordenarPor('indice_oportunidad')"
                        >
                            Oportunidad{{ flecha('indice_oportunidad') }}
                        </th>
                        <th
                            v-if="mostrarOportunidad"
                            class="cursor-pointer p-2 text-right select-none"
                            @click="ordenarPor('potencial_movilizacion')"
                        >
                            Potencial{{ flecha('potencial_movilizacion') }}
                        </th>
                        <th
                            class="cursor-pointer p-2 text-right select-none"
                            @click="ordenarPor('cobertura')"
                        >
                            Cobertura{{ flecha('cobertura') }}
                        </th>
                        <th class="p-2"></th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="seccion in seccionesVisibles"
                        :key="seccion.seccion_id"
                        class="border-t"
                    >
                        <td class="p-2 font-medium tabular-nums">
                            <Link
                                :href="detalleRoute(seccion.seccion_id).url"
                                class="hover:underline"
                            >
                                {{ seccion.numero }}
                            </Link>
                        </td>
                        <td v-if="mostrarTipoSeccion" class="p-2">
                            <span
                                v-if="seccion.tipo_seccion"
                                class="rounded-md px-2 py-0.5 text-xs font-semibold text-white"
                                :style="{
                                    backgroundColor:
                                        TIPOS_SECCION[seccion.tipo_seccion]
                                            ?.color,
                                }"
                            >
                                {{ TIPOS_SECCION[seccion.tipo_seccion]?.label }}
                            </span>
                            <span v-else class="text-xs text-muted-foreground"
                                >—</span
                            >
                        </td>
                        <td v-if="mostrarCompetitividad" class="p-2">
                            <span
                                class="inline-flex items-center gap-1.5 text-xs font-medium"
                            >
                                <span
                                    class="size-2.5 shrink-0 rounded-sm"
                                    :style="{
                                        backgroundColor: seccion.estatus_color,
                                    }"
                                />
                                {{ seccion.estatus_label }}
                                <span
                                    v-if="
                                        formatoDiferenciaCompetitividad(
                                            page.props.campana
                                                ?.modo_calculo_competitividad ??
                                                'votos',
                                            seccion.diferencia_votos,
                                            seccion.diferencia_pct,
                                        )
                                    "
                                    class="text-muted-foreground tabular-nums"
                                >
                                    ({{
                                        formatoDiferenciaCompetitividad(
                                            page.props.campana
                                                ?.modo_calculo_competitividad ??
                                                'votos',
                                            seccion.diferencia_votos,
                                            seccion.diferencia_pct,
                                        )
                                    }})
                                </span>
                            </span>
                        </td>
                        <td v-if="mostrarIndiceNeutral" class="p-2">
                            <span
                                v-if="seccion.prioridad !== null"
                                class="rounded-full px-2.5 py-0.5 text-xs font-semibold text-white tabular-nums"
                                :style="{
                                    backgroundColor: colorPrioridad(
                                        seccion.prioridad,
                                    ),
                                }"
                            >
                                {{ Math.round(seccion.prioridad) }}
                            </span>
                            <span v-else class="text-xs text-muted-foreground">
                                Sin datos
                            </span>
                        </td>
                        <td class="p-2">
                            <span
                                v-if="seccion.ganador_bloque"
                                class="inline-flex items-center gap-1.5"
                            >
                                <span
                                    class="size-2.5 shrink-0 rounded-sm"
                                    :style="{
                                        backgroundColor: colorBloque(
                                            seccion.ganador_bloque,
                                        ),
                                    }"
                                />
                                {{ seccion.ganador_bloque }}
                            </span>
                            <span v-else class="text-muted-foreground">—</span>
                        </td>
                        <td class="p-2 text-right tabular-nums">
                            {{
                                seccion.margen_pp !== null
                                    ? seccion.margen_pp.toFixed(1)
                                    : '—'
                            }}
                        </td>
                        <td v-if="mostrarOportunidad" class="p-2 text-right">
                            <span
                                v-if="seccion.nivel_oportunidad"
                                class="inline-flex items-center gap-1.5 tabular-nums"
                            >
                                <span
                                    class="size-2.5 shrink-0 rounded-sm"
                                    :style="{
                                        backgroundColor:
                                            COLORES_OPORTUNIDAD[
                                                seccion.nivel_oportunidad
                                            ] ?? '#9ca3af',
                                    }"
                                />
                                {{
                                    seccion.indice_oportunidad !== null
                                        ? Math.round(seccion.indice_oportunidad)
                                        : seccion.nivel_oportunidad
                                }}
                            </span>
                            <span v-else class="text-muted-foreground">—</span>
                        </td>
                        <td
                            v-if="mostrarOportunidad"
                            class="p-2 text-right tabular-nums"
                        >
                            {{
                                seccion.potencial_movilizacion !== null
                                    ? fmt(seccion.potencial_movilizacion)
                                    : '—'
                            }}
                        </td>
                        <td class="p-2 text-right tabular-nums">
                            {{ pct(seccion.cobertura) }}
                            <span class="text-xs text-muted-foreground">
                                ({{ seccion.capturados }}/{{ seccion.meta }})
                            </span>
                        </td>
                        <td class="p-2 text-right whitespace-nowrap">
                            <Link
                                :href="
                                    mapa.url({
                                        query: { seccion: seccion.seccion_id },
                                    })
                                "
                                class="text-xs font-medium text-muted-foreground hover:text-foreground hover:underline"
                            >
                                Ver en mapa
                            </Link>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <p class="text-[0.7rem] text-muted-foreground">
            Fuente: cómputos oficiales 2024 por sección y análisis de
            participación por grupos de edad. La cobertura corresponde a las
            capturas reales de esta campaña.
        </p>
    </div>
</template>
