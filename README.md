# Light TMS

Mini TMS (Transport Management System) para Colombia con capa **store-and-forward**
hacia el **RNDC** (Registro Nacional de Despachos de Carga). Cuando el web service del
RNDC está caído, la información se guarda localmente y se reenvía cuando vuelve a estar
disponible.

## Concepto central

La **Solicitud de Servicio** se captura **una sola vez** y siembra automáticamente el
**Manifiesto** y la **Remesa**. El usuario no crea esos documentos por separado.

## Stack

Pensado para **hosting web/cloud de Hostinger** (LAMP administrado):

- **PHP 8.x** (sin Composer; se sube por FTP/File Manager)
- **MariaDB** (compatible MySQL), administrada con **phpMyAdmin**
- Cliente RNDC en PHP (SOAP/XML)
- **Cron job** de Hostinger para el worker de reintento
- Sin autenticación ni CSRF (gaps conocidos, no abordados aún)

## Estructura

```
light-tms/
├── public/                  <- document root del dominio
│   ├── index.php            front-controller (ruteo via ?r=)
│   └── assets/
│       ├── css/styles.css
│       └── js/app.js        autocompletado, mapa, cálculos
├── src/
│   ├── config.php           carga .env + config()
│   ├── db.php               conexión PDO a MariaDB
│   ├── vista.php            layout + helpers
│   ├── helpers.php          utilidades
│   ├── Maestro/             repositorios (Empresa, Tercero, Vehículo, Municipio, Catálogo)
│   ├── Solicitud/           SolicitudRepo (captura única → siembra remesa+manifiesto)
│   ├── Despacho/            ColaRepo (store-and-forward, payload XML)
│   ├── Rndc/                Diccionario, RndcClient (SOAP/XML), RenderVariables
│   └── vistas/              templates PHP (solicitud, cola, empresa, etc.)
├── cron/
│   └── retry_worker.php     worker store-and-forward (Fase 4)
├── sql/
│   ├── schema.sql           tablas base
│   ├── catalogos.sql        catálogos (empaque, carrocería, config vehicular, producto, errores RNDC)
│   ├── importar_productos_csv.php  importa catálogo de productos desde CSV oficial del RNDC
│   ├── municipios.sql       DIVIPOLA
│   ├── maestros.sql         tercero + vehículo
│   ├── catalogo_configuracion.sql  configuraciones de unidad de carga
│   └── migracion_v*.sql     migraciones incrementales (v2–v19)
├── .env.example
└── README.md
```

## Modelo de datos

- `solicitud_servicio` — captura única (siembra manifiesto + remesa)
- `manifiesto` — documento RNDC (ManifiestoCarga)
- `remesa` — documento RNDC (RemesaTerrestreCarga)
- `cola_envios` — bandeja store-and-forward (estado: pendiente / enviando / enviado / error)

Los campos llevan en comentarios SQL su variable oficial del RNDC entre `[corchetes]`.

## Puesta en marcha (local)

1. Copia `.env.example` a `.env` y completa los datos de la BD.
2. Crea la base de datos e importa, en orden, los SQL de `sql/`:

   | Orden | Archivo | Descripción |
   |-------|---------|-------------|
   | 1 | `schema.sql` | Tablas base (solicitud, remesa, manifiesto, cola) |
    | 2 | `municipios.sql` | Catálogo DIVIPOLA completo: 7845 registros (municipios + corregimientos) |
   | 3 | `maestros.sql` | Tercero y Vehículo |
   | 4 | `catalogos.sql` | Catálogos (empaque, carrocería, producto — estructura, errores RNDC) |
   | 5 | `catalogo_configuracion.sql` | Configuraciones de unidad de carga |
   | 6 | `importar_productos_csv.php` | **Importar catálogo de productos** desde CSV oficial del RNDC |
   | 7 | `migracion_v2.sql` | Primeros ajustes de esquema |
   | 8 | `migracion_v3.sql` | Campos de despacho, maestro_empresa, retenciones |
   | 9 | `migracion_v4.sql` | Vehículo: solo requeridos + remolque |
   | 10 | `migracion_v5.sql` | Quita marca/modelo del vehículo |
   | 11 | `migracion_v6.sql` | Estado `despachada` en solicitud |
   | 12 | `migracion_v7.sql` | Cola de envíos ligada a solicitud |
   | 13 | `migracion_v8.sql` | Tiempo pactado cargue |
   | 14 | `migracion_v9.sql` | Elimina `REMDUENOPOLIZA` (`tomador_poliza`) |
   | 15 | `migracion_v10.sql` | Consecutivos `consecutivo_remesa`, `consecutivo_manifiesto` en empresa |
   | 16 | `migracion_v11.sql` | `radicado_remesa` en empresa |
    | 17 | `migracion_v12.sql` | **Reestructura tabla `producto`** con columnas del CSV oficial RNDC |
    | 18 | `migracion_v13.sql` | `dueno_poliza` en solicitud_servicio y remesa |
    | 19 | `migracion_v14.sql` | `conductor_tipo_id`, `conductor_num_id` en vehiculo (conductor por defecto) |
    | 20 | `migracion_v15.sql` | `consecutivo_remesa`, `consecutivo_manifiesto` pasan a VARCHAR, se elimina `radicado_remesa` |
    | 21 | `migracion_v16.sql` | Renombra `titular_*` → `generador_*` en solicitud_servicio |
    | 22 | `migracion_v17.sql` | Elimina columnas innecesarias de solicitud_servicio |
    | 23 | `migracion_v18.sql` | Agrega `peso` a remesa |
    | 24 | `migracion_v19_municipios.sql` | Reemplaza datos de municipio con DIVIPOLA actualizado (7845 registros, incluye corregimientos) |

   > La migración v12 reemplaza la tabla `producto` completa. Después de ejecutarla,
   > corre el script `importar_productos_csv.php` para poblar los 3758 productos desde
   > el archivo `Maestro_Codificación de Productos_RNDC.csv`:
   > ```bash
   > php sql/importar_productos_csv.php /ruta/al/Maestro_Codificación_de_Productos_RNDC.csv
   > ```

3. Sirve la carpeta `public/`:
   ```bash
   php -S localhost:8000 -t public
   ```
4. Abre <http://localhost:8000> — el tablero debe mostrar "Base de datos conectada".

## Despliegue en Hostinger

1. En hPanel crea una **base de datos MySQL** y un usuario; anota host, nombre, usuario y clave.
2. Importa `sql/schema.sql` desde **phpMyAdmin**.
3. Sube los archivos por **File Manager / FTP** (p. ej. a `domains/TU_DOMINIO/light-tms`).
4. Apunta el **document root** del dominio/subdominio a la carpeta `public/`
   (hPanel > Avanzado > Document root). Así `src/`, `cron/` y `.env` quedan fuera de la web.
5. Crea el archivo `.env` en el servidor con las credenciales reales.
6. Configura un **Cron Job** (Fase 4):
   ```
   */15 * * * * /usr/bin/php /home/USUARIO/domains/TU_DOMINIO/light-tms/cron/retry_worker.php
   ```

## Estado

- [x] **Fase 1** — Esqueleto + esquema de BD
- [x] **Fase 2** — Cliente RNDC (SOAP/XML + `<acceso>`) — ver [docs/RNDC.md](docs/RNDC.md)
- [x] **Fase 3** — Flujo Solicitud de Servicio (captura única → siembra Manifiesto + Remesa)
- [x] **Fase 4** — Confirmar despacho → cola store-and-forward → worker de envío al RNDC
- [x] **Correcciones post-integración RNDC:**
  - Eliminado `REMDUENOPOLIZA` (`tomador_poliza`) del XML, BD, formulario y diccionario (v9)
  - Corregido error REM020: `CONSECUTIVOREMESA` ahora se envía desde `remesa.num_remesa`
  - Visor XML en cola.xml: muestra el XML enviado junto a la respuesta de RNDC en errores
  - Contadores auto-incrementales en empresa: `consecutivo_remesa`, `consecutivo_manifiesto` (v10) y `radicado_remesa` (v11)
- [x] **Normalización del XML remesa (v13):**
  - Variables renombradas a camelCase para coincidir con el RNDC (ej. `CODOPERACIONTRANSPORTE` → `codOperacionTransporte`)
  - Eliminado `CONSECUTIVOREMESA` duplicado (se envía solo `consecutivoRemesa`)
  - Agregados `pesoContenedorVacio` (constante `2100`), `duenoPoliza`, `codSedePropietario` al XML
  - `codSedeRemitente`/`codSedeDestinatario`/`codSedePropietario` se obtienen del campo `sede` del `tercero` maestro
  - Nuevo campo `dueno_poliza` en solicitud_servicio + remesa (v13)
- [x] **Ajustes despacho y auto-completado (v14):**
  - Remesa usa procesoid `3`
  - `valor_anticipo` movido del formulario inicial al de confirmar despacho
  - Conductor y propietario de la carga se auto-llenan desde el vehículo al seleccionar placa
  - Nuevos campos `conductor_tipo_id`, `conductor_num_id` en `vehiculo` (conductor por defecto)
  - Propietario de la carga = tenedor del vehículo
  - Conductor muestra nombre completo (nombres + apellidos)
  - Vista previa XML siempre disponible en cola
- [x] **Consecutivos como string (v15):**
  - `consecutivo_remesa`, `consecutivo_manifiesto` cambiados a VARCHAR(20) con formato `REM-00001`/`MAN-00001`
  - Auto-incremento ocurre al confirmar despacho, no al crear solicitud
  - `consecutivoRemesa` del XML se toma de `consecutivo_remesa`
  - Eliminado `radicado_remesa`
  - Consecutivo de solicitud auto-generado desde el `id` de la BD (empieza en 1)
- [x] **Catálogo de productos RNDC actualizado:**
  - Tabla `producto` reestructurada con 16 columnas del CSV oficial (v12)
  - 3758 productos cargados: CP (carga peligrosa), DP (desecho peligroso), DCRP (desagregación) y 00 (general)
  - Nuevos campos: `tipo`, `partida`, `clase_division`, `peligro_secundario`, `grupo_embalaje`, etc.
  - Script `importar_productos_csv.php` para importar desde CSV del RNDC (conversión W1252→UTF-8)
  - Autocompletado del formulario ahora muestra tipo (con badge de color), clase, peligrosidad y embalaje

> El cliente RNDC (`src/Rndc/RndcClient.php`) está verificado de extremo a extremo
> contra el servidor real del RNDC con credenciales válidas en `.env`.

### Fase 4 — Despacho y cola

1. Una solicitud en `borrador` se **confirma** (botón *Confirmar despacho*): se
   completan vehículo, conductor, propietario de la carga, citas/tiempos de
   cargue-descargue y responsables de pago.
2. Al confirmar se re-siembran remesa y manifiesto completos y se **encolan** en
   orden: tercero (11) → vehículo (12) → remesa (3) → manifiesto (4).
3. El **worker** (`cron/retry_worker.php`, o el botón *Procesar ahora* en la
   pantalla **Cola RNDC**) drena la cola con reintentos y backoff. Cuando el
   manifiesto es aceptado, la solicitud pasa a `despachada`.
4. **Interruptor de seguridad** `COLA_ENVIO_HABILITADO` (en `.env`):
   - `false` (por defecto) → *modo seguro*: arma y previsualiza el XML pero **no**
     lo envía. Útil para revisar el XML antes de escribir en el RNDC.
   - `true` → envío real al RNDC (según `RNDC_AMBIENTE`).
5. Cuando un envío falla, la vista `cola.xml` muestra **el XML enviado** junto con
   la respuesta de error del RNDC, facilitando la depuración.

### Consecutivos automáticos

Cada empresa tiene tres contadores que se auto-incrementan al crear una solicitud:

| Contador | Columna en `maestro_empresa` | Uso |
|----------|------------------------------|-----|
| Remesa | `consecutivo_remesa` | `num_remesa` en la remesa |
| Manifiesto | `consecutivo_manifiesto` | `num_manifiesto` en el manifiesto |
| Radicado remesa | `radicado_remesa` | `consecutivoRemesa` en el XML de RNDC |

Si el usuario deja vacío el campo "Consecutivo" en la solicitud, se genera automáticamente
desde el contador correspondiente de la empresa.

### Catálogo de productos

La tabla `producto` se alimenta del archivo CSV oficial del RNDC
(`Maestro_Codificación de Productos_RNDC.csv`). Contiene 3758 productos clasificados por tipo:

| Tipo | Significado | Cantidad |
|------|-------------|----------|
| `00` | Mercancía general (subpartidas arancelarias) | 1264 |
| `CP` | Carga peligrosa (clase ONU) | 2347 |
| `DP` | Desechos peligrosos | 107 |
| `DCRP` | Desagregación de corrientes de residuo peligroso | 40 |

Al seleccionar un producto en el formulario de solicitud, se muestra su tipo (con badge
de color), clase/división, peligro secundario y grupo de embalaje/envase.
