<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import L from 'leaflet';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import 'leaflet/dist/leaflet.css';
import { dashboard } from '@/routes';
import { cobertura as coberturaRoute } from '@/routes/mapa';
import {
    detalle as detalleRoute,
    resumen as resumenRoute,
} from '@/routes/secciones';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: dashboard() },
            { title: 'Mapa de cobertura', href: '/mapa' },
        ],
    },
});

const props = defineProps<{
    municipio: string | null;
    estado: string | null;
    totalSecciones: number;
}>();

const page = usePage<{
    marca: { nombre: string; color: string };
    auth: { tenant: string | null };
}>();

type Modo = 'cobertura' | 'penetracion' | 'tipo';

type FeatureProps = {
    seccion_id: number;
    numero: number;
    tipo: number;
    distrito_local: number | null;
    distrito_federal: number | null;
    capturados: number;
    meta: number;
    cobertura: number;
    penetracion: number;
    lista_nominal: number | null;
};

type Brigadista = { membership_id: number; nombre: string | null };

type SeccionSel = FeatureProps & {
    brigadistas_activos: Brigadista[];
    ultimo_registro: string | null;
    cargando: boolean;
};

// Escala de 5 buckets (cobertura de meta). Compartida por relleno, leyenda y badge.
const ESCALA = [
    { key: 'cumplida', label: 'Meta cumplida', color: '#0ea5e9', min: 1 },
    { key: 'buen', label: 'Buen avance', color: '#16a34a', min: 0.7 },
    { key: 'proceso', label: 'En proceso', color: '#84cc16', min: 0.4 },
    { key: 'rezagada', label: 'Rezagada', color: '#f59e0b', min: 0.0001 },
    { key: 'desertica', label: 'Desértica', color: '#ef4444', min: 0 },
] as const;

// Penetración = capturados / lista nominal (padrón completo). Sus valores son
// naturalmente bajos (capturar el 100% del padrón es irreal), por eso usa cortes
// propios en vez de reutilizar la escala de cobertura de meta.
const ESCALA_PENETRACION = [
    { key: 'alta', label: 'Alta penetración', color: '#0ea5e9', min: 0.3 },
    { key: 'buena', label: 'Buena penetración', color: '#16a34a', min: 0.15 },
    { key: 'media', label: 'Media penetración', color: '#84cc16', min: 0.07 },
    { key: 'baja', label: 'Baja penetración', color: '#f59e0b', min: 0.0001 },
    { key: 'nula', label: 'Sin penetración', color: '#ef4444', min: 0 },
] as const;

const TIPOS = [
    { key: 1, label: 'Urbana', color: '#6366f1' },
    { key: 2, label: 'No urbana', color: '#14b8a6' },
    { key: 3, label: 'Mixta', color: '#f59e0b' },
] as const;

function bucket(valor: number) {
    return ESCALA.find((b) => valor >= b.min) ?? ESCALA[ESCALA.length - 1];
}

function bucketPenetracion(valor: number) {
    return (
        ESCALA_PENETRACION.find((b) => valor >= b.min) ??
        ESCALA_PENETRACION[ESCALA_PENETRACION.length - 1]
    );
}

const modo = ref<Modo>('cobertura');
const seccionSel = ref<SeccionSel | null>(null);
const busqueda = ref('');
const features = ref<FeatureProps[]>([]);

function colorFeature(p: FeatureProps): string {
    if (modo.value === 'tipo') {
        return TIPOS.find((t) => t.key === p.tipo)?.color ?? '#94a3b8';
    }

    if (modo.value === 'penetracion') {
        return bucketPenetracion(p.penetracion).color;
    }

    return bucket(p.cobertura).color;
}

let mapa: L.Map | null = null;
let capa: L.GeoJSON | null = null;

const estadisticas = computed(() => {
    const f = features.value;
    const totalCap = f.reduce((s, x) => s + x.capturados, 0);
    const totalMeta = f.reduce((s, x) => s + x.meta, 0);
    const conMeta = f.filter((x) => x.meta > 0);
    const deserticas = f.filter((x) => x.capturados === 0).length;
    const coberturaMedia = conMeta.length
        ? conMeta.reduce((s, x) => s + Math.min(x.cobertura, 1), 0) /
          conMeta.length
        : 0;

    return {
        totalCap,
        totalMeta,
        avanceGlobal: totalMeta > 0 ? totalCap / totalMeta : 0,
        deserticas,
        coberturaMedia,
    };
});

const subtitulo = computed(() => {
    const partes = [props.municipio, props.estado].filter(Boolean).join(', ');
    const secciones = `${props.totalSecciones} secciones`;
    const campana = page.props.auth?.tenant ?? page.props.marca?.nombre;

    return [partes, secciones, campana].filter(Boolean).join(' · ');
});

const brandColor = computed(() => page.props.marca?.color ?? '#1d4ed8');

const leyenda = computed(() => {
    if (modo.value === 'tipo') {
        return TIPOS.map((t) => ({ label: t.label, color: t.color }));
    }

    const escala = modo.value === 'penetracion' ? ESCALA_PENETRACION : ESCALA;

    return escala.map((b) => ({ label: b.label, color: b.color }));
});

const tituloLeyenda = computed(() =>
    modo.value === 'tipo'
        ? 'Tipo de sección'
        : modo.value === 'penetracion'
          ? 'Penetración'
          : 'Cobertura de meta',
);

function fmt(n: number): string {
    return n.toLocaleString('es-MX');
}

function pct(v: number): string {
    return `${Math.round(v * 100)}%`;
}

function tiempoRelativo(iso: string | null): string {
    if (!iso) {
        return 'Sin registros';
    }

    const ms = Date.now() - new Date(iso).getTime();
    const horas = Math.floor(ms / 3_600_000);

    if (horas < 1) {
        return 'Hace menos de 1h';
    }

    if (horas < 48) {
        return `Hace ${horas}h`;
    }

    return `Hace ${Math.floor(horas / 24)}d`;
}

function estiloFeature(feature?: GeoJSON.Feature) {
    const p = (feature?.properties ?? {}) as FeatureProps;
    const seleccionada = seccionSel.value?.seccion_id === p.seccion_id;

    if (seleccionada) {
        // Borde oscuro grueso + relleno tenue para destacar la sección sin
        // tapar las cuadras y calles del mapa de fondo.
        return {
            fillColor: colorFeature(p),
            fillOpacity: 0.35,
            color: '#11151c',
            weight: 3,
            opacity: 1,
            lineJoin: 'round' as const,
        };
    }

    // Bordes blancos finos entre secciones: lucen como divisores limpios y
    // al solaparse en los bordes compartidos se funden (sin líneas dobles).
    return {
        fillColor: colorFeature(p),
        fillOpacity: 0.72,
        color: '#ffffff',
        weight: 0.7,
        opacity: 1,
        lineJoin: 'round' as const,
    };
}

async function cargarCobertura() {
    const respuesta = await fetch(
        coberturaRoute.url({ query: { modo: modo.value } }),
    );
    const geojson = await respuesta.json();

    features.value = (geojson.features ?? []).map(
        (f: GeoJSON.Feature) => f.properties as FeatureProps,
    );

    capa?.remove();
    capa = L.geoJSON(geojson, {
        style: estiloFeature,
        smoothFactor: 0.5,
        onEachFeature: (feature, layer) => {
            const p = feature.properties as FeatureProps;
            layer.on('click', () => seleccionar(p));
            layer.on('mouseover', (e) => {
                if (seccionSel.value?.seccion_id === p.seccion_id) {
                    return;
                }

                (e.target as L.Path).setStyle({
                    weight: 1.6,
                    fillOpacity: 0.9,
                });
            });
            layer.on('mouseout', () => capa?.resetStyle());
            (layer as L.Path).bindTooltip(
                `Sec. ${p.numero} · ${pct(p.cobertura)}`,
                {
                    sticky: true,
                },
            );
        },
    }).addTo(mapa as L.Map);

    const bounds = capa.getBounds();

    if (bounds.isValid()) {
        mapa?.fitBounds(bounds, { padding: [20, 20] });
    }
}

async function seleccionar(p: FeatureProps) {
    seccionSel.value = {
        ...p,
        brigadistas_activos: [],
        ultimo_registro: null,
        cargando: true,
    };
    capa?.setStyle(estiloFeature);
    capa?.eachLayer((layer) => {
        const feature = (layer as L.GeoJSON).feature as
            | GeoJSON.Feature
            | undefined;

        if (
            (feature?.properties as FeatureProps | undefined)?.seccion_id ===
            p.seccion_id
        ) {
            (layer as L.Path).bringToFront();
        }
    });

    const respuesta = await fetch(resumenRoute.url(p.seccion_id));
    const resumen = await respuesta.json();

    if (seccionSel.value?.seccion_id === p.seccion_id) {
        seccionSel.value = {
            ...seccionSel.value,
            brigadistas_activos: resumen.brigadistas_activos ?? [],
            ultimo_registro: resumen.ultimo_registro ?? null,
            cargando: false,
        };
    }
}

function buscar() {
    const numero = Number.parseInt(busqueda.value.trim(), 10);

    if (Number.isNaN(numero)) {
        return;
    }

    const objetivo = features.value.find((f) => f.numero === numero);

    if (!objetivo) {
        return;
    }

    seleccionar(objetivo);

    capa?.eachLayer((layer) => {
        const feature = (layer as L.GeoJSON).feature as
            | GeoJSON.Feature
            | undefined;
        const p = feature?.properties as FeatureProps | undefined;

        if (p?.seccion_id === objetivo.seccion_id) {
            mapa?.fitBounds((layer as L.Polygon).getBounds(), {
                maxZoom: 16,
                padding: [40, 40],
            });
        }
    });
}

function cambiarModo(nuevo: Modo) {
    modo.value = nuevo;
    capa?.setStyle(estiloFeature);
}

onMounted(() => {
    mapa = L.map('mapa-cobertura', {
        zoomControl: true,
        attributionControl: true,
    }).setView([23.6345, -106.42], 12);
    L.tileLayer(
        'https://{s}.basemaps.cartocdn.com/light_all/{z}/{x}/{y}{r}.png',
        {
            attribution: '&copy; OpenStreetMap, &copy; CARTO',
            maxZoom: 19,
        },
    ).addTo(mapa);
    cargarCobertura();
});

onBeforeUnmount(() => {
    mapa?.remove();
});
</script>

<template>
    <Head title="Mapa de cobertura" />

    <div class="flex h-[calc(100dvh-5rem)] min-h-0 gap-4 p-4">
        <!-- Panel de control -->
        <aside
            class="flex w-[22rem] shrink-0 flex-col gap-3 overflow-y-auto rounded-2xl border border-sidebar-border/70 bg-card shadow-sm dark:border-sidebar-border"
        >
            <!-- Header de marca -->
            <header
                class="rounded-t-2xl px-5 py-4 text-white"
                :style="{
                    background: `linear-gradient(135deg, ${brandColor}, color-mix(in srgb, ${brandColor} 65%, #000))`,
                }"
            >
                <div class="flex items-center gap-2">
                    <span
                        class="size-2.5 rounded-full bg-white/90 ring-4 ring-white/20"
                    />
                    <h1 class="text-lg font-semibold tracking-tight">
                        {{ page.props.marca?.nombre ?? 'Territori' }}
                    </h1>
                </div>
                <p class="mt-1 text-xs text-white/80">{{ subtitulo }}</p>
            </header>

            <div class="flex flex-col gap-3 px-4 pb-4">
                <!-- Toggle de modo -->
                <div class="flex gap-1 rounded-lg bg-muted p-1 text-sm">
                    <button
                        v-for="opcion in [
                            'cobertura',
                            'penetracion',
                            'tipo',
                        ] as Modo[]"
                        :key="opcion"
                        type="button"
                        class="flex-1 rounded-md px-2 py-1.5 font-medium capitalize transition-colors"
                        :class="
                            modo === opcion
                                ? 'bg-background text-foreground shadow-sm'
                                : 'text-muted-foreground hover:text-foreground'
                        "
                        @click="cambiarModo(opcion)"
                    >
                        {{ opcion }}
                    </button>
                </div>

                <!-- Stats globales -->
                <div class="grid grid-cols-2 gap-2">
                    <div class="rounded-xl border bg-background p-3">
                        <div class="text-xl font-bold tabular-nums">
                            {{ fmt(estadisticas.totalCap) }}
                        </div>
                        <div
                            class="text-[0.7rem] font-medium tracking-wide text-muted-foreground uppercase"
                        >
                            Capturados
                        </div>
                    </div>
                    <div class="rounded-xl border bg-background p-3">
                        <div class="text-xl font-bold tabular-nums">
                            {{ fmt(estadisticas.totalMeta) }}
                        </div>
                        <div
                            class="text-[0.7rem] font-medium tracking-wide text-muted-foreground uppercase"
                        >
                            Meta total
                        </div>
                    </div>

                    <div class="col-span-2 rounded-xl border bg-background p-3">
                        <div class="flex items-baseline justify-between">
                            <div class="text-xl font-bold tabular-nums">
                                {{ pct(estadisticas.avanceGlobal) }}
                            </div>
                            <div
                                class="text-[0.7rem] font-medium tracking-wide text-muted-foreground uppercase"
                            >
                                Avance global
                            </div>
                        </div>
                        <div
                            class="mt-2 h-1.5 overflow-hidden rounded-full bg-muted"
                        >
                            <div
                                class="h-full rounded-full transition-all"
                                :style="{
                                    width: `${Math.min(estadisticas.avanceGlobal * 100, 100)}%`,
                                    backgroundColor: brandColor,
                                }"
                            />
                        </div>
                    </div>

                    <div class="rounded-xl border bg-background p-3">
                        <div class="text-xl font-bold tabular-nums">
                            {{ estadisticas.deserticas }}
                        </div>
                        <div
                            class="text-[0.7rem] font-medium tracking-wide text-muted-foreground uppercase"
                        >
                            Secciones desérticas
                        </div>
                    </div>
                    <div class="rounded-xl border bg-background p-3">
                        <div class="text-xl font-bold tabular-nums">
                            {{ pct(estadisticas.coberturaMedia) }}
                        </div>
                        <div
                            class="text-[0.7rem] font-medium tracking-wide text-muted-foreground uppercase"
                        >
                            Cobertura media
                        </div>
                    </div>
                </div>

                <!-- Buscador -->
                <input
                    v-model="busqueda"
                    type="search"
                    inputmode="numeric"
                    placeholder="Buscar sección por número…"
                    class="w-full rounded-lg border bg-background px-3 py-2 text-sm outline-none focus:ring-2 focus:ring-ring"
                    @keyup.enter="buscar"
                />

                <!-- Ficha de sección -->
                <section
                    v-if="seccionSel"
                    class="rounded-xl border bg-background p-4"
                >
                    <div class="flex items-center justify-between">
                        <h2 class="text-base font-semibold">
                            Sección {{ seccionSel.numero }}
                        </h2>
                        <span
                            v-if="modo !== 'tipo'"
                            class="rounded-full px-2.5 py-0.5 text-xs font-semibold text-white"
                            :style="{
                                backgroundColor: bucket(seccionSel.cobertura)
                                    .color,
                            }"
                        >
                            {{ bucket(seccionSel.cobertura).label }}
                        </span>
                    </div>

                    <div class="mt-3 flex items-end gap-2">
                        <div
                            class="text-4xl font-bold tabular-nums"
                            :style="{
                                color: bucket(seccionSel.cobertura).color,
                            }"
                        >
                            {{ pct(seccionSel.cobertura) }}
                        </div>
                        <div class="pb-1 text-xs text-muted-foreground">
                            de la meta ({{ seccionSel.capturados }} /
                            {{ seccionSel.meta }})
                        </div>
                    </div>
                    <div
                        class="mt-2 h-1.5 overflow-hidden rounded-full bg-muted"
                    >
                        <div
                            class="h-full rounded-full"
                            :style="{
                                width: `${Math.min(seccionSel.cobertura * 100, 100)}%`,
                                backgroundColor: bucket(seccionSel.cobertura)
                                    .color,
                            }"
                        />
                    </div>

                    <dl class="mt-4 divide-y divide-border text-sm">
                        <div class="flex justify-between py-1.5">
                            <dt class="text-muted-foreground">Capturados</dt>
                            <dd class="font-medium tabular-nums">
                                {{ fmt(seccionSel.capturados) }}
                            </dd>
                        </div>
                        <div class="flex justify-between py-1.5">
                            <dt class="text-muted-foreground">Meta</dt>
                            <dd class="font-medium tabular-nums">
                                {{ fmt(seccionSel.meta) }}
                            </dd>
                        </div>
                        <div class="flex justify-between py-1.5">
                            <dt class="text-muted-foreground">Lista nominal</dt>
                            <dd class="font-medium tabular-nums">
                                {{
                                    seccionSel.lista_nominal
                                        ? fmt(seccionSel.lista_nominal)
                                        : '—'
                                }}
                            </dd>
                        </div>
                        <div class="flex justify-between py-1.5">
                            <dt class="text-muted-foreground">Penetración</dt>
                            <dd class="font-medium tabular-nums">
                                {{ pct(seccionSel.penetracion) }}
                            </dd>
                        </div>
                        <div class="flex justify-between py-1.5">
                            <dt class="text-muted-foreground">
                                Distrito local / fed.
                            </dt>
                            <dd class="font-medium tabular-nums">
                                {{ seccionSel.distrito_local ?? '—' }} /
                                {{ seccionSel.distrito_federal ?? '—' }}
                            </dd>
                        </div>
                        <div class="flex justify-between py-1.5">
                            <dt class="text-muted-foreground">
                                Último registro
                            </dt>
                            <dd class="font-medium">
                                <span
                                    v-if="seccionSel.cargando"
                                    class="text-muted-foreground"
                                    >…</span
                                >
                                <span v-else>{{
                                    tiempoRelativo(seccionSel.ultimo_registro)
                                }}</span>
                            </dd>
                        </div>
                        <div class="flex justify-between py-1.5">
                            <dt class="text-muted-foreground">
                                Brigadistas activos
                            </dt>
                            <dd class="font-medium tabular-nums">
                                {{ seccionSel.brigadistas_activos.length }}
                            </dd>
                        </div>
                    </dl>

                    <div
                        v-if="seccionSel.brigadistas_activos.length"
                        class="mt-1 flex flex-wrap gap-1"
                    >
                        <span
                            v-for="b in seccionSel.brigadistas_activos"
                            :key="b.membership_id"
                            class="rounded-md bg-muted px-2 py-0.5 text-xs font-medium"
                        >
                            {{ b.nombre }}
                        </span>
                    </div>

                    <Link
                        :href="detalleRoute(seccionSel.seccion_id).url"
                        class="mt-4 flex w-full items-center justify-center gap-1 rounded-lg px-3 py-2 text-sm font-semibold text-white transition-opacity hover:opacity-90"
                        :style="{ backgroundColor: brandColor }"
                    >
                        Ver detalle de la sección →
                    </Link>
                </section>

                <p
                    v-else
                    class="rounded-xl border border-dashed bg-background p-4 text-sm text-muted-foreground"
                >
                    Haz clic en una sección del mapa para ver su detalle, o
                    búscala por número.
                </p>

                <p
                    class="px-1 text-[0.7rem] leading-relaxed text-muted-foreground"
                >
                    Cobertura calculada sobre capturas reales del tenant.
                    Cartografía: INE, Marco Geográfico Seccional.
                </p>
            </div>
        </aside>

        <!-- Mapa -->
        <div
            class="relative flex-1 overflow-hidden rounded-2xl border border-sidebar-border/70 shadow-sm dark:border-sidebar-border"
        >
            <div id="mapa-cobertura" class="h-full w-full" />

            <!-- Leyenda -->
            <div
                class="absolute right-3 bottom-3 z-[1000] rounded-xl border border-black/5 bg-background/95 p-3 shadow-lg backdrop-blur"
            >
                <div
                    class="mb-2 text-[0.7rem] font-semibold tracking-wide text-muted-foreground uppercase"
                >
                    {{ tituloLeyenda }}
                </div>
                <ul class="space-y-1">
                    <li
                        v-for="item in leyenda"
                        :key="item.label"
                        class="flex items-center gap-2 text-xs"
                    >
                        <span
                            class="size-3 rounded-sm"
                            :style="{ backgroundColor: item.color }"
                        />
                        {{ item.label }}
                    </li>
                </ul>
            </div>
        </div>
    </div>
</template>
