<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
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
import { mapa } from '@/routes';
import { vigente as avisoVigente } from '@/routes/avisos';
import { store as electoresStore } from '@/routes/electores';
import {
    electores as loteriaElectores,
    store as loteriaStore,
} from '@/routes/loterias';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Loterías', href: '/loterias' }],
    },
});

type Loteria = {
    id: number;
    nombre: string;
    fecha: string;
    seccion_id: number;
    encargado: string | null;
    creador: string | null;
    capturados_count: number;
};

type Seccion = { id: number; numero: number };
type MiembroOpcion = { membership_id: number; nombre: string; rol: string };
type Aviso = { id: number; version: string; texto: string } | null;

const props = defineProps<{
    loterias: Loteria[];
    secciones: Seccion[];
    miembros: MiembroOpcion[];
    esGestion: boolean;
}>();

function seccionLabel(seccionId: number): string {
    const numero = props.secciones.find((s) => s.id === seccionId)?.numero;

    return numero !== undefined ? `Sección ${numero}` : 'Sección';
}

// La carga del aviso vive en onMounted (solo cliente): un fetch en el cuerpo de
// setup se ejecuta también en SSR, donde fetch exige URL absoluta y una ruta
// relativa revienta con ERR_INVALID_URL.
const aviso = ref<Aviso>(null);
onMounted(() => {
    fetch(avisoVigente.url(), { headers: { Accept: 'application/json' } })
        .then((r) => r.json())
        .then((d) => (aviso.value = d.aviso));
});

function csrf(): string {
    return (
        document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')
            ?.content ?? ''
    );
}

// --- Crear lotería ---
const form = useForm({
    nombre: '',
    fecha: '',
    seccion_id: props.secciones[0]?.id ?? (null as number | null),
    asignado_membership_id: null as number | null,
});

function crear() {
    form.post(loteriaStore.url(), {
        preserveScroll: true,
        onSuccess: () =>
            form.reset('nombre', 'fecha', 'asignado_membership_id'),
    });
}

// --- Capturados bajo demanda por lotería ---
const capturados = ref<
    Record<
        number,
        Array<{ id: number; nombre: string; telefono: string | null }>
    >
>({});

async function verCapturados(id: number) {
    if (capturados.value[id]) {
        delete capturados.value[id];

        return;
    }

    const res = await fetch(loteriaElectores.url(id), {
        headers: { Accept: 'application/json' },
    });

    if (res.ok) {
        capturados.value[id] = (await res.json()).electores;
    }
}

// --- Capturar elector en una lotería (modal) ---
const modalAbierto = ref(false);
const loteriaActiva = ref<Loteria | null>(null);
const guardando = ref(false);
const mensaje = ref<{ tipo: 'ok' | 'error'; texto: string } | null>(null);
const captura = reactive({
    nombre: '',
    telefono: '',
    email: '',
    consentimiento: false,
});

function limpiarCaptura() {
    captura.nombre = '';
    captura.telefono = '';
    captura.email = '';
    captura.consentimiento = false;
}

function abrirModal(loteria: Loteria) {
    mensaje.value = null;
    limpiarCaptura();
    loteriaActiva.value = loteria;
    modalAbierto.value = true;
}

async function guardarCaptura() {
    if (!aviso.value || !loteriaActiva.value) {
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
                modo_captura: 'loteria',
                loteria_id: loteriaActiva.value.id,
                nombre: captura.nombre,
                telefono: captura.telefono,
                email: captura.email || null,
                consentimiento: captura.consentimiento,
                aviso_privacidad_id: aviso.value.id,
            }),
        });

        if (resp.status === 201) {
            limpiarCaptura();
            mensaje.value = { tipo: 'ok', texto: 'Elector capturado.' };
            delete capturados.value[loteriaActiva.value.id];
            router.reload({ only: ['loterias'] });
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
    <Head title="Loterías" />

    <div class="flex h-full flex-1 flex-col gap-4 p-4">
        <h1 class="text-xl font-semibold">Loterías</h1>

        <!-- Alta -->
        <div
            class="flex flex-col gap-2 rounded-xl border border-sidebar-border/70 p-4 md:flex-row md:items-end dark:border-sidebar-border"
        >
            <div class="flex flex-1 flex-col gap-1">
                <label class="text-sm font-medium">Nombre</label>
                <Input
                    v-model="form.nombre"
                    placeholder="Lotería Zona Centro"
                    dusk="loteria-nombre"
                />
            </div>
            <div class="flex flex-col gap-1">
                <label class="text-sm font-medium">Fecha</label>
                <input
                    v-model="form.fecha"
                    type="date"
                    class="rounded border bg-background p-2"
                    dusk="loteria-fecha"
                />
            </div>
            <div class="flex flex-col gap-1">
                <label class="text-sm font-medium">Sección</label>
                <select
                    v-model.number="form.seccion_id"
                    class="rounded border bg-background p-2"
                    dusk="loteria-seccion"
                >
                    <option
                        v-for="seccion in secciones"
                        :key="seccion.id"
                        :value="seccion.id"
                    >
                        Sección {{ seccion.numero }}
                    </option>
                </select>
            </div>
            <div v-if="esGestion" class="flex flex-col gap-1">
                <label class="text-sm font-medium">Asignar a</label>
                <select
                    v-model.number="form.asignado_membership_id"
                    class="rounded border bg-background p-2"
                    dusk="loteria-asignado"
                >
                    <option :value="null">Yo mismo</option>
                    <option
                        v-for="miembro in miembros"
                        :key="miembro.membership_id"
                        :value="miembro.membership_id"
                    >
                        {{ miembro.nombre }} ({{ miembro.rol }})
                    </option>
                </select>
            </div>
            <Button
                :disabled="form.processing"
                @click="crear"
                dusk="loteria-crear"
                >Crear</Button
            >
        </div>

        <p v-if="secciones.length === 0" class="text-sm text-muted-foreground">
            No tienes secciones asignadas; pide a gestión que te asigne zonas
            para crear loterías.
        </p>

        <p v-if="form.errors.seccion_id" class="text-sm text-destructive">
            {{ form.errors.seccion_id }}
        </p>
        <p v-if="form.errors.nombre" class="text-sm text-destructive">
            {{ form.errors.nombre }}
        </p>
        <p v-if="form.errors.fecha" class="text-sm text-destructive">
            {{ form.errors.fecha }}
        </p>
        <p
            v-if="form.errors.asignado_membership_id"
            class="text-sm text-destructive"
        >
            {{ form.errors.asignado_membership_id }}
        </p>

        <!-- Lista -->
        <div v-if="loterias.length === 0" class="text-sm text-muted-foreground">
            Aún no hay loterías.
        </div>
        <ul v-else class="flex flex-col gap-2">
            <li
                v-for="loteria in loterias"
                :key="loteria.id"
                class="rounded-xl border border-sidebar-border/70 p-3 dark:border-sidebar-border"
            >
                <div class="flex items-center justify-between gap-3">
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="font-medium">{{ loteria.nombre }}</span>
                        <Link
                            :href="
                                mapa.url({
                                    query: { seccion: loteria.seccion_id },
                                })
                            "
                            class="rounded-full bg-sky-100 px-2 py-0.5 text-xs font-medium text-sky-800 hover:underline dark:bg-sky-900/40 dark:text-sky-200"
                        >
                            {{ seccionLabel(loteria.seccion_id) }}
                        </Link>
                        <span class="text-xs text-muted-foreground">
                            · {{ new Date(loteria.fecha).toLocaleDateString() }}
                            <template v-if="loteria.encargado">
                                · Encargado: {{ loteria.encargado }}</template
                            >
                            <template
                                v-if="
                                    loteria.creador &&
                                    loteria.creador !== loteria.encargado
                                "
                            >
                                · Creada por {{ loteria.creador }}</template
                            >
                        </span>
                    </div>
                    <div class="flex shrink-0 gap-2">
                        <Button
                            size="sm"
                            variant="outline"
                            @click="verCapturados(loteria.id)"
                        >
                            {{ loteria.capturados_count }} capturados
                        </Button>
                        <Button
                            size="sm"
                            @click="abrirModal(loteria)"
                            dusk="loteria-capturar"
                        >
                            Capturar
                        </Button>
                    </div>
                </div>
                <ul
                    v-if="capturados[loteria.id]"
                    class="mt-2 flex flex-col gap-1 border-t pt-2 text-sm"
                >
                    <li
                        v-for="c in capturados[loteria.id]"
                        :key="c.id"
                        class="flex justify-between text-muted-foreground"
                    >
                        <a :href="`/electores/${c.id}`" class="hover:underline">
                            {{ c.nombre }}
                        </a>
                        <span v-if="c.telefono">{{ c.telefono }}</span>
                    </li>
                    <li
                        v-if="capturados[loteria.id].length === 0"
                        class="text-muted-foreground"
                    >
                        Sin capturados todavía.
                    </li>
                </ul>
            </li>
        </ul>

        <!-- Modal: capturar elector en la lotería -->
        <Dialog v-model:open="modalAbierto">
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>
                        Capturar · {{ loteriaActiva?.nombre }}
                    </DialogTitle>
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

                    <Input
                        v-model="captura.nombre"
                        placeholder="Nombre"
                        dusk="captura-loteria-nombre"
                    />
                    <Input
                        v-model="captura.telefono"
                        placeholder="Teléfono (10 dígitos)"
                        inputmode="tel"
                        dusk="captura-loteria-telefono"
                    />
                    <Input
                        v-model="captura.email"
                        type="email"
                        placeholder="Email (opcional)"
                        inputmode="email"
                    />

                    <label class="flex items-center gap-2 text-sm">
                        <input
                            v-model="captura.consentimiento"
                            type="checkbox"
                            dusk="captura-loteria-consentimiento"
                        />
                        Acepta el aviso de privacidad
                    </label>
                </div>

                <DialogFooter>
                    <Button variant="outline" @click="modalAbierto = false">
                        Cerrar
                    </Button>
                    <Button
                        :disabled="guardando || !captura.consentimiento"
                        @click="guardarCaptura"
                        dusk="captura-loteria-guardar"
                    >
                        Guardar elector
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </div>
</template>
