import { usePage } from '@inertiajs/vue3';
import type { ComputedRef } from 'vue';
import { computed } from 'vue';

// Roles con acceso a las rutas de mapa y detalle de sección (routes/web.php:
// grupo rol:coordinador,admin). El brigadista quedó fuera: su perfil es de
// captura y no incluye las vistas de análisis.
const ROLES_CON_MAPA = ['coordinador', 'admin'];
// La ficha del elector sí la conserva el brigadista: es operativa (seguimiento
// de a quien capturó). Grupo rol:brigadista,coordinador,admin.
const ROLES_CON_FICHA_ELECTOR = ['brigadista', 'coordinador', 'admin'];

export type UsePermisosCapturadosReturn = {
    puedeVerMapa: ComputedRef<boolean>;
    puedeVerFichaElector: ComputedRef<boolean>;
};

/**
 * Permisos de navegación para las listas de capturados (ListaCapturados.vue,
 * Loterias.vue, Eventos.vue): los roles sin acceso a una ruta no deben ver el
 * link, o los llevaría a un 403. "enlace", "anfitrion" y "brigadista" no llegan
 * al mapa; solo "enlace" y "anfitrion" quedan fuera de la ficha del elector.
 */
export function usePermisosCapturados(): UsePermisosCapturadosReturn {
    const page = usePage<{ auth: { rol: string | null } }>();
    const rol = computed(() => page.props.auth?.rol ?? null);

    return {
        puedeVerMapa: computed(() => ROLES_CON_MAPA.includes(rol.value ?? '')),
        puedeVerFichaElector: computed(() => ROLES_CON_FICHA_ELECTOR.includes(rol.value ?? '')),
    };
}
