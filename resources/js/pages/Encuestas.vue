<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useBreadcrumbs } from '@/composables/useBreadcrumbs';
import { captura as capturaEncuesta, store as crearEncuesta } from '@/routes/encuestas';

useBreadcrumbs([{ title: 'Encuestas', href: '/encuestas' }]);

type Pregunta = {
    id: number;
    etiqueta: string;
    tipo: 'opcion_unica' | 'texto_libre' | 'numerica';
    opciones: string[] | null;
    incluida: boolean;
    orden: number;
};

type Encuesta = {
    id: number;
    nombre: string;
    canal: string;
    ambito_tipo: string;
    modo_acceso: string;
    token_publico: string | null;
    estado: string;
    preguntas: Pregunta[];
};

defineProps<{
    encuestas: Encuesta[];
}>();

type PreguntaForm = {
    etiqueta: string;
    tipo: 'opcion_unica' | 'texto_libre' | 'numerica';
    opcionesTexto: string;
};

const form = useForm({
    nombre: '',
    ambito_tipo: 'seccion' as 'seccion' | 'zona',
    canal: 'nativo',
    modo_acceso: 'brigadista' as 'brigadista' | 'publico' | 'ambos',
    preguntas: [] as PreguntaForm[],
});

function agregarPregunta() {
    form.preguntas.push({ etiqueta: '', tipo: 'opcion_unica', opcionesTexto: '' });
}

function quitarPregunta(index: number) {
    form.preguntas.splice(index, 1);
}

function crear() {
    const payload = {
        ...form.data(),
        preguntas: form.preguntas.map((p) => ({
            etiqueta: p.etiqueta,
            tipo: p.tipo,
            opciones:
                p.tipo === 'opcion_unica'
                    ? p.opcionesTexto
                          .split(',')
                          .map((o) => o.trim())
                          .filter((o) => o.length > 0)
                    : null,
        })),
    };

    form.transform(() => payload).post(crearEncuesta.url(), {
        preserveScroll: true,
        onSuccess: () => {
            form.reset();
            form.preguntas = [];
        },
    });
}

const copiado = ref<number | null>(null);

function linkPublico(token: string): string {
    return `${window.location.origin}/e/${token}`;
}

async function copiarLink(encuesta: Encuesta) {
    if (!encuesta.token_publico) {
        return;
    }

    await navigator.clipboard.writeText(linkPublico(encuesta.token_publico));
    copiado.value = encuesta.id;
    setTimeout(() => {
        if (copiado.value === encuesta.id) {
            copiado.value = null;
        }
    }, 2000);
}
</script>

<template>
    <Head title="Encuestas" />

    <div class="flex h-full flex-1 flex-col gap-4 p-4">
        <h1 class="text-xl font-semibold">Encuestas</h1>

        <!-- Alta -->
        <div class="flex flex-col gap-3 rounded-xl border border-sidebar-border/70 p-4 dark:border-sidebar-border">
            <div class="grid gap-3 sm:grid-cols-2">
                <div class="flex flex-col gap-1">
                    <label class="text-sm font-medium">Nombre</label>
                    <Input v-model="form.nombre" placeholder="Encuesta de obra pública" dusk="encuesta-nombre" />
                </div>

                <div class="flex flex-col gap-1">
                    <label class="text-sm font-medium">Canal</label>
                    <select v-model="form.canal" class="rounded border bg-background p-2" dusk="encuesta-canal">
                        <option value="nativo">Formulario propio</option>
                        <option value="sheets" disabled>Google Sheets (próximamente)</option>
                        <option value="voz" disabled>Voz/Twilio (próximamente)</option>
                    </select>
                </div>

                <div class="flex flex-col gap-1">
                    <label class="text-sm font-medium">Ámbito</label>
                    <select v-model="form.ambito_tipo" class="rounded border bg-background p-2" dusk="encuesta-ambito-tipo">
                        <option value="seccion">Sección</option>
                        <option value="zona">Zona</option>
                    </select>
                </div>

                <div class="flex flex-col gap-1">
                    <label class="text-sm font-medium">Acceso</label>
                    <select v-model="form.modo_acceso" class="rounded border bg-background p-2" dusk="encuesta-modo-acceso">
                        <option value="brigadista">Solo brigadistas</option>
                        <option value="publico">Solo link público</option>
                        <option value="ambos">Brigadistas y link público</option>
                    </select>
                </div>
            </div>

            <div class="flex flex-col gap-2 border-t border-sidebar-border/70 pt-3 dark:border-sidebar-border">
                <div class="flex items-center justify-between">
                    <span class="text-sm font-medium">Preguntas</span>
                    <Button variant="outline" size="sm" @click="agregarPregunta" dusk="encuesta-agregar-pregunta">
                        Agregar pregunta
                    </Button>
                </div>

                <p v-if="form.preguntas.length === 0" class="text-sm text-muted-foreground">
                    Agrega al menos una pregunta.
                </p>

                <div
                    v-for="(pregunta, index) in form.preguntas"
                    :key="index"
                    class="flex flex-col gap-2 rounded-lg border border-sidebar-border/70 p-3 dark:border-sidebar-border"
                >
                    <div class="flex items-center gap-2">
                        <Input
                            v-model="pregunta.etiqueta"
                            placeholder="¿Apoya la obra del parque?"
                            class="flex-1"
                        />
                        <select v-model="pregunta.tipo" class="rounded border bg-background p-2">
                            <option value="opcion_unica">Opción única</option>
                            <option value="texto_libre">Texto libre</option>
                            <option value="numerica">Numérica</option>
                        </select>
                        <Button variant="ghost" size="sm" @click="quitarPregunta(index)">
                            Quitar
                        </Button>
                    </div>
                    <Input
                        v-if="pregunta.tipo === 'opcion_unica'"
                        v-model="pregunta.opcionesTexto"
                        placeholder="Opciones separadas por coma: Sí, No, No sabe"
                    />
                </div>
            </div>

            <Button
                :disabled="form.processing || !form.nombre || form.preguntas.length === 0"
                @click="crear"
                dusk="encuesta-crear"
            >
                Crear encuesta
            </Button>
        </div>

        <!-- Lista -->
        <div v-if="encuestas.length === 0" class="text-sm text-muted-foreground">
            Aún no hay encuestas.
        </div>

        <ul v-else class="flex flex-col gap-2">
            <li
                v-for="encuesta in encuestas"
                :key="encuesta.id"
                class="flex flex-col gap-2 rounded-xl border border-sidebar-border/70 p-3 dark:border-sidebar-border"
            >
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="font-medium">{{ encuesta.nombre }}</span>
                        <span class="text-xs text-muted-foreground">
                            · {{ encuesta.preguntas.length }} pregunta(s) · {{ encuesta.estado }}
                        </span>
                    </div>
                    <Link
                        :href="capturaEncuesta.url(encuesta.id)"
                        class="rounded-full bg-sky-100 px-2 py-0.5 text-xs font-medium text-sky-800 hover:underline dark:bg-sky-900/40 dark:text-sky-200"
                    >
                        Responder
                    </Link>
                </div>

                <div v-if="encuesta.token_publico" class="flex items-center gap-2 text-xs text-muted-foreground">
                    <span class="truncate">{{ linkPublico(encuesta.token_publico) }}</span>
                    <Button variant="outline" size="sm" @click="copiarLink(encuesta)">
                        {{ copiado === encuesta.id ? 'Copiado ✓' : 'Copiar link' }}
                    </Button>
                </div>
            </li>
        </ul>
    </div>
</template>
