<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { dashboard } from '@/routes';
import {
    store,
    activo as activoRoute,
    zonas as zonasRoute,
} from '@/routes/brigadistas';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: dashboard() },
            { title: 'Miembros', href: '/brigadistas' },
        ],
    },
});

type Brigadista = {
    membership_id: number;
    nombre: string | null;
    email: string | null;
    activo: boolean;
    meta_diaria: number | null;
    capturas_dia: number;
    capturas_total: number;
    pct_completos: number;
    avance_meta: number | null;
    secciones_asignadas: number;
    secciones_ids: number[];
};

type Miembro = {
    membership_id: number;
    nombre: string | null;
    email: string | null;
    rol: string;
    activo: boolean;
    secciones_ids: number[];
};

type Seccion = { id: number; numero: number };

const props = defineProps<{
    brigadistas: Brigadista[];
    otrosMiembros: Miembro[];
    rolesAsignables: string[];
    secciones: Seccion[];
    facturacion: {
        activos: number;
        limite: number | null;
        puede_activar: boolean;
    };
}>();

const ETIQUETA_ROL: Record<string, string> = {
    admin: 'Administrador',
    coordinador: 'Coordinador',
    brigadista: 'Brigadista',
    enlace: 'Enlace',
    anfitrion: 'Anfitrión',
};

function etiquetaRol(rol: string): string {
    return ETIQUETA_ROL[rol] ?? rol;
}

const rolInicial = props.rolesAsignables.includes('brigadista')
    ? 'brigadista'
    : (props.rolesAsignables[0] ?? '');

const alta = ref({ email: '', name: '', rol: rolInicial, meta_diaria: 0 });
const errorAlta = ref<string | null>(null);
const procesando = ref(false);

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

async function agregar() {
    errorAlta.value = null;
    procesando.value = true;

    try {
        const res = await enviar(store.url(), 'POST', alta.value);

        if (res.status === 422) {
            errorAlta.value =
                (await res.json()).message ?? 'No se pudo agregar.';

            return;
        }

        alta.value = { email: '', name: '', rol: rolInicial, meta_diaria: 0 };
        router.reload({
            only: ['brigadistas', 'otrosMiembros', 'facturacion'],
        });
    } finally {
        procesando.value = false;
    }
}

async function alternarActivo(b: Brigadista) {
    const res = await enviar(activoRoute.url(b.membership_id), 'PUT', {
        activo: !b.activo,
    });

    if (res.status === 422) {
        alert((await res.json()).message ?? 'Límite de brigadistas alcanzado.');

        return;
    }

    router.reload({ only: ['brigadistas', 'facturacion'] });
}

// Editor de zonas: aplica a brigadistas y anfitriones (sus zonas acotan
// dónde capturan / crean loterías).
const zonasAbiertas = ref<number | null>(null);
const seleccion = ref<Set<number>>(new Set());

function abrirZonas(m: { membership_id: number; secciones_ids: number[] }) {
    zonasAbiertas.value = m.membership_id;
    seleccion.value = new Set(m.secciones_ids);
}

function toggleSeccion(id: number) {
    if (seleccion.value.has(id)) {
        seleccion.value.delete(id);
    } else {
        seleccion.value.add(id);
    }

    seleccion.value = new Set(seleccion.value);
}

function seleccionarTodas() {
    seleccion.value = new Set(props.secciones.map((s) => s.id));
}

function vaciarSeleccion() {
    seleccion.value = new Set();
}

async function guardarZonas(m: { membership_id: number }) {
    await enviar(zonasRoute.url(m.membership_id), 'PUT', {
        seccion_ids: [...seleccion.value],
    });
    zonasAbiertas.value = null;
    router.reload({ only: ['brigadistas', 'otrosMiembros'] });
}
</script>

<template>
    <Head title="Miembros" />

    <div class="flex h-full flex-1 flex-col gap-4 p-4">
        <h1 class="text-xl font-semibold">Miembros</h1>

        <div
            class="flex items-center justify-between rounded-xl border p-3 text-sm"
            :class="
                facturacion.puede_activar
                    ? 'border-emerald-500/40 bg-emerald-500/5'
                    : 'border-amber-500/50 bg-amber-500/10'
            "
        >
            <span>
                <strong>{{ facturacion.activos }}</strong>
                /
                {{ facturacion.limite ?? '∞' }}
                brigadistas activos
            </span>
            <span
                v-if="!facturacion.puede_activar"
                class="text-amber-700 dark:text-amber-400"
            >
                Alcanzaste el límite de tu plan. Actualiza para activar a más.
            </span>
        </div>

        <form class="flex flex-wrap items-end gap-2" @submit.prevent="agregar">
            <div class="flex flex-col gap-1">
                <label class="text-xs text-muted-foreground">Email</label>
                <Input
                    v-model="alta.email"
                    type="email"
                    required
                    placeholder="miembro@x.com"
                />
            </div>
            <div class="flex flex-col gap-1">
                <label class="text-xs text-muted-foreground">Nombre</label>
                <Input v-model="alta.name" type="text" placeholder="Nombre" />
            </div>
            <div class="flex flex-col gap-1">
                <label class="text-xs text-muted-foreground">Rol</label>
                <select
                    v-model="alta.rol"
                    class="h-9 rounded-md border border-input bg-background px-3 text-sm"
                >
                    <option
                        v-for="rol in rolesAsignables"
                        :key="rol"
                        :value="rol"
                    >
                        {{ etiquetaRol(rol) }}
                    </option>
                </select>
            </div>
            <div v-if="alta.rol === 'brigadista'" class="flex flex-col gap-1">
                <label class="text-xs text-muted-foreground">Meta diaria</label>
                <Input
                    v-model="alta.meta_diaria"
                    type="number"
                    min="0"
                    class="w-24"
                />
            </div>
            <Button type="submit" :disabled="procesando || !alta.rol"
                >Agregar miembro</Button
            >
            <p v-if="errorAlta" class="w-full text-sm text-red-600">
                {{ errorAlta }}
            </p>
        </form>

        <h2 class="mt-2 text-base font-semibold">Brigadistas</h2>

        <div
            v-if="brigadistas.length === 0"
            class="text-sm text-muted-foreground"
        >
            Aún no hay brigadistas en esta campaña.
        </div>

        <div
            v-else
            class="overflow-hidden rounded-xl border border-sidebar-border/70 dark:border-sidebar-border"
        >
            <table class="w-full text-sm">
                <thead class="bg-muted/50">
                    <tr>
                        <th class="p-2 text-left">Brigadista</th>
                        <th class="p-2 text-left">Activo</th>
                        <th class="p-2 text-left">Hoy</th>
                        <th class="p-2 text-left">Avance meta</th>
                        <th class="p-2 text-left">Total</th>
                        <th class="p-2 text-left">% completos</th>
                        <th class="p-2 text-left">Zonas</th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="b in brigadistas"
                        :key="b.membership_id"
                        class="border-t align-top"
                    >
                        <td class="p-2">
                            <div class="font-medium">
                                {{ b.nombre ?? b.email }}
                            </div>
                            <div class="text-xs text-muted-foreground">
                                {{ b.email }}
                            </div>
                        </td>
                        <td class="p-2">
                            <button
                                class="rounded px-2 py-1 text-xs"
                                :class="
                                    b.activo
                                        ? 'bg-emerald-500/20 text-emerald-700'
                                        : 'bg-muted text-muted-foreground'
                                "
                                @click="alternarActivo(b)"
                            >
                                {{ b.activo ? 'Activo' : 'Inactivo' }}
                            </button>
                        </td>
                        <td class="p-2">{{ b.capturas_dia }}</td>
                        <td class="p-2">
                            <span v-if="b.avance_meta !== null">
                                {{ Math.round(b.avance_meta * 100) }}%
                            </span>
                            <span v-else class="text-muted-foreground">—</span>
                        </td>
                        <td class="p-2">{{ b.capturas_total }}</td>
                        <td class="p-2">
                            {{ Math.round(b.pct_completos * 100) }}%
                        </td>
                        <td class="p-2">
                            <button
                                class="text-xs underline"
                                @click="abrirZonas(b)"
                            >
                                {{ b.secciones_asignadas }} secciones
                            </button>
                            <div
                                v-if="zonasAbiertas === b.membership_id"
                                class="mt-2 w-56 rounded border bg-background p-2"
                            >
                                <div
                                    class="mb-2 flex items-center justify-between gap-2"
                                >
                                    <span class="text-xs text-muted-foreground">
                                        {{ seleccion.size }} de
                                        {{ secciones.length }}
                                    </span>
                                    <div class="flex gap-1">
                                        <button
                                            type="button"
                                            class="rounded border px-2 py-0.5 text-xs hover:bg-muted"
                                            @click="seleccionarTodas"
                                        >
                                            Todas
                                        </button>
                                        <button
                                            type="button"
                                            class="rounded border px-2 py-0.5 text-xs hover:bg-muted"
                                            @click="vaciarSeleccion"
                                        >
                                            Vaciar
                                        </button>
                                    </div>
                                </div>
                                <div
                                    class="max-h-48 overflow-auto rounded border bg-background p-2"
                                >
                                    <label
                                        v-for="s in secciones"
                                        :key="s.id"
                                        class="flex items-center gap-2 py-0.5 text-xs"
                                    >
                                        <input
                                            type="checkbox"
                                            :checked="seleccion.has(s.id)"
                                            @change="toggleSeccion(s.id)"
                                        />
                                        {{ s.numero }}
                                    </label>
                                </div>
                                <Button
                                    class="mt-2 w-full"
                                    @click="guardarZonas(b)"
                                    >Guardar zonas</Button
                                >
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <h2 class="mt-4 text-base font-semibold">Otros miembros</h2>

        <div
            v-if="otrosMiembros.length === 0"
            class="text-sm text-muted-foreground"
        >
            Aún no hay coordinadores ni enlaces en esta campaña.
        </div>

        <div
            v-else
            class="overflow-hidden rounded-xl border border-sidebar-border/70 dark:border-sidebar-border"
        >
            <table class="w-full text-sm">
                <thead class="bg-muted/50">
                    <tr>
                        <th class="p-2 text-left">Miembro</th>
                        <th class="p-2 text-left">Rol</th>
                        <th class="p-2 text-left">Activo</th>
                        <th class="p-2 text-left">Zonas</th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="m in otrosMiembros"
                        :key="m.membership_id"
                        class="border-t align-top"
                    >
                        <td class="p-2">
                            <div class="font-medium">
                                {{ m.nombre ?? m.email }}
                            </div>
                            <div class="text-xs text-muted-foreground">
                                {{ m.email }}
                            </div>
                        </td>
                        <td class="p-2">{{ etiquetaRol(m.rol) }}</td>
                        <td class="p-2">{{ m.activo ? 'Sí' : 'No' }}</td>
                        <td class="p-2">
                            <template v-if="m.rol === 'anfitrion'">
                                <button
                                    class="text-xs underline"
                                    @click="abrirZonas(m)"
                                >
                                    {{ m.secciones_ids.length }} secciones
                                </button>
                                <div
                                    v-if="zonasAbiertas === m.membership_id"
                                    class="mt-2 w-56 rounded border bg-background p-2"
                                >
                                    <div
                                        class="mb-2 flex items-center justify-between gap-2"
                                    >
                                        <span
                                            class="text-xs text-muted-foreground"
                                        >
                                            {{ seleccion.size }} de
                                            {{ secciones.length }}
                                        </span>
                                        <div class="flex gap-1">
                                            <button
                                                type="button"
                                                class="rounded border px-2 py-0.5 text-xs hover:bg-muted"
                                                @click="seleccionarTodas"
                                            >
                                                Todas
                                            </button>
                                            <button
                                                type="button"
                                                class="rounded border px-2 py-0.5 text-xs hover:bg-muted"
                                                @click="vaciarSeleccion"
                                            >
                                                Vaciar
                                            </button>
                                        </div>
                                    </div>
                                    <div
                                        class="max-h-48 overflow-auto rounded border bg-background p-2"
                                    >
                                        <label
                                            v-for="s in secciones"
                                            :key="s.id"
                                            class="flex items-center gap-2 py-0.5 text-xs"
                                        >
                                            <input
                                                type="checkbox"
                                                :checked="seleccion.has(s.id)"
                                                @change="toggleSeccion(s.id)"
                                            />
                                            {{ s.numero }}
                                        </label>
                                    </div>
                                    <Button
                                        class="mt-2 w-full"
                                        @click="guardarZonas(m)"
                                        >Guardar zonas</Button
                                    >
                                </div>
                            </template>
                            <span v-else class="text-xs text-muted-foreground"
                                >—</span
                            >
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
