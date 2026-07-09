<?php

namespace App\Models;

use Database\Factories\EstadisticaSeccionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Estadística pública 2024 por sección: resultados por bloque electoral y
 * perfil demográfico por grupos de edad. Global (no tenant-scoped), 1:1 con
 * secciones. Se pobla con territori:importar-estadisticas.
 *
 * @property array<string, int>|null $votos_partidos
 * @property array<string, array{ln: int, votos: int, participacion: float, abstencion: float, potencial: int}>|null $grupos_edad
 */
class EstadisticaSeccion extends Model
{
    /** @use HasFactory<EstadisticaSeccionFactory> */
    use HasFactory;

    protected $table = 'estadisticas_seccion';

    protected $fillable = [
        'seccion_id',
        'lista_nominal_2024',
        'total_votos',
        'participacion_pct',
        'votos_fuerza',
        'pct_fuerza',
        'votos_morena_pvem',
        'pct_morena_pvem',
        'votos_otros',
        'pct_otros',
        'ganador_bloque',
        'margen_votos',
        'margen_pp',
        'ganador_partido',
        'votos_partidos',
        'participacion_2024_pct',
        'abstencion_2024_pct',
        'indice_oportunidad',
        'nivel_oportunidad',
        'grupo_dominante',
        'grupo_mayor_abstencion',
        'potencial_movilizacion',
        'tipo_composicion_edad',
        'universo_operativo',
        'recomendacion',
        'grupos_edad',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'participacion_pct' => 'decimal:2',
            'pct_fuerza' => 'decimal:2',
            'pct_morena_pvem' => 'decimal:2',
            'pct_otros' => 'decimal:2',
            'margen_pp' => 'decimal:2',
            'votos_partidos' => 'array',
            'participacion_2024_pct' => 'decimal:2',
            'abstencion_2024_pct' => 'decimal:2',
            'indice_oportunidad' => 'decimal:2',
            'grupos_edad' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Seccion, $this>
     */
    public function seccion(): BelongsTo
    {
        return $this->belongsTo(Seccion::class);
    }
}
