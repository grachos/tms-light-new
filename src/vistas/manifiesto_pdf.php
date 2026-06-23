<?php
/**
 * Genera un PDF imprimible del Manifiesto usando Dompdf.
 *
 * Variables inyectadas desde index.php:
 * @var array<string,mixed>      $remesa, $manifiesto, $solicitud, $vehiculo, $empresa
 * @var MunicipioRepo            $muni
 * @var CatalogoRepo             $cat
 * @var string                   $natuNombre, $tipoManifiesto
 * @var array                    $responsables
 * @var array<string,mixed>|null $titular, $conductor, $remitente, $destinatario, $generador
 * @var string                   $empaqueDesc
 * @var callable                 $nomTerc, $fmtMuni
 */
declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use Dompdf\Dompdf;
use Dompdf\Options;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use chillerlan\QRCode\Output\QRMarkupHTML;

$options = new Options();
$options->set('defaultFont', 'Helvetica');
$options->set('isHtml5ParserEnabled', true);
$options->set('isRemoteEnabled', true);
$dompdf = new Dompdf($options);

$m = $manifiesto;
$r = $remesa;
$s = $solicitud;
$v = $vehiculo;
$e = $empresa;

$configDsc = '';
if (!empty($v['cod_configuracion'])) {
    foreach ($cat->configuraciones() as $cfg) {
        if ($cfg['codigo'] === $v['cod_configuracion']) {
            $configDsc = $cfg['nombre'] . ' - ' . $cfg['descripcion'];
            break;
        }
    }
}

$origen = $fmtMuni($m['municipio_origen'] ?? null);
$destino = $fmtMuni($m['municipio_destino'] ?? null);
$lugarPago = $fmtMuni($m['municipio_pago_saldo'] ?? null);

$reteFuente  = (float) ($m['retencion_fuente'] ?? 0);
$reteIca     = (float) ($m['retencion_ica'] ?? 0);
$fopat       = (float) ($m['fopat'] ?? 0);
$flete       = (float) ($m['valor_flete_pactado'] ?? 0);
$anticipo    = (float) ($m['valor_anticipo'] ?? 0);
$valorNeto   = $flete - $reteFuente - $reteIca - $fopat;
$saldoPagar  = $valorNeto - $anticipo;

$fmtMoney = static fn ($v) => number_format((float) $v, 2, ',', '.');

$autorizacionNum = $m['rndc_ingreso_id'] ?? '';

$qrImageHtml = '';
if ($autorizacionNum) {
    $mec       = $autorizacionNum;
    $fechaQr   = $m['fecha_expedicion'] ? date('Y/m/d', strtotime($m['fecha_expedicion'])) : '';
    $placaQr   = $m['placa_vehiculo'] ?? '';
    $remolqueQr = $v['remolque_placa'] ?? '';
    $configQr  = $v['cod_configuracion'] ?? '';
    $origQr    = mb_substr($origen, 0, 20);
    $destQr    = mb_substr($destino, 0, 20);
    $mercanciaQr = mb_substr($r['descripcion_producto'] ?? '', 0, 30);
    $condQr    = $m['conductor_num_id'] ?? '';
    $empresaQr = mb_substr($e['razon_social'] ?? '', 0, 30);
    $obsQr     = mb_substr($s['observaciones'] ?? '', 0, 120);
    $seguroQr  = $m['seguridadqr'] ?? '';

    $qrText = "MEC:" . $mec . "\r\n";
    $qrText .= "Fecha:" . $fechaQr . "\r\n";
    $qrText .= "Placa:" . $placaQr . "\r\n";
    if (!empty($remolqueQr)) {
        $qrText .= "Remolque:" . $remolqueQr . "\r\n";
    }
    $qrText .= "Config:" . $configQr . "\r\n";
    $qrText .= "Orig:" . $origQr . "\r\n";
    $qrText .= "Dest:" . $destQr . "\r\n";
    $qrText .= "Mercancia:" . $mercanciaQr . "\r\n";
    $qrText .= "Conductor:" . $condQr . "\r\n";
    $qrText .= "Empresa:" . $empresaQr . "\r\n";
    if (!empty(trim($obsQr))) {
        $qrText .= "Obs:" . $obsQr . "\r\n";
    }
    $qrText .= "Seguro:" . $seguroQr;

$qrOptions = new QROptions;
$qrOptions->outputInterface = QRMarkupHTML::class;
$qrOptions->scale = 10;
$qrCode = new QRCode($qrOptions);
$qrCode->addSegment(new \chillerlan\QRCode\Data\Byte($qrText));
$qrMatrix = $qrCode->getQRMatrix();
$rows = $qrMatrix->getMatrix();
$size   = count($rows);
$cellMm = 30 / $size;
$dark  = '#000000';
$light = '#ffffff';
$isDark = \chillerlan\QRCode\Data\QRMatrix::IS_DARK;
$qrImageHtml = '<div style="padding:2mm;display:inline-block;">
    <table style="border-collapse:collapse;" cellspacing="0" cellpadding="0">';
foreach ($rows as $row) {
    $qrImageHtml .= '<tr>';
    foreach ($row as $cell) {
        $qrImageHtml .= '<td style="width:' . $cellMm . 'mm;height:' . $cellMm . 'mm;background:' . (($cell & $isDark) ? $dark : $light) . ';padding:0;font-size:0;line-height:0;"></td>';
    }
    $qrImageHtml .= '</tr>';
}
$qrImageHtml .= '</table></div>';
}

$html = '<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <style>
        body { font-family: Helvetica, Arial, sans-serif; font-size: 9px; color: #111; line-height: 1.25; }
        .w-100 { width: 100%; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 5px; }
        th, td { border: 1px solid #777; padding: 3px; text-align: left; vertical-align: top; }
        th { background-color: #e5e5e5; font-weight: bold; font-size: 8px; text-transform: uppercase; }
        .header-box { border: 2px solid #000; padding: 5px; text-align: center; background-color: #f9f9f9; }
        .section-header { background-color: #111; color: #fff; padding: 3px; font-weight: bold; text-transform: uppercase; font-size: 9px; margin-top: 6px; margin-bottom: 3px; }
        .legal-footer { font-size: 7px; color: #444; border: 1px dashed #999; padding: 5px; text-align: justify; margin-top: 8px; }
    </style>
</head>
<body>

    <table class="w-100" style="border: none;">
        <tr style="border: none;">
            <td width="20%" style="border: none; text-align: center; vertical-align: middle;">
                ' . $qrImageHtml . '
            </td>
            <td width="50%" style="border: none;">
                <div style="font-size: 7px; font-weight: bold; color:#333;">Vigilado Super Transporte</div>
                <div style="font-size: 11px; font-weight: bold;">' . e($e['razon_social'] ?? '') . '</div>
                <div><strong>NIT:</strong> ' . e($e['nit'] ?? '') . '</div>
                <div style="font-size: 9px; font-weight: bold; margin-top: 3px;">MANIFIESTO ELECTR&Oacute;NICO DE CARGA</div>
            </td>
            <td width="30%" style="border: none;">
                <div class="header-box">
                    <strong>MANIFIESTO:</strong><br><span style="color: blue; font-size: 10px;">' . e($m['num_manifiesto'] ?? '') . '</span><br>
                    <strong>AUTORIZACI&Oacute;N:</strong><br><span style="color: red; font-size: 10px;">' . e($autorizacionNum ?: '—') . '</span><br>
                    <span style="font-size: 8px;">F. Expedici&oacute;n: ' . e($m['fecha_expedicion'] ?? '') . '</span>
                </div>
            </td>
        </tr>
    </table>

    <table>
        <tr>
            <th>Origen del Viaje</th><td>' . e($origen) . '</td>
            <th>Destino Final</th><td>' . e($destino) . '</td>
            <th>Tipo Manifiesto</th><td>' . e($tipoManifiesto) . '</td>
        </tr>
    </table>

    <div class="section-header">Titular del Manifiesto / Poseedor o Tenedor Veh&iacute;culo</div>
    <table>
        <tr>
            <th>Titular:</th><td colspan="3"><strong>' . e($nomTerc($titular)) . '</strong></td>
            <th>Documento:</th><td>' . e(($titular['tipo_id'] ?? '') . ' ' . ($titular['num_id'] ?? '—')) . '</td>
        </tr>
        <tr>
            <th>Direcci&oacute;n:</th><td colspan="3">' . e($titular['direccion'] ?? '—') . '</td>
            <th>Tel&eacute;fono:</th><td>' . e($titular['telefono'] ?? '—') . '</td>
        </tr>
        <tr>
            <th>Ciudad:</th><td colspan="5">' . $fmtMuni($titular['cod_municipio'] ?? null) . '</td>
        </tr>
    </table>

    <div class="section-header">Informaci&oacute;n T&eacute;cnica del Veh&iacute;culo y Seguros</div>
    <table>
        <tr>
            <th>Placa</th><td><strong>' . e($m['placa_vehiculo'] ?? '') . '</strong></td>
            <th>Configuraci&oacute;n</th><td>' . ($configDsc ? e($configDsc) : e($v['cod_configuracion'] ?? '—')) . '</td>
            <th>Placa Remolque</th><td>' . e($v['remolque_placa'] ?? '—') . '</td>
        </tr>
        <tr>
            <th>Peso Vac&iacute;o Veh&iacute;culo</th><td>' . e((string) ($v['peso_vacio'] ?? '—')) . '</td>
            <th colspan="4">&nbsp;</td>
        </tr>
    </table>

    <div class="section-header">Personal de Tripulaci&oacute;n (Conductores)</div>
    <table>
        <tr>
            <th width="15%">Conductor 1:</th><td width="35%"><strong>' . e($nomTerc($conductor)) . '</strong></td>
            <th width="15%">Documento / Licencia:</th><td width="35%">' . e(($conductor['tipo_id'] ?? '') . ' ' . ($conductor['num_id'] ?? '—')) . ' / ' . e($conductor['num_licencia'] ?? '—') . '</td>
        </tr>
        <tr>
            <th>Direcci&oacute;n / Tel:</th><td>' . e($conductor['direccion'] ?? '—') . ' - ' . e($conductor['telefono'] ?? '—') . '</td>
            <th>Ciudad:</th><td>' . $fmtMuni($conductor['cod_municipio'] ?? null) . '</td>
        </tr>
        <tr style="color: #666;">
            <th>Conductor 2:</th><td>NINGUNO</td>
            <th>Documento / Licencia:</th><td>&nbsp;</td>
        </tr>
    </table>

    <div class="section-header">Informaci&oacute;n de la Mercanc&iacute;a Transportada</div>
    <table>
        <thead>
            <tr>
                <th>Nro. Remesa</th>
                <th>Cant/UM</th>
                <th>Naturaleza / Empaque</th>
                <th>Producto Transportado</th>
                <th>Remitente / NIT</th>
                <th>Destinatario / NIT</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td>' . e($r['num_remesa'] ?? '—') . '</td>
                <td>' . e((string) ($r['peso'] ?? '—')) . ' ' . e($r['unidad_medida'] ?? 'Kg') . '</td>
                <td>' . e($natuNombre) . ' / ' . e($empaqueDesc) . '</td>
                <td>' . e($r['descripcion_producto'] ?? '—') . '</td>
                <td>' . e($nomTerc($remitente)) . '<br><strong>NIT:</strong> ' . e(($remitente['tipo_id'] ?? '') . ' ' . ($remitente['num_id'] ?? '—')) . '</td>
                <td>' . e($nomTerc($destinatario)) . '<br><strong>NIT:</strong> ' . e(($destinatario['tipo_id'] ?? '') . ' ' . ($destinatario['num_id'] ?? '—')) . '</td>
            </tr>
            <tr>
                <td colspan="6"><strong>Due&ntilde;o P&oacute;liza Carga:</strong> ' . e($r['dueno_poliza'] ?? '—') . ' &nbsp;|&nbsp; <strong>Generador:</strong> ' . e($nomTerc($generador)) . '</td>
            </tr>
        </tbody>
    </table>

    <div class="section-header">Liquidaci&oacute;n de Valores de Flete</div>
    <table style="width: 100%;">
        <tr>
            <th>Valor Total del Viaje:</th><td style="text-align: right; font-weight: bold;">$ ' . $fmtMoney($flete) . '</td>
            <th>Valor Anticipo:</th><td style="text-align: right;">$ ' . $fmtMoney($anticipo) . '</td>
        </tr>
        <tr>
            <th>Retenci&oacute;n en la Fuente:</th><td style="text-align: right; color: red;">$ ' . $fmtMoney($reteFuente) . '</td>
            <th>Retenci&oacute;n ICA:</th><td style="text-align: right; color: red;">$ ' . $fmtMoney($reteIca) . '</td>
        </tr>
        <tr>
            <th>Retenci&oacute;n FOPAT:</th><td style="text-align: right;">$ ' . $fmtMoney($fopat) . '</td>
            <th>Saldo Neto a Pagar:</th><td style="text-align: right; font-weight: bold; color: green; font-size: 10px;">$ ' . $fmtMoney($saldoPagar) . '</td>
        </tr>
        <tr>
            <th>Lugar de Pago:</th><td>' . e($lugarPago) . '</td>
            <th>Costos Cargue / Descargue:</th><td>Cargue: ' . e($responsables[$m['responsable_pago_cargue'] ?? ''] ?? '—') . ' | Descargue: ' . e($responsables[$m['responsable_pago_descargue'] ?? ''] ?? '—') . '</td>
        </tr>
    </table>

    <table>
        <tr><th>Observaciones de Control:</th><td>' . e($s['observaciones'] ?? '') . '</td></tr>
    </table>

    <div class="legal-footer">
        La impresi&oacute;n en soporte cartular (papel) de este acto administrativo producido por medios electr&oacute;nicos en cumplimiento de la ley 527 de 1999 (Art&iacute;culos 6 a 13) y de la ley 962 de 2005 (Art&iacute;culo 6), es una reproducci&oacute;n del documento original que se encuentra en formato electr&oacute;nico...
    </div>

</body>
</html>';

$dompdf->loadHtml($html);
$dompdf->setPaper('A4', 'portrait');
$dompdf->render();

$dompdf->stream('manifiesto_' . e($m['num_manifiesto'] ?? $remesaId) . '.pdf', ['Attachment' => false]);
