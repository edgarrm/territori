<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import { Button } from '@/components/ui/button';
import { dashboard } from '@/routes';
import {
    atendido as atendidoRoute,
    index as indexRoute,
} from '@/routes/solicitudes-arco';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: dashboard() },
            { title: 'Solicitudes ARCO', href: '/solicitudes-arco' },
        ],
    },
});

type Tipo = 'acceso' | 'rectificacion' | 'cancelacion' | 'oposicion';

type Solicitud = {
    id: number;
    tipo: Tipo;
    estado: 'pendiente' | 'atendida';
    elector_id: number | null;
    elector: { id: number; nombre: string } | null;
    solicitado_en: string | null;
    atendido_en: string | null;
};

const props = defineProps<{
    estado: 'pendiente' | 'atendida';
    solicitudes: Solicitud[];
}>();

const TIPOS: Record<Tipo, { label: string; clase: string }> = {
    acceso: {
        label: 'Acceso',
        clase: 'bg-sky-500/15 text-sky-700 dark:text-sky-300',
    },
    rectificacion: {
        label: 'Rectificación',
        clase: 'bg-amber-500/15 text-amber-700 dark:text-amber-300',
    },
    cancelacion: {
        label: 'Cancelación',
        clase: 'bg-red-500/15 text-red-700 dark:text-red-300',
    },
    oposicion: {
        label: 'Oposición',
        clase: 'bg-violet-500/15 text-violet-700 dark:text-violet-300',
    },
};

const procesando = ref<number | null>(null);

function csrf(): string {
    return (
        document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')
            ?.content ?? ''
    );
}

function fecha(iso: string | null): string {
    if (!iso) {
        return '—';
    }

    return new Date(iso).toLocaleDateString('es-MX', {
        day: '2-digit',
        month: 'short',
        year: 'numeric',
    });
}

function cambiarFiltro(estado: 'pendiente' | 'atendida') {
    router.get(
        indexRoute.url({ query: { estado } }),
        {},
        { only: ['estado', 'solicitudes'], preserveScroll: true },
    );
}

async function atender(s: Solicitud) {
    if (
        s.tipo === 'cancelacion' &&
        !window.confirm(
            'Atender esta cancelación dará de baja al elector y borrará sus datos personales. Esta acción no se puede deshacer. ¿Continuar?',
        )
    ) {
        return;
    }

    procesando.value = s.id;

    try {
        await fetch(atendidoRoute.url(s.id), {
            method: 'PUT',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrf(),
            },
        });
        router.reload({ only: ['solicitudes'] });
    } finally {
        procesando.value = null;
    }
}
</script>

<template>
    <Head title="Solicitudes ARCO" />

    <div class="flex h-full flex-1 flex-col gap-4 p-4">
        <div class="flex items-center justify-between">
            <h1 class="text-xl font-semibold">Solicitudes ARCO</h1>
            <div class="flex gap-1 rounded-lg bg-muted p-1 text-sm">
                <button
                    type="button"
                    class="rounded-md px-3 py-1.5 font-medium transition-colors"
                    :class="
                        props.estado === 'pendiente'
                            ? 'bg-background text-foreground shadow-sm'
                            : 'text-muted-foreground hover:text-foreground'
                    "
                    @click="cambiarFiltro('pendiente')"
                >
                    Pendientes
                </button>
                <button
                    type="button"
                    class="rounded-md px-3 py-1.5 font-medium transition-colors"
                    :class="
                        props.estado === 'atendida'
                            ? 'bg-background text-foreground shadow-sm'
                            : 'text-muted-foreground hover:text-foreground'
                    "
                    @click="cambiarFiltro('atendida')"
                >
                    Atendidas
                </button>
            </div>
        </div>

        <p class="text-sm text-muted-foreground">
            Derechos de Acceso, Rectificación, Cancelación y Oposición
            (LFPDPPP). El brigadista registra; coordinación las atiende.
        </p>

        <div v-if="props.solicitudes.length === 0" class="text-sm text-muted-foreground">
            {{
                props.estado === 'pendiente'
                    ? 'No hay solicitudes pendientes. ✅'
                    : 'Aún no hay solicitudes atendidas.'
            }}
        </div>

        <div
            v-else
            class="overflow-hidden rounded-xl border border-sidebar-border/70 dark:border-sidebar-border"
        >
            <table class="w-full text-sm">
                <thead class="bg-muted/50">
                    <tr>
                        <th class="p-2 text-left">Tipo</th>
                        <th class="p-2 text-left">Elector</th>
                        <th class="p-2 text-left">Solicitado</th>
                        <th class="p-2 text-left">
                            {{ props.estado === 'atendida' ? 'Atendido' : 'Acción' }}
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="s in props.solicitudes"
                        :key="s.id"
                        class="border-t align-middle"
                    >
                        <td class="p-2">
                            <span
                                class="rounded-full px-2.5 py-0.5 text-xs font-semibold"
                                :class="TIPOS[s.tipo].clase"
                            >
                                {{ TIPOS[s.tipo].label }}
                            </span>
                        </td>
                        <td class="p-2">
                            <Link
                                v-if="s.elector"
                                :href="`/electores/${s.elector.id}`"
                                class="font-medium underline-offset-2 hover:underline"
                            >
                                {{ s.elector.nombre }}
                            </Link>
                            <span
                                v-else-if="s.elector_id"
                                class="text-muted-foreground"
                            >
                                Elector dado de baja
                            </span>
                            <span v-else class="text-muted-foreground">—</span>
                        </td>
                        <td class="p-2 text-muted-foreground">
                            {{ fecha(s.solicitado_en) }}
                        </td>
                        <td class="p-2">
                            <span
                                v-if="s.estado === 'atendida'"
                                class="text-muted-foreground"
                            >
                                {{ fecha(s.atendido_en) }}
                            </span>
                            <Button
                                v-else
                                size="sm"
                                :variant="
                                    s.tipo === 'cancelacion'
                                        ? 'destructive'
                                        : 'outline'
                                "
                                :disabled="procesando === s.id"
                                @click="atender(s)"
                            >
                                {{
                                    s.tipo === 'cancelacion'
                                        ? 'Atender y dar de baja'
                                        : 'Marcar atendida'
                                }}
                            </Button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
