<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Carbon\CarbonInterface;
use Database\Factories\SolicitudArcoFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Solicitud de derechos ARCO (LFPDPPP, ADR-004): Acceso, Rectificación,
 * Cancelación, Oposición. La Cancelación ejecutada se registra atendida y va
 * acompañada de la baja lógica + scrub del elector.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int|null $elector_id
 * @property int|null $membership_id
 * @property string $tipo
 * @property string $estado
 * @property string|null $titular_contacto
 * @property Carbon $solicitado_en
 * @property Carbon|null $atendido_en
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class SolicitudArco extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<SolicitudArcoFactory> */
    use HasFactory;

    /**
     * Plazo legal de respuesta en días hábiles (LFPDPPP art. 32).
     */
    public const PLAZO_DIAS_HABILES = 20;

    protected $table = 'solicitudes_arco';

    protected $fillable = [
        'elector_id',
        'membership_id',
        'tipo',
        'estado',
        'titular_contacto',
        'solicitado_en',
        'atendido_en',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'titular_contacto' => 'encrypted',
            'solicitado_en' => 'datetime',
            'atendido_en' => 'datetime',
        ];
    }

    /**
     * Fecha límite legal para responder: 20 días hábiles desde la solicitud
     * (LFPDPPP art. 32). Se cuentan solo días laborables; los días festivos
     * oficiales no se modelan, así que la fecha es conservadora por defecto
     * (siempre igual o posterior a la real, nunca antes).
     */
    public function venceEl(): CarbonInterface
    {
        return $this->diaDeSolicitud()->addWeekdays(self::PLAZO_DIAS_HABILES);
    }

    /**
     * ¿Se pasó el plazo legal sin atenderse?
     */
    public function estaVencida(): bool
    {
        return $this->estado !== 'atendida' && $this->diasHabilesRestantes() < 0;
    }

    /**
     * Días hábiles que faltan para el vencimiento. Negativo si ya venció.
     *
     * Se cuenta lo transcurrido y se resta del plazo, en vez de medir de hoy
     * al vencimiento: `addWeekdays` desde un fin de semana arranca el conteo el
     * lunes siguiente, así que la distancia de vuelta da un día de más. A día
     * completo en ambos extremos, porque es una cuenta de calendario.
     */
    public function diasHabilesRestantes(): int
    {
        $transcurridos = (int) round(
            $this->diaDeSolicitud()->diffInWeekdays(now()->startOfDay())
        );

        return self::PLAZO_DIAS_HABILES - $transcurridos;
    }

    /**
     * Día de la solicitud en la zona horaria de la aplicación. La conversión es
     * necesaria, no cosmética: la conexión de Postgres no fija `timezone`, así
     * que un `timestamptz` puede volver con el offset del servidor y comparar
     * "días" contra `now()` (UTC) daría un día de diferencia.
     */
    private function diaDeSolicitud(): CarbonInterface
    {
        return $this->solicitado_en
            ->copy()
            ->setTimezone((string) config('app.timezone'))
            ->startOfDay();
    }

    /**
     * @return BelongsTo<Elector, $this>
     */
    public function elector(): BelongsTo
    {
        return $this->belongsTo(Elector::class);
    }

    /**
     * Quien atendió la solicitud (null mientras está pendiente).
     *
     * @return BelongsTo<Membership, $this>
     */
    public function membership(): BelongsTo
    {
        return $this->belongsTo(Membership::class);
    }
}
