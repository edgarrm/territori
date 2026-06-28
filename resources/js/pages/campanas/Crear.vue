<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';

type Municipio = { id: number; nombre: string };
type Entidad = { id: number; nombre: string; municipios: Municipio[] };

const props = defineProps<{ entidades: Entidad[] }>();

const entidadId = ref<string>('');

const municipios = computed<Municipio[]>(
    () =>
        props.entidades.find((e) => String(e.id) === entidadId.value)
            ?.municipios ?? [],
);

const form = useForm({
    nombre: '',
    municipio_id: null as number | null,
    subdominio: '',
});

function onEntidadChange(valor: unknown) {
    entidadId.value = valor == null ? '' : String(valor);
    form.municipio_id = null;
}

function crear() {
    form.transform((data) => ({
        ...data,
        subdominio: data.subdominio || null,
    })).post('/campanas');
}
</script>

<template>
    <Head title="Crear campaña" />

    <div class="mx-auto flex max-w-md flex-col gap-6 p-6">
        <div>
            <h1 class="text-xl font-semibold">Crear campaña</h1>
            <p class="text-sm text-muted-foreground">
                Serás el administrador de la nueva campaña.
            </p>
        </div>

        <form class="flex flex-col gap-4" @submit.prevent="crear">
            <div class="grid gap-1.5">
                <Label for="nombre">Nombre</Label>
                <Input
                    id="nombre"
                    v-model="form.nombre"
                    type="text"
                    autofocus
                />
                <InputError :message="form.errors.nombre" />
            </div>

            <div class="grid gap-1.5">
                <Label>Entidad</Label>
                <Select
                    :model-value="entidadId"
                    @update:model-value="onEntidadChange"
                >
                    <SelectTrigger>
                        <SelectValue placeholder="Selecciona una entidad" />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem
                            v-for="entidad in entidades"
                            :key="entidad.id"
                            :value="String(entidad.id)"
                        >
                            {{ entidad.nombre }}
                        </SelectItem>
                    </SelectContent>
                </Select>
            </div>

            <div class="grid gap-1.5">
                <Label>Municipio</Label>
                <Select
                    :model-value="
                        form.municipio_id ? String(form.municipio_id) : ''
                    "
                    :disabled="municipios.length === 0"
                    @update:model-value="form.municipio_id = Number($event)"
                >
                    <SelectTrigger>
                        <SelectValue placeholder="Selecciona un municipio" />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem
                            v-for="municipio in municipios"
                            :key="municipio.id"
                            :value="String(municipio.id)"
                        >
                            {{ municipio.nombre }}
                        </SelectItem>
                    </SelectContent>
                </Select>
                <InputError :message="form.errors.municipio_id" />
            </div>

            <div class="grid gap-1.5">
                <Label for="subdominio"
                    >Subdominio
                    <span class="text-muted-foreground">(opcional)</span></Label
                >
                <Input
                    id="subdominio"
                    v-model="form.subdominio"
                    type="text"
                    placeholder="micampana2026"
                />
                <InputError :message="form.errors.subdominio" />
            </div>

            <Button type="submit" :disabled="form.processing"
                >Crear campaña</Button
            >
        </form>
    </div>
</template>
