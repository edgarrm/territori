import { usePage } from '@inertiajs/vue3';
import type { ComputedRef } from 'vue';
import { computed } from 'vue';

// Roles con acceso a las rutas de mapa (routes/web.php: grupo rol:brigadista,coordinador,admin).
const ROLES_CON_MAPA = ['brigadista', 'coordinador', 'admin'];
// Mismo grupo de rutas cubre la ficha del elector (electores.page/show/update, interacciones.*).
const ROLES_CON_FICHA_ELECTOR = ROLES_CON_MAPA;

export type UsePermisosCapturadosReturn = {
    puedeVerMapa: ComputedRef<boolean>;
    puedeVerFichaElector: ComputedRef<boolean>;
};

/**
 * Permisos de navegación para las listas de capturados (ListaCapturados.vue,
 * Loterias.vue): roles como "enlace" y "anfitrion" no tienen acceso a las rutas de
 * mapa ni a la ficha del elector, así que esos links no deben renderizarse para ellos.
 */
export function usePermisosCapturados(): UsePermisosCapturadosReturn {
    const page = usePage<{ auth: { rol: string | null } }>();
    const rol = computed(() => page.props.auth?.rol ?? null);

    return {
        puedeVerMapa: computed(() => ROLES_CON_MAPA.includes(rol.value ?? '')),
        puedeVerFichaElector: computed(() => ROLES_CON_FICHA_ELECTOR.includes(rol.value ?? '')),
    };
}
