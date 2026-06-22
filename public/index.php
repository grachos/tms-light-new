<?php
/**
 * Light TMS - Front controller.
 * Enruta las páginas mediante ?r=<ruta>.
 */

declare(strict_types=1);

require_once __DIR__ . '/../src/db.php';
require_once __DIR__ . '/../src/vista.php';
require_once __DIR__ . '/../src/Solicitud/SolicitudRepo.php';
require_once __DIR__ . '/../src/Maestro/MunicipioRepo.php';
require_once __DIR__ . '/../src/Maestro/TerceroRepo.php';
require_once __DIR__ . '/../src/Maestro/VehiculoRepo.php';
require_once __DIR__ . '/../src/Maestro/CatalogoRepo.php';
require_once __DIR__ . '/../src/Maestro/EmpresaRepo.php';
require_once __DIR__ . '/../src/Despacho/ColaRepo.php';
require_once __DIR__ . '/../src/Rndc/RndcClient.php';

$r = $_GET['r'] ?? 'inicio';

try {
    switch ($r) {

        case 'municipios.buscar':
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode((new MunicipioRepo())->buscar((string) ($_GET['q'] ?? '')), JSON_UNESCAPED_UNICODE);
            break;

        case 'terceros.buscar':
            header('Content-Type: application/json; charset=utf-8');
            $solo = !empty($_GET['solo_conductor']);
            echo json_encode((new TerceroRepo())->buscar((string) ($_GET['q'] ?? ''), $solo), JSON_UNESCAPED_UNICODE);
            break;

        case 'vehiculos.buscar':
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode((new VehiculoRepo())->buscar((string) ($_GET['q'] ?? '')), JSON_UNESCAPED_UNICODE);
            break;

        case 'vehiculo.detalle':
            header('Content-Type: application/json; charset=utf-8');
            $det = (new VehiculoRepo())->detalle((string) ($_GET['placa'] ?? ''));
            echo json_encode($det ?? new stdClass(), JSON_UNESCAPED_UNICODE);
            break;

        case 'productos.buscar':
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode((new CatalogoRepo())->buscarProductos((string) ($_GET['q'] ?? '')), JSON_UNESCAPED_UNICODE);
            break;

        case 'productos':
            $cat = new CatalogoRepo();
            $pagina = max(1, (int) ($_GET['p'] ?? 1));
            $res = $cat->listarProductos((string) ($_GET['q'] ?? ''), $pagina, 10);
            $lista = $res['items'];
            $total = $res['total'];
            $paginas = (int) ceil($total / 10);
            layout_top('Productos', 'productos');
            require __DIR__ . '/../src/vistas/productos.php';
            layout_bottom();
            break;

        case 'producto.editar':
            $codigo = (string) ($_GET['codigo'] ?? '');
            $prod = (new CatalogoRepo())->productoPorCodigo($codigo);
            if ($prod === null) {
                header('Location: ' . ruta('productos', ['err' => 'Producto no encontrado.']));
                break;
            }
            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                (new CatalogoRepo())->actualizarProducto($codigo, $_POST);
                header('Location: ' . ruta('productos', ['ok' => 'Producto actualizado.']));
                break;
            }
            layout_top('Editar producto', 'productos');
            require __DIR__ . '/../src/vistas/producto_form.php';
            layout_bottom();
            break;

        case 'terceros':
            $pagina = max(1, (int) ($_GET['p'] ?? 1));
            $res = (new TerceroRepo())->listarConPaginacion((string) ($_GET['q'] ?? ''), $pagina, 10);
            $terceros = $res['items'];
            $total = $res['total'];
            $paginas = (int) ceil($total / 10);
            layout_top('Terceros', 'terceros');
            require __DIR__ . '/../src/vistas/terceros.php';
            layout_bottom();
            break;

        case 'tercero.nuevo':
            layout_top('Nuevo tercero', 'terceros');
            require __DIR__ . '/../src/vistas/tercero_form.php';
            layout_bottom();
            break;

        case 'tercero.crear':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                header('Location: ' . ruta('tercero.nuevo'));
                break;
            }
            if (empty($_POST['cod_municipio'])) {
                header('Location: ' . ruta('tercero.nuevo', ['err' => 'Elige el municipio de la lista.']));
                break;
            }
            try {
                (new TerceroRepo())->crear($_POST);
                header('Location: ' . ruta('terceros', ['ok' => 'Tercero guardado.']));
            } catch (Throwable $e) {
                $msg = config()['app']['debug'] ? $e->getMessage() : 'No se pudo guardar el tercero.';
                header('Location: ' . ruta('tercero.nuevo', ['err' => $msg]));
            }
            break;

        case 'tercero.editar':
            $tercero = (new TerceroRepo())->obtener((int) ($_GET['id'] ?? 0));
            if ($tercero === null) {
                header('Location: ' . ruta('terceros', ['err' => 'Tercero no encontrado.']));
                break;
            }
            $accion = ruta('tercero.actualizar', ['id' => (int) $tercero['id']]);
            layout_top('Editar tercero', 'terceros');
            require __DIR__ . '/../src/vistas/tercero_form.php';
            layout_bottom();
            break;

        case 'tercero.actualizar':
            $id = (int) ($_GET['id'] ?? 0);
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                header('Location: ' . ruta('tercero.editar', ['id' => $id]));
                break;
            }
            if (empty($_POST['cod_municipio'])) {
                header('Location: ' . ruta('tercero.editar', ['id' => $id, 'err' => 'Elige el municipio de la lista.']));
                break;
            }
            try {
                (new TerceroRepo())->actualizar($id, $_POST);
                header('Location: ' . ruta('terceros', ['ok' => 'Tercero actualizado.']));
            } catch (Throwable $e) {
                $msg = config()['app']['debug'] ? $e->getMessage() : 'No se pudo actualizar.';
                header('Location: ' . ruta('tercero.editar', ['id' => $id, 'err' => $msg]));
            }
            break;

        case 'tercero.registrar':
            $id = (int) ($_GET['id'] ?? 0);
            $resp = (new TerceroRepo())->registrarEnRndc($id);
            if ($resp->ok) {
                header('Location: ' . ruta('terceros', ['ok' => 'Tercero registrado en RNDC (id ' . $resp->ingresoId . ').']));
            } else {
                header('Location: ' . ruta('terceros', ['err' => 'RNDC: ' . $resp->error]));
            }
            break;

        case 'vehiculos':
            $pagina = max(1, (int) ($_GET['p'] ?? 1));
            $res = (new VehiculoRepo())->listarConPaginacion((string) ($_GET['q'] ?? ''), $pagina, 10);
            $vehiculos = $res['items'];
            $total = $res['total'];
            $paginas = (int) ceil($total / 10);
            layout_top('Vehículos', 'vehiculos');
            require __DIR__ . '/../src/vistas/vehiculos.php';
            layout_bottom();
            break;

        case 'vehiculo.nuevo':
            layout_top('Nuevo vehículo', 'vehiculos');
            require __DIR__ . '/../src/vistas/vehiculo_form.php';
            layout_bottom();
            break;

        case 'vehiculo.crear':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                header('Location: ' . ruta('vehiculo.nuevo'));
                break;
            }
            if (empty($_POST['tenedor_num_id'])) {
                header('Location: ' . ruta('vehiculo.nuevo', ['err' => 'Elige el tenedor de la lista de terceros.']));
                break;
            }
            try {
                (new VehiculoRepo())->crear($_POST);
                header('Location: ' . ruta('vehiculos', ['ok' => 'Vehículo guardado.']));
            } catch (Throwable $e) {
                $msg = config()['app']['debug'] ? $e->getMessage() : 'No se pudo guardar el vehículo.';
                header('Location: ' . ruta('vehiculo.nuevo', ['err' => $msg]));
            }
            break;

        case 'vehiculo.editar':
            $vehiculo = (new VehiculoRepo())->obtener((int) ($_GET['id'] ?? 0));
            if ($vehiculo === null) {
                header('Location: ' . ruta('vehiculos', ['err' => 'Vehículo no encontrado.']));
                break;
            }
            $accion = ruta('vehiculo.actualizar', ['id' => (int) $vehiculo['id']]);
            layout_top('Editar vehículo', 'vehiculos');
            require __DIR__ . '/../src/vistas/vehiculo_form.php';
            layout_bottom();
            break;

        case 'vehiculo.actualizar':
            $id = (int) ($_GET['id'] ?? 0);
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                header('Location: ' . ruta('vehiculo.editar', ['id' => $id]));
                break;
            }
            if (empty($_POST['tenedor_num_id'])) {
                header('Location: ' . ruta('vehiculo.editar', ['id' => $id, 'err' => 'Elige el tenedor de la lista de terceros.']));
                break;
            }
            try {
                (new VehiculoRepo())->actualizar($id, $_POST);
                header('Location: ' . ruta('vehiculos', ['ok' => 'Vehículo actualizado.']));
            } catch (Throwable $e) {
                $msg = config()['app']['debug'] ? $e->getMessage() : 'No se pudo actualizar.';
                header('Location: ' . ruta('vehiculo.editar', ['id' => $id, 'err' => $msg]));
            }
            break;

        case 'vehiculo.registrar':
            $id = (int) ($_GET['id'] ?? 0);
            $resp = (new VehiculoRepo())->registrarEnRndc($id);
            if ($resp->ok) {
                header('Location: ' . ruta('vehiculos', ['ok' => 'Vehículo registrado en RNDC (id ' . $resp->ingresoId . ').']));
            } else {
                header('Location: ' . ruta('vehiculos', ['err' => 'RNDC: ' . $resp->error]));
            }
            break;
        case 'solicitudes':
            $repo = new SolicitudRepo();
            $pagina = max(1, (int) ($_GET['p'] ?? 1));
            $desde = !empty($_GET['desde']) ? $_GET['desde'] : null;
            $hasta = !empty($_GET['hasta']) ? $_GET['hasta'] : null;
            $res = $repo->listarConPaginacion((string) ($_GET['q'] ?? ''), $pagina, 10, $desde, $hasta);
            $solicitudes = $res['items'];
            $total = $res['total'];
            $paginas = (int) ceil($total / 10);
            layout_top('Solicitudes', 'solicitudes');
            require __DIR__ . '/../src/vistas/solicitudes.php';
            layout_bottom();
            break;

        case 'solicitud.nueva':
            layout_top('Nueva solicitud', 'solicitud.nueva');
            require __DIR__ . '/../src/vistas/solicitud_form.php';
            layout_bottom();
            break;

        case 'solicitud.crear':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                header('Location: ' . ruta('solicitud.nueva'));
                break;
            }
            $errPeligrosa = validarProductoPeligrosa($_POST['mercancia_codigo'] ?? '', $_POST['naturaleza_carga'] ?? '');
            if ($errPeligrosa !== null) {
                header('Location: ' . ruta('solicitud.nueva', ['err' => $errPeligrosa]));
                break;
            }
            $repo = new SolicitudRepo();
            try {
                $id = $repo->crear($_POST);
                header('Location: ' . ruta('solicitud.ver', [
                    'id' => $id,
                    'ok' => 'Solicitud creada.',
                ]));
            } catch (Throwable $e) {
                $msg = config()['app']['debug'] ? $e->getMessage() : 'No se pudo guardar la solicitud.';
                header('Location: ' . ruta('solicitud.nueva', ['err' => $msg]));
            }
            break;

        case 'solicitud.editar':
            $datos = (new SolicitudRepo())->obtener((int) ($_GET['id'] ?? 0));
            if ($datos === null) {
                header('Location: ' . ruta('solicitudes', ['err' => 'Solicitud no encontrada.']));
                break;
            }
            $solicitud = $datos['solicitud'];
            if ($solicitud['estado'] === 'despachada') {
                header('Location: ' . ruta('solicitud.ver', ['id' => (int) $solicitud['id'], 'err' => 'La solicitud ya fue despachada; no se puede editar.']));
                break;
            }
            $accion = ruta('solicitud.actualizar', ['id' => (int) $solicitud['id']]);
            layout_top('Editar solicitud', 'solicitudes');
            require __DIR__ . '/../src/vistas/solicitud_form.php';
            layout_bottom();
            break;

        case 'solicitud.actualizar':
            $id = (int) ($_GET['id'] ?? 0);
            $repo = new SolicitudRepo();
            $datos = $repo->obtener($id);
            if ($datos === null) {
                header('Location: ' . ruta('solicitudes', ['err' => 'Solicitud no encontrada.']));
                break;
            }
            if ($datos['solicitud']['estado'] !== 'borrador') {
                header('Location: ' . ruta('solicitud.ver', ['id' => $id, 'err' => 'La solicitud solo se puede editar en estado borrador.']));
                break;
            }
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                header('Location: ' . ruta('solicitud.editar', ['id' => $id]));
                break;
            }
            $errPeligrosa = validarProductoPeligrosa($_POST['mercancia_codigo'] ?? '', $_POST['naturaleza_carga'] ?? '');
            if ($errPeligrosa !== null) {
                header('Location: ' . ruta('solicitud.editar', ['id' => $id, 'err' => $errPeligrosa]));
                break;
            }
            try {
                $repo->actualizar($id, $_POST);
                header('Location: ' . ruta('solicitud.ver', ['id' => $id, 'ok' => 'Solicitud actualizada.']));
            } catch (Throwable $e) {
                $msg = config()['app']['debug'] ? $e->getMessage() : 'No se pudo actualizar la solicitud.';
                header('Location: ' . ruta('solicitud.editar', ['id' => $id, 'err' => $msg]));
            }
            break;

        case 'solicitud.ver':
            $id = (int) ($_GET['id'] ?? 0);
            $remesaId = isset($_GET['remesa_id']) ? (int) $_GET['remesa_id'] : null;
            $repo = new SolicitudRepo();
            $datos = $repo->obtener($id, $remesaId);
            if ($datos === null) {
                http_response_code(404);
                layout_top('No encontrada', 'solicitudes');
                echo '<div class="tarjeta vacio">Solicitud no encontrada.</div>';
                layout_bottom();
                break;
            }
            $solicitud  = $datos['solicitud'];
            $manifiesto = $datos['manifiesto'];
            $remesa     = $datos['remesa'];
            layout_top('Solicitud #' . $id, 'solicitudes');
            require __DIR__ . '/../src/vistas/solicitud_detalle.php';
            layout_bottom();
            break;

        case 'despacho.confirmar':
            $id = (int) ($_GET['id'] ?? 0);
            $datos = (new SolicitudRepo())->obtener($id);
            if ($datos === null) {
                header('Location: ' . ruta('solicitudes', ['err' => 'Solicitud no encontrada.']));
                break;
            }
            if ($datos['solicitud']['estado'] === 'despachada') {
                header('Location: ' . ruta('solicitud.ver', ['id' => $id, 'err' => 'La solicitud ya fue despachada.']));
                break;
            }
            if (($datos['solicitud']['cantidad_vehiculos'] ?? 1) < 1) {
                header('Location: ' . ruta('solicitud.ver', ['id' => $id, 'err' => 'Ya no quedan vehículos por despachar en esta solicitud.']));
                break;
            }
            $solicitud = $datos['solicitud'];
            if (empty($solicitud['emf'])) {
                $empresa = (new EmpresaRepo())->obtener();
                $solicitud['emf'] = $empresa['emf'] ?? '';
            }
            layout_top('Confirmar despacho', 'solicitudes');
            require __DIR__ . '/../src/vistas/despacho_form.php';
            layout_bottom();
            break;

        case 'despacho.guardar':
            $id = (int) ($_GET['id'] ?? 0);
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                header('Location: ' . ruta('despacho.confirmar', ['id' => $id]));
                break;
            }
            if (empty($_POST['placa_vehiculo']) || empty($_POST['conductor_num_id'])) {
                header('Location: ' . ruta('despacho.confirmar', ['id' => $id, 'err' => 'Placa y conductor son obligatorios para despachar.']));
                break;
            }
            try {
                (new SolicitudRepo())->confirmarDespacho($id, $_POST);
                header('Location: ' . ruta('cola', ['ok' => 'Despacho confirmado. Documentos encolados para el RNDC.']));
            } catch (Throwable $e) {
                $msg = config()['app']['debug'] ? $e->getMessage() : 'No se pudo confirmar el despacho.';
                header('Location: ' . ruta('despacho.confirmar', ['id' => $id, 'err' => $msg]));
            }
            break;

        case 'cola':
            $cola = (new ColaRepo());
            $filas = $cola->listar();
            $resumen = $cola->resumen();
            $envioHabilitado = (bool) config()['cola']['envio_habilitado'];
            layout_top('Cola de envíos', 'cola');
            require __DIR__ . '/../src/vistas/cola.php';
            layout_bottom();
            break;

        case 'cola.procesar':
            try {
                $r2 = (new ColaRepo())->drenar();
                $modo = ((bool) config()['cola']['envio_habilitado']) ? 'envío real' : 'modo seguro';
                $msg = sprintf('Cola procesada (%s): enviados=%d, errores=%d, esperando=%d, previstos=%d.',
                    $modo, $r2['enviados'], $r2['errores'], $r2['esperando'], $r2['previstos']);
                header('Location: ' . ruta('cola', ['ok' => $msg]));
            } catch (Throwable $e) {
                $msg = config()['app']['debug'] ? $e->getMessage() : 'No se pudo procesar la cola.';
                header('Location: ' . ruta('cola', ['err' => $msg]));
            }
            break;

        case 'cola.procesar_item':
            $id = (int) ($_GET['id'] ?? 0);
            $r2 = (new ColaRepo())->procesarItem($id);
            header('Location: ' . ruta('cola', [$r2['ok'] ? 'ok' : 'err' => $r2['mensaje']]));
            break;

        case 'cola.xml':
            $fila = db()->prepare('SELECT * FROM cola_envios WHERE id = ?');
            $fila->execute([(int) ($_GET['id'] ?? 0)]);
            $f = $fila->fetch();
            header('Content-Type: text/plain; charset=utf-8');
            if ($f === false) {
                echo 'No encontrado.';
                break;
            }
            try {
                $rndc = RndcClient::desdeConfig();
                echo "=== PREVISUALIZACIÓN XML ===\n\n";
                echo $rndc->previewXmlInterno((int) $f['proceso_rndc'], (string) $f['payload_xml']);
            } catch (Throwable) {
                echo "(Fragmento <variables>):\n" . $f['payload_xml'];
            }
            if ($f['respuesta_rndc'] !== null && $f['respuesta_rndc'] !== '') {
                echo "\n\n=== RESPUESTA DEL RNDC ===\n\n";
                echo $f['respuesta_rndc'];
            }
            break;

        case 'despachos':
            $pagina = max(1, (int) ($_GET['p'] ?? 1));
            $desde = !empty($_GET['desde']) ? $_GET['desde'] : null;
            $hasta = !empty($_GET['hasta']) ? $_GET['hasta'] : null;
            $res = (new ColaRepo())->listarDespachosConPaginacion((string) ($_GET['q'] ?? ''), $pagina, 10, $desde, $hasta);
            $despachos = $res['items'];
            $total = $res['total'];
            $paginas = (int) ceil($total / 10);
            layout_top('Despachos', 'despachos');
            require __DIR__ . '/../src/vistas/despachos.php';
            layout_bottom();
            break;

        case 'despacho.procesar':
            $remesaId = (int) ($_GET['remesa_id'] ?? 0);
            try {
                $r2 = (new ColaRepo())->procesarDespacho($remesaId);
                $msg = $r2['ok'] ? 'ok' : 'err';
                header('Location: ' . ruta('despachos', [$msg => $r2['mensaje']]));
            } catch (Throwable $e) {
                $msg = config()['app']['debug'] ? $e->getMessage() : 'No se pudo procesar el despacho.';
                header('Location: ' . ruta('despachos', ['err' => $msg]));
            }
            break;

        case 'empresa':
            $empresa = (new EmpresaRepo())->obtener();
            layout_top('Empresa', 'empresa');
            require __DIR__ . '/../src/vistas/empresa_form.php';
            layout_bottom();
            break;

        case 'empresa.guardar':
            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                (new EmpresaRepo())->guardar($_POST);
            }
            header('Location: ' . ruta('empresa', ['ok' => 'Datos de la empresa guardados.']));
            break;

        case 'inicio':
        default:
            require __DIR__ . '/../src/vistas/inicio.php';
            break;
    }
} catch (Throwable $e) {
    http_response_code(500);
    layout_top('Error', '');
    echo '<div class="alerta alerta--err">Ocurrió un error.';
    if (config()['app']['debug']) {
        echo '<br><small>' . e($e->getMessage()) . '</small>';
    }
    echo '</div>';
    layout_bottom();
}
