<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { watchDebounced } from '@vueuse/core';
import { onMounted, reactive, ref } from 'vue';
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

const props = defineProps<{ seccion: { id: number; numero: number } }>();

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
};

type ElectorFila = {
    id: number;
    nombre: string;
    telefono: string;
    origen: string;
};

type Pagina = {
    data: ElectorFila[];
    current_page: number;
    last_page: number;
    total: number;
    next_page_url: string | null;
    prev_page_url: string | null;
};

type Aviso = { id: number; version: string; texto: string } | null;

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
const pagina = ref<Pagina | null>(null);
const cargandoLista = ref(false);
const aviso = ref<Aviso>(null);
const busqueda = ref('');

async function cargarResumen() {
    const resp = await fetch(resumenRoute.url(props.seccion.id), {
        headers: { Accept: 'application/json' },
    });
    resumen.value = await resp.json();
}

async function cargarElectores(url?: string) {
    cargandoLista.value = true;

    // El teléfono está cifrado: la búsqueda es solo por nombre.
    const base = electoresRoute.url(props.seccion.id, {
        query: busqueda.value.trim() ? { q: busqueda.value.trim() } : {},
    });

    try {
        const resp = await fetch(url ?? base, {
            headers: { Accept: 'application/json' },
        });
        pagina.value = await resp.json();
    } finally {
        cargandoLista.value = false;
    }
}

// Reinicia a la primera página al cambiar la búsqueda (debounce para no
// disparar una petición por tecla).
watchDebounced(busqueda, () => cargarElectores(), { debounce: 300 });

onMounted(() => {
    cargarResumen();
    cargarElectores();

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
            await Promise.all([cargarElectores(), cargarResumen()]);
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
            <h1 class="text-xl font-semibold">Sección {{ seccion.numero }}</h1>
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

        <!-- Lista de electores -->
        <section
            class="rounded-xl border border-sidebar-border/70 bg-card p-4 dark:border-sidebar-border"
        >
            <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                <h2 class="text-base font-semibold">
                    Electores
                    <span v-if="pagina" class="text-muted-foreground"
                        >({{ fmt(pagina.total) }})</span
                    >
                </h2>
                <Input
                    v-model="busqueda"
                    type="search"
                    placeholder="Buscar por nombre…"
                    class="w-full sm:w-64"
                />
            </div>

            <p
                v-if="pagina && pagina.total === 0"
                class="rounded-lg border border-dashed bg-background p-6 text-center text-sm text-muted-foreground"
            >
                {{
                    busqueda.trim()
                        ? 'Ningún elector coincide con la búsqueda.'
                        : 'Esta sección aún no tiene electores capturados.'
                }}
            </p>

            <div v-else-if="pagina" class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr
                            class="border-b text-left text-xs tracking-wide text-muted-foreground uppercase"
                        >
                            <th class="py-2 pr-4 font-medium">Nombre</th>
                            <th class="py-2 pr-4 font-medium">Teléfono</th>
                            <th class="py-2 pr-4 font-medium">Origen</th>
                            <th class="py-2 font-medium"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        <tr v-for="elector in pagina.data" :key="elector.id">
                            <td class="py-2 pr-4 font-medium">
                                {{ elector.nombre }}
                            </td>
                            <td class="py-2 pr-4 tabular-nums">
                                {{ elector.telefono }}
                            </td>
                            <td class="py-2 pr-4">
                                <span
                                    class="inline-block rounded-md bg-muted px-2 py-0.5 text-xs font-medium text-muted-foreground"
                                >
                                    {{ elector.origen }}
                                </span>
                            </td>
                            <td class="py-2 text-right">
                                <a
                                    :href="`/electores/${elector.id}`"
                                    class="font-medium text-primary hover:underline"
                                >
                                    Ver ficha
                                </a>
                            </td>
                        </tr>
                    </tbody>
                </table>

                <div
                    v-if="pagina.last_page > 1"
                    class="mt-3 flex items-center justify-between text-sm"
                >
                    <Button
                        variant="outline"
                        size="sm"
                        :disabled="!pagina.prev_page_url || cargandoLista"
                        @click="cargarElectores(pagina.prev_page_url!)"
                    >
                        Anterior
                    </Button>
                    <span class="text-muted-foreground">
                        Página {{ pagina.current_page }} de
                        {{ pagina.last_page }}
                    </span>
                    <Button
                        variant="outline"
                        size="sm"
                        :disabled="!pagina.next_page_url || cargandoLista"
                        @click="cargarElectores(pagina.next_page_url!)"
                    >
                        Siguiente
                    </Button>
                </div>
            </div>

            <p v-else class="text-sm text-muted-foreground">
                Cargando electores…
            </p>
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
