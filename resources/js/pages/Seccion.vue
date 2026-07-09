<script setup lang="ts">
import { Head, usePage } from '@inertiajs/vue3';
import { onMounted, reactive, ref } from 'vue';
import ListaCapturados from '@/components/ListaCapturados.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { dashboard } from '@/routes';
import { vigente as avisoVigente } from '@/routes/avisos';
import { store as electoresStore } from '@/routes/electores';
import {
    electores as electoresRoute,
    resumen as resumenRoute,
} from '@/routes/secciones';

type Modo = { valor: string; etiqueta: string };

const props = defineProps<{
    seccion: { id: number; numero: number };
    modos: Modo[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: dashboard() },
            { title: 'Mapa de cobertura', href: '/mapa' },
            { title: 'Detalle de sección', href: '#' },
        ],
    },
});

type Brigadista = { membership_id: number; nombre: string | null };

type Electoral2024 = {
    ganador_bloque: string;
    ganador_partido: string | null;
    margen_votos: number | null;
    margen_pp: number;
    total_votos: number | null;
    participacion_pct: number;
    pct_fuerza: number;
    pct_morena_pvem: number;
    pct_otros: number;
    // F2: competitividad desde la perspectiva del partido del tenant.
    competitividad: string;
    diferencia_votos: number | null;
    votos_bloque_propio: number | null;
    votos_mejor_rival: number | null;
    bloque_propio: string | null;
} | null;

type GrupoEdad = {
    ln: number;
    votos: number;
    participacion: number;
    abstencion: number;
    potencial: number;
};

type Demografia = {
    indice_oportunidad: number;
    nivel_oportunidad: string;
    grupo_dominante: string | null;
    grupo_mayor_abstencion: string | null;
    potencial_movilizacion: number | null;
    tipo_composicion_edad: string | null;
    recomendacion: string | null;
    grupos_edad: Record<string, GrupoEdad> | null;
} | null;

type Resumen = {
    numero: number;
    tipo: number;
    lista_nominal: number | null;
    distrito_local: number | null;
    distrito_federal: number | null;
    capturados: number;
    meta: number;
    cobertura: number;
    penetracion: number;
    brigadistas_activos: Brigadista[];
    ultimo_registro: string | null;
    tipo_seccion: string | null;
    electoral_2024: Electoral2024;
    demografia: Demografia;
};

type Aviso = { id: number; version: string; texto: string } | null;

type ListaExpuesta = { recargar: () => void };

// Escala de 5 buckets (cobertura de meta), idéntica a la del Mapa.
const ESCALA = [
    { label: 'Meta cumplida', color: '#0ea5e9', min: 1 },
    { label: 'Buen avance', color: '#16a34a', min: 0.7 },
    { label: 'En proceso', color: '#84cc16', min: 0.4 },
    { label: 'Rezagada', color: '#f59e0b', min: 0.0001 },
    { label: 'Desértica', color: '#ef4444', min: 0 },
] as const;

function bucket(valor: number) {
    return ESCALA.find((b) => valor >= b.min) ?? ESCALA[ESCALA.length - 1];
}

// Colores por bloque electoral, idénticos a la capa "Ganador 2024" del Mapa.
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

// Mismos colores por nivel que la capa "Oportunidad" del Mapa.
const COLORES_OPORTUNIDAD: Record<string, string> = {
    Alta: '#ef4444',
    Media: '#f59e0b',
    Baja: '#84cc16',
    Seguimiento: '#0ea5e9',
};

// F2: mismos colores/etiquetas que Mapa.vue y Prioridades.vue.
const ESTATUS_COMPETITIVIDAD: Record<string, { label: string; color: string }> = {
    ganada_franca: { label: 'Ganada franca', color: '#15803d' },
    competida: { label: 'Competida', color: '#f59e0b' },
    empatada: { label: 'Empatada', color: '#6b7280' },
    perdida: { label: 'Perdida', color: '#dc2626' },
    sin_datos: { label: 'Sin datos', color: '#9ca3af' },
};

const TIPOS_SECCION: Record<string, string> = { alfa: 'α', beta: 'β', gama: 'γ' };

const page = usePage<{
    campana: {
        partido: { id: number; siglas: string; nombre: string; color: string } | null;
        indicadores: { competitividad: boolean; tipo_seccion: boolean };
    } | null;
}>();

const GRUPOS_EDAD_ORDEN = ['18-29', '30-39', '40-49', '50-59', '60-79', '80+'];

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

const resumen = ref<Resumen | null>(null);
const aviso = ref<Aviso>(null);
const lista = ref<ListaExpuesta | null>(null);

async function cargarResumen() {
    const resp = await fetch(resumenRoute.url(props.seccion.id), {
        headers: { Accept: 'application/json' },
    });
    resumen.value = await resp.json();
}

onMounted(() => {
    cargarResumen();

    fetch(avisoVigente.url(), { headers: { Accept: 'application/json' } })
        .then((r) => r.json())
        .then((d) => (aviso.value = d.aviso));
});

// Alta de elector (modal)
const modalAbierto = ref(false);
const guardando = ref(false);
const mensaje = ref<{ tipo: 'ok' | 'error'; texto: string } | null>(null);
const form = reactive({
    nombre: '',
    telefono: '',
    email: '',
    domicilio: '',
    observaciones: '',
    consentimiento: false,
});

function csrf(): string {
    return (
        document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')
            ?.content ?? ''
    );
}

function limpiarForm() {
    form.nombre = '';
    form.telefono = '';
    form.email = '';
    form.domicilio = '';
    form.observaciones = '';
    form.consentimiento = false;
}

function abrirModal() {
    mensaje.value = null;
    limpiarForm();
    modalAbierto.value = true;
}

async function guardar() {
    if (!aviso.value) {
        mensaje.value = {
            tipo: 'error',
            texto: 'No hay aviso de privacidad vigente.',
        };

        return;
    }

    guardando.value = true;
    mensaje.value = null;

    try {
        const resp = await fetch(electoresStore.url(), {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-CSRF-TOKEN': csrf(),
            },
            body: JSON.stringify({
                modo_captura: 'enlace_seccional',
                seccion_id: props.seccion.id,
                nombre: form.nombre,
                telefono: form.telefono,
                email: form.email || null,
                domicilio: form.domicilio || null,
                observaciones: form.observaciones || null,
                consentimiento: form.consentimiento,
                aviso_privacidad_id: aviso.value.id,
            }),
        });

        if (resp.status === 201) {
            modalAbierto.value = false;
            limpiarForm();
            lista.value?.recargar();
            await cargarResumen();
        } else if (resp.status === 409) {
            mensaje.value = {
                tipo: 'error',
                texto: 'Ese teléfono ya fue capturado en esta campaña.',
            };
        } else if (resp.status === 422) {
            const data = await resp.json().catch(() => null);
            const primerError = data?.errors
                ? (Object.values(data.errors)[0] as string[] | undefined)?.[0]
                : data?.message;
            mensaje.value = {
                tipo: 'error',
                texto: primerError ?? 'Revisa los datos del formulario.',
            };
        } else {
            mensaje.value = {
                tipo: 'error',
                texto: 'No se pudo guardar. Intenta de nuevo.',
            };
        }
    } finally {
        guardando.value = false;
    }
}
</script>

<template>
    <Head :title="`Sección ${seccion.numero}`" />

    <div class="mx-auto flex w-full max-w-5xl flex-1 flex-col gap-4 p-4">
        <div class="flex items-center justify-between">
            <h1 class="flex items-center gap-1.5 text-xl font-semibold">
                Sección {{ seccion.numero }}
                <span
                    v-if="
                        page.props.campana?.indicadores.tipo_seccion &&
                        resumen?.tipo_seccion
                    "
                    class="rounded-md bg-muted px-1.5 py-0.5 text-xs font-bold text-foreground"
                >
                    {{ TIPOS_SECCION[resumen.tipo_seccion] }}
                </span>
            </h1>
            <Button @click="abrirModal">Agregar elector</Button>
        </div>

        <!-- Estadísticas -->
        <section
            v-if="resumen"
            class="rounded-xl border border-sidebar-border/70 bg-card p-4 dark:border-sidebar-border"
        >
            <div class="flex items-center justify-between">
                <h2 class="text-base font-semibold">Cobertura</h2>
                <span
                    class="rounded-full px-2.5 py-0.5 text-xs font-semibold text-white"
                    :style="{
                        backgroundColor: bucket(resumen.cobertura).color,
                    }"
                >
                    {{ bucket(resumen.cobertura).label }}
                </span>
            </div>

            <div class="mt-3 flex items-end gap-2">
                <div
                    class="text-4xl font-bold tabular-nums"
                    :style="{ color: bucket(resumen.cobertura).color }"
                >
                    {{ pct(resumen.cobertura) }}
                </div>
                <div class="pb-1 text-xs text-muted-foreground">
                    de la meta ({{ resumen.capturados }} / {{ resumen.meta }})
                </div>
            </div>
            <div class="mt-2 h-1.5 overflow-hidden rounded-full bg-muted">
                <div
                    class="h-full rounded-full"
                    :style="{
                        width: `${Math.min(resumen.cobertura * 100, 100)}%`,
                        backgroundColor: bucket(resumen.cobertura).color,
                    }"
                />
            </div>

            <dl
                class="mt-4 grid grid-cols-1 gap-x-8 divide-y divide-border text-sm sm:grid-cols-2 sm:divide-y-0"
            >
                <div class="flex justify-between py-1.5">
                    <dt class="text-muted-foreground">Capturados</dt>
                    <dd class="font-medium tabular-nums">
                        {{ fmt(resumen.capturados) }}
                    </dd>
                </div>
                <div class="flex justify-between py-1.5">
                    <dt class="text-muted-foreground">Meta</dt>
                    <dd class="font-medium tabular-nums">
                        {{ fmt(resumen.meta) }}
                    </dd>
                </div>
                <div class="flex justify-between py-1.5">
                    <dt class="text-muted-foreground">Lista nominal</dt>
                    <dd class="font-medium tabular-nums">
                        {{
                            resumen.lista_nominal
                                ? fmt(resumen.lista_nominal)
                                : '—'
                        }}
                    </dd>
                </div>
                <div class="flex justify-between py-1.5">
                    <dt class="text-muted-foreground">Penetración</dt>
                    <dd class="font-medium tabular-nums">
                        {{ pct(resumen.penetracion) }}
                    </dd>
                </div>
                <div class="flex justify-between py-1.5">
                    <dt class="text-muted-foreground">Distrito local / fed.</dt>
                    <dd class="font-medium tabular-nums">
                        {{ resumen.distrito_local ?? '—' }} /
                        {{ resumen.distrito_federal ?? '—' }}
                    </dd>
                </div>
                <div class="flex justify-between py-1.5">
                    <dt class="text-muted-foreground">Último registro</dt>
                    <dd class="font-medium">
                        {{ tiempoRelativo(resumen.ultimo_registro) }}
                    </dd>
                </div>
            </dl>

            <div v-if="resumen.brigadistas_activos.length" class="mt-3">
                <div
                    class="text-[0.7rem] font-medium tracking-wide text-muted-foreground uppercase"
                >
                    Brigadistas activos
                </div>
                <div class="mt-1 flex flex-wrap gap-1">
                    <span
                        v-for="b in resumen.brigadistas_activos"
                        :key="b.membership_id"
                        class="rounded-md bg-muted px-2 py-0.5 text-xs font-medium"
                    >
                        {{ b.nombre }}
                    </span>
                </div>
            </div>
        </section>

        <!-- Elección 2024 -->
        <section
            v-if="resumen?.electoral_2024"
            class="rounded-xl border border-sidebar-border/70 bg-card p-4 dark:border-sidebar-border"
        >
            <div class="flex items-center justify-between">
                <h2 class="text-base font-semibold">Elección 2024</h2>
                <span
                    v-if="
                        page.props.campana?.indicadores.competitividad &&
                        page.props.campana?.partido &&
                        resumen.electoral_2024.competitividad !== 'sin_datos'
                    "
                    class="flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-xs font-semibold text-white"
                    :style="{
                        backgroundColor:
                            ESTATUS_COMPETITIVIDAD[
                                resumen.electoral_2024.competitividad
                            ]?.color,
                    }"
                >
                    {{ ESTATUS_COMPETITIVIDAD[resumen.electoral_2024.competitividad]?.label }}
                    <span v-if="resumen.electoral_2024.diferencia_votos !== null">
                        · {{ resumen.electoral_2024.diferencia_votos >= 0 ? '+' : '−' }}{{
                            fmt(Math.abs(resumen.electoral_2024.diferencia_votos))
                        }}
                        votos
                    </span>
                </span>
                <span
                    v-else
                    class="rounded-full px-2.5 py-0.5 text-xs font-semibold text-white"
                    :style="{
                        backgroundColor: colorBloque(
                            resumen.electoral_2024.ganador_bloque,
                        ),
                    }"
                >
                    Ganó {{ resumen.electoral_2024.ganador_bloque }}
                </span>
            </div>

            <!-- Barra apilada de % por bloque -->
            <div class="mt-3 flex h-3 overflow-hidden rounded-full bg-muted">
                <div
                    class="h-full"
                    :style="{
                        width: `${resumen.electoral_2024.pct_fuerza}%`,
                        backgroundColor: '#db2777',
                    }"
                />
                <div
                    class="h-full"
                    :style="{
                        width: `${resumen.electoral_2024.pct_morena_pvem}%`,
                        backgroundColor: '#7c2d12',
                    }"
                />
                <div
                    class="h-full"
                    :style="{
                        width: `${resumen.electoral_2024.pct_otros}%`,
                        backgroundColor: '#f59e0b',
                    }"
                />
            </div>
            <div
                class="mt-1.5 flex flex-wrap gap-x-4 gap-y-1 text-xs text-muted-foreground"
            >
                <span class="flex items-center gap-1">
                    <span
                        class="size-2 rounded-sm"
                        style="background-color: #db2777"
                    />
                    Fuerza y Corazón
                    {{ resumen.electoral_2024.pct_fuerza.toFixed(1) }}%
                </span>
                <span class="flex items-center gap-1">
                    <span
                        class="size-2 rounded-sm"
                        style="background-color: #7c2d12"
                    />
                    Morena-PVEM
                    {{ resumen.electoral_2024.pct_morena_pvem.toFixed(1) }}%
                </span>
                <span class="flex items-center gap-1">
                    <span
                        class="size-2 rounded-sm"
                        style="background-color: #f59e0b"
                    />
                    Otros {{ resumen.electoral_2024.pct_otros.toFixed(1) }}%
                </span>
            </div>

            <dl
                class="mt-4 grid grid-cols-1 gap-x-8 divide-y divide-border text-sm sm:grid-cols-2 sm:divide-y-0"
            >
                <div class="flex justify-between py-1.5">
                    <dt class="text-muted-foreground">Margen de victoria</dt>
                    <dd class="font-medium tabular-nums">
                        {{ resumen.electoral_2024.margen_pp.toFixed(1) }} pp
                        <span
                            v-if="resumen.electoral_2024.margen_votos !== null"
                            class="text-muted-foreground"
                        >
                            ({{ fmt(resumen.electoral_2024.margen_votos) }}
                            votos)
                        </span>
                    </dd>
                </div>
                <div class="flex justify-between py-1.5">
                    <dt class="text-muted-foreground">Partido más votado</dt>
                    <dd class="font-medium">
                        {{ resumen.electoral_2024.ganador_partido ?? '—' }}
                    </dd>
                </div>
                <div class="flex justify-between py-1.5">
                    <dt class="text-muted-foreground">Participación</dt>
                    <dd class="font-medium tabular-nums">
                        {{ resumen.electoral_2024.participacion_pct.toFixed(1) }}%
                    </dd>
                </div>
                <div class="flex justify-between py-1.5">
                    <dt class="text-muted-foreground">Votos totales</dt>
                    <dd class="font-medium tabular-nums">
                        {{
                            resumen.electoral_2024.total_votos !== null
                                ? fmt(resumen.electoral_2024.total_votos)
                                : '—'
                        }}
                    </dd>
                </div>
            </dl>
        </section>

        <!-- Perfil por edad -->
        <section
            v-if="resumen?.demografia"
            class="rounded-xl border border-sidebar-border/70 bg-card p-4 dark:border-sidebar-border"
        >
            <div class="flex items-center justify-between">
                <h2 class="text-base font-semibold">Perfil por edad (2024)</h2>
                <span
                    class="rounded-full px-2.5 py-0.5 text-xs font-semibold text-white"
                    :style="{
                        backgroundColor:
                            COLORES_OPORTUNIDAD[
                                resumen.demografia.nivel_oportunidad
                            ] ?? '#9ca3af',
                    }"
                >
                    Oportunidad {{ resumen.demografia.nivel_oportunidad }} ·
                    {{ Math.round(resumen.demografia.indice_oportunidad) }}/100
                </span>
            </div>

            <!-- Participación por grupo de edad -->
            <div v-if="resumen.demografia.grupos_edad" class="mt-4 space-y-2">
                <div
                    v-for="grupo in GRUPOS_EDAD_ORDEN.filter(
                        (g) => resumen?.demografia?.grupos_edad?.[g],
                    )"
                    :key="grupo"
                    class="flex items-center gap-3 text-sm"
                >
                    <span class="w-12 shrink-0 text-muted-foreground">{{
                        grupo
                    }}</span>
                    <div
                        class="h-2.5 flex-1 overflow-hidden rounded-full bg-muted"
                    >
                        <div
                            class="h-full rounded-full"
                            :style="{
                                width: `${Math.min(resumen.demografia.grupos_edad[grupo].participacion, 100)}%`,
                                backgroundColor:
                                    grupo ===
                                    resumen.demografia.grupo_mayor_abstencion
                                        ? '#ef4444'
                                        : '#0ea5e9',
                            }"
                        />
                    </div>
                    <span
                        class="w-24 shrink-0 text-right text-xs text-muted-foreground tabular-nums"
                    >
                        {{
                            resumen.demografia.grupos_edad[
                                grupo
                            ].participacion.toFixed(0)
                        }}% de
                        {{ fmt(resumen.demografia.grupos_edad[grupo].ln) }}
                    </span>
                </div>
                <p class="text-[0.7rem] text-muted-foreground">
                    Participación 2024 por grupo de edad (barra roja: el grupo
                    con mayor abstención).
                </p>
            </div>

            <dl
                class="mt-4 grid grid-cols-1 gap-x-8 divide-y divide-border text-sm sm:grid-cols-2 sm:divide-y-0"
            >
                <div class="flex justify-between py-1.5">
                    <dt class="text-muted-foreground">
                        Grupo dominante (votos)
                    </dt>
                    <dd class="font-medium">
                        {{ resumen.demografia.grupo_dominante ?? '—' }}
                    </dd>
                </div>
                <div class="flex justify-between py-1.5">
                    <dt class="text-muted-foreground">Mayor abstención</dt>
                    <dd class="font-medium">
                        {{ resumen.demografia.grupo_mayor_abstencion ?? '—' }}
                    </dd>
                </div>
                <div class="flex justify-between py-1.5">
                    <dt class="text-muted-foreground">
                        Potencial de movilización
                    </dt>
                    <dd class="font-medium tabular-nums">
                        {{
                            resumen.demografia.potencial_movilizacion !== null
                                ? `${fmt(resumen.demografia.potencial_movilizacion)} votos`
                                : '—'
                        }}
                    </dd>
                </div>
                <div class="flex justify-between py-1.5">
                    <dt class="text-muted-foreground">Composición por edad</dt>
                    <dd class="font-medium">
                        {{ resumen.demografia.tipo_composicion_edad ?? '—' }}
                    </dd>
                </div>
            </dl>

            <p
                v-if="resumen.demografia.recomendacion"
                class="mt-3 rounded-md bg-muted px-3 py-2 text-sm text-muted-foreground"
            >
                <span class="font-semibold text-foreground"
                    >Recomendación del análisis:</span
                >
                {{ resumen.demografia.recomendacion }}
            </p>
        </section>

        <!-- Lista de electores -->
        <section
            class="rounded-xl border border-sidebar-border/70 bg-card p-4 dark:border-sidebar-border"
        >
            <h2 class="mb-3 text-base font-semibold">Electores</h2>

            <ListaCapturados
                ref="lista"
                :build-url="
                    (query) => electoresRoute.url(seccion.id, { query })
                "
                :secciones-catalogo="[seccion]"
                :modos="modos"
                unidad="electores"
            />
        </section>

        <!-- Modal de alta -->
        <Dialog v-model:open="modalAbierto">
            <DialogContent>
                <DialogHeader>
                    <DialogTitle
                        >Agregar elector a la sección
                        {{ seccion.numero }}</DialogTitle
                    >
                </DialogHeader>

                <div class="flex flex-col gap-3">
                    <p
                        v-if="mensaje"
                        :class="[
                            'rounded-md p-2 text-sm',
                            mensaje.tipo === 'ok'
                                ? 'bg-green-100 text-green-800'
                                : 'bg-red-100 text-red-800',
                        ]"
                    >
                        {{ mensaje.texto }}
                    </p>

                    <Input v-model="form.nombre" placeholder="Nombre" />
                    <Input
                        v-model="form.telefono"
                        placeholder="Teléfono (10 dígitos)"
                        inputmode="tel"
                    />
                    <Input
                        v-model="form.email"
                        type="email"
                        placeholder="Email (opcional)"
                        inputmode="email"
                    />
                    <Input
                        v-model="form.domicilio"
                        placeholder="Domicilio (opcional)"
                    />
                    <Input
                        v-model="form.observaciones"
                        placeholder="Observaciones (opcional)"
                    />

                    <label class="flex items-center gap-2 text-sm">
                        <input v-model="form.consentimiento" type="checkbox" />
                        Acepta el aviso de privacidad
                    </label>
                </div>

                <DialogFooter>
                    <Button variant="outline" @click="modalAbierto = false"
                        >Cancelar</Button
                    >
                    <Button
                        :disabled="guardando || !form.consentimiento"
                        @click="guardar"
                    >
                        Guardar elector
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </div>
</template>
