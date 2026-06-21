<?php
/**
 * Light TMS - Repositorio de Solicitud de Servicio.
 *
 * La Solicitud se captura UNA vez y al guardarla SIEMBRA automáticamente
 * un Manifiesto y una Remesa (estado_rndc = 'pendiente'), heredando los
 * datos base. El usuario no crea esos documentos por separado.
 */

declare(strict_types=1);

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../Maestro/EmpresaRepo.php';
require_once __DIR__ . '/../Maestro/TerceroRepo.php';
require_once __DIR__ . '/../Despacho/ColaRepo.php';

final class SolicitudRepo
{
    /**
     * Datos del despacho que se completan al confirmar (diferidos de la captura).
     * Se guardan en la solicitud y alimentan la remesa y el manifiesto.
     */
    private const CAMPOS_DESPACHO = [
        'placa_vehiculo',
        'conductor_tipo_id', 'conductor_num_id',
        'fecha_cita_cargue', 'hora_cita_cargue',
        'fecha_cita_descargue', 'hora_cita_descargue',
        'horas_pacto_cargue', 'minutos_pacto_cargue',
        'horas_pacto_descargue', 'minutos_pacto_descargue',
        'responsable_pago_cargue', 'responsable_pago_descargue',
        'emf',
    ];

    /**
     * Campos aceptados desde el formulario (lista blanca).
     * Vehículo, conductor, propietario de carga y cargue/descargue se
     * capturan en la Fase 4 (al confirmar el manifiesto).
     */
    private const CAMPOS = [
        'consecutivo', 'fecha_solicitud', 'operacion_transporte', 'tipo_viaje',
        'municipio_pago_saldo',
        'remitente_tipo_id', 'remitente_num_id',
        'destinatario_tipo_id', 'destinatario_num_id',
        'generador_tipo_id', 'generador_num_id',
        'naturaleza_carga', 'tipo_empaque', 'mercancia_codigo',
        'descripcion_producto', 'cantidad_vehiculos', 'unidad_medida', 'peso', 'valor_mercancia',
        'valor_flete', 'porcentaje_ica',
        'retencion_ica', 'retencion_fuente', 'fopat',
        'tipo_flete', 'tipo_valor_pactado', 'fecha_pago_saldo',
        'observaciones', 'dueno_poliza',
    ];

    /**
     * Inserta la solicitud y siembra manifiesto + remesa en una transacción.
     *
     * @param array<string,mixed> $datos
     * @return int id de la solicitud creada
     */
    /**
     * Normaliza los datos del formulario y calcula las retenciones.
     *
     * @param array<string,mixed> $datos
     * @return array<string,mixed>
     */
    private function prepararFila(array $datos): array
    {
        $fila = [];
        foreach (self::CAMPOS as $c) {
            $valor = $datos[$c] ?? null;
            $fila[$c] = ($valor === '' ? null : $valor);
        }
        if (empty($fila['fecha_solicitud'])) {
            $fila['fecha_solicitud'] = date('Y-m-d');
        }
        // El consecutivo de solicitud se asigna post-INSERT con el id (en crear()).
        // Retenciones calculadas en el servidor (no se confía en el cliente).
        $flete = (float) ($fila['valor_flete'] ?? 0);
        $pIca  = (float) ($fila['porcentaje_ica'] ?? 0);  // tarifa ICA por mil
        $fila['retencion_ica']    = round($flete * $pIca / 1000, 2);
        $fila['retencion_fuente'] = round($flete * 0.01, 2);   // 1%
        $fila['fopat']            = round($flete * 0.001, 2);  // 0.1%
        // Municipios desde los terceros (remitente → origen, destinatario → destino).
        $repo = new TerceroRepo();
        if (!empty($fila['remitente_num_id'])) {
            $t = $repo->obtenerPorTipoNum((string) $fila['remitente_tipo_id'], (string) $fila['remitente_num_id']);
            $fila['municipio_origen'] = $t['cod_municipio'] ?? $fila['municipio_origen'] ?? null;
        }
        if (!empty($fila['destinatario_num_id'])) {
            $t = $repo->obtenerPorTipoNum((string) $fila['destinatario_tipo_id'], (string) $fila['destinatario_num_id']);
            $fila['municipio_destino'] = $t['cod_municipio'] ?? $fila['municipio_destino'] ?? null;
        }
        return $fila;
    }

    public function crear(array $datos): int
    {
        $fila = $this->prepararFila($datos);
        $fila['cantidad_vehiculos_original'] = (int) ($fila['cantidad_vehiculos'] ?? 1);

        $pdo = db();
        $pdo->beginTransaction();
        try {
            $cols = implode(', ', array_keys($fila));
            $ph   = implode(', ', array_map(static fn ($c) => ":$c", array_keys($fila)));
            $stmt = $pdo->prepare("INSERT INTO solicitud_servicio ($cols) VALUES ($ph)");
            $stmt->execute($fila);
            $id = (int) $pdo->lastInsertId();

            // Auto-consecutivo: usar el id si el usuario no digita uno.
            if (empty($fila['consecutivo'])) {
                $pdo->prepare('UPDATE solicitud_servicio SET consecutivo = ? WHERE id = ?')
                    ->execute([(string) $id, $id]);
            }

            $this->sembrarRemesa($pdo, $id, $fila);
            $this->sembrarManifiesto($pdo, $id, $fila);

            $pdo->commit();
            return $id;
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Actualiza la solicitud y re-siembra su remesa + manifiesto.
     * Solo debe usarse mientras la solicitud no esté 'despachada'.
     *
     * @param array<string,mixed> $datos
     */
    public function actualizar(int $id, array $datos): void
    {
        $fila = $this->prepararFila($datos);
        $pdo = db();
        $pdo->beginTransaction();
        try {
            $sets = implode(', ', array_map(static fn ($c) => "$c = :$c", array_keys($fila)));
            $params = $fila;
            $params['id'] = $id;
            $pdo->prepare("UPDATE solicitud_servicio SET $sets WHERE id = :id")->execute($params);

            // Pre-despacho: se regeneran remesa y manifiesto desde la solicitud.
            $this->sembrarRemesa($pdo, $id, $fila);
            $this->sembrarManifiesto($pdo, $id, $fila);

            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Confirma el despacho de una solicitud: guarda los datos diferidos
     * (vehículo, conductor, propietario de carga, citas, responsables de pago),
     * re-siembra remesa + manifiesto ya completos, marca la solicitud como
     * 'procesada' y encola los documentos para su envío al RNDC.
     *
     * @param array<string,mixed> $datos
     */
    public function confirmarDespacho(int $id, array $datos): void
    {
        $pdo = db();
        $pdo->beginTransaction();
        try {
            // 1) Guardar los datos del despacho en la solicitud.
            $fila = [];
            foreach (self::CAMPOS_DESPACHO as $c) {
                $valor = $datos[$c] ?? null;
                $fila[$c] = ($valor === '' ? null : $valor);
            }

            // Decrementar vehículos restantes, marcar despachada si llega a 0.
            $stmt = $pdo->prepare('SELECT cantidad_vehiculos FROM solicitud_servicio WHERE id = ?');
            $stmt->execute([$id]);
            $restantes = (int) ($stmt->fetchColumn() ?: 1);
            $nuevosRestantes = max(0, $restantes - 1);
            $fila['cantidad_vehiculos'] = $nuevosRestantes;
            $fila['estado'] = $nuevosRestantes > 0 ? 'procesada' : 'despachada';

            $sets = implode(', ', array_map(static fn ($c) => "$c = :$c", array_keys($fila)));
            $fila['id'] = $id;
            $pdo->prepare("UPDATE solicitud_servicio SET $sets WHERE id = :id")->execute($fila);

            // 2) Leer solicitud completa y sembrar nueva remesa + manifiesto.
            $stmt = $pdo->prepare('SELECT * FROM solicitud_servicio WHERE id = ?');
            $stmt->execute([$id]);
            $s = $stmt->fetch();
            $s['valor_anticipo'] = $datos['valor_anticipo'] ?? null;
            $s['cantidad_vehiculos'] = $nuevosRestantes;

            $this->sembrarRemesa($pdo, $id, $s);
            $this->sembrarManifiesto($pdo, $id, $s);

            // 3) Auto-incrementar consecutivos de la empresa.
            (new EmpresaRepo())->siguienteRemesa();
            (new EmpresaRepo())->siguienteManifiesto();

            // 4) Encolar tercero(11) → vehículo(12) → remesa(3) → manifiesto(4).
            (new ColaRepo())->encolar($pdo, $id);

            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /** @param array<string,mixed> $s */
    private function sembrarRemesa(PDO $pdo, int $solicitudId, array $s): void
    {
        $remesa = [
            'solicitud_id'         => $solicitudId,
            'num_remesa'           => $s['consecutivo'] ?? null,
            'operacion_transporte' => $s['operacion_transporte'] ?? null,
            'naturaleza_carga'     => $s['naturaleza_carga'] ?? null,
            'tipo_empaque'         => $s['tipo_empaque'] ?? null,
            'mercancia_codigo'     => $s['mercancia_codigo'] ?? null,
            'descripcion_producto' => $s['descripcion_producto'] ?? null,
            'cantidad_cargada'     => $s['cantidad_vehiculos'] ?? 1,
            'unidad_medida'        => $s['unidad_medida'] ?? null,
            'peso'                 => $s['peso'] ?? null,
            'remitente_tipo_id'    => $s['remitente_tipo_id'] ?? null,
            'remitente_num_id'     => $s['remitente_num_id'] ?? null,
            'destinatario_tipo_id' => $s['destinatario_tipo_id'] ?? null,
            'destinatario_num_id'  => $s['destinatario_num_id'] ?? null,
            'municipio_cargue'     => $s['municipio_origen'] ?? null,
            'municipio_descargue'  => $s['municipio_destino'] ?? null,
            // Datos completados al confirmar el despacho (Fase 4):
            'propietario_tipo_id'    => $s['generador_tipo_id'] ?? null,
            'propietario_num_id'     => $s['generador_num_id'] ?? null,
            'fecha_cita_cargue'      => $s['fecha_cita_cargue'] ?? null,
            'hora_cita_cargue'       => $s['hora_cita_cargue'] ?? null,
            'fecha_cita_descargue'   => $s['fecha_cita_descargue'] ?? null,
            'hora_cita_descargue'    => $s['hora_cita_descargue'] ?? null,
            'horas_pacto_cargue'     => $s['horas_pacto_cargue'] ?? null,
            'minutos_pacto_cargue'   => $s['minutos_pacto_cargue'] ?? null,
            'horas_pacto_descargue'  => $s['horas_pacto_descargue'] ?? null,
            'minutos_pacto_descargue' => $s['minutos_pacto_descargue'] ?? null,
            'dueno_poliza'           => $s['dueno_poliza'] ?? 'N',
        ];
        $this->insertar($pdo, 'remesa', $remesa);
    }

    /** @param array<string,mixed> $s */
    private function sembrarManifiesto(PDO $pdo, int $solicitudId, array $s): void
    {
        $empresa = (new EmpresaRepo())->obtener();
        $poliza = $empresa['nro_poliza'] ?? null;
        $manifiesto = [
            'solicitud_id'         => $solicitudId,
            'num_manifiesto'       => $s['consecutivo'] ?? null,
            'fecha_expedicion'     => $s['fecha_solicitud'] ?? null,
            'operacion_transporte' => $s['operacion_transporte'] ?? null,
            'municipio_origen'     => $s['municipio_origen'] ?? null,
            'municipio_destino'    => $s['municipio_destino'] ?? null,
            'titular_tipo_id'      => self::tenedorCampo($pdo, $s['placa_vehiculo'] ?? '', 'tenedor_tipo_id'),
            'titular_num_id'       => self::tenedorCampo($pdo, $s['placa_vehiculo'] ?? '', 'tenedor_num_id'),
            'valor_flete_pactado'  => $s['valor_flete'] ?? null,
            'valor_anticipo'       => $s['valor_anticipo'] ?? null,
            'retencion_ica'        => $s['retencion_ica'] ?? null,
            'retencion_fuente'     => $s['retencion_fuente'] ?? null,
            'fopat'                => $s['fopat'] ?? null,
            'tipo_valor_pactado'   => $s['tipo_valor_pactado'] ?? null,
            'municipio_pago_saldo' => $s['municipio_pago_saldo'] ?? null,
            'fecha_pago_saldo'     => $s['fecha_pago_saldo'] ?? null,
            'nro_poliza'           => $poliza,
            'emf'                  => $s['emf'] ?? $empresa['emf'] ?? null,
            // Datos completados al confirmar el despacho (Fase 4):
            'placa_vehiculo'           => $s['placa_vehiculo'] ?? null,
            'conductor_tipo_id'        => $s['conductor_tipo_id'] ?? null,
            'conductor_num_id'         => $s['conductor_num_id'] ?? null,
            'responsable_pago_cargue'  => $s['responsable_pago_cargue'] ?? null,
            'responsable_pago_descargue' => $s['responsable_pago_descargue'] ?? null,
        ];
        $this->insertar($pdo, 'manifiesto', $manifiesto);
    }

    /** @param array<string,mixed> $fila */
    private function insertar(PDO $pdo, string $tabla, array $fila): void
    {
        $cols = implode(', ', array_keys($fila));
        $ph   = implode(', ', array_map(static fn ($c) => ":$c", array_keys($fila)));
        $pdo->prepare("INSERT INTO `$tabla` ($cols) VALUES ($ph)")->execute($fila);
    }

    /** @return list<array<string,mixed>> */
    public function listar(int $limite = 100, ?string $desde = null, ?string $hasta = null): array
    {
        $sql = 'SELECT s.id, s.consecutivo, s.fecha_solicitud,
                       s.municipio_origen, s.municipio_destino,
                       s.valor_flete, s.placa_vehiculo, s.estado,
                       s.cantidad_vehiculos, s.cantidad_vehiculos_original,
                       s.generador_tipo_id, s.generador_num_id,
                       r.nombre AS remitente_nombre,
                       d.nombre AS destinatario_nombre,
                       g.nombre AS generador_nombre,
                       om.nombre_completo AS origen_nombre,
                       dm.nombre_completo AS destino_nombre
                FROM solicitud_servicio s
                LEFT JOIN tercero r ON r.tipo_id = s.remitente_tipo_id AND r.num_id = s.remitente_num_id
                LEFT JOIN tercero d ON d.tipo_id = s.destinatario_tipo_id AND d.num_id = s.destinatario_num_id
                LEFT JOIN tercero g ON g.tipo_id = s.generador_tipo_id AND g.num_id = s.generador_num_id
                LEFT JOIN municipio om ON om.codigo_rndc = s.municipio_origen
                LEFT JOIN municipio dm ON dm.codigo_rndc = s.municipio_destino
                WHERE 1=1';
        $params = [];
        if ($desde !== null) {
            $sql .= ' AND s.fecha_solicitud >= ?';
            $params[] = $desde;
        }
        if ($hasta !== null) {
            $sql .= ' AND s.fecha_solicitud <= ?';
            $params[] = $hasta;
        }
        $sql .= ' ORDER BY s.id DESC LIMIT ' . (int) $limite;
        $stmt = db()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /** @return array<string,mixed>|null */
    public function obtener(int $id): ?array
    {
        $stmt = db()->prepare('SELECT * FROM solicitud_servicio WHERE id = ?');
        $stmt->execute([$id]);
        $solicitud = $stmt->fetch();
        if (!$solicitud) {
            return null;
        }

        $m = db()->prepare('SELECT * FROM manifiesto WHERE solicitud_id = ?');
        $m->execute([$id]);
        $r = db()->prepare('SELECT * FROM remesa WHERE solicitud_id = ?');
        $r->execute([$id]);

        return [
            'solicitud'  => $solicitud,
            'manifiesto' => $m->fetch() ?: null,
            'remesa'     => $r->fetch() ?: null,
        ];
    }

    private static function tenedorCampo(PDO $pdo, string $placa, string $col): ?string
    {
        if ($placa === '') { return null; }
        $q = $pdo->prepare("SELECT $col FROM vehiculo WHERE placa = ?");
        $q->execute([strtoupper($placa)]);
        $v = $q->fetch(PDO::FETCH_COLUMN);
        return $v !== false ? $v : null;
    }
}
