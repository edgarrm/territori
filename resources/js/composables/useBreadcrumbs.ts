import { setLayoutProps, usePage } from '@inertiajs/vue3';
import { dashboard } from '@/routes';
import type { BreadcrumbItem } from '@/types';

/**
 * Breadcrumbs de páginas compartidas entre gestión y roles de campo. Solo la
 * gestión (coordinador/admin) tiene acceso al dashboard, así que para el resto
 * la raíz se omite: dejarla los llevaría a un 403.
 */
export function useBreadcrumbs(items: BreadcrumbItem[]): void {
    const page = usePage<{ auth: { rol: string | null } }>();
    const esGestion = ['coordinador', 'admin'].includes(page.props.auth?.rol ?? '');

    setLayoutProps({
        breadcrumbs: esGestion
            ? [{ title: 'Dashboard', href: dashboard() }, ...items]
            : items,
    });
}
