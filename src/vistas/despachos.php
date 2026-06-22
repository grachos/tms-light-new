<?php
/**
 * Vista: Lista de despachos (remesas) con estado y botón procesar.
 * @var list<array{remesa_id:int, solicitud_id:int, consecutivo:string,
 *                  estado_remesa:string, num_remesa:string, num_manifiesto:string}> $despachos
 * @var int $total
 * @var int $pagina
 * @var int $paginas
 */
declare(strict_types=1);
$q = (string) ($_GET['q'] ?? '');
$estadosRndc = ['pendiente' => 'Pendiente', 'enviado' => 'Enviado', 'aceptado' => 'Aceptado', 'rechazado' => 'Rechazado'];
?>
<h1>Despachos <small><?= number_format($total) ?> registros</small></h1>
<?php flash(); ?>

<form method="get" class="barra-busqueda">
    <input type="hidden" name="r" value="despachos">
    <input type="text" name="q" value="<?= e($q) ?>" placeholder="Buscar por remesa o manifiesto…" autofocus>
    <button type="submit" class="btn">Buscar</button>
    <a href="<?= e(ruta('despachos')) ?>" class="btn">Limpiar</a>
</form>

<?php if (empty($despachos)): ?>
    <div class="tarjeta vacio">No hay despachos. Confirma el despacho de una solicitud para generar remesas.</div>
<?php else: ?>
    <table class="tabla">
        <thead>
            <tr>
                <th>Remesa</th>
                <th>Manifiesto</th>
                <th>Solicitud</th>
                <th>Estado</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($despachos as $d): ?>
                <tr>
                    <td><?= e((!empty($d['num_remesa']) && $d['num_remesa'] !== '(auto)') ? $d['num_remesa'] : ('#' . $d['remesa_id'])) ?></td>
                    <td><?= e($d['num_manifiesto'] ?? '—') ?></td>
                    <td><?= e($d['consecutivo'] ?: ('#' . $d['solicitud_id'])) ?></td>
                    <td><span class="chip chip--<?= e($d['estado_remesa']) ?>"><?= e($estadosRndc[$d['estado_remesa']] ?? $d['estado_remesa']) ?></span></td>
                    <td>
                        <a href="<?= e(ruta('despacho.procesar', ['remesa_id' => $d['remesa_id']])) ?>" class="btn btn--small btn--primario">Procesar ahora</a>
                        <a href="<?= e(ruta('solicitud.ver', ['id' => $d['solicitud_id'], 'remesa_id' => $d['remesa_id']])) ?>" class="btn btn--small">Ver</a>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php if ($paginas > 1): ?>
    <?php $bloque = (int) ceil($pagina / 10);
    $inicio = ($bloque - 1) * 10 + 1;
    $fin = min($bloque * 10, $paginas); ?>
    <nav class="paginacion">
        <?php if ($bloque > 1): ?>
            <a href="<?= e(ruta('despachos', ['q' => $q, 'p' => $inicio - 1])) ?>" class="btn btn--small">&laquo;</a>
        <?php endif; ?>
        <?php for ($i = $inicio; $i <= $fin; $i++): ?>
            <a href="<?= e(ruta('despachos', ['q' => $q, 'p' => $i])) ?>" class="btn btn--small<?= $i === $pagina ? ' btn--activo' : '' ?>"><?= $i ?></a>
        <?php endfor; ?>
        <?php if ($fin < $paginas): ?>
            <a href="<?= e(ruta('despachos', ['q' => $q, 'p' => $fin + 1])) ?>" class="btn btn--small">&raquo;</a>
        <?php endif; ?>
    </nav>
    <?php endif; ?>
<?php endif; ?>
