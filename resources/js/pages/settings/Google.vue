<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { conectar, desconectar, show as editGoogle } from '@/routes/google';

defineProps<{
    conectado: boolean;
    cuentaEmail: string | null;
    conectadoEn: string | null;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Google',
                href: editGoogle(),
            },
        ],
    },
});
</script>

<template>
    <Head title="Google" />

    <h1 class="sr-only">Conexión con Google</h1>

    <div class="flex flex-col space-y-6">
        <Heading
            variant="small"
            title="Google"
            description="Conecta la cuenta de Google de la campaña para leer Sheets como canal de encuestas"
        />

        <div
            v-if="conectado"
            class="flex flex-col gap-3 rounded-xl border border-sidebar-border/70 p-4 dark:border-sidebar-border"
        >
            <p class="text-sm">
                Conectado como
                <span class="font-medium">{{ cuentaEmail }}</span>
            </p>
            <Link :href="desconectar.url()" method="post" as="button">
                <Button type="button" variant="destructive">
                    Desconectar
                </Button>
            </Link>
        </div>

        <div v-else class="flex flex-col gap-3 rounded-xl border border-sidebar-border/70 p-4 dark:border-sidebar-border">
            <p class="text-sm text-muted-foreground">
                Sin conexión. Conecta una cuenta de Google para poder usar el
                canal de encuestas "Google Sheets".
            </p>
            <a :href="conectar.url()">
                <Button type="button">Conectar con Google</Button>
            </a>
        </div>
    </div>
</template>
