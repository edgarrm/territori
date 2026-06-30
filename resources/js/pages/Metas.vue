<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { dashboard } from '@/routes';
import { meta as metaRoute } from '@/routes/secciones';

const exportDesde = ref('');
const exportHasta = ref('');
const urlExport = computed(() => {
    const params = new URLSearchParams();
    if (exportDesde.value) params.set('desde', exportDesde.value);
    if (exportHasta.value) params.set('hasta', exportHasta.value);
    const qs = params.toString();
    return '/api/export/electores.csv' + (qs ? `?${qs}` : '');
});

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: dashboard() },
            { title: 'Metas por sección', href: '/metas' },
        ],
    },
});

type SeccionMeta = {
    seccion_id: number;
    numero: number;
    lista_nominal: number | null;
    meta_capturas: number;
    fuente_meta: 'manual' | 'lista_nominal_pct' | null;
    pct_lista_nominal: number | null;
};

const props = defineProps<{ secciones: SeccionMeta[] }>();

const borrador = ref<
    Record<
        number,
        {
            fuenteMeta: 'manual' | 'lista_nominal_pct';
            metaCapturas: number;
            pct: number;
        }
    >
>(
    Object.fromEntries(
        props.secciones.map((seccion) => [
            seccion.seccion_id,
            {
                fuenteMeta: seccion.fuente_meta ?? 'manual',
                metaCapturas: seccion.meta_capturas,
                pct: seccion.pct_lista_nominal ?? 0,
            },
        ]),
    ),
);

const guardando = ref<number | null>(null);

async function guardar(seccion: SeccionMeta) {
    const datos = borrador.value[seccion.seccion_id];
    guardando.value = seccion.seccion_id;

    try {
        const body =
            datos.fuenteMeta === 'manual'
                ? { fuente_meta: 'manual', meta_capturas: datos.metaCapturas }
                : {
                      fuente_meta: 'lista_nominal_pct',
                      pct_lista_nominal: datos.pct,
                  };

        await fetch(metaRoute.url(seccion.seccion_id), {
            method: 'PUT',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN':
                    document.querySelector<HTMLMetaElement>(
                        'meta[name="csrf-token"]',
                    )?.content ?? '',
            },
            body: JSON.stringify(body),
        });
    } finally {
        guardando.value = null;
    }
}
</script>

<template>
    <Head title="Metas por sección" />

    <div class="flex h-full flex-1 flex-col gap-4 p-4">
        <div class="flex flex-wrap items-end justify-between gap-3">
            <h1 class="text-xl font-semibold">Metas por sección</h1>

            <!-- Export CSV (coordinador/admin) -->
            <div class="flex items-end gap-2">
                <div class="flex flex-col">
                    <label class="text-xs text-muted-foreground">Desde</label>
                    <input
                        v-model="exportDesde"
                        type="date"
                        class="rounded border bg-background p-1 text-sm"
                    />
                </div>
                <div class="flex flex-col">
                    <label class="text-xs text-muted-foreground">Hasta</label>
                    <input
                        v-model="exportHasta"
                        type="date"
                        class="rounded border bg-background p-1 text-sm"
                    />
                </div>
                <a :href="urlExport">
                    <Button variant="outline">Exportar CSV</Button>
                </a>
            </div>
        </div>

        <div
            v-if="secciones.length === 0"
            class="text-sm text-muted-foreground"
        >
            No hay secciones cargadas para esta campaña.
        </div>

        <div
            v-else
            class="overflow-hidden rounded-xl border border-sidebar-border/70 dark:border-sidebar-border"
        >
            <table class="w-full text-sm">
                <thead class="bg-muted/50">
                    <tr>
                        <th class="p-2 text-left">Sección</th>
                        <th class="p-2 text-left">Lista nominal</th>
                        <th class="p-2 text-left">Fuente</th>
                        <th class="p-2 text-left">Meta</th>
                        <th class="p-2 text-left"></th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="seccion in secciones"
                        :key="seccion.seccion_id"
                        class="border-t"
                    >
                        <td class="p-2">{{ seccion.numero }}</td>
                        <td class="p-2">{{ seccion.lista_nominal ?? '—' }}</td>
                        <td class="p-2">
                            <select
                                v-model="
                                    borrador[seccion.seccion_id].fuenteMeta
                                "
                                class="rounded border bg-background p-1"
                            >
                                <option value="manual">Manual</option>
                                <option value="lista_nominal_pct">
                                    % lista nominal
                                </option>
                            </select>
                        </td>
                        <td class="p-2">
                            <Input
                                v-if="
                                    borrador[seccion.seccion_id].fuenteMeta ===
                                    'manual'
                                "
                                v-model="
                                    borrador[seccion.seccion_id].metaCapturas
                                "
                                type="number"
                                min="0"
                                class="w-24"
                                :dusk="`meta-capturas-${seccion.seccion_id}`"
                            />
                            <Input
                                v-else
                                v-model="borrador[seccion.seccion_id].pct"
                                type="number"
                                min="0"
                                max="100"
                                class="w-24"
                            />
                        </td>
                        <td class="p-2">
                            <Button
                                :disabled="guardando === seccion.seccion_id"
                                @click="guardar(seccion)"
                                :dusk="`meta-guardar-${seccion.seccion_id}`"
                            >
                                Guardar
                            </Button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
