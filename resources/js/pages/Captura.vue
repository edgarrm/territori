<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { computed, onMounted, ref } from 'vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { dashboard } from '@/routes';
import { vigente as avisoVigente } from '@/routes/avisos';
import { store as electoresStore } from '@/routes/electores';
import {
    activa as loteriaActiva,
    cerrar as loteriaCerrar,
    store as loteriaStore,
} from '@/routes/loterias';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: dashboard() },
            { title: 'Captura', href: '/captura' },
        ],
    },
});

type Seccion = { id: number; numero: number };
type Evento = { id: number; nombre: string; fecha: string; seccion_id: number | null };
type Aviso = { id: number; version: string; texto: string } | null;
type Modo = 'loteria' | 'individual' | 'evento';

const props = defineProps<{ secciones: Seccion[]; eventos: Evento[] }>();

const modo = ref<Modo>('loteria');
const eventoId = ref<number | null>(props.eventos[0]?.id ?? null);
const aviso = ref<Aviso>(null);
const mensaje = ref<{ tipo: 'ok' | 'error'; texto: string } | null>(null);
const guardando = ref(false);

// Lotería
const loteriaId = ref<number | null>(null);
const seccionLoteria = ref<number | null>(null);
const seccionSeleccionada = ref<number | null>(props.secciones[0]?.id ?? null);
const capturadosSesion = ref(0);

// Formulario de captura
const form = ref({
    nombre: '',
    telefono: '',
    domicilio: '',
    observaciones: '',
    consentimiento: false,
    seccionId: null as number | null,
    ubicacion: null as { lat: number; lng: number } | null,
});

const numeroSeccionLoteria = computed(
    () =>
        props.secciones.find((s) => s.id === seccionLoteria.value)?.numero ??
        null,
);

function csrf(): string {
    return (
        document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')
            ?.content ?? ''
    );
}

async function postJson(url: string, body: Record<string, unknown>) {
    return fetch(url, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            'X-CSRF-TOKEN': csrf(),
        },
        body: JSON.stringify(body),
    });
}

function limpiarForm() {
    form.value.nombre = '';
    form.value.telefono = '';
    form.value.domicilio = '';
    form.value.observaciones = '';
    form.value.consentimiento = false;
    form.value.ubicacion = null;
}

onMounted(async () => {
    const respAviso = await fetch(avisoVigente.url(), {
        headers: { Accept: 'application/json' },
    });
    aviso.value = (await respAviso.json()).aviso;

    const respActiva = await fetch(loteriaActiva.url(), {
        headers: { Accept: 'application/json' },
    });
    const data = (await respActiva.json()).loteria;
    if (data) {
        loteriaId.value = data.loteria_id;
        seccionLoteria.value = data.seccion_id;
    }
});

async function abrirLoteria() {
    if (!seccionSeleccionada.value) {
        return;
    }
    const resp = await postJson(loteriaStore.url(), {
        seccion_id: seccionSeleccionada.value,
    });
    const data = await resp.json();
    loteriaId.value = data.loteria_id;
    seccionLoteria.value = data.seccion_id;
    capturadosSesion.value = 0;
}

async function cerrarLoteria() {
    if (!loteriaId.value) {
        return;
    }
    await postJson(loteriaCerrar.url(loteriaId.value), {});
    loteriaId.value = null;
    seccionLoteria.value = null;
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

    const payload: Record<string, unknown> = {
        modo_captura: modo.value,
        nombre: form.value.nombre,
        telefono: form.value.telefono,
        consentimiento: form.value.consentimiento,
        aviso_privacidad_id: aviso.value.id,
    };

    if (modo.value === 'individual') {
        payload.seccion_id = form.value.seccionId;
        payload.domicilio = form.value.domicilio || null;
        payload.observaciones = form.value.observaciones || null;
        payload.ubicacion = form.value.ubicacion;
    }

    if (modo.value === 'evento') {
        payload.evento_id = eventoId.value;
    }

    try {
        const resp = await postJson(electoresStore.url(), payload);

        if (resp.status === 201) {
            mensaje.value = { tipo: 'ok', texto: 'Elector capturado.' };
            if (modo.value === 'loteria') {
                capturadosSesion.value++;
            }
            limpiarForm();
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

function ubicarme() {
    if (!navigator.geolocation) {
        return;
    }
    navigator.geolocation.getCurrentPosition((pos) => {
        form.value.ubicacion = {
            lat: pos.coords.latitude,
            lng: pos.coords.longitude,
        };
    });
}
</script>

<template>
    <Head title="Captura" />

    <div class="mx-auto flex w-full max-w-xl flex-1 flex-col gap-4 p-4">
        <h1 class="text-xl font-semibold">Captura de electores</h1>

        <div class="flex gap-2">
            <Button
                :variant="modo === 'loteria' ? 'default' : 'outline'"
                @click="modo = 'loteria'"
            >
                Lotería
            </Button>
            <Button
                :variant="modo === 'individual' ? 'default' : 'outline'"
                @click="modo = 'individual'"
            >
                Individual
            </Button>
            <Button
                :variant="modo === 'evento' ? 'default' : 'outline'"
                @click="modo = 'evento'"
            >
                Evento
            </Button>
        </div>

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

        <!-- LOTERÍA -->
        <section
            v-if="modo === 'loteria'"
            class="flex flex-col gap-3 rounded-xl border border-sidebar-border/70 p-4 dark:border-sidebar-border"
        >
            <div v-if="!loteriaId" class="flex flex-col gap-2">
                <label class="text-sm font-medium">Sección de la sesión</label>
                <select
                    v-model.number="seccionSeleccionada"
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
                <Button @click="abrirLoteria">Abrir lotería</Button>
            </div>

            <div v-else class="flex flex-col gap-3">
                <div class="flex items-center justify-between">
                    <span class="text-sm">
                        Sección
                        <strong>{{ numeroSeccionLoteria }}</strong> ·
                        capturados:
                        <strong>{{ capturadosSesion }}</strong>
                    </span>
                    <Button variant="outline" @click="cerrarLoteria">
                        Cerrar
                    </Button>
                </div>

                <Input v-model="form.nombre" placeholder="Nombre" />
                <Input
                    v-model="form.telefono"
                    placeholder="Teléfono (10 dígitos)"
                    inputmode="tel"
                />
                <label class="flex items-center gap-2 text-sm">
                    <input v-model="form.consentimiento" type="checkbox" />
                    Acepta el aviso de privacidad
                </label>
                <Button
                    :disabled="guardando || !form.consentimiento"
                    @click="guardar"
                >
                    Guardar y siguiente
                </Button>
            </div>
        </section>

        <!-- INDIVIDUAL -->
        <section
            v-else-if="modo === 'individual'"
            class="flex flex-col gap-3 rounded-xl border border-sidebar-border/70 p-4 dark:border-sidebar-border"
        >
            <label class="text-sm font-medium">Sección</label>
            <select
                v-model.number="form.seccionId"
                class="rounded border bg-background p-2"
                dusk="captura-seccion"
            >
                <option :value="null">Detectar por GPS</option>
                <option
                    v-for="seccion in secciones"
                    :key="seccion.id"
                    :value="seccion.id"
                >
                    Sección {{ seccion.numero }}
                </option>
            </select>

            <Input v-model="form.nombre" placeholder="Nombre" dusk="captura-nombre" />
            <Input
                v-model="form.telefono"
                placeholder="Teléfono (10 dígitos)"
                inputmode="tel"
                dusk="captura-telefono"
            />
            <Input v-model="form.domicilio" placeholder="Domicilio (opcional)" />
            <Input
                v-model="form.observaciones"
                placeholder="Observaciones (opcional)"
            />

            <Button variant="outline" @click="ubicarme">
                {{
                    form.ubicacion
                        ? 'Ubicación capturada ✓'
                        : 'Usar mi ubicación GPS'
                }}
            </Button>

            <label class="flex items-center gap-2 text-sm">
                <input
                    v-model="form.consentimiento"
                    type="checkbox"
                    dusk="captura-consentimiento"
                />
                Acepta el aviso de privacidad
            </label>
            <Button
                :disabled="guardando || !form.consentimiento"
                @click="guardar"
                dusk="captura-guardar"
            >
                Guardar elector
            </Button>
        </section>

        <!-- EVENTO -->
        <section
            v-else
            class="flex flex-col gap-3 rounded-xl border border-sidebar-border/70 p-4 dark:border-sidebar-border"
        >
            <p
                v-if="eventos.length === 0"
                class="text-sm text-muted-foreground"
            >
                No hay eventos. Crea uno en la sección
                <a href="/eventos" class="underline">Eventos</a>.
            </p>
            <template v-else>
                <label class="text-sm font-medium">Evento</label>
                <select
                    v-model.number="eventoId"
                    class="rounded border bg-background p-2"
                    dusk="captura-evento-select"
                >
                    <option
                        v-for="evento in eventos"
                        :key="evento.id"
                        :value="evento.id"
                    >
                        {{ evento.nombre }} · {{ evento.fecha }}
                    </option>
                </select>

                <Input v-model="form.nombre" placeholder="Nombre" />
                <Input
                    v-model="form.telefono"
                    placeholder="Teléfono (10 dígitos)"
                    inputmode="tel"
                />

                <label class="flex items-center gap-2 text-sm">
                    <input v-model="form.consentimiento" type="checkbox" />
                    Acepta el aviso de privacidad
                </label>
                <Button
                    :disabled="guardando || !form.consentimiento || !eventoId"
                    @click="guardar"
                >
                    Guardar asistente
                </Button>
            </template>
        </section>

        <details
            v-if="aviso"
            class="rounded-md bg-muted/40 p-3 text-xs text-muted-foreground"
        >
            <summary class="cursor-pointer">
                Aviso de privacidad (v{{ aviso.version }})
            </summary>
            <p class="mt-2">{{ aviso.texto }}</p>
        </details>
    </div>
</template>
