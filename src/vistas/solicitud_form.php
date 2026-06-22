<?php
/**
 * Vista: Solicitud de Servicio (crear o editar).
 * @var array<string,mixed> $solicitud (vacío al crear)
 * @var string $accion
 */
declare(strict_types=1);

$s      = $solicitud ?? [];
$accion = $accion ?? ruta('solicitud.crear');
$editar = !empty($s);
$muni   = new MunicipioRepo();
$cat    = new CatalogoRepo();
$empaques = $cat->empaques();
$prodInfo = $editar && !empty($s['mercancia_codigo']) ? $cat->productoPorCodigo($s['mercancia_codigo']) : null;

$v   = static fn (string $c): string => e((string) ($GLOBALS['s'][$c] ?? ''));
/** valor guardado o, si no hay, el default (para selects). */
$cur = static fn (string $c, string $def): string => (string) ($GLOBALS['s'][$c] ?? $def);

$operaciones  = ['G' => 'General', 'P' => 'Paqueteo', 'C' => 'Contenedor Cargado', 'V' => 'Contenedor Vacío'];
$naturalezas  = [
    '1' => 'Carga normal', '2' => 'Carga peligrosa', '3' => 'Carga extradimensionada',
    '4' => 'Carga extrapesada', '5' => 'Desechos peligrosos', '6' => 'Semovientes', '7' => 'Refrigerada',
];
$unidades     = ['1' => 'Kilogramos', '2' => 'Galones'];
$tiposFlete   = [
    'G' => 'General', 'M' => 'Multiparada', 'W' => 'Viaje Vacío', 'D' => 'Varios Viajes en el Día',
    'I' => 'Viaje de Ida y Regreso', 'U' => 'Viaje Municipal o Urbano', 'V' => 'Varios Viajes Urbanos en el día',
];
$tiposPactado = ['V' => 'Por Viaje', 'K' => 'Por Kilogramo', 'G' => 'Por Galón'];

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
if (!function_exists('acTerceroP')) {
    /** Picker de tercero con precarga (tipo/num + texto visible). */
    function acTerceroP(array $s, string $tipoName, string $numName, string $ph = 'Buscar tercero…', string $params = '', string $muniTarget = ''): string
    {
        $tipo = (string) ($s[$tipoName] ?? '');
        $num  = (string) ($s[$numName] ?? '');
        $txt  = $num !== '' ? trim($tipo . ' ' . $num) : '';
        $p    = $params !== '' ? ' data-ac-params="' . e($params) . '"' : '';
        $mt   = $muniTarget !== '' ? ' data-muni-target="' . e($muniTarget) . '"' : '';
        return '<div class="autocompletar" data-ac="terceros"' . $p . $mt . '>'
            . '<input type="text" class="ac-texto" autocomplete="off" placeholder="' . e($ph) . '" value="' . e($txt) . '">'
            . '<ul class="ac-lista"></ul>'
            . '<input type="hidden" name="' . e($tipoName) . '" data-ac-field="tipo_id" value="' . e($tipo) . '">'
            . '<input type="hidden" name="' . e($numName) . '" data-ac-field="num_id" value="' . e($num) . '">'
            . '</div>';
    }
}
if (!function_exists('acMunicipioP')) {
    /** Picker de municipio con precarga (código + nombre). */
    function acMunicipioP(array $s, MunicipioRepo $muni, string $name, string $ph = 'Escribe y elige…'): string
    {
        $code = (string) ($s[$name] ?? '');
        $nom  = $code !== '' ? (string) ($muni->nombre($code) ?? '') : '';
        return '<div class="autocompletar" data-ac="municipios">'
            . '<input type="text" class="ac-texto" autocomplete="off" placeholder="' . e($ph) . '" value="' . e($nom) . '">'
            . '<ul class="ac-lista"></ul>'
            . '<input type="hidden" name="' . e($name) . '" data-ac-field="codigo_rndc" value="' . e($code) . '">'
            . '</div>';
    }
}
?>
<h1><?= $editar ? 'Editar' : 'Nueva' ?> Solicitud de Servicio</h1>
<p class="ayuda">Genera el <strong>Manifiesto</strong> y la <strong>Remesa</strong>.
   El vehículo, conductor y cargue/descargue se completan al confirmar el despacho.</p>

<?php flash(); ?>

<form method="post" action="<?= e($accion) ?>" class="form">

    <fieldset>
        <legend>1. Generales</legend>
        <div class="grid">
            <label>Consecutivo <input type="text" name="consecutivo" readonly value="<?= $v('consecutivo') ?: '(auto)' ?>"><small>Se genera automáticamente</small></label>
            <label>Fecha <input type="date" name="fecha_solicitud" value="<?= $editar ? $v('fecha_solicitud') : e(date('Y-m-d')) ?>"></label>
            <label>Operación de transporte <?= selOpc('operacion_transporte', $operaciones, $cur('operacion_transporte', 'G')) ?></label>
            <label>Tipo de viaje <?= selOpc('tipo_viaje', ['NACIONAL' => 'Nacional', 'URBANO' => 'Urbano'], $cur('tipo_viaje', 'NACIONAL'), false) ?></label>
            <label class="ancho-total">Observaciones <textarea name="observaciones" rows="2" maxlength="200"><?= $v('observaciones') ?></textarea></label>
        </div>
    </fieldset>

    <fieldset>
        <legend>2. Partes / Ruta</legend>
        <div class="grid">
            <label class="ancho-total">Remitente <?= acTerceroP($s, 'remitente_tipo_id', 'remitente_num_id', 'Buscar remitente…', '', 'muni_remitente') ?>
                <small>Municipio: <span id="muni_remitente" class="muni-label"><?= $v('municipio_nombre_origen') ?: '(seleccione remitente)' ?></span></small>
            </label>
            <label class="ancho-total">Destinatario <?= acTerceroP($s, 'destinatario_tipo_id', 'destinatario_num_id', 'Buscar destinatario…', '', 'muni_destinatario') ?>
                <small>Municipio: <span id="muni_destinatario" class="muni-label"><?= $v('municipio_nombre_destino') ?: '(seleccione destinatario)' ?></span></small>
            </label>
            <label class="ancho-total">Generador de carga <?= acTerceroP($s, 'generador_tipo_id', 'generador_num_id') ?></label>
            <label>¿Dueño póliza? <?= selOpc('dueno_poliza', ['N' => 'No', 'S' => 'Sí'], $cur('dueno_poliza', 'N'), false) ?></label>
            <label class="ancho-total">Municipio pago del saldo <?= acMunicipioP($s, $muni, 'municipio_pago_saldo') ?></label>
        </div>
        <p class="ayuda">Origen y destino se heredan del municipio del remitente/destinatario. ¿Falta alguien? Créalo en <a href="<?= e(ruta('terceros')) ?>" target="_blank">Terceros</a>.</p>
    </fieldset>

    <fieldset>
        <legend>4. Carga</legend>
        <div class="grid">
            <label>Naturaleza de la carga <?= selOpc('naturaleza_carga', $naturalezas, $cur('naturaleza_carga', '1')) ?></label>
            <label>Tipo de empaque
                <select name="tipo_empaque">
                    <option value="">—</option>
                    <?php foreach ($empaques as $emp): ?>
                        <option value="<?= e($emp['codigo']) ?>" <?= $emp['codigo'] === ($s['tipo_empaque'] ?? '') ? 'selected' : '' ?>><?= e($emp['codigo'] . ' - ' . $emp['descripcion']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="ancho-total">Producto / mercancía (catálogo o código libre)
                <div class="autocompletar" data-ac="productos">
                    <input type="text" class="ac-texto" autocomplete="off" placeholder="Buscar producto…">
                    <ul class="ac-lista"></ul>
                    <input type="text" name="mercancia_codigo" data-ac-field="codigo" maxlength="10" placeholder="Código" value="<?= $v('mercancia_codigo') ?>">
                    <input type="hidden" data-ac-field="tipo">
                    <input type="hidden" data-ac-field="clase_division">
                    <input type="hidden" data-ac-field="peligro_secundario">
                    <input type="hidden" data-ac-field="grupo_embalaje">
                    <input type="hidden" data-ac-field="peligrosa">
                    <input type="hidden" data-ac-field="codigo_un">
                    <input type="hidden" data-ac-field="estado_producto">
                </div>
                <div id="producto-info" class="producto-info<?= $prodInfo ? '' : ' oculto' ?>">
                    <?php if ($prodInfo): ?>
                        <span class="prod-badge prod-tipo-<?= e(strtolower($prodInfo['tipo'] ?? '')) ?>"><?= e($prodInfo['tipo'] ?? '') ?></span>
                        <?php if ($prodInfo['peligrosa'] === 'SI'): ?>
                            <span class="prod-peligrosa">&#9888; Peligrosa</span>
                        <?php endif; ?>
                        <span class="prod-detalle">Clase: <?= e($prodInfo['clase_division'] ?? '—') ?></span>
                        <span class="prod-detalle">Peligro sec.: <?= e($prodInfo['peligro_secundario'] ?? '—') ?></span>
                        <span class="prod-detalle">Embalaje: <?= e($prodInfo['grupo_embalaje'] ?? '—') ?></span>
                        <span class="prod-detalle">UN: <?= e($prodInfo['codigo_un'] ?? '—') ?></span>
                        <span class="prod-detalle">Estado: <?= e(['L'=>'Líquido','S'=>'Sólido/semi-sólido','G'=>'Gaseoso'][$prodInfo['estado_producto'] ?? ''] ?? '—') ?></span>
                    <?php endif; ?>
                </div>
            </label>
            <label class="ancho-total">Descripción del producto <input type="text" name="descripcion_producto" maxlength="250" value="<?= $v('descripcion_producto') ?>"></label>
            <label>Cantidad vehículos <input type="number" step="1" name="cantidad_vehiculos" value="<?= $v('cantidad_vehiculos') ?>"></label>
            <label>Unidad de medida <?= selOpc('unidad_medida', $unidades, $cur('unidad_medida', '1')) ?></label>
            <label>Peso (kg) <input type="number" step="0.001" name="peso" value="<?= $v('peso') ?>"></label>
            <label>Valor de la mercancía <input type="number" step="0.01" name="valor_mercancia" value="<?= $v('valor_mercancia') ?>"></label>
        </div>
    </fieldset>

    <fieldset>
        <legend>5. Valores y manifiesto</legend>
        <div class="grid">
            <label>Valor del flete <input type="number" step="0.01" name="valor_flete" id="valor_flete" value="<?= $v('valor_flete') ?>"></label>
            <label>Tarifa ICA (por mil) <input type="number" step="0.01" name="porcentaje_ica" id="porcentaje_ica" value="<?= $v('porcentaje_ica') ?>"></label>
            <label>Retención ICA <input type="number" step="0.01" name="retencion_ica" id="retencion_ica" readonly value="<?= $v('retencion_ica') ?>"></label>
            <label>Retención en la fuente (1%) <input type="number" step="0.01" name="retencion_fuente" id="retencion_fuente" readonly value="<?= $v('retencion_fuente') ?>"></label>
            <label>FOPAT (0.1%) <input type="number" step="0.01" name="fopat" id="fopat" readonly value="<?= $v('fopat') ?>"></label>
            <label>Tipo de flete <?= selOpc('tipo_flete', $tiposFlete, $cur('tipo_flete', 'G')) ?></label>
            <label>Tipo de viaje pactado <?= selOpc('tipo_valor_pactado', $tiposPactado, $cur('tipo_valor_pactado', 'V'), false) ?></label>
            <label>Fecha pago del saldo <input type="date" name="fecha_pago_saldo" value="<?= $v('fecha_pago_saldo') ?>"></label>
        </div>
        <p class="ayuda">El NIT de la empresa y la póliza se toman de <a href="<?= e(ruta('empresa')) ?>" target="_blank">Empresa</a>.</p>
    </fieldset>

    <div class="acciones">
        <button type="submit" class="btn btn--primario"><?= $editar ? 'Actualizar' : 'Guardar' ?> solicitud</button>
        <a href="<?= e($editar ? ruta('solicitud.ver', ['id' => (int) $s['id']]) : ruta('solicitudes')) ?>" class="btn">Cancelar</a>
    </div>
</form>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var caja = document.querySelector('[data-ac="productos"]');
    var info = document.getElementById('producto-info');

    if (!caja || !info) { return; }

    caja.addEventListener('ac:select', function (e) {
        var p = e.detail || {};
        var html = '';
        var tipo = (p.tipo || '').trim();
        if (tipo) {
            html += '<span class="prod-badge prod-tipo-' + tipo.toLowerCase() + '">' + tipo + '</span>';
        }
        if (p.peligrosa === 'SI') {
            html += ' <span class="prod-peligrosa">\u26A0 Peligrosa</span>';
        }
        html += ' <span class="prod-detalle">Clase: ' + (p.clase_division || '\u2014') + '</span>';
        html += ' <span class="prod-detalle">Peligro sec.: ' + (p.peligro_secundario || '\u2014') + '</span>';
        html += ' <span class="prod-detalle">Embalaje: ' + (p.grupo_embalaje || '\u2014') + '</span>';
        html += ' <span class="prod-detalle">UN: ' + (p.codigo_un || '\u2014') + '</span>';
        html += ' <span class="prod-detalle">Estado: ' + ({L:'L\u00edquido',S:'S\u00f3lido/semi-s\u00f3lido',G:'Gaseoso'}[p.estado_producto] || '\u2014') + '</span>';
        info.innerHTML = html;
        info.classList.remove('oculto');
    });
});
</script>
