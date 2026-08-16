<script setup lang="ts">
import { computed, ref } from 'vue';
import { Button } from '@/components/ui/button';

type Pregunta = {
    id: number;
    etiqueta: string;
    tipo: 'opcion_unica' | 'texto_libre' | 'numerica';
    opciones: string[] | null;
};

type Seccion = { id: number; numero: number };

/**
 * Componente compartido entre EncuestaCaptura.vue (brigadista autenticado) y
 * EncuestaPublica.vue (link público sin login). Solo cambia de dónde sale
 * `ambito_id` (prop `secciones` para elegirlo aquí, o fijo desde el padre) y
 * a qué endpoint postea (`postUrl`).
 */
const props = defineProps<{
    nombre: string;
    ambitoTipo: string;
    preguntas: Pregunta[];
    secciones: Seccion[];
    postUrl: string;
    avisoTransparencia?: boolean;
}>();

const ambitoId = ref<number | null>(null);
const respuestas = ref<Record<number, string>>({});
const otrosValores = ref<Record<number, string>>({});
const enviando = ref(false);
const mensaje = ref<{ tipo: 'ok' | 'error'; texto: string } | null>(null);

const listo = computed(
    () =>
        ambitoId.value !== null &&
        props.preguntas.every((p) => {
            const valor =
                p.tipo === 'opcion_unica' ? respuestas.value[p.id] : otrosValores.value[p.id];

            return !!valor;
        }),
);

function csrf(): string {
    return (
        document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')
            ?.content ?? ''
    );
}

function limpiar() {
    respuestas.value = {};
    otrosValores.value = {};
}

async function enviar() {
    if (!listo.value || ambitoId.value === null) {
        return;
    }

    enviando.value = true;
    mensaje.value = null;

    const cuerpo: Record<number, string> = {};

    for (const p of props.preguntas) {
        cuerpo[p.id] =
            p.tipo === 'opcion_unica'
                ? (respuestas.value[p.id] ?? '')
                : (otrosValores.value[p.id] ?? '');
    }

    try {
        const resp = await fetch(props.postUrl, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-CSRF-TOKEN': csrf(),
            },
            body: JSON.stringify({
                ambito_id: ambitoId.value,
                respuestas: cuerpo,
            }),
        });

        if (resp.status === 201) {
            mensaje.value = { tipo: 'ok', texto: '¡Gracias! Tu respuesta quedó registrada.' };
            limpiar();
        } else if (resp.status === 429) {
            mensaje.value = {
                tipo: 'error',
                texto: 'Demasiados intentos. Espera un minuto e inténtalo de nuevo.',
            };
        } else if (resp.status === 422) {
            const data = await resp.json().catch(() => null);
            const primerError = data?.errors
                ? (Object.values(data.errors)[0] as string[] | undefined)?.[0]
                : data?.message;
            mensaje.value = {
                tipo: 'error',
                texto: primerError ?? 'Revisa tus respuestas.',
            };
        } else {
            mensaje.value = { tipo: 'error', texto: 'No se pudo enviar. Intenta de nuevo.' };
        }
    } finally {
        enviando.value = false;
    }
}
</script>

<template>
    <div class="mx-auto flex w-full max-w-xl flex-col gap-4 p-4">
        <h1 class="text-xl font-semibold">{{ nombre }}</h1>

        <p
            v-if="avisoTransparencia"
            class="rounded-md bg-muted/40 p-3 text-xs text-muted-foreground"
        >
            Tus respuestas son anónimas y se muestran agregadas: no se
            guarda tu nombre, teléfono ni ningún dato que te identifique.
        </p>

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

        <div class="flex flex-col gap-3 rounded-xl border border-sidebar-border/70 p-4 dark:border-sidebar-border">
            <label class="text-sm font-medium">
                {{ ambitoTipo === 'zona' ? 'Zona' : 'Sección' }}
            </label>
            <select
                v-model.number="ambitoId"
                class="rounded border bg-background p-2"
                dusk="encuesta-ambito"
            >
                <option :value="null">Selecciona una opción</option>
                <option v-for="s in secciones" :key="s.id" :value="s.id">
                    Sección {{ s.numero }}
                </option>
            </select>

            <div v-for="p in preguntas" :key="p.id" class="flex flex-col gap-2">
                <label class="text-sm font-medium">{{ p.etiqueta }}</label>

                <div v-if="p.tipo === 'opcion_unica'" class="flex flex-col gap-1">
                    <label
                        v-for="opcion in p.opciones ?? []"
                        :key="opcion"
                        class="flex items-center gap-2 text-sm"
                    >
                        <input
                            type="radio"
                            :name="`pregunta-${p.id}`"
                            :value="opcion"
                            v-model="respuestas[p.id]"
                        />
                        {{ opcion }}
                    </label>
                </div>

                <input
                    v-else-if="p.tipo === 'numerica'"
                    v-model="otrosValores[p.id]"
                    type="number"
                    class="rounded border bg-background p-2"
                />

                <textarea
                    v-else
                    v-model="otrosValores[p.id]"
                    class="rounded border bg-background p-2"
                    rows="3"
                />
            </div>

            <Button :disabled="!listo || enviando" @click="enviar" dusk="encuesta-enviar">
                Enviar respuesta
            </Button>
        </div>
    </div>
</template>
