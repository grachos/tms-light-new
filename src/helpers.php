<?php
/**
 * Light TMS - Utilidades comunes.
 */

declare(strict_types=1);

/**
 * Escapa texto para imprimir en HTML de forma segura.
 */
function e(?string $valor): string
{
    return htmlspecialchars((string) $valor, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Cuenta filas de una tabla; devuelve null si la tabla no existe.
 */
function contar_tabla(string $tabla): ?int
{
    // Lista blanca: evita inyección en el nombre de tabla.
    $permitidas = ['solicitud_servicio', 'manifiesto', 'remesa', 'cola_envios'];
    if (!in_array($tabla, $permitidas, true)) {
        return null;
    }
    try {
        return (int) db()->query("SELECT COUNT(*) FROM `$tabla`")->fetchColumn();
    } catch (Throwable) {
        return null;
    }
}

/**
 * Valida que un producto de naturaleza peligrosa tenga codigo_un y estado_producto.
 * Retorna null si es válido, o un mensaje de error si no.
 */
function validarProductoPeligrosa(string $codigo, string $naturaleza): ?string
{
    if ($naturaleza !== '2' || $codigo === '') {
        return null;
    }
    $prod = (new CatalogoRepo())->productoPorCodigo($codigo);
    if ($prod === null) {
        return null;
    }
    if (empty($prod['codigo_un']) || empty($prod['estado_producto'])) {
        return 'El producto es de naturaleza peligrosa pero le falta Código UN y/o Estado del producto. Edítalo en Productos primero.';
    }
    return null;
}
