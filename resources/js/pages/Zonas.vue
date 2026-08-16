<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
// Igual que en Mapa.vue: Leaflet toca `window` al importarse y rompería SSR.
// Leaflet.draw además espera un `window.L` global (plugin clásico, no ESM),
// así que se expone explícitamente antes de importarlo.
import type * as L from 'leaflet';
import { onBeforeUnmount, onMounted, ref } from 'vue';
import 'leaflet/dist/leaflet.css';
import 'leaflet-draw/dist/leaflet.draw.css';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { colorPorIndice, fmt, pct } from '@/lib/formato';
import { dashboard } from '@/routes';
import {
    destroy as destroyRoute,
    geojson as geojsonRoute,
    resumen as resumenRoute,
    store as storeRoute,
    update as updateRoute,
} from '@/routes/zonas';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: dashboard() },
            { title: 'Zonas', href: '/zonas' },
        ],
    },
});

type Zona = {
    id: number;
    nombre: string;
    clave: string | null;
    descripcion: string | null;
    tipo: string;
    zona_padre_id: number | null;
    zona_padre_nombre: string | null;
    fuente: 'dibujada' | 'importada';
    activa: boolean;
    tiene_geometria: boolean;
};

const props = defineProps<{ zonas: Zona[] }>();

// --- Resumen de zona (F7): estadísticas + cobertura agregadas ---
type BloqueVotos = {
    nombre: string;
    color: string;
    total: number;
    es_bloque_propio: boolean;
};

type Competitividad = {
    estatus_slug: string;
    estatus_label: string;
    estatus_color: string;
} | null;

// F10: desglose de una encuesta por pregunta+opción, ya calculado por el
// backend (App\Actions\Encuestas\ResumirEncuestasParaAmbito). Solo trae
// encuestas activas con al menos una respuesta en esta zona.
type OpcionEncuesta = { opcion: string; conteo: number; pct: number };
type PreguntaEncuesta = {
    pregunta_id: number;
    etiqueta: string;
    total_respuestas: number;
    opciones: OpcionEncuesta[];
};
type EncuestaResumen = {
    encuesta_id: number;
    nombre: string;
    preguntas: PreguntaEncuesta[];
};

type ZonaResumen = {
    zona_id: number;
    zona_nombre: string;
    secciones_total: number;
    estadistica: {
        total_votos: number | null;
        participacion_pct: number | null;
        competitividad: Competitividad;
        desglose: { total_votos: number | null; bloques: BloqueVotos[] } | null;
        secciones_incluidas: number;
    };
    cobertura: {
        capturados: number;
        meta: number;
        verificados: number;
        cobertura: number;
        movilizacion_verificada: number;
        penetracion: number;
    };
    encuestas: EncuestaResumen[];
};

const ESCALA_COBERTURA = [
    { label: 'Meta cumplida', color: '#0ea5e9', min: 1 },
    { label: 'Buen avance', color: '#16a34a', min: 0.7 },
    { label: 'En proceso', color: '#84cc16', min: 0.4 },
    { label: 'Rezagada', color: '#f59e0b', min: 0.0001 },
    { label: 'Desértica', color: '#ef4444', min: 0 },
] as const;

function bucketCobertura(valor: number) {
    return (
        ESCALA_COBERTURA.find((b) => valor >= b.min) ??
        ESCALA_COBERTURA[ESCALA_COBERTURA.length - 1]
    );
}

const zonaSeleccionada = ref<Zona | null>(null);
const resumenZona = ref<ZonaResumen | null>(null);
const cargandoResumen = ref(false);

async function verResumen(zona: Zona) {
    if (zonaSeleccionada.value?.id === zona.id) {
        zonaSeleccionada.value = null;
        resumenZona.value = null;

        return;
    }

    zonaSeleccionada.value = zona;
    resumenZona.value = null;
    cargandoResumen.value = true;

    try {
        const resp = await fetch(resumenRoute.url(zona.id), {
            headers: { Accept: 'application/json' },
        });
        resumenZona.value = await resp.json();
    } finally {
        cargandoResumen.value = false;
    }
}

function csrf(): string {
    return (
        document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')
            ?.content ?? ''
    );
}

async function enviar(
    url: string,
    method: string,
    body?: unknown,
): Promise<Response> {
    return fetch(url, {
        method,
        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf() },
        body: body ? JSON.stringify(body) : undefined,
    });
}

// --- Alta ---
type GeoJsonGeometry = { type: string; coordinates: unknown };

const alta = ref({
    nombre: '',
    clave: '',
    descripcion: '',
    tipo: 'colonia',
    zona_padre_id: '' as string | number,
});
const geomBorrador = ref<GeoJsonGeometry | null>(null);
const errorAlta = ref<string | null>(null);
const procesando = ref(false);

async function guardarZona() {
    errorAlta.value = null;
    procesando.value = true;

    try {
        const res = await enviar(storeRoute.url(), 'POST', {
            nombre: alta.value.nombre,
            clave: alta.value.clave || null,
            descripcion: alta.value.descripcion || null,
            tipo: alta.value.tipo,
            zona_padre_id: alta.value.zona_padre_id || null,
            geom: geomBorrador.value,
        });

        if (!res.ok) {
            const data = await res.json().catch(() => null);
            errorAlta.value =
                data?.errors?.geom?.[0] ??
                data?.errors?.clave?.[0] ??
                data?.message ??
                'No se pudo guardar la zona.';

            return;
        }

        alta.value = {
            nombre: '',
            clave: '',
            descripcion: '',
            tipo: alta.value.tipo,
            zona_padre_id: '',
        };
        limpiarDibujo();
        router.reload({ only: ['zonas'] });
    } finally {
        procesando.value = false;
    }
}

async function alternarActiva(zona: Zona) {
    await enviar(updateRoute.url(zona.id), 'PUT', { activa: !zona.activa });
    router.reload({ only: ['zonas'] });
}

async function eliminarZona(zona: Zona) {
    if (!confirm(`¿Eliminar la zona "${zona.nombre}"?`)) {
        return;
    }

    await enviar(destroyRoute.url(zona.id), 'DELETE');
    router.reload({ only: ['zonas'] });
}

// --- Mapa + dibujo (Leaflet.draw) ---
// eslint-disable-next-line @typescript-eslint/consistent-type-imports -- carga diferida (SSR)
type LeafletModule = typeof import('leaflet');
let leaflet: LeafletModule;
let mapa: L.Map | null = null;
let capaDibujo: L.FeatureGroup | null = null;
let capaExistentes: L.GeoJSON | null = null;
let capaPreview: L.GeoJSON | null = null;

function limpiarDibujo() {
    geomBorrador.value = null;
    capaDibujo?.clearLayers();
    capaPreview?.remove();
    capaPreview = null;
}

async function cargarZonasExistentes() {
    const respuesta = await fetch(geojsonRoute.url());
    const geojson = await respuesta.json();

    capaExistentes?.remove();
    capaExistentes = leaflet
        .geoJSON(geojson, {
            style: { color: '#6366f1', weight: 2, fillOpacity: 0.08 },
            onEachFeature: (feature, layer) => {
                const nombre = feature.properties?.nombre ?? 'Zona';
                (layer as L.Path).bindTooltip(nombre, { sticky: true });
            },
        })
        .addTo(mapa as L.Map);
}

// --- Importar .geojson con preview ---
const archivoNombre = ref<string | null>(null);
const errorImport = ref<string | null>(null);

function onArchivoSeleccionado(evento: Event) {
    errorImport.value = null;
    const input = evento.target as HTMLInputElement;
    const archivo = input.files?.[0];

    if (!archivo) {
        return;
    }

    archivoNombre.value = archivo.name;

    const lector = new FileReader();
    lector.onload = () => {
        try {
            const contenido = JSON.parse(lector.result as string);
            const geometria = extraerPrimeraGeometria(contenido);

            if (!geometria) {
                errorImport.value =
                    'El archivo no trae ninguna geometría reconocible.';

                return;
            }

            geomBorrador.value = geometria;
            mostrarPreview(geometria);
        } catch {
            errorImport.value = 'El archivo no es un GeoJSON válido.';
        }
    };
    lector.readAsText(archivo);
}

function extraerPrimeraGeometria(
    contenido: Record<string, unknown>,
): GeoJsonGeometry | null {
    if (contenido.type === 'FeatureCollection') {
        const features = contenido.features as
            | { geometry: GeoJsonGeometry }[]
            | undefined;

        return features?.[0]?.geometry ?? null;
    }

    if (contenido.type === 'Feature') {
        return (contenido.geometry as GeoJsonGeometry) ?? null;
    }

    if (typeof contenido.type === 'string' && contenido.coordinates) {
        return contenido as unknown as GeoJsonGeometry;
    }

    return null;
}

function mostrarPreview(geometria: GeoJsonGeometry) {
    capaPreview?.remove();
    capaDibujo?.clearLayers();
    capaPreview = leaflet
        .geoJSON(geometria as GeoJSON.Geometry, {
            style: { color: '#dc2626', weight: 3, fillOpacity: 0.15 },
        })
        .addTo(mapa as L.Map);

    const bounds = capaPreview.getBounds();

    if (bounds.isValid()) {
        mapa?.fitBounds(bounds, { padding: [20, 20] });
    }
}

onMounted(async () => {
    leaflet = await import('leaflet');
    (window as unknown as { L: typeof leaflet }).L = leaflet;
    await import('leaflet-draw');

    mapa = leaflet
        .map('mapa-zonas', { zoomControl: true, attributionControl: true })
        .setView([23.6345, -106.42], 6);

    leaflet
        .tileLayer(
            'https://{s}.basemaps.cartocdn.com/light_all/{z}/{x}/{y}{r}.png',
            { attribution: '&copy; OpenStreetMap, &copy; CARTO', maxZoom: 19 },
        )
        .addTo(mapa);

    capaDibujo = new leaflet.FeatureGroup().addTo(mapa);

    // API de leaflet-draw se cuelga de `L` global (window.L), no del módulo
    // importado: por eso se usa `(window as any).L` aquí en vez de `leaflet`.
    type LeafletConDraw = LeafletModule & {
        Control: { Draw: new (options: unknown) => L.Control };
        Draw: { Event: { CREATED: string } };
    };
    const LGlobal = (window as unknown as { L: LeafletConDraw }).L;

    const control = new LGlobal.Control.Draw({
        draw: {
            polygon: { allowIntersection: false, showArea: false },
            polyline: false,
            rectangle: false,
            circle: false,
            circlemarker: false,
            marker: false,
        },
        edit: { featureGroup: capaDibujo, remove: true },
    });
    mapa.addControl(control);

    mapa.on(LGlobal.Draw.Event.CREATED, (evento: L.LeafletEvent) => {
        const layer = (evento as unknown as { layer: L.Layer }).layer;
        capaDibujo?.clearLayers();
        capaPreview?.remove();
        capaPreview = null;
        capaDibujo?.addLayer(layer);
        geomBorrador.value = (
            layer as unknown as { toGeoJSON: () => GeoJSON.Feature }
        ).toGeoJSON().geometry as GeoJsonGeometry;
    });

    mapa.on('draw:deleted', () => {
        geomBorrador.value = null;
    });

    await cargarZonasExistentes();
});

onBeforeUnmount(() => {
    mapa?.remove();
});
</script>

<template>
    <Head title="Zonas" />

    <div class="flex h-full flex-1 flex-col gap-4 p-4">
        <h1 class="text-xl font-semibold">Zonas personalizadas</h1>
        <p class="text-sm text-muted-foreground">
            Define tus propios polígonos territoriales (ciudad, municipio,
            estado, país o lo que necesites), independientes de las secciones
            del INE. Dibújalos en el mapa o sube un archivo .geojson.
        </p>

        <div class="grid gap-4 lg:grid-cols-2">
            <!-- Mapa + dibujo -->
            <div class="flex flex-col gap-2">
                <div
                    id="mapa-zonas"
                    class="h-96 w-full rounded-xl border border-sidebar-border/70 dark:border-sidebar-border"
                />
                <div class="flex items-center gap-2">
                    <label
                        class="cursor-pointer rounded-md border px-3 py-1.5 text-sm hover:bg-muted"
                    >
                        Subir .geojson
                        <input
                            type="file"
                            accept=".geojson,application/geo+json,application/json"
                            class="hidden"
                            @change="onArchivoSeleccionado"
                        />
                    </label>
                    <span
                        v-if="archivoNombre"
                        class="text-xs text-muted-foreground"
                    >
                        {{ archivoNombre }}
                    </span>
                    <button
                        v-if="geomBorrador"
                        type="button"
                        class="text-xs text-red-600 underline"
                        @click="limpiarDibujo"
                    >
                        Quitar geometría
                    </button>
                </div>
                <p v-if="errorImport" class="text-sm text-red-600">
                    {{ errorImport }}
                </p>
                <p class="text-xs text-muted-foreground">
                    {{
                        geomBorrador
                            ? 'Geometría lista para guardar.'
                            : 'Sin geometría (opcional para niveles como "mundial").'
                    }}
                </p>
            </div>

            <!-- Alta -->
            <form
                class="flex flex-col gap-2 rounded-xl border p-4"
                @submit.prevent="guardarZona"
            >
                <h2 class="text-base font-semibold">Nueva zona</h2>
                <div class="flex flex-col gap-1">
                    <label class="text-xs text-muted-foreground">Nombre</label>
                    <Input
                        v-model="alta.nombre"
                        type="text"
                        required
                        placeholder="Colonia Centro"
                    />
                </div>
                <div class="flex flex-col gap-1">
                    <label class="text-xs text-muted-foreground"
                        >Clave (opcional)</label
                    >
                    <Input v-model="alta.clave" type="text" />
                </div>
                <div class="flex flex-col gap-1">
                    <label class="text-xs text-muted-foreground">Tipo</label>
                    <Input
                        v-model="alta.tipo"
                        type="text"
                        required
                        placeholder="colonia, ciudad, estado, país…"
                    />
                </div>
                <div class="flex flex-col gap-1">
                    <label class="text-xs text-muted-foreground"
                        >Zona padre (opcional)</label
                    >
                    <select
                        v-model="alta.zona_padre_id"
                        class="h-9 rounded-md border border-input bg-background px-3 text-sm"
                    >
                        <option value="">Sin zona padre</option>
                        <option v-for="z in props.zonas" :key="z.id" :value="z.id">
                            {{ z.nombre }}
                        </option>
                    </select>
                </div>
                <div class="flex flex-col gap-1">
                    <label class="text-xs text-muted-foreground"
                        >Descripción (opcional)</label
                    >
                    <textarea
                        v-model="alta.descripcion"
                        class="min-h-16 rounded-md border border-input bg-background p-2 text-sm"
                    />
                </div>
                <Button type="submit" :disabled="procesando"
                    >Guardar zona</Button
                >
                <p v-if="errorAlta" class="text-sm text-red-600">
                    {{ errorAlta }}
                </p>
            </form>
        </div>

        <h2 class="mt-2 text-base font-semibold">Zonas existentes</h2>

        <div
            v-if="props.zonas.length === 0"
            class="text-sm text-muted-foreground"
        >
            Aún no hay zonas personalizadas en esta campaña.
        </div>

        <div
            v-else
            class="overflow-hidden rounded-xl border border-sidebar-border/70 dark:border-sidebar-border"
        >
            <table class="w-full text-sm">
                <thead class="bg-muted/50">
                    <tr>
                        <th class="p-2 text-left">Nombre</th>
                        <th class="p-2 text-left">Clave</th>
                        <th class="p-2 text-left">Tipo</th>
                        <th class="p-2 text-left">Padre</th>
                        <th class="p-2 text-left">Fuente</th>
                        <th class="p-2 text-left">Geometría</th>
                        <th class="p-2 text-left">Estado</th>
                        <th class="p-2 text-left"></th>
                    </tr>
                </thead>
                <tbody>
                    <template v-for="z in props.zonas" :key="z.id">
                        <tr class="border-t">
                            <td class="p-2 font-medium">
                                <button
                                    class="text-left underline-offset-2 hover:underline"
                                    @click="verResumen(z)"
                                >
                                    {{ z.nombre }}
                                </button>
                            </td>
                            <td class="p-2">{{ z.clave ?? '—' }}</td>
                            <td class="p-2">{{ z.tipo }}</td>
                            <td class="p-2">{{ z.zona_padre_nombre ?? '—' }}</td>
                            <td class="p-2">{{ z.fuente }}</td>
                            <td class="p-2">
                                {{ z.tiene_geometria ? 'Sí' : 'Sin polígono' }}
                            </td>
                            <td class="p-2">
                                <button
                                    class="rounded px-2 py-1 text-xs"
                                    :class="
                                        z.activa
                                            ? 'bg-emerald-500/20 text-emerald-700'
                                            : 'bg-muted text-muted-foreground'
                                    "
                                    @click="alternarActiva(z)"
                                >
                                    {{ z.activa ? 'Activa' : 'Inactiva' }}
                                </button>
                            </td>
                            <td class="p-2">
                                <button
                                    class="text-xs text-red-600 underline"
                                    @click="eliminarZona(z)"
                                >
                                    Eliminar
                                </button>
                            </td>
                        </tr>

                        <!-- Panel de resumen (F7): cobertura + electoral 2024, mismo criterio visual que Seccion.vue -->
                        <tr v-if="zonaSeleccionada?.id === z.id" class="border-t bg-muted/20">
                            <td colspan="8" class="p-3 sm:p-4">
                                <div v-if="cargandoResumen" class="text-sm text-muted-foreground">
                                    Cargando resumen…
                                </div>
                                <div v-else-if="resumenZona" class="grid gap-4 sm:grid-cols-2">
                                    <div class="rounded-xl border border-sidebar-border/70 bg-card p-3 dark:border-sidebar-border">
                                        <div class="flex flex-wrap items-start justify-between gap-2">
                                            <h3 class="text-sm font-semibold">Cobertura</h3>
                                            <span
                                                class="rounded-full px-2.5 py-0.5 text-xs font-semibold text-white"
                                                :style="{ backgroundColor: bucketCobertura(resumenZona.cobertura.cobertura).color }"
                                            >
                                                {{ bucketCobertura(resumenZona.cobertura.cobertura).label }}
                                            </span>
                                        </div>
                                        <div class="mt-2 flex items-end gap-2">
                                            <div
                                                class="text-2xl font-bold tabular-nums"
                                                :style="{ color: bucketCobertura(resumenZona.cobertura.cobertura).color }"
                                            >
                                                {{ pct(resumenZona.cobertura.cobertura) }}
                                            </div>
                                            <div class="pb-0.5 text-xs text-muted-foreground">
                                                de la meta ({{ fmt(resumenZona.cobertura.capturados) }} / {{ fmt(resumenZona.cobertura.meta) }})
                                            </div>
                                        </div>
                                        <div class="mt-2 h-1.5 overflow-hidden rounded-full bg-muted">
                                            <div
                                                class="h-full rounded-full"
                                                :style="{
                                                    width: `${Math.min(resumenZona.cobertura.cobertura * 100, 100)}%`,
                                                    backgroundColor: bucketCobertura(resumenZona.cobertura.cobertura).color,
                                                }"
                                            />
                                        </div>
                                        <dl class="mt-3 space-y-1 text-xs">
                                            <div class="flex justify-between">
                                                <dt class="text-muted-foreground">Verificados</dt>
                                                <dd class="font-medium tabular-nums">{{ fmt(resumenZona.cobertura.verificados) }}</dd>
                                            </div>
                                            <div class="flex justify-between">
                                                <dt class="text-muted-foreground">Penetración</dt>
                                                <dd class="font-medium tabular-nums">{{ pct(resumenZona.cobertura.penetracion) }}</dd>
                                            </div>
                                            <div class="flex justify-between">
                                                <dt class="text-muted-foreground">Secciones incluidas</dt>
                                                <dd class="font-medium tabular-nums">{{ resumenZona.secciones_total }}</dd>
                                            </div>
                                        </dl>
                                    </div>

                                    <div class="rounded-xl border border-sidebar-border/70 bg-card p-3 dark:border-sidebar-border">
                                        <div class="flex flex-wrap items-start justify-between gap-2">
                                            <h3 class="text-sm font-semibold">Elección 2024</h3>
                                            <span
                                                v-if="resumenZona.estadistica.competitividad"
                                                class="rounded-full px-2.5 py-0.5 text-xs font-semibold text-white"
                                                :style="{ backgroundColor: resumenZona.estadistica.competitividad.estatus_color }"
                                            >
                                                {{ resumenZona.estadistica.competitividad.estatus_label }}
                                            </span>
                                        </div>
                                        <div v-if="resumenZona.estadistica.total_votos !== null" class="mt-2 space-y-2">
                                            <div class="mt-1 space-y-1.5">
                                                <div
                                                    v-for="bloque in resumenZona.estadistica.desglose?.bloques ?? []"
                                                    :key="bloque.nombre"
                                                    class="flex items-center gap-2 text-xs"
                                                >
                                                    <span class="size-2 shrink-0 rounded-sm" :style="{ backgroundColor: bloque.color }" />
                                                    <span class="min-w-0 flex-1 truncate">{{ bloque.nombre }}</span>
                                                    <span
                                                        v-if="bloque.es_bloque_propio"
                                                        class="shrink-0 rounded-full bg-muted px-1.5 py-0.5 text-[0.65rem] font-semibold"
                                                    >
                                                        Tu bloque
                                                    </span>
                                                    <span class="shrink-0 font-medium tabular-nums">{{ fmt(bloque.total) }}</span>
                                                </div>
                                            </div>
                                            <dl class="mt-2 space-y-1 text-xs">
                                                <div class="flex justify-between">
                                                    <dt class="text-muted-foreground">Votos totales</dt>
                                                    <dd class="font-medium tabular-nums">{{ fmt(resumenZona.estadistica.total_votos) }}</dd>
                                                </div>
                                                <div class="flex justify-between">
                                                    <dt class="text-muted-foreground">Participación</dt>
                                                    <dd class="font-medium tabular-nums">
                                                        {{ resumenZona.estadistica.participacion_pct?.toFixed(1) ?? '—' }}%
                                                    </dd>
                                                </div>
                                            </dl>
                                        </div>
                                        <p v-else class="mt-2 text-xs text-muted-foreground">
                                            Sin datos electorales 2024 para las secciones de esta zona.
                                        </p>
                                    </div>

                                    <!-- Encuestas (F10): una card por encuesta activa con respuestas en esta zona -->
                                    <div
                                        v-for="encuesta in resumenZona.encuestas"
                                        :key="encuesta.encuesta_id"
                                        class="rounded-xl border border-sidebar-border/70 bg-card p-3 dark:border-sidebar-border sm:col-span-2"
                                    >
                                        <h3 class="text-sm font-semibold">
                                            Encuesta: {{ encuesta.nombre }}
                                        </h3>
                                        <div class="mt-3 space-y-3">
                                            <div
                                                v-for="pregunta in encuesta.preguntas"
                                                :key="pregunta.pregunta_id"
                                            >
                                                <div class="flex items-center justify-between gap-2">
                                                    <span class="text-xs font-medium">{{ pregunta.etiqueta }}</span>
                                                    <span class="shrink-0 text-[0.65rem] text-muted-foreground tabular-nums">
                                                        {{ fmt(pregunta.total_respuestas) }} respuestas
                                                    </span>
                                                </div>
                                                <div class="mt-1.5 space-y-1 pl-2">
                                                    <div v-for="(opcion, i) in pregunta.opciones" :key="opcion.opcion">
                                                        <div class="flex items-center gap-2 text-xs">
                                                            <span
                                                                class="size-2 shrink-0 rounded-sm"
                                                                :style="{ backgroundColor: colorPorIndice(i) }"
                                                            />
                                                            <span class="min-w-0 flex-1 truncate">{{ opcion.opcion }}</span>
                                                            <span class="shrink-0 font-medium tabular-nums">
                                                                {{ fmt(opcion.conteo) }}
                                                                <span v-if="pregunta.total_respuestas" class="text-muted-foreground">
                                                                    ({{ pct(opcion.pct) }})
                                                                </span>
                                                            </span>
                                                        </div>
                                                        <div class="mt-1 h-1.5 overflow-hidden rounded-full bg-muted">
                                                            <div
                                                                class="h-full rounded-full"
                                                                :style="{
                                                                    width: `${opcion.pct * 100}%`,
                                                                    backgroundColor: colorPorIndice(i),
                                                                }"
                                                            />
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </td>
                        </tr>
                    </template>
                </tbody>
            </table>
        </div>
    </div>
</template>
