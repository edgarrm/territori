<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import L from 'leaflet';
import { onBeforeUnmount, onMounted, ref } from 'vue';
import 'leaflet/dist/leaflet.css';
import { dashboard } from '@/routes';
import { cobertura as coberturaRoute } from '@/routes/mapa';
import { resumen as resumenRoute } from '@/routes/secciones';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: dashboard() },
            { title: 'Mapa de cobertura', href: '/mapa' },
        ],
    },
});

type Modo = 'cobertura' | 'penetracion' | 'tipo';

type SeccionResumen = {
    numero: number;
    capturados: number;
    meta: number;
    cobertura: number;
    penetracion: number;
    brigadistas_activos: unknown[];
    ultimo_registro: string | null;
};

const modo = ref<Modo>('cobertura');
const seccionSeleccionada = ref<SeccionResumen | null>(null);
const cargandoResumen = ref(false);

let mapa: L.Map | null = null;
let capaGeojson: L.GeoJSON | null = null;

function colorPorValor(valor: number): string {
    if (valor >= 1) {
        return '#16a34a';
    }

    if (valor >= 0.6) {
        return '#65a30d';
    }

    if (valor >= 0.3) {
        return '#ca8a04';
    }

    if (valor > 0) {
        return '#ea580c';
    }

    return '#dc2626';
}

function estiloFeature(feature?: GeoJSON.Feature) {
    const props = feature?.properties ?? {};
    const valor =
        modo.value === 'penetracion' ? props.penetracion : props.cobertura;

    return {
        fillColor: colorPorValor(valor ?? 0),
        fillOpacity: 0.65,
        color: '#1f2937',
        weight: 1,
    };
}

async function cargarCobertura() {
    const respuesta = await fetch(
        coberturaRoute.url({ query: { modo: modo.value } }),
    );
    const geojson = await respuesta.json();

    if (capaGeojson) {
        capaGeojson.remove();
    }

    capaGeojson = L.geoJSON(geojson, {
        style: estiloFeature,
        onEachFeature: (feature, layer) => {
            layer.on('click', () =>
                seleccionarSeccion(feature.properties?.seccion_id),
            );
        },
    }).addTo(mapa as L.Map);
}

async function seleccionarSeccion(seccionId: number) {
    if (!seccionId) {
        return;
    }

    cargandoResumen.value = true;

    try {
        const respuesta = await fetch(resumenRoute.url(seccionId));
        seccionSeleccionada.value = await respuesta.json();
    } finally {
        cargandoResumen.value = false;
    }
}

function cambiarModo(nuevoModo: Modo) {
    modo.value = nuevoModo;
    cargarCobertura();
}

onMounted(() => {
    mapa = L.map('mapa-cobertura').setView([23.6345, -102.5528], 6);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '&copy; OpenStreetMap',
    }).addTo(mapa);
    cargarCobertura();
});

onBeforeUnmount(() => {
    mapa?.remove();
});
</script>

<template>
    <Head title="Mapa de cobertura" />

    <div class="flex h-full flex-1 gap-4 p-4">
        <div
            class="relative flex-1 overflow-hidden rounded-xl border border-sidebar-border/70 dark:border-sidebar-border"
        >
            <div
                class="absolute top-2 left-2 z-[1000] flex gap-2 rounded-md bg-background/90 p-1 shadow"
            >
                <button
                    v-for="opcion in ['cobertura', 'penetracion'] as Modo[]"
                    :key="opcion"
                    type="button"
                    class="rounded px-3 py-1 text-sm capitalize"
                    :class="
                        modo === opcion
                            ? 'bg-primary text-primary-foreground'
                            : 'hover:bg-muted'
                    "
                    @click="cambiarModo(opcion)"
                >
                    {{ opcion }}
                </button>
            </div>
            <div id="mapa-cobertura" class="h-full w-full" />
        </div>

        <aside
            class="w-80 shrink-0 rounded-xl border border-sidebar-border/70 p-4 dark:border-sidebar-border"
        >
            <h2 class="mb-3 text-lg font-semibold">Detalle de sección</h2>

            <div v-if="cargandoResumen" class="space-y-2">
                <div class="h-4 w-2/3 animate-pulse rounded bg-muted" />
                <div class="h-4 w-1/2 animate-pulse rounded bg-muted" />
            </div>

            <div v-else-if="seccionSeleccionada" class="space-y-2 text-sm">
                <div class="text-base font-medium">
                    Sección {{ seccionSeleccionada.numero }}
                </div>
                <div>Capturados: {{ seccionSeleccionada.capturados }}</div>
                <div>Meta: {{ seccionSeleccionada.meta }}</div>
                <div>
                    Cobertura:
                    {{ (seccionSeleccionada.cobertura * 100).toFixed(1) }}%
                </div>
                <div>
                    Penetración:
                    {{ (seccionSeleccionada.penetracion * 100).toFixed(1) }}%
                </div>
                <div>
                    Brigadistas activos:
                    {{ seccionSeleccionada.brigadistas_activos.length }}
                </div>
                <div>
                    Último registro:
                    {{ seccionSeleccionada.ultimo_registro ?? 'Sin registros' }}
                </div>
            </div>

            <p v-else class="text-sm text-muted-foreground">
                Haz clic en una sección del mapa para ver su detalle.
            </p>
        </aside>
    </div>
</template>
