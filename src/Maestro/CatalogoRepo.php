<?php
/**
 * Light TMS - Catálogos RNDC (empaque, carrocería, producto).
 */

declare(strict_types=1);

require_once __DIR__ . '/../db.php';

final class CatalogoRepo
{
    /** @return list<array{codigo:string,descripcion:string}> */
    public function empaques(): array
    {
        return db()->query('SELECT codigo, descripcion FROM empaque ORDER BY descripcion')->fetchAll();
    }

    /** Descripción de un tipo de empaque por su código. */
    public function empaquePorCodigo(string $codigo): ?string
    {
        if ($codigo === '') { return null; }
        $stmt = db()->prepare('SELECT descripcion FROM empaque WHERE codigo = ?');
        $stmt->execute([$codigo]);
        $v = $stmt->fetchColumn();
        return $v !== false ? (string) $v : null;
    }

    /** @return list<array{codigo:string,descripcion:string}> */
    public function carrocerias(): array
    {
        return db()->query('SELECT codigo, descripcion FROM carroceria ORDER BY descripcion')->fetchAll();
    }

    /** @return list<array{codigo:string,nombre:string,descripcion:string}> */
    public function configuraciones(): array
    {
        return db()->query(
            'SELECT codigo, nombre, descripcion FROM configuracion_vehiculo ORDER BY tipo, nombre'
        )->fetchAll();
    }

    /**
     * Obtiene un producto por su código.
     * @return array<string,string|null>|null
     */
    public function productoPorCodigo(string $codigo): ?array
    {
        if ($codigo === '') { return null; }
        $stmt = db()->prepare(
            "SELECT codigo, nombre, tipo, peligrosa, clase_division,
                    peligro_secundario, grupo_embalaje, alerta,
                    codigo_un, estado_producto
             FROM producto WHERE codigo = ?"
        );
        $stmt->execute([$codigo]);
        $r = $stmt->fetch();
        return $r ?: null;
    }

    /**
     * Busca productos por nombre o código (autocompletado).
     *
     * @return list<array{codigo:string,nombre:string,tipo:string,peligrosa:string,clase_division:string,peligro_secundario:string,grupo_embalaje:string,alerta:string,codigo_un:string,estado_producto:string,label:string}>
     */
    public function buscarProductos(string $q, int $limite = 15): array
    {
        $q = trim($q);
        if ($q === '') {
            return [];
        }
        $like = '%' . $q . '%';
        $stmt = db()->prepare(
            "SELECT codigo, nombre, tipo, peligrosa, clase_division,
                    peligro_secundario, grupo_embalaje, alerta,
                    codigo_un, estado_producto
             FROM producto
             WHERE nombre <> '' AND (nombre LIKE ? OR codigo LIKE ?)
             ORDER BY nombre LIMIT " . (int) $limite
        );
        $stmt->execute([$like, $like]);
        $filas = $stmt->fetchAll();
        foreach ($filas as &$f) {
            $f['label'] = $f['codigo'] . ' — ' . $f['nombre'];
        }
        return $filas;
    }

    /**
     * Actualiza codigo_un y estado_producto de un producto.
     * @param array{codigo_un:string,estado_producto:string} $datos
     */
    public function actualizarProducto(string $codigo, array $datos): void
    {
        $stmt = db()->prepare(
            'UPDATE producto SET codigo_un = ?, estado_producto = ? WHERE codigo = ?'
        );
        $stmt->execute([
            trim((string) ($datos['codigo_un'] ?? '')) ?: null,
            trim((string) ($datos['estado_producto'] ?? '')) ?: null,
            $codigo,
        ]);
    }

    /**
     * @return array{items:list<array{codigo:string,nombre:string,tipo:string,codigo_un:string,estado_producto:string}>,total:int}
     */
    public function listarProductos(string $q = '', int $pagina = 1, int $porPagina = 10): array
    {
        $offset = max(0, ($pagina - 1) * $porPagina);
        $where = "WHERE nombre <> ''";
        $params = [];
        if ($q !== '') {
            $like = '%' . $q . '%';
            $where .= ' AND (nombre LIKE ? OR codigo LIKE ?)';
            $params = [$like, $like];
        }
        $stmtCount = db()->prepare("SELECT COUNT(*) FROM producto $where");
        $stmtCount->execute($params);
        $total = (int) $stmtCount->fetchColumn();
        $stmt = db()->prepare(
            "SELECT codigo, nombre, tipo, codigo_un, estado_producto
             FROM producto $where
             ORDER BY codigo LIMIT ? OFFSET ?"
        );
        $stmt->execute(array_merge($params, [(int) $porPagina, (int) $offset]));
        return ['items' => $stmt->fetchAll(), 'total' => $total];
    }
}
