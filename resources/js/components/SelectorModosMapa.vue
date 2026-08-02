<script setup lang="ts">
type OpcionModo = { key: string; label: string };

withDefaults(
    defineProps<{
        modo: string;
        modos: OpcionModo[];
        /**
         * `grid`: dos columnas, para el panel lateral de escritorio.
         * `chips`: una fila con scroll horizontal, para la barra compacta en móvil.
         */
        variante?: 'grid' | 'chips';
    }>(),
    { variante: 'grid' },
);

defineEmits<{ cambiar: [key: string] }>();
</script>

<template>
    <div
        class="rounded-lg bg-muted p-1 text-sm"
        :class="
            variante === 'chips'
                ? 'flex gap-1 overflow-x-auto'
                : 'grid grid-cols-2 gap-1'
        "
    >
        <button
            v-for="opcion in modos"
            :key="opcion.key"
            type="button"
            class="rounded-md px-2 py-1.5 font-medium transition-colors"
            :class="[
                variante === 'chips' ? 'shrink-0 px-3 whitespace-nowrap' : '',
                modo === opcion.key
                    ? 'bg-background text-foreground shadow-sm'
                    : 'text-muted-foreground hover:text-foreground',
            ]"
            @click="$emit('cambiar', opcion.key)"
        >
            {{ opcion.label }}
        </button>
    </div>
</template>
