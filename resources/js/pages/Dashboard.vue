<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { dashboard } from '@/routes';

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Dashboard',
                href: dashboard(),
            },
        ],
    },
});

type Kpi = { clave: string; label: string; valor: number; sufijo?: string };
type EventoProximo = { id: number; nombre: string; tipo: string; fecha: string | null; lugar: string | null };

defineProps<{
    rol: string | null;
    kpis: Kpi[];
    eventosProximos: EventoProximo[];
    seguimientosPendientes: number;
}>();

const accesos = [
    { titulo: 'Mapa de cobertura', descripcion: 'Avance por sección', href: '/mapa' },
    { titulo: 'Captura', descripcion: 'Registrar electores', href: '/captura' },
    { titulo: 'Agenda', descripcion: 'Seguimientos del día', href: '/agenda' },
    { titulo: 'Eventos', descripcion: 'Mítines y reuniones', href: '/eventos' },
];

function numero(valor: number): string {
    return new Intl.NumberFormat('es-MX').format(valor);
}
</script>

<template>
    <Head title="Dashboard" />

    <div class="flex h-full flex-1 flex-col gap-6 p-4">
        <!-- KPIs -->
        <div class="grid auto-rows-min gap-4 sm:grid-cols-2 lg:grid-cols-4" dusk="dashboard-kpis">
            <Card v-for="kpi in kpis" :key="kpi.clave" :dusk="`kpi-${kpi.clave}`">
                <CardHeader>
                    <CardTitle class="text-sm font-medium text-muted-foreground">
                        {{ kpi.label }}
                    </CardTitle>
                </CardHeader>
                <CardContent>
                    <p class="text-3xl font-semibold tracking-tight">
                        {{ numero(kpi.valor) }}<span v-if="kpi.sufijo" class="ml-0.5 text-xl">{{ kpi.sufijo }}</span>
                    </p>
                </CardContent>
            </Card>
        </div>

        <div class="grid gap-4 lg:grid-cols-3">
            <!-- Próximos eventos -->
            <Card class="lg:col-span-2" dusk="dashboard-eventos">
                <CardHeader>
                    <CardTitle>Próximos eventos</CardTitle>
                </CardHeader>
                <CardContent class="flex flex-col gap-3">
                    <p v-if="eventosProximos.length === 0" class="text-sm text-muted-foreground">
                        No hay eventos próximos.
                    </p>
                    <Link
                        v-for="evento in eventosProximos"
                        :key="evento.id"
                        href="/eventos"
                        class="flex items-center justify-between rounded-lg border p-3 transition-colors hover:bg-muted"
                    >
                        <span>
                            <span class="font-medium">{{ evento.nombre }}</span>
                            <span class="text-sm text-muted-foreground">
                                · {{ evento.tipo }} · {{ evento.lugar }}
                            </span>
                        </span>
                        <span class="text-sm text-muted-foreground">{{ evento.fecha }}</span>
                    </Link>
                </CardContent>
            </Card>

            <!-- Seguimientos pendientes -->
            <Card dusk="dashboard-seguimientos">
                <CardHeader>
                    <CardTitle>Seguimientos pendientes</CardTitle>
                </CardHeader>
                <CardContent class="flex flex-col gap-3">
                    <p class="text-4xl font-semibold tracking-tight">{{ numero(seguimientosPendientes) }}</p>
                    <Link href="/agenda" class="text-sm text-primary underline-offset-4 hover:underline">
                        Ir a la agenda →
                    </Link>
                </CardContent>
            </Card>
        </div>

        <!-- Accesos rápidos -->
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <Link
                v-for="acceso in accesos"
                :key="acceso.href"
                :href="acceso.href"
                class="rounded-xl border p-4 transition-colors hover:bg-muted"
            >
                <div class="font-medium">{{ acceso.titulo }}</div>
                <div class="text-sm text-muted-foreground">{{ acceso.descripcion }}</div>
            </Link>
        </div>
    </div>
</template>
