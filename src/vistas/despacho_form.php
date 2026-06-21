<?php
/**
 * Vista: confirmar el despacho de una Solicitud.
 * Completa los datos diferidos (vehículo, conductor, propietario de carga,
 * citas/tiempos de cargue-descargue, responsables de pago) y encola los
 * documentos para su envío al RNDC.
 *
 * @var array<string,mixed> $solicitud
 */
declare(strict_types=1);

$s = $solicitud;
$v = static fn (string $c): string => e((string) ($s[$c] ?? ''));

$responsables = ['E' => 'Empresa de transporte', 'R' => 'Remitente', 'D' => 'Destinatario'];

if (!function_exists('selOpc')) {
    function selOpc(string $name, array $opciones, string $sel, bool $conVacio = true): string
    {
        $h = '<select name="' . e($name) . '">';
        if ($conVacio) { $h .= '<option value="">—</option>'; }
        foreach ($opciones as $val => $t) {
            $h .= '<option value="' . e((string) $val) . '"' . ((string) $val === $sel ? ' selected' : '') . '>' . e($t) . '</option>';
        }
        return $h . '</select>';
    }
}
/** Picker de vehículo (placa). */
$acVehiculo = static function (string $name, string $val): string {
    return '<div class="autocompletar" data-ac="vehiculos">'
        . '<input type="text" class="ac-texto" autocomplete="off" placeholder="Buscar placa…" value="' . e($val) . '">'
        . '<ul class="ac-lista"></ul>'
        . '<input type="hidden" name="' . e($name) . '" data-ac-field="placa" value="' . e($val) . '">'
        . '</div>';
};
?>
<div class="cabecera-lista">
    <h1>Confirmar despacho · Solicitud #<?= (int) $s['id'] ?></h1>
    <a href="<?= e(ruta('solicitud.ver', ['id' => (int) $s['id']])) ?>" class="btn">← Volver</a>
</div>

<p class="ayuda">Al confirmar se completan el manifiesto y la remesa, y se <strong>encolan</strong>
   para enviarse al RNDC (tercero → vehículo → remesa → manifiesto).</p>

<p class="ayuda"><strong>Vehículos restantes:</strong> <?= (int) ($s['cantidad_vehiculos'] ?? 1) ?></p>

<?php flash(); ?>

<form method="post" action="<?= e(ruta('despacho.guardar', ['id' => (int) $s['id']])) ?>" class="form">

    <fieldset>
        <legend>Vehículo y conductor</legend>
        <div class="grid">
            <label>Vehículo (placa) <?= $acVehiculo('placa_vehiculo', (string) ($s['placa_vehiculo'] ?? '')) ?></label>
            <label>Conductor
                <input type="hidden" name="conductor_tipo_id" id="conductor_tipo_id" value="<?= $v('conductor_tipo_id') ?>">
                <input type="hidden" name="conductor_num_id" id="conductor_num_id" value="<?= $v('conductor_num_id') ?>">
                <span id="conductor_label" class="campo-lectura"><?php
                    $ct = $s['conductor_tipo_id'] ?? '';
                    $cn = $s['conductor_num_id'] ?? '';
                    echo e($ct && $cn ? trim("$ct $cn") : '(seleccione placa)');
                ?></span>
            </label>
            <label>Tenedor
                <span id="tenedor_label" class="campo-lectura"><?php
                    $tt = $s['vehiculo_tenedor_tipo_id'] ?? '';
                    $tn = $s['vehiculo_tenedor_num_id'] ?? '';
                    echo e($tt && $tn ? trim($tt . ' ' . $tn) : '(seleccione placa)');
                ?></span>
            </label>
        </div>
        <p class="ayuda">El remolque se hereda del maestro de vehículos. Conductor y tenedor se cargan automáticamente desde el vehículo.</p>
    </fieldset>

    <fieldset>
        <legend>Cargue / Descargue / Valores</legend>
        <div class="grid">
            <label>Fecha cita cargue <input type="date" name="fecha_cita_cargue" value="<?= $v('fecha_cita_cargue') ?>"></label>
            <label>Hora cita cargue <input type="time" name="hora_cita_cargue" value="<?= $v('hora_cita_cargue') ?>"></label>
            <label>Tiempo pactado cargue (horas) <input type="number" min="0" name="horas_pacto_cargue" value="<?= $v('horas_pacto_cargue') ?>"></label>
            <label>Minutos pactado cargue <input type="number" min="0" max="59" name="minutos_pacto_cargue" value="<?= $v('minutos_pacto_cargue') ?>"></label>
            <label>Fecha cita descargue <input type="date" name="fecha_cita_descargue" value="<?= $v('fecha_cita_descargue') ?>"></label>
            <label>Hora cita descargue <input type="time" name="hora_cita_descargue" value="<?= $v('hora_cita_descargue') ?>"></label>
            <label>Tiempo pactado descargue (horas) <input type="number" min="0" name="horas_pacto_descargue" value="<?= $v('horas_pacto_descargue') ?>"></label>
            <label>Minutos pactado descargue <input type="number" min="0" max="59" name="minutos_pacto_descargue" value="<?= $v('minutos_pacto_descargue') ?>"></label>
            <label>Responsable pago cargue <?= selOpc('responsable_pago_cargue', $responsables, (string) ($s['responsable_pago_cargue'] ?? 'E')) ?></label>
            <label>Responsable pago descargue <?= selOpc('responsable_pago_descargue', $responsables, (string) ($s['responsable_pago_descargue'] ?? 'E')) ?></label>
            <label>Valor del anticipo <input type="number" step="0.01" name="valor_anticipo" value="<?= $v('valor_anticipo') ?>"></label>
            <label>NIT EMF (Monitoreo Flota) <input type="text" name="emf" maxlength="20" value="<?= $v('emf') ?>"></label>
        </div>
    </fieldset>

    <div class="acciones">
        <button type="submit" class="btn btn--primario">Confirmar despacho y encolar</button>
        <a href="<?= e(ruta('solicitud.ver', ['id' => (int) $s['id']])) ?>" class="btn">Cancelar</a>
    </div>
</form>
