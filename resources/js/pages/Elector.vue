<script setup lang="ts">
import { Head, router, usePage } from '@inertiajs/vue3';
import { computed, reactive, ref, watch } from 'vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useBreadcrumbs } from '@/composables/useBreadcrumbs';

const page = usePage<{ auth: { rol: string | null } }>();
const esGestion = computed(() =>
    ['coordinador', 'admin'].includes(page.props.auth?.rol ?? ''),
);

useBreadcrumbs([
    { title: 'Agenda', href: '/agenda' },
    { title: 'Ficha de elector', href: '#' },
]);

type Elector = {
    id: number;
    seccion_id: number;
    nombre: string;
    telefono: string;
    email: string | null;
    domicilio: string | null;
    observaciones: string | null;
    /** Falso cuando el servidor entregó los campos enmascarados (••••). */
    pii_visible: boolean;
    /** Oposición ARCO ejercida: fuera de la agenda y del export. */
    no_contactar: boolean;
    verificado: boolean;
    verificado_via: string | null;
    verificado_en: string | null;
};

type Interaccion = {
    id: number;
    tipo: string;
    resultado: string | null;
    nota: string | null;
    fecha: string;
    proximo_seguimiento: string | null;
    atendido_en: string | null;
};

const props = defineProps<{
    elector: Elector;
    interacciones: Interaccion[];
}>();

const TIPOS = ['llamada', 'correo', 'visita', 'whatsapp', 'sms', 'nota'];
const RESULTADOS = [
    'contesto',
    'no_contesto',
    'buzon',
    'correo_enviado',
    'no_estaba',
    'rechazo',
    'compromiso',
];

const timeline = ref<Interaccion[]>([...props.interacciones]);

// Cuando el viewer no puede ver la PII, el servidor entrega los campos
// enmascarados. Precargarlos en el formulario haría que "Guardar" reenviara
// los bullets como si fueran el valor real (P15), así que no se precargan ni
// se muestran los inputs: se enseña el valor enmascarado como texto.
const puedeEditarPii = computed(() => props.elector.pii_visible);

const CAMPOS = [
    'nombre',
    'telefono',
    'email',
    'domicilio',
    'observaciones',
] as const;
type Campo = (typeof CAMPOS)[number];

const datos = reactive<Record<Campo, string>>({
    nombre: puedeEditarPii.value ? props.elector.nombre : '',
    telefono: puedeEditarPii.value ? props.elector.telefono : '',
    email: puedeEditarPii.value ? (props.elector.email ?? '') : '',
    domicilio: puedeEditarPii.value ? (props.elector.domicilio ?? '') : '',
    observaciones: puedeEditarPii.value
        ? (props.elector.observaciones ?? '')
        : '',
});

// Solo se envían los campos que el usuario tocó: un PUT con el payload entero
// reescribiría con valores que nunca vio.
const tocados = reactive(new Set<Campo>());

for (const campo of CAMPOS) {
    watch(
        () => datos[campo],
        () => tocados.add(campo),
    );
}

const hayCambios = computed(() => tocados.size > 0);

const guardando = ref(false);
const errorEdicion = ref<string | null>(null);

const nueva = reactive({
    tipo: 'llamada',
    resultado: '' as string,
    nota: '',
    proximo_seguimiento: '',
    verificar: false,
});
const registrando = ref(false);

const verificacion = reactive({
    verificado: props.elector.verificado,
    via: props.elector.verificado_via,
    en: props.elector.verificado_en,
});
const quitandoVerificacion = ref(false);

function csrf(): string {
    return (
        document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')
            ?.content ?? ''
    );
}

function headers() {
    return {
        'Content-Type': 'application/json',
        Accept: 'application/json',
        'X-CSRF-TOKEN': csrf(),
    };
}

async function guardarElector() {
    if (tocados.size === 0) {
        return;
    }

    guardando.value = true;
    errorEdicion.value = null;

    try {
        const cambios: Partial<Record<Campo, string>> = {};

        for (const campo of tocados) {
            cambios[campo] = datos[campo];
        }

        const res = await fetch(`/api/electores/${props.elector.id}`, {
            method: 'PUT',
            headers: headers(),
            body: JSON.stringify(cambios),
        });

        if (res.status === 409) {
            errorEdicion.value =
                'Ese teléfono ya pertenece a otro elector de la campaña.';
        } else if (!res.ok) {
            errorEdicion.value = 'No se pudo guardar.';
        } else {
            tocados.clear();
        }
    } finally {
        guardando.value = false;
    }
}

async function registrarInteraccion() {
    registrando.value = true;

    try {
        const verificar = nueva.tipo !== 'nota' && nueva.verificar;
        const body: Record<string, unknown> = { tipo: nueva.tipo };

        if (nueva.tipo !== 'nota' && nueva.resultado) {
            body.resultado = nueva.resultado;
        }

        if (nueva.nota) {
            body.nota = nueva.nota;
        }

        if (nueva.proximo_seguimiento) {
            body.proximo_seguimiento = nueva.proximo_seguimiento;
        }

        if (verificar) {
            body.verificar = true;
        }

        const res = await fetch(
            `/api/electores/${props.elector.id}/interacciones`,
            { method: 'POST', headers: headers(), body: JSON.stringify(body) },
        );

        if (res.ok) {
            const creada: Interaccion = await res.json();
            timeline.value.unshift(creada);

            if (verificar) {
                verificacion.verificado = true;
                verificacion.via = creada.tipo;
                verificacion.en = creada.fecha;
            }

            nueva.resultado = '';
            nueva.nota = '';
            nueva.proximo_seguimiento = '';
            nueva.verificar = false;
        }
    } finally {
        registrando.value = false;
    }
}

async function quitarVerificacion() {
    quitandoVerificacion.value = true;

    try {
        const res = await fetch(
            `/api/electores/${props.elector.id}/verificacion`,
            { method: 'DELETE', headers: headers() },
        );

        if (res.ok) {
            verificacion.verificado = false;
            verificacion.via = null;
            verificacion.en = null;
        }
    } finally {
        quitandoVerificacion.value = false;
    }
}

// --- Derechos ARCO (ADR-004) ---
const arcoTipo = ref<'acceso' | 'rectificacion' | 'cancelacion' | 'oposicion'>(
    'acceso',
);
const arcoMensaje = ref<string | null>(null);
const procesandoArco = ref(false);

async function registrarSolicitudArco() {
    procesandoArco.value = true;
    arcoMensaje.value = null;

    try {
        const res = await fetch('/api/solicitudes-arco', {
            method: 'POST',
            headers: headers(),
            body: JSON.stringify({
                tipo: arcoTipo.value,
                elector_id: props.elector.id,
            }),
        });
        arcoMensaje.value = res.ok
            ? 'Solicitud ARCO registrada (pendiente).'
            : 'No se pudo registrar la solicitud.';
    } finally {
        procesandoArco.value = false;
    }
}

function cancelarElector() {
    if (
        !window.confirm(
            'Cancelación ARCO: se dará de baja al elector y se borrarán sus datos personales. Esta acción no se puede deshacer. ¿Continuar?',
        )
    ) {
        return;
    }

    procesandoArco.value = true;
    fetch(`/api/electores/${props.elector.id}`, {
        method: 'DELETE',
        headers: headers(),
    }).then((res) => {
        procesandoArco.value = false;

        if (res.ok) {
            router.visit('/agenda');
        } else {
            arcoMensaje.value = 'No se pudo cancelar el elector.';
        }
    });
}
</script>

<template>
    <Head :title="elector.nombre" />

    <div class="flex h-full flex-1 flex-col gap-6 p-4 md:flex-row">
        <!-- Datos del elector -->
        <section class="flex flex-col gap-3 md:w-1/3">
            <h1 class="text-xl font-semibold">{{ elector.nombre }}</h1>
            <p class="text-sm text-muted-foreground">
                Sección {{ elector.seccion_id }}
            </p>

            <p
                v-if="elector.no_contactar"
                class="rounded-lg border border-violet-600/30 bg-violet-600/10 px-3 py-2 text-sm text-violet-700 dark:text-violet-300"
            >
                Ejerció su derecho de Oposición: no debe contactarse. Queda
                fuera de la agenda de seguimientos y del export.
            </p>

            <div
                v-if="verificacion.verificado"
                class="flex flex-wrap items-center gap-2 rounded-lg border border-green-600/30 bg-green-600/10 px-3 py-2 text-sm text-green-700 dark:text-green-400"
            >
                <span class="font-medium">✓ Verificado</span>
                <span v-if="verificacion.via">vía {{ verificacion.via }}</span>
                <span
                    v-if="verificacion.en"
                    class="text-xs text-muted-foreground"
                >
                    · {{ new Date(verificacion.en).toLocaleDateString() }}
                </span>
                <button
                    type="button"
                    class="ml-auto text-xs underline disabled:opacity-50"
                    :disabled="quitandoVerificacion"
                    @click="quitarVerificacion"
                >
                    Quitar
                </button>
            </div>

            <template v-if="puedeEditarPii">
                <label class="text-sm font-medium">Nombre</label>
                <Input v-model="datos.nombre" />

                <label class="text-sm font-medium">Teléfono</label>
                <Input v-model="datos.telefono" />

                <label class="text-sm font-medium">Email</label>
                <Input v-model="datos.email" type="email" />

                <label class="text-sm font-medium">Domicilio</label>
                <Input v-model="datos.domicilio" />

                <label class="text-sm font-medium">Observaciones</label>
                <textarea
                    v-model="datos.observaciones"
                    rows="3"
                    class="rounded-md border bg-background p-2 text-sm"
                />

                <p v-if="errorEdicion" class="text-sm text-destructive">
                    {{ errorEdicion }}
                </p>

                <Button
                    :disabled="guardando || !hayCambios"
                    @click="guardarElector"
                >
                    Guardar cambios
                </Button>
            </template>

            <!-- Sin permiso de PII el servidor entrega los campos enmascarados:
                 se muestran como texto y no hay formulario que los reenvíe. -->
            <template v-else>
                <dl class="flex flex-col gap-2 text-sm">
                    <div>
                        <dt class="font-medium">Teléfono</dt>
                        <dd class="text-muted-foreground">
                            {{ elector.telefono }}
                        </dd>
                    </div>
                    <div v-if="elector.email">
                        <dt class="font-medium">Email</dt>
                        <dd class="text-muted-foreground">
                            {{ elector.email }}
                        </dd>
                    </div>
                    <div v-if="elector.domicilio">
                        <dt class="font-medium">Domicilio</dt>
                        <dd class="text-muted-foreground">
                            {{ elector.domicilio }}
                        </dd>
                    </div>
                </dl>
                <p class="text-xs text-muted-foreground">
                    Solo quien registró a este contacto (o la coordinación)
                    puede ver y editar sus datos personales.
                </p>
            </template>

            <!-- Derechos ARCO (ADR-004) -->
            <div
                class="mt-2 flex flex-col gap-2 rounded-xl border border-sidebar-border/70 p-3 dark:border-sidebar-border"
            >
                <h2 class="text-sm font-semibold">Derechos ARCO</h2>
                <div class="flex gap-2">
                    <select
                        v-model="arcoTipo"
                        class="flex-1 rounded border bg-background p-1 text-sm"
                    >
                        <option value="acceso">Acceso</option>
                        <option value="rectificacion">Rectificación</option>
                        <option value="cancelacion">Cancelación</option>
                        <option value="oposicion">Oposición</option>
                    </select>
                    <Button
                        size="sm"
                        variant="outline"
                        :disabled="procesandoArco"
                        @click="registrarSolicitudArco"
                    >
                        Registrar solicitud
                    </Button>
                </div>

                <Button
                    v-if="esGestion"
                    size="sm"
                    variant="destructive"
                    :disabled="procesandoArco"
                    @click="cancelarElector"
                >
                    Cancelar elector (baja ARCO)
                </Button>

                <p v-if="arcoMensaje" class="text-xs text-muted-foreground">
                    {{ arcoMensaje }}
                </p>
            </div>
        </section>

        <!-- Timeline + nueva interacción -->
        <section class="flex flex-1 flex-col gap-4">
            <div
                class="flex flex-col gap-2 rounded-xl border border-sidebar-border/70 p-3 dark:border-sidebar-border"
            >
                <h2 class="font-semibold">Nueva interacción</h2>
                <div class="flex flex-wrap gap-2">
                    <select
                        v-model="nueva.tipo"
                        class="rounded border bg-background p-1 text-sm"
                    >
                        <option v-for="t in TIPOS" :key="t" :value="t">
                            {{ t }}
                        </option>
                    </select>
                    <select
                        v-if="nueva.tipo !== 'nota'"
                        v-model="nueva.resultado"
                        class="rounded border bg-background p-1 text-sm"
                    >
                        <option value="">— resultado —</option>
                        <option v-for="r in RESULTADOS" :key="r" :value="r">
                            {{ r }}
                        </option>
                    </select>
                    <input
                        v-model="nueva.proximo_seguimiento"
                        type="date"
                        class="rounded border bg-background p-1 text-sm"
                    />
                </div>
                <textarea
                    v-model="nueva.nota"
                    rows="2"
                    placeholder="Nota…"
                    class="rounded-md border bg-background p-2 text-sm"
                />
                <label
                    v-if="nueva.tipo !== 'nota'"
                    class="flex items-center gap-2 text-sm"
                >
                    <input v-model="nueva.verificar" type="checkbox" />
                    Confirmar datos del contacto por este medio
                </label>
                <Button :disabled="registrando" @click="registrarInteraccion">
                    Registrar
                </Button>
            </div>

            <div class="flex flex-col gap-2">
                <h2 class="font-semibold">Timeline</h2>
                <p
                    v-if="timeline.length === 0"
                    class="text-sm text-muted-foreground"
                >
                    Sin interacciones aún.
                </p>
                <ul v-else class="flex flex-col gap-2">
                    <li
                        v-for="i in timeline"
                        :key="i.id"
                        class="rounded-xl border border-sidebar-border/70 p-3 text-sm dark:border-sidebar-border"
                    >
                        <div class="flex items-center gap-2">
                            <span class="font-medium">{{ i.tipo }}</span>
                            <span
                                v-if="i.resultado"
                                class="rounded bg-muted px-1.5 py-0.5 text-xs"
                            >
                                {{ i.resultado }}
                            </span>
                            <span class="ml-auto text-xs text-muted-foreground">
                                {{ new Date(i.fecha).toLocaleString() }}
                            </span>
                        </div>
                        <p v-if="i.nota" class="mt-1 text-muted-foreground">
                            {{ i.nota }}
                        </p>
                        <p
                            v-if="i.proximo_seguimiento"
                            class="mt-1 text-xs text-muted-foreground"
                        >
                            Seguimiento: {{ i.proximo_seguimiento }}
                            <span v-if="i.atendido_en">· atendido</span>
                        </p>
                    </li>
                </ul>
            </div>
        </section>
    </div>
</template>
