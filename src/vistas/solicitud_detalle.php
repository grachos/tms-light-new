<?php
/**
 * Vista: detalle de una Solicitud con su Manifiesto y Remesa sembrados.
 * @var array<string,mixed>      $solicitud
 * @var array<string,mixed>|null $manifiesto
 * @var array<string,mixed>|null $remesa
 */
declare(strict_types=1);

/** Imprime una tabla clave/valor con formateo opcional. */
if (!function_exists('fichaCampos')) {
    function fichaCampos(?array $fila, array $campos, array $format = []): void
    {
        if ($fila === null) {
            echo '<p class="ayuda">No generado.</p>';
            return;
        }
        echo '<dl class="ficha">';
        foreach ($campos as $col => $etq) {
            $val = $fila[$col] ?? null;
            if (isset($format[$col])) {
                $mostrar = $format[$col]($val, $fila);
            } else {
                $mostrar = $val === null || $val === '' ? '—' : e((string) $val);
            }
            echo '<dt>' . e($etq) . '</dt><dd>' . $mostrar . '</dd>';
        }
        echo '</dl>';
    }
}

$__muni = new MunicipioRepo();
$__terc = new TerceroRepo();

$__fmtMuni = static function (?string $cod) use ($__muni): string {
    if (!$cod) { return '—'; }
    $nom = $__muni->nombre($cod);
    return e($cod . ($nom ? ' - ' . $nom : ''));
};

$__fmtTerc = static function (?string $num, ?array $fila, string $tipoCol) use ($__terc): string {
    if (!$num) { return '—'; }
    $tipo = $fila[$tipoCol] ?? null;
    if (!$tipo) { return e($num); }
    $t = $__terc->obtenerPorTipoNum($tipo, $num);
    $base = $tipo . ' ' . $num;
    if ($t) {
        $nom = $t['nombre'] ?? '';
        $ape1 = $t['primer_apellido'] ?? '';
        $ape2 = $t['segundo_apellido'] ?? '';
        $nomCompleto = trim($nom . ' ' . $ape1 . ' ' . $ape2) ?: $t['nombre_completo'] ?? '';
        return e($base . ' - ' . $nomCompleto);
    }
    return e($base);
};

$__fmtTercCol = function (string $tipoCol) use ($__fmtTerc): callable {
    return static function ($v, $f) use ($__fmtTerc, $tipoCol): string {
        return ($__fmtTerc)($v, $f, $tipoCol);
    };
};

$__fmtCita = static function (?string $fecha, ?array $fila, string $horaCol): string {
    if (!$fecha) { return '—'; }
    $hora = $fila[$horaCol] ?? null;
    return e($fecha . ($hora ? ' ' . substr($hora, 0, 5) : ''));
};

$__operaciones = ['G' => 'General', 'P' => 'Paqueteo', 'C' => 'Contenedor Cargado', 'V' => 'Contenedor Vacío'];

$__naturalezas = [
    '1' => 'Carga normal', '2' => 'Carga peligrosa', '3' => 'Carga extradimensionada',
    '4' => 'Carga extrapesada', '5' => 'Desechos peligrosos', '6' => 'Semovientes', '7' => 'Refrigerada',
];

$__cat = new CatalogoRepo();

$__fmtOp = static fn (?string $v) => e($v ? ($__operaciones[$v] ?? $v) : '—');

$__fmtNatu = static fn (?string $v) => e($v ? ($__naturalezas[$v] ?? $v) : '—');

$__fmtEmpaque = static function (?string $v) use ($__cat): string {
    if (!$v) { return '—'; }
    $desc = $__cat->empaquePorCodigo($v);
    return e($v . ($desc ? ' - ' . $desc : ''));
};

$__fmtProducto = static function (?string $v) use ($__cat): string {
    if (!$v) { return '—'; }
    $p = $__cat->productoPorCodigo($v);
    return e($v . ($p ? ' - ' . ($p['nombre'] ?? '') : ''));
};
?>
<div class="cabecera-lista">
    <h1>Solicitud #<?= (int) $solicitud['id'] ?>
        <span class="chip chip--<?= e($solicitud['estado']) ?>"><?= e($solicitud['estado']) ?></span>
    </h1>
    <a href="<?= e(ruta('solicitudes')) ?>" class="btn">← Volver</a>
</div>

<?php flash(); ?>

<section class="tarjeta">
    <h2>Datos de la solicitud</h2>
    <?php fichaCampos($solicitud, [
        'consecutivo'          => 'Consecutivo',
        'fecha_solicitud'      => 'Fecha',
        'operacion_transporte' => 'Operación',
        'municipio_origen'     => 'Municipio origen',
        'municipio_destino'    => 'Municipio destino',
        'remitente_num_id'     => 'Remitente',
        'destinatario_num_id'  => 'Destinatario',
        'generador_num_id'     => 'Generador de carga',
        'descripcion_producto' => 'Producto',
        'cantidad_vehiculos' => 'Vehículos',
        'peso'                 => 'Peso (kg)',
        'valor_flete'          => 'Flete',
        'observaciones'        => 'Observaciones',
    ], [
        'operacion_transporte' => $__fmtOp,
        'municipio_origen'  => $__fmtMuni,
        'municipio_destino' => $__fmtMuni,
        'remitente_num_id'  => $__fmtTercCol('remitente_tipo_id'),
        'destinatario_num_id' => $__fmtTercCol('destinatario_tipo_id'),
        'generador_num_id'  => $__fmtTercCol('generador_tipo_id'),
    ]); ?>
</section>

<div class="dos-columnas">
    <section class="tarjeta">
        <h2>Remesa <span class="chip chip--rndc"><?= e($remesa['estado_rndc'] ?? '—') ?></span></h2>
        <?php fichaCampos($remesa, [
            'num_remesa'           => 'Consecutivo remesa',
            'naturaleza_carga'     => 'Naturaleza carga',
            'tipo_empaque'         => 'Tipo empaque',
            'mercancia_codigo'     => 'Cód. mercancía',
            'descripcion_producto' => 'Producto',
            'cantidad_cargada'     => 'Cantidad cargada',
            'municipio_cargue'     => 'Mun. cargue',
            'municipio_descargue'  => 'Mun. descargue',
            'remitente_num_id'     => 'Remitente',
            'destinatario_num_id'  => 'Destinatario',
            'propietario_num_id'   => 'Propietario carga',
            'fecha_cita_cargue'    => 'Cita cargue',
            'fecha_cita_descargue' => 'Cita descargue',
            'rndc_ingreso_id'      => 'Ingreso RNDC',
        ], [
            'naturaleza_carga'    => $__fmtNatu,
            'tipo_empaque'        => $__fmtEmpaque,
            'mercancia_codigo'    => $__fmtProducto,
            'municipio_cargue'    => $__fmtMuni,
            'municipio_descargue' => $__fmtMuni,
            'remitente_num_id'    => $__fmtTercCol('remitente_tipo_id'),
            'destinatario_num_id' => $__fmtTercCol('destinatario_tipo_id'),
            'propietario_num_id'  => $__fmtTercCol('propietario_tipo_id'),
            'fecha_cita_cargue'   => static fn ($v, $f) => ($__fmtCita)($v, $f, 'hora_cita_cargue'),
            'fecha_cita_descargue' => static fn ($v, $f) => ($__fmtCita)($v, $f, 'hora_cita_descargue'),
        ]); ?>
    </section>

    <section class="tarjeta">
        <h2>Manifiesto <span class="chip chip--rndc"><?= e($manifiesto['estado_rndc'] ?? '—') ?></span></h2>
        <?php fichaCampos($manifiesto, [
            'num_manifiesto'        => 'Consecutivo manifiesto',
            'fecha_expedicion'      => 'Fecha expedición',
            'municipio_origen'      => 'Origen',
            'municipio_destino'     => 'Destino',
            'titular_num_id'        => 'Tenedor (vehículo)',
            'placa_vehiculo'        => 'Placa',
            'conductor_num_id'      => 'Conductor',
            'valor_flete_pactado'   => 'Flete pactado',
            'valor_anticipo'        => 'Anticipo',
            'retencion_ica'         => 'Retención ICA',
            'municipio_pago_saldo'  => 'Mun. pago saldo',
            'fecha_pago_saldo'      => 'Fecha pago saldo',
            'nro_poliza'            => 'Nro. póliza',
            'emf'                   => 'EMF (Monitoreo Flota)',
            'rndc_ingreso_id'       => 'Ingreso RNDC',
        ], [
            'municipio_origen'     => $__fmtMuni,
            'municipio_destino'    => $__fmtMuni,
            'municipio_pago_saldo' => $__fmtMuni,
            'titular_num_id'       => $__fmtTercCol('titular_tipo_id'),
            'conductor_num_id'     => $__fmtTercCol('conductor_tipo_id'),
        ]); ?>
    </section>
</div>

<div class="acciones">
    <?php if (($solicitud['estado'] ?? '') === 'borrador'): ?>
        <a href="<?= e(ruta('despacho.confirmar', ['id' => (int) $solicitud['id']])) ?>" class="btn btn--primario">Confirmar despacho</a>
        <a href="<?= e(ruta('solicitud.editar', ['id' => (int) $solicitud['id']])) ?>" class="btn">Editar</a>
    <?php elseif (($solicitud['estado'] ?? '') === 'procesada'): ?>
        <span class="chip chip--procesada">En cola de envío</span>
        <a href="<?= e(ruta('cola')) ?>" class="btn btn--primario">Ver cola RNDC</a>
        <a href="<?= e(ruta('despacho.confirmar', ['id' => (int) $solicitud['id']])) ?>" class="btn">Reeditar despacho</a>
    <?php elseif (($solicitud['estado'] ?? '') === 'despachada'): ?>
        <span class="chip chip--despachada">Despachada al RNDC</span>
        <a href="<?= e(ruta('cola')) ?>" class="btn">Ver cola RNDC</a>
    <?php endif; ?>
</div>
