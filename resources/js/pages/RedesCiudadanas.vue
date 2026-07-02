<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
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
import { vigente as avisoVigente } from '@/routes/avisos';
import { store as electoresStore } from '@/routes/electores';
import {
    registros as registrosRoute,
    store as redStore,
} from '@/routes/redes-ciudadanas';

type ListaExpuesta = { recargar: () => void };

type Seccion = { id: number; numero: number };
type EnlaceOpcion = { membership_id: number; nombre: string; rol: string };

type Red = {
    id: number;
    nombre: string;
    descripcion: string | null;
    activa: boolean;
    enlace: {
        membership_id: number;
        nombre: string | null;
        rol: string | null;
    };
    registros_count: number;
};

type Aviso = { id: number; version: string; texto: string } | null;

const props = defineProps<{
    redes: Red[];
    secciones: Seccion[];
    seccionesFiltro: Seccion[];
    enlaces: EnlaceOpcion[];
    esGestion: boolean;
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Redes ciudadanas', href: '/redes-ciudadanas' }],
    },
});

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

// --- Crear red (gestión) ---
const nuevaRed = reactive({
    nombre: '',
    descripcion: '',
    enlace_membership_id: props.enlaces[0]?.membership_id ?? null,
});
const creandoRed = ref(false);

function crearRed() {
    if (!nuevaRed.nombre || !nuevaRed.enlace_membership_id) {
        return;
    }

    creandoRed.value = true;
    router.post(
        redStore.url(),
        {
            nombre: nuevaRed.nombre,
            descripcion: nuevaRed.descripcion || null,
            enlace_membership_id: nuevaRed.enlace_membership_id,
        },
        {
            preserveScroll: true,
            onSuccess: () => {
                nuevaRed.nombre = '';
                nuevaRed.descripcion = '';
            },
            onFinish: () => (creandoRed.value = false),
        },
    );
}

// --- Registros por red (lista paginada estándar, expandible) ---
const expandido = ref<Record<number, boolean>>({});
const listaRefs = new Map<number, ListaExpuesta>();

function verRegistros(red: Red) {
    expandido.value[red.id] = !expandido.value[red.id];
}

function registrarLista(id: number, el: unknown) {
    if (el) {
        listaRefs.set(id, el as ListaExpuesta);
    } else {
        listaRefs.delete(id);
    }
}

// --- Agregar registro a una red ---
const modalAbierto = ref(false);
const redActiva = ref<Red | null>(null);
const guardando = ref(false);
const mensaje = ref<{ tipo: 'ok' | 'error'; texto: string } | null>(null);
const form = reactive({
    nombre: '',
    telefono: '',
    email: '',
    domicilio: '',
    observaciones: '',
    seccionId: props.secciones[0]?.id ?? null,
    consentimiento: false,
});

function limpiarForm() {
    form.nombre = '';
    form.telefono = '';
    form.email = '';
    form.domicilio = '';
    form.observaciones = '';
    form.consentimiento = false;
}

function abrirModal(red: Red) {
    mensaje.value = null;
    limpiarForm();
    redActiva.value = red;
    modalAbierto.value = true;
}

async function guardarRegistro() {
    if (!aviso.value || !redActiva.value) {
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
                modo_captura: 'red_ciudadana',
                red_ciudadana_id: redActiva.value.id,
                seccion_id: form.seccionId,
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
            listaRefs.get(redActiva.value.id)?.recargar();
            router.reload({ only: ['redes'] });
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
    <Head title="Redes ciudadanas" />

    <div class="mx-auto flex w-full max-w-5xl flex-1 flex-col gap-4 p-4">
        <h1 class="text-xl font-semibold">Redes ciudadanas</h1>

        <!-- Crear red (solo gestión) -->
        <section
            v-if="esGestion"
            class="flex flex-col gap-3 rounded-xl border border-sidebar-border/70 bg-card p-4 dark:border-sidebar-border"
        >
            <h2 class="text-base font-semibold">Nueva red ciudadana</h2>
            <div class="grid gap-2 sm:grid-cols-2">
                <Input
                    v-model="nuevaRed.nombre"
                    placeholder="Nombre de la red"
                />
                <select
                    v-model.number="nuevaRed.enlace_membership_id"
                    class="rounded border bg-background p-2 text-sm"
                >
                    <option :value="null" disabled>Selecciona al enlace</option>
                    <option
                        v-for="opcion in enlaces"
                        :key="opcion.membership_id"
                        :value="opcion.membership_id"
                    >
                        {{ opcion.nombre }} ({{ opcion.rol }})
                    </option>
                </select>
            </div>
            <Input
                v-model="nuevaRed.descripcion"
                placeholder="Descripción (opcional)"
            />
            <div>
                <Button
                    :disabled="
                        creandoRed ||
                        !nuevaRed.nombre ||
                        !nuevaRed.enlace_membership_id
                    "
                    @click="crearRed"
                >
                    Crear red
                </Button>
            </div>
        </section>

        <!-- Listado de redes -->
        <p
            v-if="redes.length === 0"
            class="rounded-lg border border-dashed bg-background p-6 text-center text-sm text-muted-foreground"
        >
            Aún no tienes redes ciudadanas asignadas.
        </p>

        <section
            v-for="red in redes"
            :key="red.id"
            class="flex flex-col gap-3 rounded-xl border border-sidebar-border/70 bg-card p-4 dark:border-sidebar-border"
        >
            <div class="flex items-start justify-between gap-3">
                <div>
                    <h2 class="text-base font-semibold">{{ red.nombre }}</h2>
                    <p
                        v-if="red.descripcion"
                        class="text-sm text-muted-foreground"
                    >
                        {{ red.descripcion }}
                    </p>
                    <p class="mt-1 text-xs text-muted-foreground">
                        Enlace: {{ red.enlace.nombre ?? '—' }}
                        <span v-if="red.enlace.rol"
                            >({{ red.enlace.rol }})</span
                        >
                        · {{ red.registros_count }} registros
                    </p>
                </div>
                <div class="flex shrink-0 gap-2">
                    <Button
                        variant="outline"
                        size="sm"
                        @click="verRegistros(red)"
                    >
                        Ver registros
                    </Button>
                    <Button size="sm" @click="abrirModal(red)">
                        Agregar registro
                    </Button>
                </div>
            </div>

            <div v-if="expandido[red.id]" class="border-t pt-3">
                <ListaCapturados
                    :ref="(el) => registrarLista(red.id, el)"
                    :build-url="
                        (query) => registrosRoute.url(red.id, { query })
                    "
                    :secciones-catalogo="secciones"
                    :secciones-filtro="seccionesFiltro"
                    unidad="registros"
                />
            </div>
        </section>

        <!-- Modal: agregar registro -->
        <Dialog v-model:open="modalAbierto">
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>
                        Agregar registro · {{ redActiva?.nombre }}
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

                    <label class="text-sm font-medium">Sección</label>
                    <select
                        v-model.number="form.seccionId"
                        class="rounded border bg-background p-2 text-sm"
                    >
                        <option
                            v-for="seccion in secciones"
                            :key="seccion.id"
                            :value="seccion.id"
                        >
                            Sección {{ seccion.numero }}
                        </option>
                    </select>

                    <label class="flex items-center gap-2 text-sm">
                        <input v-model="form.consentimiento" type="checkbox" />
                        Acepta el aviso de privacidad
                    </label>
                </div>

                <DialogFooter>
                    <Button variant="outline" @click="modalAbierto = false">
                        Cancelar
                    </Button>
                    <Button
                        :disabled="guardando || !form.consentimiento"
                        @click="guardarRegistro"
                    >
                        Guardar registro
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </div>
</template>
