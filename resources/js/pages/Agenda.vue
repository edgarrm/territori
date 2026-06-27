<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { ref } from 'vue';
import { Button } from '@/components/ui/button';
import { dashboard } from '@/routes';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: dashboard() },
            { title: 'Agenda', href: '/agenda' },
        ],
    },
});

type Pendiente = {
    id: number;
    tipo: string;
    nota: string | null;
    proximo_seguimiento: string | null;
    elector: { id: number; nombre: string; seccion_id: number };
};

const props = defineProps<{ pendientes: Pendiente[] }>();

const lista = ref<Pendiente[]>([...props.pendientes]);
const marcando = ref<number | null>(null);

function csrf(): string {
    return (
        document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')
            ?.content ?? ''
    );
}

async function marcarAtendido(item: Pendiente) {
    marcando.value = item.id;
    const previo = [...lista.value];
    // Optimista: lo sacamos de inmediato.
    lista.value = lista.value.filter((p) => p.id !== item.id);

    try {
        const res = await fetch(`/api/interacciones/${item.id}/atendido`, {
            method: 'PUT',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrf(),
            },
        });
        if (!res.ok) {
            lista.value = previo; // rollback
        }
    } catch {
        lista.value = previo;
    } finally {
        marcando.value = null;
    }
}
</script>

<template>
    <Head title="Agenda" />

    <div class="flex h-full flex-1 flex-col gap-4 p-4">
        <h1 class="text-xl font-semibold">Agenda del día</h1>
        <p class="text-sm text-muted-foreground">
            Seguimientos vencidos pendientes de atender.
        </p>

        <div
            v-if="lista.length === 0"
            class="rounded-xl border border-dashed border-sidebar-border/70 p-8 text-center text-sm text-muted-foreground dark:border-sidebar-border"
        >
            No tienes seguimientos pendientes. 🎉
        </div>

        <ul v-else class="flex flex-col gap-2">
            <li
                v-for="item in lista"
                :key="item.id"
                class="flex items-center justify-between gap-3 rounded-xl border border-sidebar-border/70 p-3 dark:border-sidebar-border"
            >
                <div class="min-w-0">
                    <Link
                        :href="`/electores/${item.elector.id}`"
                        class="font-medium hover:underline"
                    >
                        {{ item.elector.nombre }}
                    </Link>
                    <div class="text-xs text-muted-foreground">
                        Sección {{ item.elector.seccion_id }} ·
                        {{ item.tipo }} ·
                        {{ item.proximo_seguimiento }}
                    </div>
                    <p
                        v-if="item.nota"
                        class="truncate text-xs text-muted-foreground"
                    >
                        {{ item.nota }}
                    </p>
                </div>
                <Button
                    size="sm"
                    variant="outline"
                    :disabled="marcando === item.id"
                    @click="marcarAtendido(item)"
                >
                    Atendido
                </Button>
            </li>
        </ul>
    </div>
</template>
