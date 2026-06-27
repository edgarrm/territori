<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { dashboard } from '@/routes';
import { store, activo as activoRoute, zonas as zonasRoute } from '@/routes/brigadistas';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: dashboard() },
            { title: 'Brigadistas', href: '/brigadistas' },
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

type Seccion = { id: number; numero: number };

const props = defineProps<{
    brigadistas: Brigadista[];
    secciones: Seccion[];
    facturacion: { activos: number; limite: number | null; puede_activar: boolean };
}>();

const alta = ref({ email: '', name: '', meta_diaria: 0 });
const errorAlta = ref<string | null>(null);
const procesando = ref(false);

function csrf(): string {
    return (
        document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? ''
    );
}

async function enviar(url: string, method: string, body?: unknown): Promise<Response> {
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
            errorAlta.value = (await res.json()).message ?? 'No se pudo agregar.';
            return;
        }
        alta.value = { email: '', name: '', meta_diaria: 0 };
        router.reload({ only: ['brigadistas', 'facturacion'] });
    } finally {
        procesando.value = false;
    }
}

async function alternarActivo(b: Brigadista) {
    const res = await enviar(activoRoute.url(b.membership_id), 'PUT', { activo: !b.activo });
    if (res.status === 422) {
        alert((await res.json()).message ?? 'Límite de brigadistas alcanzado.');
        return;
    }
    router.reload({ only: ['brigadistas', 'facturacion'] });
}

const zonasAbiertas = ref<number | null>(null);
const seleccion = ref<Set<number>>(new Set());

function abrirZonas(b: Brigadista) {
    zonasAbiertas.value = b.membership_id;
    seleccion.value = new Set(b.secciones_ids);
}

function toggleSeccion(id: number) {
    if (seleccion.value.has(id)) {
        seleccion.value.delete(id);
    } else {
        seleccion.value.add(id);
    }
    seleccion.value = new Set(seleccion.value);
}

async function guardarZonas(b: Brigadista) {
    await enviar(zonasRoute.url(b.membership_id), 'PUT', {
        seccion_ids: [...seleccion.value],
    });
    zonasAbiertas.value = null;
    router.reload({ only: ['brigadistas'] });
}
</script>

<template>
    <Head title="Brigadistas" />

    <div class="flex h-full flex-1 flex-col gap-4 p-4">
        <h1 class="text-xl font-semibold">Brigadistas</h1>

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
            <span v-if="!facturacion.puede_activar" class="text-amber-700 dark:text-amber-400">
                Alcanzaste el límite de tu plan. Actualiza para activar a más.
            </span>
        </div>

        <form class="flex flex-wrap items-end gap-2" @submit.prevent="agregar">
            <div class="flex flex-col gap-1">
                <label class="text-xs text-muted-foreground">Email</label>
                <Input v-model="alta.email" type="email" required placeholder="brigadista@x.com" />
            </div>
            <div class="flex flex-col gap-1">
                <label class="text-xs text-muted-foreground">Nombre</label>
                <Input v-model="alta.name" type="text" placeholder="Nombre" />
            </div>
            <div class="flex flex-col gap-1">
                <label class="text-xs text-muted-foreground">Meta diaria</label>
                <Input v-model="alta.meta_diaria" type="number" min="0" class="w-24" />
            </div>
            <Button type="submit" :disabled="procesando">Agregar brigadista</Button>
            <p v-if="errorAlta" class="w-full text-sm text-red-600">{{ errorAlta }}</p>
        </form>

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
                    <tr v-for="b in brigadistas" :key="b.membership_id" class="border-t align-top">
                        <td class="p-2">
                            <div class="font-medium">{{ b.nombre ?? b.email }}</div>
                            <div class="text-xs text-muted-foreground">{{ b.email }}</div>
                        </td>
                        <td class="p-2">
                            <button
                                class="rounded px-2 py-1 text-xs"
                                :class="b.activo ? 'bg-emerald-500/20 text-emerald-700' : 'bg-muted text-muted-foreground'"
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
                        <td class="p-2">{{ Math.round(b.pct_completos * 100) }}%</td>
                        <td class="p-2">
                            <button class="text-xs underline" @click="abrirZonas(b)">
                                {{ b.secciones_asignadas }} secciones
                            </button>
                            <div
                                v-if="zonasAbiertas === b.membership_id"
                                class="mt-2 max-h-48 w-48 overflow-auto rounded border bg-background p-2"
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
                                <Button class="mt-2 w-full" @click="guardarZonas(b)">Guardar zonas</Button>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
