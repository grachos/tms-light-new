<?php
/**
 * Light TMS - Cola de envíos store-and-forward (Fase 4).
 *
 * Al confirmar el despacho de una solicitud se ENCOLAN sus documentos en el
 * orden que exige el RNDC:
 *
 *     tercero (11)  →  vehículo (12)  →  remesa (3)  →  manifiesto (4)
 *
 * El worker de cron (cron/retry_worker.php) DRENA la cola: envía cada fila
 * pendiente cuyas dependencias (filas de menor `orden` de la misma solicitud)
 * ya estén 'enviado'. Reintenta con backoff hasta `max_intentos`.
 *
 * Interruptor de seguridad: si config()['cola']['envio_habilitado'] es false,
 * el worker ARMA y guarda el XML pero NO lo envía (modo previsualización).
 */

declare(strict_types=1);

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../Rndc/RndcClient.php';
require_once __DIR__ . '/../Maestro/EmpresaRepo.php';
require_once __DIR__ . '/../Maestro/TerceroRepo.php';
require_once __DIR__ . '/../Maestro/VehiculoRepo.php';

final class ColaRepo
{
    /** Orden de envío por tipo de documento. */
    private const ORDEN = ['tercero' => 10, 'vehiculo' => 20, 'remesa' => 30, 'manifiesto' => 40];
    /** Proceso RNDC por tipo de documento. */
    private const PROCESO = ['tercero' => 11, 'vehiculo' => 12, 'remesa' => 3, 'manifiesto' => 4];

    /**
     * Encola los documentos de una solicitud ya despachada.
     * Cada despacho (multi-vehículo) añade su propia remesa+manifiesto
     * sin afectar los ya encolados de despachos anteriores.
     */
    public function encolar(PDO $pdo, int $solicitudId): void
    {
        $s = $this->fila($pdo, 'SELECT * FROM solicitud_servicio WHERE id = ?', [$solicitudId]);
        // Obtener la ÚLTIMA remesa y manifiesto creados para este despacho.
        $remesa = $this->fila($pdo, 'SELECT * FROM remesa WHERE solicitud_id = ? ORDER BY id DESC LIMIT 1', [$solicitudId]);
        $manif  = $this->fila($pdo, 'SELECT * FROM manifiesto WHERE solicitud_id = ? ORDER BY id DESC LIMIT 1', [$solicitudId]);
        if ($s === null || $remesa === null || $manif === null) {
            throw new RuntimeException('No se puede encolar: faltan remesa o manifiesto.');
        }

        $maxIntentos = (int) config()['cola']['max_intentos'];

        // 1) Terceros referenciados que existen en el maestro y no están registrados.
        $vistos = [];
        foreach ([
            ['remitente_tipo_id', 'remitente_num_id'],
            ['destinatario_tipo_id', 'destinatario_num_id'],
            ['conductor_tipo_id', 'conductor_num_id'],
            ['generador_tipo_id', 'generador_num_id'],
        ] as [$ct, $cn]) {
            $tipo = $s[$ct] ?? null;
            $num  = $s[$cn] ?? null;
            if (!$tipo || !$num) {
                continue;
            }
            $clave = $tipo . '|' . $num;
            if (isset($vistos[$clave])) {
                continue;
            }
            $vistos[$clave] = true;
            $t = $this->fila($pdo, 'SELECT id, estado_rndc FROM tercero WHERE tipo_id = ? AND num_id = ?', [$tipo, $num]);
            if ($t !== null && ($t['estado_rndc'] ?? '') !== 'registrado') {
                $this->insertarCola($pdo, $solicitudId, 'tercero', (int) $t['id'], 'Tercero ' . $tipo . ' ' . $num);
            }
        }

        // 2) Vehículo del maestro (si no está registrado).
        if (!empty($manif['placa_vehiculo'])) {
            $v = $this->fila($pdo, 'SELECT id, estado_rndc FROM vehiculo WHERE placa = ?', [strtoupper((string) $manif['placa_vehiculo'])]);
            if ($v !== null && ($v['estado_rndc'] ?? '') !== 'registrado') {
                $this->insertarCola($pdo, $solicitudId, 'vehiculo', (int) $v['id'], 'Vehículo ' . $manif['placa_vehiculo']);
            }
        }

        // 3) Remesa y 4) Manifiesto (payload XML completo, sin credenciales).
        $this->insertarCola($pdo, $solicitudId, 'remesa', (int) $remesa['id'], $this->payloadRemesa($remesa, $pdo));
        $this->insertarCola($pdo, $solicitudId, 'manifiesto', (int) $manif['id'], $this->payloadManifiesto($manif, $remesa, $pdo));

        unset($maxIntentos);
    }

    /** Inserta una fila en la cola. */
    private function insertarCola(PDO $pdo, int $solicitudId, string $tipo, int $referenciaId, string $payloadXml): void
    {
        $pdo->prepare(
            'INSERT INTO cola_envios
                (solicitud_id, tipo_documento, referencia_id, proceso_rndc, orden, payload_xml, estado, max_intentos)
             VALUES (?, ?, ?, ?, ?, ?, \'pendiente\', ?)'
        )->execute([
            $solicitudId, $tipo, $referenciaId, self::PROCESO[$tipo], self::ORDEN[$tipo],
            $payloadXml, (int) config()['cola']['max_intentos'],
        ]);
    }

    /**
     * Drena la cola: envía las filas pendientes en orden, respetando dependencias.
     *
     * @return array{enviados:int,errores:int,esperando:int,previstos:int}
     */
    public function drenar(): array
    {
        $habilitado = (bool) config()['cola']['envio_habilitado'];
        $minutos    = (int) config()['cola']['minutos_reintento'];
        $rndc       = RndcClient::desdeConfig();

        $pendientes = db()->query(
            "SELECT * FROM cola_envios
             WHERE estado = 'pendiente'
               AND (programado_para IS NULL OR programado_para <= NOW())
             ORDER BY solicitud_id, orden, id"
        )->fetchAll();

        $res = ['enviados' => 0, 'errores' => 0, 'esperando' => 0, 'previstos' => 0];

        foreach ($pendientes as $row) {
            $id = (int) $row['id'];

            // Modo seguro: previsualiza el XML de TODAS las filas (sin enviar ni
            // bloquear por dependencias, que solo importan en el envío real).
            if (!$habilitado) {
                $preview = in_array($row['tipo_documento'], ['remesa', 'manifiesto'], true)
                    ? $rndc->previewXmlInterno((int) $row['proceso_rndc'], (string) $row['payload_xml'])
                    : '(envío del maestro ' . $row['tipo_documento'] . ' #' . $row['referencia_id'] . ')';
                db()->prepare(
                    'UPDATE cola_envios SET respuesta_rndc = ?, ultimo_error = ? WHERE id = ?'
                )->execute([$preview, 'Modo seguro: envío deshabilitado (COLA_ENVIO_HABILITADO=false).', $id]);
                $res['previstos']++;
                continue;
            }

            // Dependencias: no enviar si una fila previa de la misma solicitud no se ha enviado.
            if ($this->dependenciaPendiente((int) $row['solicitud_id'], (int) $row['orden'])) {
                $res['esperando']++;
                continue;
            }

            db()->prepare("UPDATE cola_envios SET estado = 'enviando' WHERE id = ?")->execute([$id]);
            $resp = $this->enviarFila($rndc, $row);

            if ($resp->ok) {
                db()->prepare(
                    "UPDATE cola_envios
                     SET estado = 'enviado', rndc_ingreso_id = ?, respuesta_rndc = ?, ultimo_error = NULL,
                         intentos = intentos + 1, enviado_at = NOW()
                     WHERE id = ?"
                )->execute([$resp->ingresoId, $resp->respuestaCruda, $id]);
                $this->marcarOrigen($row, $resp);
                $res['enviados']++;
            } else {
                $intentos = (int) $row['intentos'] + 1;
                $agotado  = $intentos >= (int) $row['max_intentos'];
                db()->prepare(
                    'UPDATE cola_envios
                     SET estado = ?, intentos = ?, ultimo_error = ?, respuesta_rndc = ?,
                         programado_para = ' . ($agotado ? 'NULL' : 'DATE_ADD(NOW(), INTERVAL ? MINUTE)') . '
                     WHERE id = ?'
                )->execute($agotado
                    ? ['error', $intentos, $resp->error, $resp->respuestaCruda, $id]
                    : ['pendiente', $intentos, $resp->error, $resp->respuestaCruda, $minutos, $id]);
                $res['errores']++;
            }
        }

        return $res;
    }

    /** Envía una fila según su tipo (maestros vía sus repos; documentos vía XML). */
    private function enviarFila(RndcClient $rndc, array $row): RndcRespuesta
    {
        return match ($row['tipo_documento']) {
            'tercero'  => (new TerceroRepo())->registrarEnRndc((int) $row['referencia_id']),
            'vehiculo' => (new VehiculoRepo())->registrarEnRndc((int) $row['referencia_id']),
            default    => $rndc->ingresarXml((int) $row['proceso_rndc'], (string) $row['payload_xml']),
        };
    }

    /** Propaga el resultado al documento de origen y cierra el despacho. */
    private function marcarOrigen(array $row, RndcRespuesta $resp): void
    {
        if ($row['tipo_documento'] === 'remesa' || $row['tipo_documento'] === 'manifiesto') {
            db()->prepare(
                "UPDATE `{$row['tipo_documento']}` SET estado_rndc = 'aceptado', rndc_ingreso_id = ? WHERE id = ?"
            )->execute([$resp->ingresoId, (int) $row['referencia_id']]);
        }
        if ($row['tipo_documento'] === 'manifiesto') {
            db()->prepare("UPDATE solicitud_servicio SET estado = 'despachada' WHERE id = ?")
                ->execute([(int) $row['solicitud_id']]);
        }
    }

    /** ¿Hay una fila previa (menor orden) de la misma solicitud sin enviar? */
    private function dependenciaPendiente(int $solicitudId, int $orden): bool
    {
        $stmt = db()->prepare(
            "SELECT COUNT(*) FROM cola_envios
             WHERE solicitud_id = ? AND orden < ? AND estado <> 'enviado'"
        );
        $stmt->execute([$solicitudId, $orden]);
        return (int) $stmt->fetchColumn() > 0;
    }

    // ---------- Construcción de payloads (variables RNDC) ----------

    /** @param array<string,mixed> $r remesa */
    private function payloadRemesa(array $r, ?PDO $pdo = null): string
    {
        $sedeTercero = static function (string $tipo, string $num) use ($pdo): string {
            if ($pdo === null || $tipo === '' || $num === '') {
                return '0';
            }
            $f = $pdo->prepare('SELECT sede FROM tercero WHERE tipo_id = ? AND num_id = ?');
            $f->execute([$tipo, $num]);
            $row = $f->fetch();
            return $row !== false && ($row['sede'] ?? '') !== '' ? $row['sede'] : '0';
        };

        $vars = [
            'NUMNITEMPRESATRANSPORTE'  => config()['rndc']['empresa'],
            'consecutivoRemesa'        => (new EmpresaRepo())->siguienteConsecutivoRemesa(),
            'codOperacionTransporte'   => $r['operacion_transporte'],
            'codTipoEmpaque'           => $r['tipo_empaque'] ?: '0',
            'codNaturalezaCarga'       => $r['naturaleza_carga'],
            'descripcionCortaProducto' => $r['descripcion_producto'],
            'mercanciaRemesa'          => $r['mercancia_codigo'],
            'cantidadCargada'          => self::num($r['cantidad_cargada']),
            'unidadMedidaCapacidad'    => $r['unidad_medida'],
            'pesoContenedorVacio'      => '2100',
            'codTipoIdRemitente'       => $r['remitente_tipo_id'],
            'numIdRemitente'           => $r['remitente_num_id'],
            'codSedeRemitente'         => $sedeTercero($r['remitente_tipo_id'], $r['remitente_num_id']),
            'codTipoIdDestinatario'    => $r['destinatario_tipo_id'],
            'numIdDestinatario'        => $r['destinatario_num_id'],
            'codSedeDestinatario'      => $sedeTercero($r['destinatario_tipo_id'], $r['destinatario_num_id']),
            'codTipoIdPropietario'     => $r['propietario_tipo_id'],
            'numIdPropietario'         => $r['propietario_num_id'],
            'duenoPoliza'              => $r['dueno_poliza'] ?? 'N',
            'horasPactoCarga'          => $r['horas_pacto_cargue'] ?? '1',
            'minutospactocarga'        => $r['minutos_pacto_cargue'] ?? '0',
            'fechaCitaPactadaCargue'   => self::fecha($r['fecha_cita_cargue']),
            'horaCitaPactadaCargue'    => $r['hora_cita_cargue'],
            'horasPactoDescargue'      => $r['horas_pacto_descargue'],
            'minutosPactoDescargue'    => $r['minutos_pacto_descargue'] ?? '0',
            'fechaCitaPactadaDescargue' => self::fecha($r['fecha_cita_descargue']),
            'horaCitaPactadaDescargueRemesa' => $r['hora_cita_descargue'],
            'codSedePropietario'       => $sedeTercero($r['propietario_tipo_id'], $r['propietario_num_id']),
            'CODIGOUN'                 => $r['codigo_un'],
            'ESTADOMERCANCIA'          => $r['estado_producto'],
        ];
        return RndcClient::renderVariables($vars);
    }

    /**
     * @param array<string,mixed> $m manifiesto
     * @param array<string,mixed> $r remesa (para CONSECUTIVOREMESA)
     */
    private function payloadManifiesto(array $m, array $r, PDO $pdo): string
    {
        // La placa del remolque se hereda del maestro de vehículos.
        $remolque = null;
        if (!empty($m['placa_vehiculo'])) {
            $v = $this->fila($pdo, 'SELECT remolque_placa FROM vehiculo WHERE placa = ?', [strtoupper((string) $m['placa_vehiculo'])]);
            $remolque = $v['remolque_placa'] ?? null;
        }

        // RETENCIONICAMANIFIESTOCARGA va como TARIFA por mil (no como monto).
        $tarifaIca = null;
        if (!empty($m['solicitud_id'])) {
            $s = $this->fila($pdo, 'SELECT porcentaje_ica FROM solicitud_servicio WHERE id = ?', [(int) $m['solicitud_id']]);
            $tarifaIca = $s['porcentaje_ica'] ?? null;
        }

        $vars = [
            'NUMNITEMPRESATRANSPORTE'      => config()['rndc']['empresa'],
            'NUMMANIFIESTOCARGA'          => $m['num_manifiesto'],
            'CODOPERACIONTRANSPORTE'      => $m['operacion_transporte'],
            'FECHAEXPEDICIONMANIFIESTO'   => self::fecha($m['fecha_expedicion']),
            'CODMUNICIPIOORIGENMANIFIESTO'  => $m['municipio_origen'],
            'CODMUNICIPIODESTINOMANIFIESTO' => $m['municipio_destino'],
            'CODIDTITULARMANIFIESTO'      => $m['titular_tipo_id'],
            'NUMIDTITULARMANIFIESTO'      => $m['titular_num_id'],
            'NUMPLACA'                    => $m['placa_vehiculo'],
            'NUMPLACAREMOLQUE'            => $remolque,
            'CODIDCONDUCTOR'              => $m['conductor_tipo_id'],
            'NUMIDCONDUCTOR'              => $m['conductor_num_id'],
            'VALORFLETEPACTADOVIAJE'      => self::num($m['valor_flete_pactado']),
            'RETENCIONICAMANIFIESTOCARGA' => self::num($tarifaIca),
            'RETENCIONFUENTEMANIFIESTO'   => self::num($m['retencion_fuente']),
            'VALORANTICIPOMANIFIESTO'     => self::num($m['valor_anticipo']),
            'CODMUNICIPIOPAGOSALDO'       => $m['municipio_pago_saldo'],
            'FECHAPAGOSALDOMANIFIESTO'    => self::fecha($m['fecha_pago_saldo']),
            'CODRESPONSABLEPAGOCARGUE'    => $m['responsable_pago_cargue'],
            'CODRESPONSABLEPAGODESCARGUE' => $m['responsable_pago_descargue'],
            'TIPOVALORPACTADO'            => $m['tipo_valor_pactado'],
            'MANNROPOLIZA'                => $m['nro_poliza'],
        ];
        // NITMONITOREOFLOTA: siempre se envía (incluso vacío), required display.
        $vars['NITMONITOREOFLOTA'] = $m['emf'] ?? '';

        // Remesas asociadas al manifiesto (bloque anidado).
        $remesas = '<REMESASMAN procesoid="43"><REMESA>'
            . '<CONSECUTIVOREMESA>' . RndcClient::escaparXml((string) $r['num_remesa']) . '</CONSECUTIVOREMESA>'
            . '</REMESA></REMESASMAN>';

        $xml = RndcClient::renderVariables($vars);
        $xml .= '<NITMONITOREOFLOTA>' . RndcClient::escaparXml($m['emf'] ?? '') . '</NITMONITOREOFLOTA>';
        return $xml . $remesas;
    }

    /** Normaliza un número: quita decimales superfluos (3000000.00 → 3000000). */
    private static function num($valor): ?string
    {
        if ($valor === null || $valor === '') {
            return null;
        }
        if (!is_numeric($valor)) {
            return (string) $valor;
        }
        $s = (string) (float) $valor;
        return $s;
    }

    /** Convierte YYYY-MM-DD (o datetime) al formato del RNDC DD/MM/YYYY. */
    private static function fecha(?string $fecha): ?string
    {
        if (empty($fecha)) {
            return null;
        }
        $ts = strtotime($fecha);
        return $ts ? date('d/m/Y', $ts) : $fecha;
    }

    /**
     * @param list<scalar> $params
     * @return array<string,mixed>|null
     */
    private function fila(PDO $pdo, string $sql, array $params): ?array
    {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetch() ?: null;
    }

    // ---------- Lectura para el monitor ----------

    /** @return list<array<string,mixed>> */
    public function listar(int $limite = 200): array
    {
        return db()->query(
            'SELECT c.*, s.consecutivo
             FROM cola_envios c
             LEFT JOIN solicitud_servicio s ON s.id = c.solicitud_id
             ORDER BY c.id DESC LIMIT ' . (int) $limite
        )->fetchAll();
    }

    /** @return array<string,int> conteo por estado */
    public function resumen(): array
    {
        $filas = db()->query('SELECT estado, COUNT(*) n FROM cola_envios GROUP BY estado')->fetchAll();
        $out = [];
        foreach ($filas as $f) {
            $out[$f['estado']] = (int) $f['n'];
        }
        return $out;
    }
}
