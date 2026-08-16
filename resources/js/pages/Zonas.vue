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
import { dashboard } from '@/routes';
import {
    destroy as destroyRoute,
    geojson as geojsonRoute,
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
                    <tr v-for="z in props.zonas" :key="z.id" class="border-t">
                        <td class="p-2 font-medium">{{ z.nombre }}</td>
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
                </tbody>
            </table>
        </div>
    </div>
</template>
