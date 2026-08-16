<script setup lang="ts">
import { Form } from '@inertiajs/vue3';
import CampanaController from '@/actions/App/Http/Controllers/CampanaController';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';

defineProps<{ diasRetencion: number }>();
</script>

<template>
    <div class="space-y-6">
        <Heading
            variant="small"
            title="Cerrar campaña"
            description="Da por terminada la campaña e inicia la supresión de los datos personales"
        />
        <div
            class="space-y-4 rounded-lg border border-red-100 bg-red-50 p-4 dark:border-red-200/10 dark:bg-red-700/10"
        >
            <div class="relative space-y-0.5 text-red-600 dark:text-red-100">
                <p class="font-medium">Advertencia</p>
                <p class="text-sm">
                    Al cerrar la campaña se agota la finalidad por la que se
                    recabaron los datos. Pasados
                    {{ diasRetencion }} días, los datos personales de todos sus
                    contactos se suprimen de forma permanente (LFPDPPP art. 11).
                    Esta acción no se puede deshacer desde la aplicación.
                </p>
            </div>
            <Dialog>
                <DialogTrigger as-child>
                    <Button variant="destructive" data-test="cerrar-campana-button"
                        >Cerrar campaña</Button
                    >
                </DialogTrigger>
                <DialogContent>
                    <Form
                        v-bind="CampanaController.cerrar.form()"
                        :options="{ preserveScroll: true }"
                        class="space-y-6"
                        v-slot="{ processing }"
                    >
                        <DialogHeader class="space-y-3">
                            <DialogTitle
                                >¿Seguro que quieres cerrar esta
                                campaña?</DialogTitle
                            >
                            <DialogDescription>
                                Dejarás de tener acceso a ella y, pasados
                                {{ diasRetencion }} días, se borrarán los datos
                                personales de todos sus contactos. Se te pedirá
                                confirmar tu contraseña.
                            </DialogDescription>
                        </DialogHeader>

                        <DialogFooter class="gap-2">
                            <DialogClose as-child>
                                <Button variant="secondary">Cancelar</Button>
                            </DialogClose>

                            <Button
                                type="submit"
                                variant="destructive"
                                :disabled="processing"
                                data-test="confirm-cerrar-campana-button"
                            >
                                Cerrar campaña
                            </Button>
                        </DialogFooter>
                    </Form>
                </DialogContent>
            </Dialog>
        </div>
    </div>
</template>
