<?php
/**
 * Light TMS - Helpers de presentación (layout + render de vistas).
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/helpers.php';

/** Construye una URL de ruta del front controller. */
function ruta(string $r, array $params = []): string
{
    $q = array_merge(['r' => $r], $params);
    return '?' . http_build_query($q);
}

/** Imprime la cabecera + navegación. */
function layout_top(string $titulo, string $activo = ''): void
{
    $app = config()['app']['name'];

    // Determinar qué grupo contiene la página activa para marcar el menú.
    $grupos = [
        'operacion' => ['inicio', 'solicitudes', 'solicitud.ver', 'solicitud.editar', 'solicitud.nueva', 'despachos', 'despacho.confirmar', 'despacho.guardar', 'despacho.procesar', 'cola', 'cola.procesar', 'cola.xml'],
        'maestros'  => ['terceros', 'tercero.nuevo', 'tercero.crear', 'tercero.editar', 'tercero.guardar', 'vehiculos', 'vehiculo.nuevo', 'vehiculo.crear', 'vehiculo.editar', 'vehiculo.guardar', 'productos', 'producto.editar', 'empresa', 'empresa.guardar'],
    ];
    $grupoActivo = '';
    foreach ($grupos as $g => $rs) {
        if (in_array($activo, $rs, true)) { $grupoActivo = $g; break; }
    }
    ?>
<!DOCTYPE html>
<html lang="es-CO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($titulo) ?> · <?= e($app) ?></title>
    <link rel="stylesheet" href="assets/css/styles.css">
    <script defer src="assets/js/app.js"></script>
</head>
<body>
    <header class="barra">
        <div class="barra__marca"><?= e($app) ?></div>
        <nav class="barra__nav">
            <a href="<?= e(ruta('inicio')) ?>" class="<?= $activo === 'inicio' ? 'activo' : '' ?>">Inicio</a>

            <div class="dropdown <?= $grupoActivo === 'operacion' ? 'activo' : '' ?>">
                <button class="dropdown__toggle">Operación ▾</button>
                <div class="dropdown__menu">
                    <a href="<?= e(ruta('solicitudes')) ?>">Solicitudes</a>
                    <a href="<?= e(ruta('despachos')) ?>">Despachos</a>
                    <a href="<?= e(ruta('cola')) ?>">Cola RNDC</a>
                </div>
            </div>

            <div class="dropdown <?= $grupoActivo === 'maestros' ? 'activo' : '' ?>">
                <button class="dropdown__toggle">Maestros ▾</button>
                <div class="dropdown__menu">
                    <a href="<?= e(ruta('terceros')) ?>">Terceros</a>
                    <a href="<?= e(ruta('vehiculos')) ?>">Vehículos</a>
                    <a href="<?= e(ruta('productos')) ?>">Productos</a>
                    <a href="<?= e(ruta('empresa')) ?>">Empresa</a>
                </div>
            </div>

            <a href="<?= e(ruta('solicitud.nueva')) ?>" class="btn--nav-alta">+ Nueva solicitud</a>
        </nav>
    </header>
    <main class="contenido">
    <?php
}

/** Cierra el layout. */
function layout_bottom(): void
{
    $app = config()['app']['name'];
    ?>
    </main>
    <footer class="pie"><?= e($app) ?> · entorno: <?= e(config()['app']['env']) ?></footer>
</body>
</html>
    <?php
}

/** Mensaje flash simple por querystring (?ok=... / ?err=...). */
function flash(): void
{
    if (!empty($_GET['ok'])) {
        echo '<div class="alerta alerta--ok">' . e((string) $_GET['ok']) . '</div>';
    }
    if (!empty($_GET['err'])) {
        echo '<div class="alerta alerta--err">' . e((string) $_GET['err']) . '</div>';
    }
}
