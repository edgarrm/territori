<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import {
    BookOpen,
    CalendarCheck,
    CalendarDays,
    ClipboardList,
    FolderGit2,
    LayoutGrid,
    Map,
    Network,
    ShieldCheck,
    Target,
    Users,
} from '@lucide/vue';
import { computed } from 'vue';
import CampanaSwitcher from '@/components/CampanaSwitcher.vue';
import NavFooter from '@/components/NavFooter.vue';
import NavMain from '@/components/NavMain.vue';
import NavUser from '@/components/NavUser.vue';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
} from '@/components/ui/sidebar';
import { dashboard } from '@/routes';
import type { NavItem } from '@/types';

const page = usePage<{ auth: { rol: string | null } }>();
const esGestion = computed(() =>
    ['coordinador', 'admin'].includes(page.props.auth?.rol ?? ''),
);
// El rol "enlace" tiene acceso restringido: solo a sus redes ciudadanas.
const esEnlace = computed(() => page.props.auth?.rol === 'enlace');

const redesItem: NavItem = {
    title: 'Redes ciudadanas',
    href: '/redes-ciudadanas',
    icon: Network,
};

const mainNavItems = computed<NavItem[]>(() => {
    if (esEnlace.value) {
        return [redesItem];
    }

    const items: NavItem[] = [
        { title: 'Dashboard', href: dashboard(), icon: LayoutGrid },
        { title: 'Mapa', href: '/mapa', icon: Map },
        { title: 'Captura', href: '/captura', icon: ClipboardList },
        { title: 'Agenda', href: '/agenda', icon: CalendarCheck },
        { title: 'Eventos', href: '/eventos', icon: CalendarDays },
        redesItem,
    ];

    if (esGestion.value) {
        items.push(
            { title: 'Metas', href: '/metas', icon: Target },
            { title: 'Brigadistas', href: '/brigadistas', icon: Users },
            { title: 'Solicitudes ARCO', href: '/solicitudes-arco', icon: ShieldCheck },
        );
    }

    return items;
});

const footerNavItems: NavItem[] = [
    {
        title: 'Repository',
        href: 'https://github.com/laravel/vue-starter-kit',
        icon: FolderGit2,
    },
    {
        title: 'Documentation',
        href: 'https://laravel.com/docs/starter-kits#vue',
        icon: BookOpen,
    },
];
</script>

<template>
    <Sidebar collapsible="icon" variant="inset">
        <SidebarHeader>
            <CampanaSwitcher />
        </SidebarHeader>

        <SidebarContent>
            <NavMain :items="mainNavItems" />
        </SidebarContent>

        <SidebarFooter>
            <NavFooter :items="footerNavItems" />
            <NavUser />
        </SidebarFooter>
    </Sidebar>
    <slot />
</template>
