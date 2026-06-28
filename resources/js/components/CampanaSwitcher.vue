<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3';
import { Check, ChevronsUpDown, Plus } from '@lucide/vue';
import { computed } from 'vue';
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
    useSidebar,
} from '@/components/ui/sidebar';
import { crear } from '@/routes/campanas';
import type { Campana } from '@/types/auth';

const page = usePage();
const { isMobile, state } = useSidebar();

const campanas = computed<Campana[]>(() => page.props.auth.campanas ?? []);
const tenantId = computed(() => page.props.auth.tenant_id);
const esAdmin = computed(() => page.props.auth.rol === 'admin');
const activa = computed(
    () =>
        campanas.value.find((c) => c.tenant_id === tenantId.value) ??
        campanas.value[0] ??
        null,
);

function cambiar(campana: Campana) {
    if (campana.tenant_id === tenantId.value) {
        return;
    }

    router.post('/campanas/seleccionar', { tenant_id: campana.tenant_id });
}
</script>

<template>
    <SidebarMenu>
        <SidebarMenuItem>
            <DropdownMenu>
                <DropdownMenuTrigger as-child>
                    <SidebarMenuButton
                        size="lg"
                        class="data-[state=open]:bg-sidebar-accent data-[state=open]:text-sidebar-accent-foreground"
                        data-test="campana-switcher"
                    >
                        <div
                            class="flex aspect-square size-8 items-center justify-center rounded-md bg-sidebar-primary text-sidebar-primary-foreground"
                        >
                            <AppLogoIcon
                                class="size-5 fill-current text-white dark:text-black"
                            />
                        </div>
                        <div
                            class="ml-1 grid flex-1 text-left text-sm leading-tight"
                        >
                            <span class="truncate font-semibold">{{
                                activa?.nombre ?? 'Territori'
                            }}</span>
                            <span
                                v-if="activa"
                                class="truncate text-xs text-muted-foreground capitalize"
                                >{{ activa.rol }}</span
                            >
                        </div>
                        <ChevronsUpDown class="ml-auto size-4" />
                    </SidebarMenuButton>
                </DropdownMenuTrigger>
                <DropdownMenuContent
                    class="w-(--reka-dropdown-menu-trigger-width) min-w-56 rounded-lg"
                    :side="
                        isMobile
                            ? 'bottom'
                            : state === 'collapsed'
                              ? 'left'
                              : 'bottom'
                    "
                    align="start"
                    :side-offset="4"
                >
                    <DropdownMenuLabel class="text-xs text-muted-foreground"
                        >Campañas</DropdownMenuLabel
                    >
                    <DropdownMenuItem
                        v-for="campana in campanas"
                        :key="campana.tenant_id"
                        class="cursor-pointer gap-2"
                        @select="cambiar(campana)"
                    >
                        <span class="flex-1 truncate">{{
                            campana.nombre
                        }}</span>
                        <Check
                            v-if="campana.tenant_id === tenantId"
                            class="size-4"
                        />
                    </DropdownMenuItem>
                    <template v-if="esAdmin">
                        <DropdownMenuSeparator />
                        <DropdownMenuItem
                            :as-child="true"
                            class="cursor-pointer gap-2"
                        >
                            <Link :href="crear()">
                                <Plus class="size-4" />
                                Crear campaña
                            </Link>
                        </DropdownMenuItem>
                    </template>
                </DropdownMenuContent>
            </DropdownMenu>
        </SidebarMenuItem>
    </SidebarMenu>
</template>
