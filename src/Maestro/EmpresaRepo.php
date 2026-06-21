<?php
/**
 * Light TMS - Maestro de la empresa propia (NIT, póliza). Fila única (id=1).
 */

declare(strict_types=1);

require_once __DIR__ . '/../db.php';

final class EmpresaRepo
{
    /** @return array<string,mixed> */
    public function obtener(): array
    {
        $fila = db()->query('SELECT * FROM maestro_empresa WHERE id = 1')->fetch();
        return $fila ?: ['id' => 1, 'tipo_id' => 'N', 'nit' => '', 'razon_social' => '', 'nro_poliza' => '', 'emf' => '', 'consecutivo_remesa' => 'REM-00000', 'consecutivo_manifiesto' => 'MAN-00000'];
    }

    /** @param array<string,mixed> $datos */
    public function guardar(array $datos): void
    {
        db()->prepare(
            'INSERT INTO maestro_empresa (id, tipo_id, nit, razon_social, nro_poliza, emf, consecutivo_remesa, consecutivo_manifiesto)
             VALUES (1, :tipo_id, :nit, :razon_social, :nro_poliza, :emf, :consecutivo_remesa, :consecutivo_manifiesto)
             ON DUPLICATE KEY UPDATE
                tipo_id = VALUES(tipo_id), nit = VALUES(nit),
                razon_social = VALUES(razon_social), nro_poliza = VALUES(nro_poliza),
                emf = VALUES(emf),
                consecutivo_remesa = VALUES(consecutivo_remesa),
                consecutivo_manifiesto = VALUES(consecutivo_manifiesto)'
        )->execute([
            'tipo_id'              => $datos['tipo_id'] ?? 'N',
            'nit'                  => trim((string) ($datos['nit'] ?? '')),
            'razon_social'         => trim((string) ($datos['razon_social'] ?? '')) ?: null,
            'nro_poliza'           => trim((string) ($datos['nro_poliza'] ?? '')) ?: null,
            'emf'                  => trim((string) ($datos['emf'] ?? '')) ?: null,
            'consecutivo_remesa'   => trim((string) ($datos['consecutivo_remesa'] ?? 'REM-00000')),
            'consecutivo_manifiesto' => trim((string) ($datos['consecutivo_manifiesto'] ?? 'MAN-00000')),
        ]);
    }

    /** Extrae el número desde un consecutivo en formato PREF-00000. */
    private static function extraerNum(string $val): int
    {
        $parts = explode('-', $val, 2);
        return (int) ($parts[1] ?? $parts[0]);
    }

    /** Genera y reserva el siguiente consecutivo de remesa (formato REM-00001). */
    public function siguienteRemesa(): string
    {
        $emp = $this->obtener();
        $num = self::extraerNum((string) ($emp['consecutivo_remesa'] ?? '0'));
        $next = $num + 1;
        $fmt = 'REM-' . str_pad((string) $next, 5, '0', STR_PAD_LEFT);
        db()->prepare('UPDATE maestro_empresa SET consecutivo_remesa = ? WHERE id = 1 AND consecutivo_remesa = ?')
            ->execute([$fmt, $emp['consecutivo_remesa'] ?? '']);
        return $fmt;
    }

    /** Genera y reserva el siguiente consecutivo de manifiesto (formato MAN-00001). */
    public function siguienteManifiesto(): string
    {
        $emp = $this->obtener();
        $num = self::extraerNum((string) ($emp['consecutivo_manifiesto'] ?? '0'));
        $next = $num + 1;
        $fmt = 'MAN-' . str_pad((string) $next, 5, '0', STR_PAD_LEFT);
        db()->prepare('UPDATE maestro_empresa SET consecutivo_manifiesto = ? WHERE id = 1 AND consecutivo_manifiesto = ?')
            ->execute([$fmt, $emp['consecutivo_manifiesto'] ?? '']);
        return $fmt;
    }

    /** Retorna el valor actual de consecutivo_remesa como entero (para el XML). */
    public function siguienteConsecutivoRemesa(): string
    {
        $emp = $this->obtener();
        $num = self::extraerNum((string) ($emp['consecutivo_remesa'] ?? '0'));
        $next = $num + 1;
        $fmt = str_pad((string) $next, 10, '0', STR_PAD_LEFT);
        db()->prepare('UPDATE maestro_empresa SET consecutivo_remesa = ? WHERE id = 1 AND consecutivo_remesa = ?')
            ->execute(['REM-' . str_pad((string) $next, 5, '0', STR_PAD_LEFT), $emp['consecutivo_remesa'] ?? '']);
        return $fmt;
    }
}
