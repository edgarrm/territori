<?php

namespace App\Http\Controllers;

use App\Actions\Electores\VerificarElector;
use App\Actions\Interacciones\RegistrarInteraccion;
use App\Http\Controllers\Concerns\PresentaElectores;
use App\Http\Requests\StoreInteraccionRequest;
use App\Models\Elector;
use App\Models\Interaccion;
use App\Models\Membership;
use App\Support\Pii;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class InteraccionController extends Controller
{
    use PresentaElectores;

    /**
     * Timeline de un elector (desc por fecha). Resolución manual: el binding
     * implícito correría antes de ResolveTenant (patrón Sprint 4).
     */
    public function indexPorElector(string $elector): JsonResponse
    {
        $modelo = Elector::query()->findOrFail($elector);

        $this->asegurarAccesoElector($modelo);

        $verPii = $this->puedeVerPii($modelo, $this->miMembership());

        return response()->json(
            $modelo->interacciones()->get()->map(fn (Interaccion $i): array => $this->presentar($i, $verPii))->all()
        );
    }

    public function store(StoreInteraccionRequest $request, string $elector, RegistrarInteraccion $registrar, VerificarElector $verificar): JsonResponse
    {
        $modelo = Elector::query()->findOrFail($elector);
        $membership = $request->user()->membershipEn(TenantContext::get());

        abort_unless($membership !== null && $membership->puedeAccederElector($modelo), 403);

        $interaccion = $registrar->handle($modelo, $request->validated(), $membership);

        if ($request->boolean('verificar')) {
            $verificar->marcar($modelo, $interaccion->tipo, $membership);
        }

        // Quien acaba de escribir la nota puede leerla de vuelta sin enmascarar.
        return response()->json($this->presentar($interaccion, true), 201);
    }

    /**
     * Marca un seguimiento como atendido (idempotente: conserva la primera marca).
     */
    public function atendido(string $interaccion): JsonResponse
    {
        $modelo = Interaccion::query()->with('elector.redCiudadana')->findOrFail($interaccion);

        $this->asegurarAccesoElector($modelo->elector);

        if ($modelo->atendido_en === null) {
            $modelo->update(['atendido_en' => now()]);
        }

        return response()->json($this->presentar(
            $modelo,
            $modelo->elector !== null && $this->puedeVerPii($modelo->elector, $this->miMembership()),
        ));
    }

    /**
     * Agenda del día: seguimientos vencidos no atendidos. La lista se carga desde
     * el cliente vía agendaData (paginada + búsqueda), como en el detalle de sección.
     */
    public function agenda(): Response
    {
        $viewer = request()->user()?->membershipEn(TenantContext::get());

        return Inertia::render('Agenda', [
            'secciones' => $viewer?->seccionesDisponibles() ?? [],
        ]);
    }

    public function agendaData(Request $request): JsonResponse
    {
        return response()->json($this->pendientes($request));
    }

    /**
     * Seguimientos vencidos no atendidos, paginados. Brigadista ve los suyos;
     * coordinador/admin ven todos los del tenant (rol desde la membership).
     * Filtros opcionales: `q` (nombre del elector, ILIKE) y `secciones` (arreglo
     * de ids de sección, del multiselect de secciones accesibles).
     *
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    private function pendientes(Request $request): LengthAwarePaginator
    {
        $membership = $request->user()->membershipEn(TenantContext::get());

        $busqueda = trim((string) $request->query('q', ''));
        $secciones = array_values(array_filter(array_map(
            'intval',
            (array) $request->query('secciones', []),
        )));

        return Interaccion::query()
            ->pendientes()
            // `membership_id` y `red_ciudadana_id` son los que decide `puedeVerPii`
            // (quién capturó / de qué red es): sin ellos en el select, el
            // enmascarado se evaluaría con atributos ausentes. `redCiudadana`
            // va precargada para no hacer una query por fila.
            ->with([
                'elector:id,nombre,seccion_id,membership_id,red_ciudadana_id',
                'elector.seccion:id,numero',
                'elector.redCiudadana:id,enlace_membership_id',
            ])
            // La agenda es la superficie desde la que se contacta: quien ejerció
            // Oposición (no_contactar) sale de ella (P9). El whereHas exige
            // además un elector vivo, así que los cancelados por ARCO tampoco
            // arrastran su seguimiento pendiente.
            ->whereHas('elector', fn (Builder $elector) => $elector->where('no_contactar', false))
            ->when(
                $membership->esBrigadista(),
                fn (Builder $query) => $query->where('membership_id', $membership->id),
            )
            ->when(
                $busqueda !== '',
                fn (Builder $query) => $query->whereHas(
                    'elector',
                    fn (Builder $elector) => $elector->where('nombre', 'ILIKE', '%'.$busqueda.'%'),
                ),
            )
            ->when(
                $secciones !== [],
                fn (Builder $query) => $query->whereHas(
                    'elector',
                    fn (Builder $elector) => $elector->whereIn('seccion_id', $secciones),
                ),
            )
            ->orderBy('proximo_seguimiento')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (Interaccion $i): array => $this->presentarPendiente($i, $membership));
    }

    /**
     * Fila de la agenda: la interacción pendiente + los datos mínimos del
     * elector que la lista necesita mostrar.
     *
     * El nombre y la nota siguen la misma regla que las listas de capturados:
     * `puedeVerPii` (propiedad del registro), no `puedeAccederElector` (zona).
     * La agenda mostraba el nombre completo de un elector ajeno que las listas
     * ya reducían a iniciales (P6), y la nota es donde más caen datos del art. 9.
     *
     * @return array<string, mixed>
     */
    private function presentarPendiente(Interaccion $i, ?Membership $viewer): array
    {
        $verPii = $this->puedeVerPii($i->elector, $viewer);

        return [
            'id' => $i->id,
            'tipo' => $i->tipo,
            'nota' => $verPii ? $i->nota : Pii::enmascararTexto($i->nota),
            'proximo_seguimiento' => $i->proximo_seguimiento?->toDateString(),
            'elector' => [
                'id' => $i->elector->id,
                'nombre' => $verPii ? $i->elector->nombre : Pii::enmascararNombre($i->elector->nombre),
                'seccion_id' => $i->elector->seccion_id,
                'seccion_numero' => $i->elector->seccion?->numero,
            ],
        ];
    }

    /**
     * Membresía activa del usuario en el tenant actual. El rol vive en la
     * membership, nunca en el user.
     */
    private function miMembership(): ?Membership
    {
        $user = request()->user();
        $tenant = TenantContext::get();

        return ($user !== null && $tenant !== null) ? $user->membershipEn($tenant) : null;
    }

    /**
     * Autoriza el acceso al timeline de un elector según las zonas/propiedad del
     * viewer (misma regla que la ficha del elector). 403 si no le corresponde.
     * El elector puede ser null si fue cancelado (ARCO, soft-delete): en ese caso
     * solo gestión puede operar sobre sus interacciones huérfanas.
     */
    private function asegurarAccesoElector(?Elector $elector): void
    {
        $viewer = request()->user()?->membershipEn(TenantContext::get());

        abort_if($viewer === null, 403);

        if ($elector === null) {
            abort_unless($viewer->esGestion(), 403);

            return;
        }

        abort_unless($viewer->puedeAccederElector($elector), 403);
    }

    /**
     * La nota es texto libre escrito por el equipo de campo: es donde más
     * probablemente aparecen datos del art. 9 (salud, creencias, filiación).
     * Se enmascara con el mismo criterio que `observaciones` del elector, para
     * que el timeline no sea la puerta trasera de la ficha.
     *
     * @return array<string, mixed>
     */
    private function presentar(Interaccion $interaccion, bool $verPii): array
    {
        return [
            'id' => $interaccion->id,
            'elector_id' => $interaccion->elector_id,
            'membership_id' => $interaccion->membership_id,
            'tipo' => $interaccion->tipo,
            'resultado' => $interaccion->resultado,
            'nota' => $verPii ? $interaccion->nota : Pii::enmascararTexto($interaccion->nota),
            'fecha' => $interaccion->fecha->toIso8601String(),
            'proximo_seguimiento' => $interaccion->proximo_seguimiento?->toDateString(),
            'atendido_en' => $interaccion->atendido_en?->toIso8601String(),
        ];
    }
}
