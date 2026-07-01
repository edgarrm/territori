<?php

namespace Database\Seeders;

use App\Models\AvisoPrivacidad;
use App\Models\Membership;
use App\Models\Seccion;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Telefono;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/**
 * Demo completo: metas + capturas en (casi) todas las secciones del municipio,
 * repartidas entre varios brigadistas, con distribución que cubre los 5 buckets
 * de color del mapa (desértica → meta cumplida). Inserción masiva por volumen.
 * Idempotente: limpia metas/capturas/zonas/cobertura del tenant y resiembra.
 *
 * Invocar manualmente: (new \Database\Seeders\DemoCapturasSeeder)->run();
 */
class DemoCapturasSeeder extends Seeder
{
    /** Brigadistas demo (además del que crea DemoTenantSeeder). */
    private const BRIGADISTAS = [
        'sofia@demo.test' => 'Sofía Vega',
        'luis@demo.test' => 'Luis Romero',
        'marisol@demo.test' => 'Marisol Cota',
        'diego@demo.test' => 'Diego Lares',
    ];

    /** Distribución de avance por sección: cubre rojo→teal, ~10% desértica. */
    private const FACTORES = [
        0, 0, 0.12, 0.22, 0.32, 0.41, 0.47, 0.52, 0.58, 0.63,
        0.68, 0.72, 0.78, 0.83, 0.88, 0.93, 0.98, 1.05, 1.12, 1.2,
    ];

    public function run(): void
    {
        $tenant = Tenant::query()->where('subdominio', 'demo')->first();

        if (! $tenant) {
            $this->command?->error('No existe el tenant demo. Corre DemoTenantSeeder primero.');

            return;
        }

        TenantContext::set($tenant);

        $aviso = AvisoPrivacidad::query()->where('tenant_id', $tenant->id)->latest('vigente_desde')->first();

        if (! $aviso) {
            $this->command?->error('Falta aviso de privacidad. Corre DemoTenantSeeder primero.');

            return;
        }

        $brigadistas = $this->brigadistas($tenant);
        $secciones = Seccion::query()->where('municipio_id', $tenant->municipio_id)->orderBy('numero')->get();

        if ($secciones->isEmpty()) {
            $this->command?->error('El municipio del tenant demo no tiene secciones cargadas.');

            return;
        }

        // La cartografía no trae lista nominal (padrón). Sin ella la penetración
        // es siempre 0. La sembramos realista por tipo para que la métrica viva.
        $this->asegurarListaNominal($secciones);

        // Reset de datos derivados/capturas del tenant.
        DB::table('solicitudes_arco')->where('tenant_id', $tenant->id)->delete();
        DB::table('interacciones')->where('tenant_id', $tenant->id)->delete();
        DB::table('electores')->where('tenant_id', $tenant->id)->delete();
        DB::table('eventos')->where('tenant_id', $tenant->id)->delete();
        DB::table('metas_seccion')->where('tenant_id', $tenant->id)->delete();
        DB::table('cobertura_seccion')->where('tenant_id', $tenant->id)->delete();
        DB::table('brigadista_seccion')->where('tenant_id', $tenant->id)->delete();

        $nombres = $this->poolNombres();
        $minutosHoy = (int) now()->diffInMinutes(now()->copy()->startOfDay());

        $zonas = [];
        $metas = [];
        $electores = [];

        foreach ($secciones as $i => $seccion) {
            $brigadista = $brigadistas[$i % count($brigadistas)];
            $zonas[] = ['tenant_id' => $tenant->id, 'membership_id' => $brigadista->id, 'seccion_id' => $seccion->id];

            $meta = $seccion->lista_nominal
                ? max(12, (int) round($seccion->lista_nominal * 0.1))
                : random_int(15, 45);
            $metas[] = [
                'tenant_id' => $tenant->id,
                'seccion_id' => $seccion->id,
                'meta_capturas' => $meta,
                'fuente_meta' => 'manual',
                'pct_lista_nominal' => null,
            ];

            $cantidad = (int) round($meta * self::FACTORES[$i % count(self::FACTORES)]);

            for ($n = 0; $n < $cantidad; $n++) {
                $sinTelefono = $n % 5 === 0;
                $hoy = $n % 5 < 2;
                $telefono = '669'.str_pad((string) random_int(100000, 9999999), 7, '0', STR_PAD_LEFT);
                $ts = ($hoy
                    ? now()->copy()->subMinutes(random_int(0, max(1, $minutosHoy)))
                    : now()->copy()->subDays(random_int(1, 14))->subMinutes(random_int(0, 1439)))
                    ->format('Y-m-d H:i:s');

                $electores[] = [
                    'tenant_id' => $tenant->id,
                    'seccion_id' => $seccion->id,
                    'membership_id' => $brigadista->id,
                    'modo_captura' => $n % 3 === 0 ? 'loteria' : 'enlace_seccional',
                    'loteria_id' => null,
                    'evento_id' => null,
                    'nombre' => $nombres[array_rand($nombres)],
                    'telefono' => Crypt::encryptString($telefono),
                    'telefono_hash' => $sinTelefono ? null : Telefono::hash($telefono),
                    'domicilio' => null,
                    'ubicacion' => null,
                    'observaciones' => null,
                    'consentimiento' => true,
                    'aviso_privacidad_id' => $aviso->id,
                    'created_at' => $ts,
                    'updated_at' => $ts,
                ];
            }
        }

        DB::transaction(function () use ($zonas, $metas, $electores): void {
            foreach (array_chunk($zonas, 1000) as $bloque) {
                DB::table('brigadista_seccion')->insert($bloque);
            }
            foreach (array_chunk($metas, 1000) as $bloque) {
                DB::table('metas_seccion')->insert($bloque);
            }
            foreach (array_chunk($electores, 500) as $bloque) {
                DB::table('electores')->insert($bloque);
            }
        });

        // Recálculo canónico e idempotente de cobertura_seccion (capturados/meta, penetración).
        Artisan::call('territori:recalcular-cobertura', ['tenant' => $tenant->id]);

        $interacciones = $this->interacciones($tenant);
        $eventos = $this->eventosYArco($tenant, $secciones);

        $this->command?->info(sprintf(
            'Demo: %d electores en %d secciones, %d brigadistas, %d interacciones, %d eventos.',
            count($electores),
            $secciones->count(),
            count($brigadistas),
            $interacciones,
            $eventos,
        ));
    }

    /**
     * Siembra eventos demo (marcando electores existentes como asistentes para no
     * alterar la cobertura) y una solicitud ARCO pendiente. Devuelve # de eventos.
     *
     * @param  Collection<int, Seccion>  $secciones
     */
    private function eventosYArco(Tenant $tenant, $secciones): int
    {
        // Sedes con electores reales (las primeras secciones son desérticas).
        $sedes = DB::table('electores')
            ->where('tenant_id', $tenant->id)
            ->select('seccion_id')
            ->groupBy('seccion_id')
            ->orderByRaw('COUNT(*) DESC')
            ->limit(2)
            ->pluck('seccion_id');

        $total = 0;

        foreach ($sedes as $seccionId) {
            $esPrimero = $total === 0;
            $eventoId = DB::table('eventos')->insertGetId([
                'tenant_id' => $tenant->id,
                'nombre' => $esPrimero ? 'Mitin de arranque' : 'Reunión vecinal',
                'tipo' => $esPrimero ? 'mitin' : 'reunion',
                'fecha' => now()->subDays($total + 1),
                'lugar' => $esPrimero ? 'Plaza principal' : 'Casa de campaña',
                'seccion_id' => $seccionId,
                'ubicacion' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // Marca hasta 15 electores de esa sección como asistentes del evento.
            $ids = DB::table('electores')
                ->where('tenant_id', $tenant->id)
                ->where('seccion_id', $seccionId)
                ->limit(15)
                ->pluck('id');

            DB::table('electores')->whereIn('id', $ids)
                ->update(['evento_id' => $eventoId, 'modo_captura' => 'evento']);

            $total++;
        }

        // Solicitudes ARCO pendientes de varios tipos sobre electores reales,
        // para que la bandeja de gestión tenga qué mostrar (incluida una
        // cancelación para demostrar el flujo de baja). Más una atendida (historial).
        $electorIds = DB::table('electores')->where('tenant_id', $tenant->id)->limit(5)->pluck('id')->all();

        if ($electorIds !== []) {
            $filas = [];
            foreach (['acceso', 'rectificacion', 'oposicion', 'cancelacion'] as $i => $tipo) {
                $filas[] = [
                    'tenant_id' => $tenant->id,
                    'elector_id' => $electorIds[$i % count($electorIds)],
                    'tipo' => $tipo,
                    'estado' => 'pendiente',
                    'solicitado_en' => now()->subDays($i),
                    'atendido_en' => null,
                ];
            }
            // Una atendida para el filtro "Atendidas" (historial/trazabilidad).
            $filas[] = [
                'tenant_id' => $tenant->id,
                'elector_id' => $electorIds[count($electorIds) - 1],
                'tipo' => 'acceso',
                'estado' => 'atendida',
                'solicitado_en' => now()->subDays(6),
                'atendido_en' => now()->subDays(5),
            ];
            DB::table('solicitudes_arco')->insert($filas);
        }

        return $total;
    }

    /**
     * Siembra interacciones sobre una muestra de electores: algunas con
     * seguimiento vencido (alimentan la agenda) y otras futuras/atendidas.
     * Devuelve el total insertado.
     */
    private function interacciones(Tenant $tenant): int
    {
        $tipos = ['llamada', 'visita', 'whatsapp', 'sms', 'nota'];
        $resultados = ['contesto', 'no_contesto', 'no_estaba', 'compromiso', 'rechazo'];

        $muestra = DB::table('electores')
            ->where('tenant_id', $tenant->id)
            ->inRandomOrder()
            ->limit(120)
            ->get(['id', 'membership_id']);

        $filas = [];

        foreach ($muestra as $j => $elector) {
            $tipo = $tipos[$j % count($tipos)];
            $fecha = now()->copy()->subDays(random_int(0, 10))->format('Y-m-d H:i:sP');

            // 1 de cada 3 deja un seguimiento vencido pendiente (agenda del día).
            $vencido = $j % 3 === 0;
            $seguimiento = $vencido
                ? now()->copy()->subDays(random_int(0, 3))->toDateString()
                : ($j % 3 === 1 ? now()->copy()->addDays(random_int(2, 7))->toDateString() : null);

            $filas[] = [
                'tenant_id' => $tenant->id,
                'elector_id' => $elector->id,
                'membership_id' => $elector->membership_id,
                'tipo' => $tipo,
                'resultado' => $tipo === 'nota' ? null : $resultados[$j % count($resultados)],
                'nota' => $tipo === 'nota' ? 'Vive cerca de la plaza; visitar por la tarde.' : null,
                'fecha' => $fecha,
                'proximo_seguimiento' => $seguimiento,
                'atendido_en' => null,
                'created_at' => $fecha,
            ];
        }

        foreach (array_chunk($filas, 500) as $bloque) {
            DB::table('interacciones')->insert($bloque);
        }

        return count($filas);
    }

    /**
     * Siembra lista_nominal (padrón) por sección, dimensionada por tipo, de forma
     * determinística (semilla = número de sección) para que las re-siembras sean
     * estables. Muta la colección en memoria para que el cálculo de meta la use.
     *
     * @param  Collection<int, Seccion>  $secciones
     */
    private function asegurarListaNominal(Collection $secciones): void
    {
        // [min, max] de padrón por tipo de sección (INE: 2=No urbana, 3=Mixta).
        $rangos = [
            2 => [220, 950],
            3 => [800, 1900],
            4 => [1300, 3200],
        ];

        foreach ($secciones as $seccion) {
            // Respeta el padrón real (CartografiaSeeder con lista_nominal.csv): solo
            // inventa la lista nominal cuando la sección no la trae.
            if ((int) ($seccion->lista_nominal ?? 0) > 0) {
                continue;
            }

            [$min, $max] = $rangos[$seccion->tipo] ?? [400, 1500];
            $span = $max - $min;
            $valor = $min + (int) (crc32((string) $seccion->numero) % ($span + 1));
            $valor = (int) (round($valor / 10) * 10);

            DB::table('secciones')->where('id', $seccion->id)->update(['lista_nominal' => $valor]);
            $seccion->lista_nominal = $valor;
        }
    }

    /**
     * Asegura los brigadistas demo (activos) y devuelve sus memberships.
     *
     * @return list<Membership>
     */
    private function brigadistas(Tenant $tenant): array
    {
        $memberships = [];

        $existente = Membership::query()
            ->where('tenant_id', $tenant->id)
            ->where('rol', 'brigadista')
            ->whereHas('user', fn ($q) => $q->where('email', 'brigadista@demo.test'))
            ->first();

        if ($existente) {
            $memberships[] = $existente;
        }

        foreach (self::BRIGADISTAS as $email => $nombre) {
            $user = User::query()->firstOrCreate(
                ['email' => $email],
                ['name' => $nombre, 'password' => bcrypt('password')],
            );

            $memberships[] = Membership::query()->firstOrCreate(
                ['tenant_id' => $tenant->id, 'user_id' => $user->id],
                ['rol' => 'brigadista', 'meta_diaria' => random_int(15, 30), 'activo' => true, 'activado_en' => now()],
            );
        }

        return $memberships;
    }

    /**
     * @return list<string>
     */
    private function poolNombres(): array
    {
        $nombres = ['José', 'María', 'Juan', 'Guadalupe', 'Francisco', 'Rosa', 'Pedro', 'Carmen', 'Luis', 'Ana', 'Jorge', 'Patricia', 'Miguel', 'Verónica', 'Roberto', 'Leticia'];
        $apellidos = ['López', 'García', 'Hernández', 'Martínez', 'González', 'Sánchez', 'Ramírez', 'Flores', 'Torres', 'Rivera', 'Cota', 'Osuna', 'Lizárraga', 'Beltrán'];

        $pool = [];

        foreach ($nombres as $nombre) {
            foreach ($apellidos as $apellido) {
                $pool[] = "{$nombre} {$apellido}";
            }
        }

        return $pool;
    }
}
