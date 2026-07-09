<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
// Solo los tipos en la carga del módulo: Leaflet toca `window` al importarse y
// rompería el render en servidor (SSR). El runtime se carga dinámicamente en
// onMounted (solo en el cliente). Ver leaflet-src.js / SSR.
import type * as L from 'leaflet';
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
    seccionInicial?: number | null;
}>();

type Campana = {
    partido: { id: number; siglas: string; nombre: string; color: string } | null;
    umbral_ganada_franca: number;
    umbral_alfa: number;
    umbral_beta: number;
    indicadores: {
        competitividad: boolean;
        tipo_seccion: boolean;
        indice_neutral: boolean;
        oportunidad: boolean;
    };
} | null;

const page = usePage<{
    marca: { nombre: string; color: string };
    auth: { tenant: string | null };
    campana: Campana;
}>();

type Modo =
    | 'cobertura'
    | 'penetracion'
    | 'tipo'
    | 'ganador'
    | 'competitividad'
    | 'tipo_seccion'
    | 'oportunidad'
    | 'prioridad';

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
    // Estadística pública 2024 (null cuando la sección no tiene dato importado).
    ganador_bloque: string | null;
    margen_pp: number | null;
    pct_fuerza: number | null;
    pct_morena_pvem: number | null;
    participacion_2024: number | null;
    indice_oportunidad: number | null;
    nivel_oportunidad: string | null;
    potencial_movilizacion: number | null;
    // F2: competitividad y tipo de sección desde la perspectiva del partido del tenant.
    competitividad: string;
    diferencia_votos: number | null;
    tipo_seccion: string | null;
};

type Brigadista = { membership_id: number; nombre: string | null };

type LeyendaItem = { key: string | number; label: string; color: string };
type LeyendaItemConCuenta = LeyendaItem & { count: number };

type Demografia = {
    grupo_dominante: string | null;
    grupo_mayor_abstencion: string | null;
    recomendacion: string | null;
} | null;

type SeccionSel = FeatureProps & {
    brigadistas_activos: Brigadista[];
    ultimo_registro: string | null;
    demografia: Demografia;
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
    { key: 'alta', label: 'Alta penetración', color: '#0ea5e9', min: 0.1 },
    { key: 'buena', label: 'Buena penetración', color: '#16a34a', min: 0.06 },
    { key: 'media', label: 'Media penetración', color: '#84cc16', min: 0.03 },
    { key: 'baja', label: 'Baja penetración', color: '#f59e0b', min: 0.0001 },
    { key: 'nula', label: 'Sin penetración', color: '#ef4444', min: 0 },
] as const;

const TIPOS = [
    { key: 1, label: 'Urbana', color: '#6366f1' },
    { key: 2, label: 'No urbana', color: '#14b8a6' },
    { key: 3, label: 'Mixta', color: '#f59e0b' },
] as const;

const SIN_DATOS = { key: 'sin_datos', label: 'Sin datos', color: '#9ca3af' };

// Elección 2024: ganador por bloque (categórico).
const GANADORES = [
    { key: 'fuerza', label: 'Fuerza y Corazón', color: '#db2777' },
    { key: 'morena', label: 'Morena-PVEM', color: '#7c2d12' },
    { key: 'otros', label: 'Otros', color: '#f59e0b' },
] as const;

// Competitividad neutral (fallback cuando el tenant no tiene partido
// configurado): margen 2024 en puntos porcentuales entre bloques fijos.
const ESCALA_COMPETITIVIDAD = [
    { key: 'dominada', label: 'Dominada (≥20pp)', color: '#0ea5e9', min: 20 },
    { key: 'clara', label: 'Clara (10–20pp)', color: '#16a34a', min: 10 },
    { key: 'competida', label: 'Competida (5–10pp)', color: '#84cc16', min: 5 },
    { key: 'cerrada', label: 'Cerrada (2–5pp)', color: '#f59e0b', min: 2 },
    { key: 'muy_cerrada', label: 'Muy cerrada (<2pp)', color: '#ef4444', min: 0 },
] as const;

// Competitividad desde la perspectiva del partido del tenant (F2): estatus ya
// calculado en el backend (App\Enums\CompetitividadSeccion).
const ESTATUS_COMPETITIVIDAD = [
    { key: 'ganada_franca', label: 'Ganada franca', color: '#15803d' },
    { key: 'competida', label: 'Competida', color: '#f59e0b' },
    { key: 'empatada', label: 'Empatada', color: '#6b7280' },
    { key: 'perdida', label: 'Perdida', color: '#dc2626' },
] as const;

// Tipo de sección por lista nominal (F2): App\Enums\TipoSeccion.
const TIPOS_SECCION = [
    { key: 'alfa', label: 'Alfa', color: '#7c3aed' },
    { key: 'beta', label: 'Beta', color: '#2563eb' },
    { key: 'gama', label: 'Gama', color: '#0d9488' },
] as const;

// Oportunidad de movilización: nivel calculado en el análisis por edad
// (abstencionismo recuperable). Alta = donde más votos se pueden sumar.
const NIVELES_OPORTUNIDAD = [
    { key: 'Alta', label: 'Oportunidad alta', color: '#ef4444' },
    { key: 'Media', label: 'Oportunidad media', color: '#f59e0b' },
    { key: 'Baja', label: 'Oportunidad baja', color: '#84cc16' },
    { key: 'Seguimiento', label: 'Seguimiento', color: '#0ea5e9' },
] as const;

// Prioridad 0-100 (misma fórmula que la vista de prioridades, neutral):
// 40% competitividad + 40% oportunidad de movilización + 20% cobertura pendiente.
const ESCALA_PRIORIDAD = [
    { key: 'muy_alta', label: 'Muy alta (≥80)', color: '#ef4444', min: 80 },
    { key: 'alta', label: 'Alta (65–80)', color: '#f97316', min: 65 },
    { key: 'media', label: 'Media (50–65)', color: '#f59e0b', min: 50 },
    { key: 'baja', label: 'Baja (35–50)', color: '#84cc16', min: 35 },
    { key: 'muy_baja', label: 'Muy baja (<35)', color: '#0ea5e9', min: 0 },
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

function bucketGanador(p: FeatureProps) {
    if (!p.ganador_bloque) {
        return SIN_DATOS;
    }

    if (p.ganador_bloque.toLowerCase().includes('morena')) {
        return GANADORES[1];
    }

    if (p.ganador_bloque.toLowerCase().includes('fuerza')) {
        return GANADORES[0];
    }

    return GANADORES[2];
}

function bucketCompetitividad(margen: number | null) {
    if (margen === null) {
        return SIN_DATOS;
    }

    return (
        ESCALA_COMPETITIVIDAD.find((b) => margen >= b.min) ??
        ESCALA_COMPETITIVIDAD[ESCALA_COMPETITIVIDAD.length - 1]
    );
}

// Si el tenant no tiene partido configurado, el modo competitividad cae al
// margen neutral (comportamiento previo a F2).
const partidoConfigurado = computed(() => page.props.campana?.partido != null);

function bucketEstatusPartido(p: FeatureProps) {
    return (
        ESTATUS_COMPETITIVIDAD.find((b) => b.key === p.competitividad) ??
        SIN_DATOS
    );
}

function bucketTipoSeccion(p: FeatureProps) {
    return (
        TIPOS_SECCION.find((t) => t.key === p.tipo_seccion) ?? SIN_DATOS
    );
}

function bucketOportunidad(nivel: string | null) {
    return NIVELES_OPORTUNIDAD.find((n) => n.key === nivel) ?? SIN_DATOS;
}

function prioridad(p: FeatureProps): number | null {
    if (p.margen_pp === null && p.indice_oportunidad === null) {
        return null;
    }

    const competitividad =
        p.margen_pp !== null ? 100 - Math.min(p.margen_pp, 25) * 4 : 0;
    const oportunidad = p.indice_oportunidad ?? 0;
    const coberturaPendiente = 100 - Math.min(p.cobertura, 1) * 100;

    return (
        0.4 * competitividad + 0.4 * oportunidad + 0.2 * coberturaPendiente
    );
}

function bucketPrioridad(p: FeatureProps) {
    const valor = prioridad(p);

    if (valor === null) {
        return SIN_DATOS;
    }

    return (
        ESCALA_PRIORIDAD.find((b) => valor >= b.min) ??
        ESCALA_PRIORIDAD[ESCALA_PRIORIDAD.length - 1]
    );
}

const modo = ref<Modo>('cobertura');
const seccionSel = ref<SeccionSel | null>(null);
const busqueda = ref('');
const features = ref<FeatureProps[]>([]);

function colorFeature(p: FeatureProps): string {
    switch (modo.value) {
        case 'tipo':
            return TIPOS.find((t) => t.key === p.tipo)?.color ?? '#94a3b8';
        case 'penetracion':
            return bucketPenetracion(p.penetracion).color;
        case 'ganador':
            return bucketGanador(p).color;
        case 'competitividad':
            return partidoConfigurado.value
                ? bucketEstatusPartido(p).color
                : bucketCompetitividad(p.margen_pp).color;
        case 'tipo_seccion':
            return bucketTipoSeccion(p).color;
        case 'oportunidad':
            return bucketOportunidad(p.nivel_oportunidad).color;
        case 'prioridad':
            return bucketPrioridad(p).color;
        default:
            return bucket(p.cobertura).color;
    }
}

// Módulo Leaflet en runtime (poblado en onMounted, solo en el cliente).
// eslint-disable-next-line @typescript-eslint/consistent-type-imports -- carga diferida (SSR)
let leaflet: typeof import('leaflet');
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

function claveEstatus(p: FeatureProps): string | number {
    switch (modo.value) {
        case 'tipo':
            return p.tipo;
        case 'penetracion':
            return bucketPenetracion(p.penetracion).key;
        case 'ganador':
            return bucketGanador(p).key;
        case 'competitividad':
            return partidoConfigurado.value
                ? bucketEstatusPartido(p).key
                : bucketCompetitividad(p.margen_pp).key;
        case 'tipo_seccion':
            return bucketTipoSeccion(p).key;
        case 'oportunidad':
            return bucketOportunidad(p.nivel_oportunidad).key;
        case 'prioridad':
            return bucketPrioridad(p).key;
        default:
            return bucket(p.cobertura).key;
    }
}

const conteosPorEstatus = computed(() => {
    const conteos = new Map<string | number, number>();

    for (const p of features.value) {
        const clave = claveEstatus(p);
        conteos.set(clave, (conteos.get(clave) ?? 0) + 1);
    }

    return conteos;
});

const leyenda = computed(() => {
    const conteos = conteosPorEstatus.value;

    if (modo.value === 'tipo') {
        return TIPOS.map((t) => ({
            key: t.key,
            label: t.label,
            color: t.color,
            count: conteos.get(t.key) ?? 0,
        }));
    }

    if (modo.value === 'tipo_seccion') {
        const items: LeyendaItemConCuenta[] = TIPOS_SECCION.map((t) => ({
            key: t.key,
            label: t.label,
            color: t.color,
            count: conteos.get(t.key) ?? 0,
        }));
        const sinDatos = conteos.get(SIN_DATOS.key) ?? 0;

        if (sinDatos > 0) {
            items.push({ ...SIN_DATOS, count: sinDatos });
        }

        return items;
    }

    const escalas: Record<
        Exclude<Modo, 'tipo' | 'tipo_seccion' | 'competitividad'>,
        readonly LeyendaItem[]
    > = {
        cobertura: ESCALA,
        penetracion: ESCALA_PENETRACION,
        ganador: GANADORES,
        oportunidad: NIVELES_OPORTUNIDAD,
        prioridad: ESCALA_PRIORIDAD,
    };

    if (modo.value === 'competitividad') {
        const escala: readonly { key: string; label: string; color: string }[] =
            partidoConfigurado.value
                ? ESTATUS_COMPETITIVIDAD
                : ESCALA_COMPETITIVIDAD;
        const items: LeyendaItemConCuenta[] = escala.map((b) => ({
            key: b.key,
            label: b.label,
            color: b.color,
            count: conteos.get(b.key) ?? 0,
        }));
        const sinDatos = conteos.get(SIN_DATOS.key) ?? 0;

        if (sinDatos > 0) {
            items.push({ ...SIN_DATOS, count: sinDatos });
        }

        return items;
    }

    const items = escalas[modo.value].map((b) => ({
        key: b.key,
        label: b.label,
        color: b.color,
        count: conteos.get(b.key) ?? 0,
    }));

    // En los modos 2024 puede haber secciones sin estadística importada.
    const sinDatos = conteos.get(SIN_DATOS.key) ?? 0;

    if (sinDatos > 0) {
        items.push({ ...SIN_DATOS, count: sinDatos });
    }

    return items;
});

const totalSeccionesMostradas = computed(() => features.value.length);

// Modos base: siempre visibles. Modos F2/F3 se filtran por los toggles de
// `indicadores` del tenant (campana compartida por Inertia).
const MODOS_BASE: { key: Modo; label: string }[] = [
    { key: 'cobertura', label: 'Cobertura' },
    { key: 'penetracion', label: 'Penetración' },
    { key: 'tipo', label: 'Tipo INE' },
    { key: 'ganador', label: 'Ganador 2024' },
];

const modos = computed(() => {
    const indicadores = page.props.campana?.indicadores;
    const opcionales: { key: Modo; label: string; activo: boolean }[] = [
        { key: 'competitividad', label: 'Competitividad', activo: indicadores?.competitividad ?? true },
        { key: 'tipo_seccion', label: 'Alfa / Beta / Gama', activo: indicadores?.tipo_seccion ?? true },
        { key: 'prioridad', label: 'Prioridad', activo: indicadores?.indice_neutral ?? true },
        { key: 'oportunidad', label: 'Oportunidad', activo: indicadores?.oportunidad ?? true },
    ];

    return [
        ...MODOS_BASE,
        ...opcionales.filter((o) => o.activo).map(({ key, label }) => ({ key, label })),
    ];
});

const TITULOS_LEYENDA: Record<Modo, string> = {
    cobertura: 'Cobertura de meta',
    penetracion: 'Penetración',
    tipo: 'Tipo INE de sección',
    ganador: 'Ganador 2024 por bloque',
    competitividad: 'Competitividad',
    tipo_seccion: 'Tipo de sección (α/β/γ)',
    oportunidad: 'Oportunidad de movilización',
    prioridad: 'Prioridad de esfuerzos',
};

const tituloLeyenda = computed(() => {
    if (modo.value === 'competitividad') {
        return partidoConfigurado.value
            ? 'Competitividad (mi partido)'
            : 'Competitividad 2024 (margen)';
    }

    return TITULOS_LEYENDA[modo.value];
});

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
        // Relleno casi transparente + borde grueso en color de marca: deja ver
        // las calles del mapa base (con sus nombres) dentro de la sección para
        // ubicarla con claridad, sin perder su contorno.
        return {
            fillColor: colorFeature(p),
            fillOpacity: 0.1,
            color: brandColor.value,
            weight: 4,
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

async function cargarCobertura(ajustarVista = true) {
    const respuesta = await fetch(
        coberturaRoute.url({ query: { modo: modo.value } }),
    );
    const geojson = await respuesta.json();

    features.value = (geojson.features ?? []).map(
        (f: GeoJSON.Feature) => f.properties as FeatureProps,
    );

    capa?.remove();
    // smoothFactor no está tipado en GeoJSONOptions pero Leaflet lo propaga a las
    // capas de polígono en tiempo de ejecución.
    const opciones: L.GeoJSONOptions & { smoothFactor?: number } = {
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
    };
    capa = leaflet.geoJSON(geojson, opciones).addTo(mapa as L.Map);

    // Al enfocar una sección concreta (deep-link) omitimos el encuadre global
    // para no competir con el zoom de la sección y perder el foco.
    if (!ajustarVista) {
        return;
    }

    const bounds = capa.getBounds();

    if (bounds.isValid()) {
        mapa?.fitBounds(bounds, { padding: [20, 20] });
    }
}

// Centra y acerca el mapa a los límites de una sección para leer sus calles.
function enfocarSeccion(seccionId: number) {
    capa?.eachLayer((layer) => {
        const feature = (layer as L.GeoJSON).feature as
            | GeoJSON.Feature
            | undefined;
        const p = feature?.properties as FeatureProps | undefined;

        if (p?.seccion_id === seccionId) {
            (layer as L.Path).bringToFront();
            mapa?.fitBounds((layer as L.Polygon).getBounds(), {
                maxZoom: 16,
                padding: [40, 40],
            });
        }
    });
}

async function seleccionar(p: FeatureProps) {
    seccionSel.value = {
        ...p,
        brigadistas_activos: [],
        ultimo_registro: null,
        demografia: null,
        cargando: true,
    };
    capa?.setStyle(estiloFeature);
    // Acerca automáticamente a la sección para ubicarla y ver sus calles.
    enfocarSeccion(p.seccion_id);

    const respuesta = await fetch(resumenRoute.url(p.seccion_id));
    const resumen = await respuesta.json();

    if (seccionSel.value?.seccion_id === p.seccion_id) {
        seccionSel.value = {
            ...seccionSel.value,
            brigadistas_activos: resumen.brigadistas_activos ?? [],
            ultimo_registro: resumen.ultimo_registro ?? null,
            demografia: resumen.demografia ?? null,
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
}

function cambiarModo(nuevo: Modo) {
    modo.value = nuevo;
    capa?.setStyle(estiloFeature);
}

onMounted(async () => {
    leaflet = await import('leaflet');

    mapa = leaflet
        .map('mapa-cobertura', {
            zoomControl: true,
            attributionControl: true,
        })
        .setView([23.6345, -106.42], 12);
    leaflet
        .tileLayer(
            'https://{s}.basemaps.cartocdn.com/light_all/{z}/{x}/{y}{r}.png',
            {
                attribution: '&copy; OpenStreetMap, &copy; CARTO',
                maxZoom: 19,
            },
        )
        .addTo(mapa);

    // Capa de solo etiquetas (calles y colonias) en un pane por encima de los
    // polígonos de sección, para que los nombres queden legibles sobre el
    // relleno de la sección seleccionada. Sin capturar clics (pointer-events).
    mapa.createPane('etiquetas');
    const paneEtiquetas = mapa.getPane('etiquetas');

    if (paneEtiquetas) {
        paneEtiquetas.style.zIndex = '620';
        paneEtiquetas.style.pointerEvents = 'none';
    }

    leaflet
        .tileLayer(
            'https://{s}.basemaps.cartocdn.com/light_only_labels/{z}/{x}/{y}{r}.png',
            {
                pane: 'etiquetas',
                maxZoom: 19,
            },
        )
        .addTo(mapa);

    // Deep-link: si otra vista enlazó con ?seccion={id}, cargamos la capa sin
    // encuadre global y enfocamos directamente esa sección (si el rol la ve).
    const seccionFoco = props.seccionInicial;

    await cargarCobertura(seccionFoco == null);

    if (seccionFoco != null) {
        const objetivo = features.value.find(
            (f) => f.seccion_id === seccionFoco,
        );

        if (objetivo) {
            seleccionar(objetivo);
        }
    }
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
                <div class="grid grid-cols-2 gap-1 rounded-lg bg-muted p-1 text-sm">
                    <button
                        v-for="opcion in modos"
                        :key="opcion.key"
                        type="button"
                        class="rounded-md px-2 py-1.5 font-medium transition-colors"
                        :class="
                            modo === opcion.key
                                ? 'bg-background text-foreground shadow-sm'
                                : 'text-muted-foreground hover:text-foreground'
                        "
                        @click="cambiarModo(opcion.key)"
                    >
                        {{ opcion.label }}
                    </button>
                </div>

                <!-- Aviso: modo competitividad sin partido configurado -->
                <p
                    v-if="modo === 'competitividad' && !partidoConfigurado"
                    class="rounded-lg border border-amber-300 bg-amber-50 px-3 py-2 text-xs text-amber-800 dark:border-amber-900 dark:bg-amber-950 dark:text-amber-200"
                >
                    Configura el partido de tu campaña para ver la
                    competitividad desde tu perspectiva.
                    <Link href="/settings/campana" class="font-semibold underline">
                        Ir a configuración →
                    </Link>
                </p>

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

                    <!-- Distribución por estatus: mismo desglose de la leyenda del mapa, dinámico según el modo -->
                    <div class="col-span-2 rounded-xl border bg-background p-3">
                        <div class="mb-2 flex items-center justify-between">
                            <div
                                class="text-[0.7rem] font-medium tracking-wide text-muted-foreground uppercase"
                            >
                                {{ tituloLeyenda }}
                            </div>
                            <div
                                class="text-[0.7rem] font-medium text-muted-foreground tabular-nums"
                            >
                                {{ fmt(totalSeccionesMostradas) }} secciones
                            </div>
                        </div>
                        <ul class="space-y-1.5">
                            <li
                                v-for="item in leyenda"
                                :key="item.key"
                                class="flex items-center gap-2 text-sm"
                            >
                                <span
                                    class="size-2.5 shrink-0 rounded-sm"
                                    :style="{ backgroundColor: item.color }"
                                />
                                <span class="flex-1 text-muted-foreground">{{
                                    item.label
                                }}</span>
                                <span class="font-medium tabular-nums">{{
                                    fmt(item.count)
                                }}</span>
                            </li>
                        </ul>
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
                        <h2 class="flex items-center gap-1.5 text-base font-semibold">
                            Sección {{ seccionSel.numero }}
                            <span
                                v-if="
                                    page.props.campana?.indicadores
                                        .tipo_seccion && seccionSel.tipo_seccion
                                "
                                class="rounded-md px-1.5 py-0.5 text-[0.65rem] font-bold text-white"
                                :style="{
                                    backgroundColor: bucketTipoSeccion(seccionSel)
                                        .color,
                                }"
                            >
                                {{
                                    { alfa: 'α', beta: 'β', gama: 'γ' }[
                                        seccionSel.tipo_seccion
                                    ]
                                }}
                            </span>
                        </h2>
                        <span
                            v-if="modo !== 'tipo' && modo !== 'tipo_seccion'"
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

                    <!-- Estadística electoral/demográfica 2024 (si fue importada) -->
                    <div
                        v-if="seccionSel.ganador_bloque || seccionSel.nivel_oportunidad"
                        class="mt-3 border-t pt-3"
                    >
                        <div
                            class="text-[0.7rem] font-medium tracking-wide text-muted-foreground uppercase"
                        >
                            Elección 2024
                        </div>
                        <dl class="mt-1 divide-y divide-border text-sm">
                            <div
                                v-if="seccionSel.ganador_bloque"
                                class="flex justify-between py-1.5"
                            >
                                <dt class="text-muted-foreground">Ganador</dt>
                                <dd class="flex items-center gap-1.5 font-medium">
                                    <span
                                        class="size-2.5 rounded-sm"
                                        :style="{
                                            backgroundColor:
                                                bucketGanador(seccionSel).color,
                                        }"
                                    />
                                    {{ seccionSel.ganador_bloque }}
                                </dd>
                            </div>
                            <div
                                v-if="
                                    page.props.campana?.indicadores
                                        .competitividad &&
                                    partidoConfigurado &&
                                    seccionSel.competitividad !== 'sin_datos'
                                "
                                class="flex justify-between py-1.5"
                            >
                                <dt class="text-muted-foreground">Estatus</dt>
                                <dd class="flex items-center gap-1.5 font-medium tabular-nums">
                                    <span
                                        class="size-2.5 rounded-sm"
                                        :style="{
                                            backgroundColor:
                                                bucketEstatusPartido(seccionSel)
                                                    .color,
                                        }"
                                    />
                                    {{ bucketEstatusPartido(seccionSel).label }}
                                    <span
                                        v-if="seccionSel.diferencia_votos !== null"
                                        class="text-muted-foreground"
                                    >
                                        ({{ seccionSel.diferencia_votos >= 0 ? '+' : '−' }}{{
                                            fmt(Math.abs(seccionSel.diferencia_votos))
                                        }}
                                        votos)
                                    </span>
                                </dd>
                            </div>
                            <div
                                v-if="seccionSel.margen_pp !== null"
                                class="flex justify-between py-1.5"
                            >
                                <dt class="text-muted-foreground">Margen</dt>
                                <dd class="font-medium tabular-nums">
                                    {{ seccionSel.margen_pp.toFixed(1) }} pp ·
                                    {{
                                        bucketCompetitividad(
                                            seccionSel.margen_pp,
                                        ).label.split(' (')[0]
                                    }}
                                </dd>
                            </div>
                            <div
                                v-if="seccionSel.participacion_2024 !== null"
                                class="flex justify-between py-1.5"
                            >
                                <dt class="text-muted-foreground">
                                    Participación 2024
                                </dt>
                                <dd class="font-medium tabular-nums">
                                    {{ seccionSel.participacion_2024.toFixed(1) }}%
                                </dd>
                            </div>
                            <div
                                v-if="seccionSel.nivel_oportunidad"
                                class="flex justify-between py-1.5"
                            >
                                <dt class="text-muted-foreground">
                                    Oportunidad
                                </dt>
                                <dd class="flex items-center gap-1.5 font-medium">
                                    <span
                                        class="size-2.5 rounded-sm"
                                        :style="{
                                            backgroundColor: bucketOportunidad(
                                                seccionSel.nivel_oportunidad,
                                            ).color,
                                        }"
                                    />
                                    {{ seccionSel.nivel_oportunidad }}
                                    <span
                                        v-if="
                                            seccionSel.indice_oportunidad !==
                                            null
                                        "
                                        class="text-muted-foreground tabular-nums"
                                    >
                                        ({{
                                            Math.round(
                                                seccionSel.indice_oportunidad,
                                            )
                                        }}/100)
                                    </span>
                                </dd>
                            </div>
                            <div
                                v-if="seccionSel.potencial_movilizacion !== null"
                                class="flex justify-between py-1.5"
                            >
                                <dt class="text-muted-foreground">
                                    Potencial de movilización
                                </dt>
                                <dd class="font-medium tabular-nums">
                                    {{ fmt(seccionSel.potencial_movilizacion) }}
                                    votos
                                </dd>
                            </div>
                            <div
                                v-if="prioridad(seccionSel) !== null"
                                class="flex justify-between py-1.5"
                            >
                                <dt class="text-muted-foreground">Prioridad</dt>
                                <dd class="flex items-center gap-1.5 font-medium tabular-nums">
                                    <span
                                        class="size-2.5 rounded-sm"
                                        :style="{
                                            backgroundColor:
                                                bucketPrioridad(seccionSel)
                                                    .color,
                                        }"
                                    />
                                    {{ Math.round(prioridad(seccionSel) ?? 0) }}/100
                                </dd>
                            </div>
                        </dl>
                        <p
                            v-if="seccionSel.demografia?.recomendacion"
                            class="mt-2 rounded-md bg-muted px-2.5 py-2 text-xs leading-relaxed text-muted-foreground"
                        >
                            <span class="font-semibold text-foreground"
                                >Recomendación:</span
                            >
                            {{ seccionSel.demografia.recomendacion }}
                        </p>
                    </div>

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
                    Cartografía: INE, Marco Geográfico Seccional. Resultados y
                    demografía 2024: cómputos oficiales por sección.
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
                        :key="item.key"
                        class="flex items-center gap-2 text-xs"
                    >
                        <span
                            class="size-3 shrink-0 rounded-sm"
                            :style="{ backgroundColor: item.color }"
                        />
                        <span class="flex-1">{{ item.label }}</span>
                        <span class="text-muted-foreground tabular-nums">
                            {{ fmt(item.count) }}
                        </span>
                    </li>
                </ul>
                <div
                    class="mt-2 flex items-center justify-between border-t border-black/5 pt-2 text-xs font-semibold"
                >
                    <span>Total</span>
                    <span class="tabular-nums">
                        {{ fmt(totalSeccionesMostradas) }}
                    </span>
                </div>
            </div>
        </div>
    </div>
</template>
