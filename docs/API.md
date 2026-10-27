# Referencia REST

Base: `https://api.xenios.com.ar/xenios-movil`. Todas las rutas requieren `X-API-KEY`; OPTIONS es preflight. JSON UTF-8 salvo multipart de imágenes y descarga de archivos. Fechas: YYYY-MM-DD salvo año/mes separados.

## Credenciales

- A: clave REST, sin sesión previa.
- U: A y `Authorization: Bearer <sesion>` o `X-Auth-Token`, usuario activo, roles y propiedad.
- S: A y `X-Service-Key`, exclusivamente entre servidores.
- B: A y sesión de usuario o puente web firmado. Consultar SEGURIDAD.md.

Estados comunes: 200 operación, 201 creación, 204 preflight, 400 datos, 401 credenciales, 403 permiso, 404 objeto/ruta, 405 método, 429 frecuencia, 500 interno, 503 dependencia/configuración. Los errores del router contienen `message`; el perímetro puede responder texto en 401/429/503. Ver expresiones de respuesta de cada handler para sus estados específicos.

## Rutas

| Método | Ruta | Acceso | Handler |
|---|---|---|---|
| GET | `/health` | A | [health](../xenios-movil/index.php#L1) |
| GET | `/app-version` | U | [handle_app_version](../xenios-movil/index.php#L120) |
| POST | `/auth/login` | A | [handle_login](../xenios-movil/index.php#L276) |
| POST | `/auth/doble-factor` | A | [handle_doble_factor](../xenios-movil/index.php#L327) |
| POST | `/auth/logout` | U | [handle_logout](../xenios-movil/index.php#L390) |
| POST | `/auth/recuperar-clave` | A | [handle_recuperar_clave_solicitud](../xenios-movil/index.php#L231) |
| GET | `/auth/recuperar-clave/validar` | A | [handle_recuperar_clave_validar](../xenios-movil/index.php#L246) |
| POST | `/auth/recuperar-clave/completar` | A | [handle_recuperar_clave_completar](../xenios-movil/index.php#L259) |
| GET | `/auth/me` | U | [handle_me](../xenios-movil/index.php#L399) |
| POST | `/auth/cambiar-clave` | U | [handle_cambiar_clave](../xenios-movil/index.php#L414) |
| POST | `/auth/avatar` | U | [handle_avatar_upload](../xenios-movil/index.php#L490) |
| DELETE | `/auth/avatar` | U | [handle_avatar_delete](../xenios-movil/index.php#L561) |
| GET | `/auth/avatar/imagen` | U | [handle_avatar_imagen](../xenios-movil/index.php#L545) |
| GET | `/fichadas` | U | [handle_fichadas](../xenios-movil/index.php#L585) |
| GET | `/autorizaciones-salida` | U | [handle_autorizaciones](../xenios-movil/index.php#L635) |
| POST | `/auth-salida/scan` | U | [handle_auth_salida_scan](../xenios-movil/lib/auth_salida_scan.php#L78) |
| GET | `/asistencia` | U | [handle_asistencia](../xenios-movil/index.php#L693) |
| GET | `/vacaciones` | U | [handle_vacaciones](../xenios-movil/index.php#L734) |
| POST | `/push/registrar-token` | U | [handle_push_registrar_token](../xenios-movil/index.php#L787) |
| POST | `/push/fichadas/evento` | U | [handle_push_fichadas_evento](../xenios-movil/index.php#L841) |
| POST | `/push/fichadas/procesar` | S | [handle_push_fichadas_procesar](../xenios-movil/index.php#L817) |
| GET | `/novedades` | U | [handle_novedades_list](../xenios-movil/index.php#L977) |
| GET | `/usuarios` | S | [handle_usuarios_list](../xenios-movil/index.php#L864) |
| GET | `/areas` | S | [handle_areas_list](../xenios-movil/index.php#L878) |
| GET | `/reportes/asistencia` | S | [handle_reporte_asistencia_xenios](../xenios-movil/index.php#L893) |
| GET | `/reportes/asistencia-detallada` | S | [handle_reporte_asistencia_detallada_xenios](../xenios-movil/index.php#L921) |
| GET | `/reportes/horas-trabajadas` | S | [handle_reporte_horas_trabajadas_xenios](../xenios-movil/index.php#L949) |
| GET | `/reportes/horas-extras-diarias` | S | [handle_reporte_horas_extras_diarias_xenios](../xenios-movil/index.php#L1083) |
| POST | `/horas-extras/motivos-pendientes/enviar-mail` | S | [handle_horas_extra_motivo_pendientes_mail](../xenios-movil/index.php#L165) |
| GET | `/notas-traslado` | B | [handle_notas_traslado_list](../xenios-movil/lib/notas_traslado.php#L1271) |
| POST | `/notas-traslado/scan` | B | [handle_notas_traslado_scan](../xenios-movil/lib/notas_traslado.php#L1559) |
| POST | `/novedades` | S | [handle_novedades_enviar](../xenios-movil/index.php#L1002) |
| GET | `/autorizaciones-salida/{id}/archivo` | U | [handle_autorizacion_archivo](../xenios-movil/index.php#L652) |
| GET | `/novedades/{id}` | U | [handle_novedades_routes](../xenios-movil/index.php#L1053) |
| POST | `/novedades/{id}/leida` | U | [handle_novedades_routes](../xenios-movil/index.php#L1053) |
| GET | `/notas-traslado/empresas-transporte` | B | [handle_notas_traslado_empresas_transporte](../xenios-movil/lib/notas_traslado.php#L1394) |
| POST | `/notas-traslado/{id}/transporte-externo` | B | [handle_notas_traslado_transporte_externo](../xenios-movil/lib/notas_traslado.php#L1434) |
| POST | `/notas-traslado/{id}/entregado` | B | [handle_notas_traslado_entregado](../xenios-movil/lib/notas_traslado.php#L1754) |
| POST | `/notas-traslado/{id}/recibido` | B | [handle_notas_traslado_recibido](../xenios-movil/lib/notas_traslado.php#L1826) |
| POST | `/notas-traslado/{id}/aprobar` | B | [handle_notas_traslado_aprobacion](../xenios-movil/lib/notas_traslado.php#L1892) |
| POST | `/notas-traslado/{id}/regenerar-pdf` | B | [handle_notas_traslado_regenerar_pdf](../xenios-movil/lib/notas_traslado.php#L1965) |
| POST | `/notas-traslado/{id}/rechazar` | B | [handle_notas_traslado_aprobacion](../xenios-movil/lib/notas_traslado.php#L1892) |
| GET | `/notas-traslado/{id}/documento` | B | [handle_notas_traslado_documento](../xenios-movil/lib/notas_traslado.php#L2009) |
| GET | `/notas-traslado/{id}/documento-firmado` | B | [handle_notas_traslado_documento_firmado](../xenios-movil/lib/notas_traslado.php#L2092) |
| GET | `/inout-seguridad` | U | [handle_inout_seguridad](../xenios-movil/lib/inout_seguridad.php#L292) |
| POST | `/inout-seguridad` | U | [handle_inout_seguridad](../xenios-movil/lib/inout_seguridad.php#L292) |
| GET | `/inout-seguridad/opciones` | U | [handle_inout_seguridad](../xenios-movil/lib/inout_seguridad.php#L292) |
| GET | `/inout-seguridad/personal` | U | [handle_inout_seguridad](../xenios-movil/lib/inout_seguridad.php#L292) |
| GET | `/inout-seguridad/presentes` | U | [handle_inout_seguridad](../xenios-movil/lib/inout_seguridad.php#L292) |
| GET | `/inout-seguridad/notificaciones` | B | [handle_inout_seguridad](../xenios-movil/lib/inout_seguridad.php#L292) |
| POST | `/inout-seguridad/leida` | B | [handle_inout_seguridad](../xenios-movil/lib/inout_seguridad.php#L292) |
| GET | `/inout-seguridad/historial/{id}` | B | [handle_inout_seguridad](../xenios-movil/lib/inout_seguridad.php#L292) |

## Salud

GET `/health` responde `ok`, `service` (`xenios-movil`) y `notasTrasladoVersion`. No conecta a MySQL; un 200 confirma el router y la clave REST, no la salud de todas las dependencias.

## Contratos por handler

Campos detectados directamente en el handler; no todos son obligatorios ni aplican a todas las subrutas de un handler compartido. Las respuestas son expresiones PHP del código, no respuestas observadas en producción. Las estructuras calculadas por funciones delegadas se consultan en la implementación enlazada y en INVENTARIO.md.

### handle_app_version

Fuente: [xenios-movil/index.php:120](../xenios-movil/index.php#L120).

Controles locales: `require_auth`.

Respuestas declaradas:

```php
json_response([
        'android' => [
            'latestVersion' => $androidLatestVersion,
            'storeUrl' => $androidStoreUrl,
        ],
        'ios' => [
            'latestVersion' => (string) ($versionConfig['ios_latest_version'] ?? ''),
            'storeUrl' => (string) ($versionConfig['ios_store_url'] ?? ''),
        ],
    ], 200, $config);
```

### handle_login

Fuente: [xenios-movil/index.php:276](../xenios-movil/index.php#L276).

JSON/formulario: `clave`, `correo`.

Respuestas declaradas:

```php
json_response(['message' => 'El inicio de sesión no está configurado. Contactá al administrador.'], 503, $config);
json_response(['message' => 'Correo y contraseña requeridos'], 400, $config);
json_response(['message' => 'Usuario o contraseña incorrectos'], 401, $config);
json_response(['message' => 'Usuario deshabilitado'], 403, $config);
json_response(doble_factor_error($result, 'No se pudo iniciar la verificación. Volvé a ingresar.'), $result['status'], $config);
json_response($result['data'], 200, $config);
json_response(['message' => 'La verificación de doble factor no está disponible. Intentá más tarde.'], 503, $config);
```

### handle_doble_factor

Fuente: [xenios-movil/index.php:327](../xenios-movil/index.php#L327).

JSON/formulario: `codigo`, `desafio`.

Respuestas declaradas:

```php
json_response(['message' => 'Ingresá el código de seis dígitos del autenticador.'], 400, $config);
json_response(doble_factor_error($result, 'Verificación inválida. Volvé a iniciar sesión.'), $result['status'], $config);
json_response(['message' => 'La configuración del usuario cambió. Volvé a iniciar sesión.'], 401, $config);
json_response(['message' => 'La verificación de doble factor no está disponible. Intentá más tarde.'], 503, $config);
```

### handle_logout

Fuente: [xenios-movil/index.php:390](../xenios-movil/index.php#L390).

Controles locales: `require_auth`.

Respuestas declaradas:

```php
json_response(['ok' => true], 200, $config);
```

### handle_recuperar_clave_solicitud

Fuente: [xenios-movil/index.php:231](../xenios-movil/index.php#L231).

JSON/formulario: `correo`.

Respuestas declaradas:

```php
json_response(['message' => 'Ingresá un correo electrónico válido'], 400, $config);
json_response($result, $code, $config);
```

### handle_recuperar_clave_validar

Fuente: [xenios-movil/index.php:246](../xenios-movil/index.php#L246).

Query: `token`.

Respuestas declaradas:

```php
json_response(['ok' => false, 'message' => 'Token requerido'], 400, $config);
json_response($result, ($result['ok'] ?? false) ? 200 : 400, $config);
```

### handle_recuperar_clave_completar

Fuente: [xenios-movil/index.php:259](../xenios-movil/index.php#L259).

JSON/formulario: `confirmacion`, `nuevaClave`, `token`.

Respuestas declaradas:

```php
json_response(['message' => 'Token requerido'], 400, $config);
json_response($result, ($result['ok'] ?? false) ? 200 : 400, $config);
```

### handle_me

Fuente: [xenios-movil/index.php:399](../xenios-movil/index.php#L399).

Controles locales: `require_auth`.

Respuestas declaradas:

```php
json_response([
        'usuario' => build_usuario_response(
            $auth['user'],
            $auth['roles'],
            $personal,
            $config
        ),
    ], 200, $config);
```

### handle_cambiar_clave

Fuente: [xenios-movil/index.php:414](../xenios-movil/index.php#L414).

JSON/formulario: `claveActual`, `confirmacion`, `nuevaClave`.

Controles locales: `require_auth`.

Respuestas declaradas:

```php
json_response(['message' => 'La contraseña actual es requerida'], 400, $config);
json_response(['message' => 'La contraseña actual no es correcta'], 400, $config);
json_response(['message' => 'Las contraseñas no coinciden'], 400, $config);
json_response(['message' => $policyError], 400, $config);
json_response(['ok' => true], 200, $config);
```

### handle_avatar_upload

Fuente: [xenios-movil/index.php:490](../xenios-movil/index.php#L490).

Multipart: `archivo`.

Controles locales: `require_auth`.

Respuestas declaradas:

```php
json_response(['message' => 'No se recibió una imagen válida'], 400, $config);
json_response(['message' => 'No se pudo guardar la imagen.'], 500, $config);
json_response(['message' => 'No se recibió el archivo correctamente (error ' . $err . ').'], 400, $config);
json_response(['message' => 'La imagen es demasiado grande (máximo 100 KB).'], 400, $config);
json_response([
            'message' => 'Imagen inválida. Use JPG o PNG.',
        ], 400, $config);
json_response([
        'ok' => true,
        'usuario' => build_usuario_response(
            $auth['user'],
            $auth['roles'],
            $personal,
            $config
        ),
    ], 200, $config);
```

### handle_avatar_delete

Fuente: [xenios-movil/index.php:561](../xenios-movil/index.php#L561).

Controles locales: `require_auth`.

Respuestas declaradas:

```php
json_response([
        'ok' => true,
        'usuario' => build_usuario_response(
            $auth['user'],
            $auth['roles'],
            $personal,
            $config
        ),
    ], 200, $config);
```

### handle_avatar_imagen

Fuente: [xenios-movil/index.php:545](../xenios-movil/index.php#L545).

Controles locales: `require_auth`.


### handle_fichadas

Fuente: [xenios-movil/index.php:585](../xenios-movil/index.php#L585).

Query: `fecha`.

Controles locales: `require_auth`, `require_empleado_role`.

Respuestas declaradas:

```php
json_response(['message' => 'Parámetro fecha inválido (YYYY-MM-DD)'], 400, $config);
json_response(['fichadas' => []], 200, $config);
json_response(['fichadas' => $fichadas], 200, $config);
```

### handle_autorizaciones

Fuente: [xenios-movil/index.php:635](../xenios-movil/index.php#L635).

Controles locales: `require_auth`, `require_empleado_role`.

Respuestas declaradas:

```php
json_response(['autorizaciones' => []], 200, $config);
json_response(['autorizaciones' => $autorizaciones], 200, $config);
```

### handle_auth_salida_scan

Fuente: [xenios-movil/lib/auth_salida_scan.php:78](../xenios-movil/lib/auth_salida_scan.php#L78).

JSON/formulario: `authSalidaId`, `key`, `qr`.

Controles locales: `require_auth`, `require_auth_salida_scan_role`.

Respuestas declaradas:

```php
json_response(['ok' => false, 'message' => 'La autorización de salida ya registra salida y regreso efectivos'], 409, $config);
json_response([
            'ok' => true,
            'accion' => 'salida',
            'message' => 'Se registró la Salida con éxito!',
            'fechaHora' => $nowSql,
        ], 200, $config);
json_response([
            'ok' => false,
            'message' => 'No es posible registrar la Hora de Regreso Efectiva ya que no se ha superado el tiempo mínimo para hacerlo. Vuelva a intentarlo más tarde',
        ], 409, $config);
json_response([
        'ok' => true,
        'accion' => 'regreso',
        'message' => 'Se registró el Regreso con éxito!',
        'fechaHora' => $nowSql,
    ], 200, $config);
```

### handle_asistencia

Fuente: [xenios-movil/index.php:693](../xenios-movil/index.php#L693).

Query: `desde`, `hasta`, `personalId`.

Controles locales: `require_auth`, `require_empleado_role`.

Respuestas declaradas:

```php
json_response(['message' => 'Parámetros desde/hasta inválidos (YYYY-MM-DD)'], 400, $config);
json_response(['message' => 'No es posible obtener la información requerida en estos momentos. Si el problema persiste, contacte a la Gerencia de Sistemas e informe el error: NSIPID'], 400, $config);
json_response([
            'desde' => $desde,
            'hasta' => $hasta,
            'dias' => [],
            'resumen' => horas_trabajadas_build_resumen($pdo, ['Id' => '', 'Legajo' => 0], $desde, $hasta, $config),
        ], 200, $config);
json_response(['message' => 'No tiene permisos para consultar ese empleado'], 403, $config);
json_response([
        'desde' => $desde,
        'hasta' => $hasta,
        'dias' => asistencia_build_calendario($pdo, $personal, $desde, $hasta),
        'resumen' => horas_trabajadas_build_resumen($pdo, $personal, $desde, $hasta, $config),
    ], 200, $config);
```

### handle_vacaciones

Fuente: [xenios-movil/index.php:734](../xenios-movil/index.php#L734).

Query: `anio`.

Controles locales: `require_auth`, `require_empleado_role`.

Respuestas declaradas:

```php
json_response(['message' => 'Parámetro anio inválido (YYYY)'], 400, $config);
json_response([
            'anio' => $anio,
            'aniosDisponibles' => [(int) date('Y')],
            'fechaAlta' => null,
            'antiguedadAnios' => 0,
            'diasCorrespondientes' => 0,
            'diasConsumidos' => 0,
            'diasRestantes' => 0,
            'consumidas' => [],
        ], 200, $config);
json_response(['message' => $e->getMessage()], 400, $config);
json_response($payload, 200, $config);
```

### handle_push_registrar_token

Fuente: [xenios-movil/index.php:787](../xenios-movil/index.php#L787).

JSON/formulario: `plataforma`, `pushToken`.

Controles locales: `novedades_require_tables`, `require_auth`.

Respuestas declaradas:

```php
json_response(['message' => 'pushToken requerido'], 400, $config);
json_response(['message' => 'Token nativo FCM/APNs inválido'], 400, $config);
json_response(['ok' => true], 200, $config);
```

### handle_push_fichadas_evento

Fuente: [xenios-movil/index.php:841](../xenios-movil/index.php#L841).

JSON/formulario: `evento`, `fichadaId`.

Controles locales: `require_any_role`, `require_auth`.

Respuestas declaradas:

```php
json_response(['message' => 'Evento de fichada invalido'], 400, $config);
json_response(['ok' => $ok], 200, $config);
```

### handle_push_fichadas_procesar

Fuente: [xenios-movil/index.php:817](../xenios-movil/index.php#L817).

JSON/formulario: `baselineOnly`, `limit`, `lookbackHours`.

Respuestas declaradas:

```php
json_response(['message' => 'Error de seguridad AK-NOT_FOUND'], 401, $config);
json_response(fichadas_push_procesar($pdo, $lookbackHours, $limit, $baselineOnly), 200, $config);
json_response([
            'message' => 'No se pudo procesar push de fichadas',
        ], 500, $config);
```

### handle_novedades_list

Fuente: [xenios-movil/index.php:977](../xenios-movil/index.php#L977).

Query: `anio`, `historial`, `mes`.

Controles locales: `novedades_require_tables`, `require_any_role`, `require_auth`.

Respuestas declaradas:

```php
json_response(['message' => 'Seleccioná un mes y año válidos.'], 422, $config);
json_response(novedades_historial_mes($pdo, $uid, $anio, $mes), 200, $config);
json_response([
        'novedades' => novedades_listar_usuario($pdo, $uid),
        'noLeidas' => novedades_contar_no_leidas($pdo, $uid),
    ], 200, $config);
```

### handle_usuarios_list

Fuente: [xenios-movil/index.php:864](../xenios-movil/index.php#L864).

Controles locales: `require_auth`, `require_gestion_role`.

Respuestas declaradas:

```php
json_response(['usuarios' => usuarios_listar_activos($pdo)], 200, $config);
json_response(['usuarios' => usuarios_listar_activos($pdo)], 200, $config);
```

### handle_areas_list

Fuente: [xenios-movil/index.php:878](../xenios-movil/index.php#L878).

Controles locales: `require_auth`, `require_gestion_role`.

Respuestas declaradas:

```php
json_response(['areas' => areas_listar_activas($pdo)], 200, $config);
json_response(['areas' => areas_listar_activas($pdo)], 200, $config);
```

### handle_reporte_asistencia_xenios

Fuente: [xenios-movil/index.php:893](../xenios-movil/index.php#L893).

Query: `month`, `sede`, `year`.

Respuestas declaradas:

```php
json_response(['message' => 'Error de seguridad AK-NOT_FOUND'], 401, $config);
json_response(['message' => 'year/month inválidos'], 400, $config);
json_response([
        'reporte' => reportes_xenios_asistencia(
            $pdo,
            $year,
            $month,
            $sede !== '' ? $sede : null,
            reportes_xenios_area_ids_desde_query()
        ),
    ], 200, $config);
```

### handle_reporte_asistencia_detallada_xenios

Fuente: [xenios-movil/index.php:921](../xenios-movil/index.php#L921).

Query: `month`, `sede`, `year`.

Respuestas declaradas:

```php
json_response(['message' => 'Error de seguridad AK-NOT_FOUND'], 401, $config);
json_response(['message' => 'year/month inválidos'], 400, $config);
json_response([
        'reporte' => reportes_xenios_asistencia_detallada(
            $pdo,
            $year,
            $month,
            $sede !== '' ? $sede : null,
            reportes_xenios_area_ids_desde_query()
        ),
    ], 200, $config);
```

### handle_reporte_horas_trabajadas_xenios

Fuente: [xenios-movil/index.php:949](../xenios-movil/index.php#L949).

Query: `fechaFin`, `fechaInicio`, `tipoContratacionId`.

Respuestas declaradas:

```php
json_response(['message' => 'Error de seguridad AK-NOT_FOUND'], 401, $config);
json_response(['message' => 'fechaInicio y fechaFin son requeridos'], 400, $config);
json_response([
        'reporte' => reportes_xenios_horas_trabajadas(
            $pdo,
            $fechaInicio,
            $fechaFin,
            $tipoContratacionId,
            reportes_xenios_area_ids_desde_query(),
            $config
        ),
    ], 200, $config);
```

### handle_reporte_horas_extras_diarias_xenios

Fuente: [xenios-movil/index.php:1083](../xenios-movil/index.php#L1083).

Query: `fechaFin`, `fechaInicio`, `tipoContratacionId`.

Respuestas declaradas:

```php
json_response(['message' => 'Error de seguridad AK-NOT_FOUND'], 401, $config);
json_response(['message' => 'Periodo invalido'], 400, $config);
json_response(['reporte' => ['empleados' => horas_extra_motivo_calcular_diarias(
        $pdo, $config, $desde, $hasta,
        isset($_GET['tipoContratacionId']) ? (int) $_GET['tipoContratacionId'] : null,
        reportes_xenios_area_ids_desde_query()
    )]], 200, $config);
```

### handle_horas_extra_motivo_pendientes_mail

Fuente: [xenios-movil/index.php:165](../xenios-movil/index.php#L165).

Respuestas declaradas:

```php
json_response($resultado, 200, $config);
json_response([
            'ok' => false,
            'message' => 'Error interno al enviar notificaciones de horas extras pendientes.',
        ], 500, $config);
```

### handle_notas_traslado_list

Fuente: [xenios-movil/lib/notas_traslado.php:1271](../xenios-movil/lib/notas_traslado.php#L1271).

Query: `desde`, `hasta`, `mostrarTodos`, `resumenPendientes`.

Controles locales: `notas_traslado_require_auth`, `notas_traslado_require_role`.

Respuestas declaradas:

```php
json_response(['message' => 'Parámetros desde/hasta inválidos (YYYY-MM-DD)'], 400, $config);
json_response(['message' => 'La fecha desde no puede ser posterior a hasta'], 400, $config);
json_response(['pendientes' => $pendientes], 200, $config);
json_response(['notas' => array_map(static fn ($row) => notas_traslado_map_row($row, $context), $rows)], 200, $config);
```

### handle_notas_traslado_scan

Fuente: [xenios-movil/lib/notas_traslado.php:1559](../xenios-movil/lib/notas_traslado.php#L1559).

JSON/formulario: `id`, `key`, `notaTrasladoId`, `qr`.

Controles locales: `notas_traslado_require_auth`, `notas_traslado_require_role`.

Respuestas declaradas:

```php
json_response(['ok' => false, 'message' => 'Nota de Traslado no válida'], 400, $config);
json_response(['ok' => false, 'message' => 'Nota de Traslado no encontrada'], 404, $config);
json_response([
                'ok' => false,
                'message' => 'La Nota de Traslado requiere autorización antes de continuar con el proceso.',
            ], 409, $config);
json_response([
                    'ok' => false,
                    'message' => 'La Nota de Traslado ya fue chequeada por Seguridad.',
                ], 409, $config);
json_response(['ok' => true, 'accion' => 'chequeo_seguridad', 'message' => 'Chequeo de Seguridad registrado correctamente.'], 200, $config);
json_response(['ok' => true, 'accion' => 'recepcion_despacho', 'message' => 'Recepción en despacho registrada correctamente.'], 200, $config);
json_response([
                    'ok' => false,
                    'message' => 'La Nota de Traslado que intentas asignarte, ya está asignada a tu nombre.',
                ], 409, $config);
json_response(['ok' => true, 'accion' => 'asignacion_transporte', 'message' => 'Traslado asignado correctamente.'], 200, $config);
json_response(['ok' => true, 'accion' => 'recepcion_despacho_destino', 'message' => 'Recepción de despacho destino registrada correctamente.'], 200, $config);
json_response(['ok' => true, 'accion' => 'recepcion_final', 'message' => 'Traslado recibido correctamente.'], 200, $config);
json_response(['ok' => false, 'message' => 'La operación que intenta realizar no está permitida o no es inválida.'], 409, $config);
```

### handle_novedades_enviar

Fuente: [xenios-movil/index.php:1002](../xenios-movil/index.php#L1002).

JSON/formulario: `cuerpo`, `enviadoPorUsuarioId`, `from`, `titulo`, `usuarioIds`.

Controles locales: `novedades_require_tables`.

Respuestas declaradas:

```php
json_response(['message' => 'Error de seguridad AK-NOT_FOUND'], 401, $config);
json_response(['message' => 'titulo y cuerpo son requeridos'], 400, $config);
json_response(['message' => 'from (GUID del remitente) es requerido'], 400, $config);
json_response(['message' => 'Remitente (from) no encontrado o inactivo'], 400, $config);
json_response(['message' => 'No hay destinatarios para la novedad'], 400, $config);
json_response(['ok' => true] + $result, 201, $config);
json_response([
            'message' => 'No se pudo registrar la novedad',
        ], 500, $config);
```

### handle_autorizacion_archivo

Fuente: [xenios-movil/index.php:652](../xenios-movil/index.php#L652).

Controles locales: `require_auth`, `require_empleado_role`.

Respuestas declaradas:

```php
json_response(['message' => 'Ruta no encontrada'], 404, $config);
json_response(['message' => 'Sin autorizaciones asociadas'], 404, $config);
json_response(['message' => 'Archivo no encontrado'], 404, $config);
json_response(['message' => 'El archivo no está en el servidor'], 404, $config);
```

### handle_novedades_routes

Fuente: [xenios-movil/index.php:1053](../xenios-movil/index.php#L1053).

Controles locales: `novedades_require_tables`, `require_any_role`, `require_auth`.

Respuestas declaradas:

```php
json_response(['message' => 'Novedad no encontrada'], 404, $config);
json_response(['novedad' => $item], 200, $config);
json_response(['ok' => $ok], $ok ? 200 : 404, $config);
```

### handle_notas_traslado_empresas_transporte

Fuente: [xenios-movil/lib/notas_traslado.php:1394](../xenios-movil/lib/notas_traslado.php#L1394).

Controles locales: `notas_traslado_require_auth`, `notas_traslado_require_role`.

Respuestas declaradas:

```php
json_response(['message' => 'Sin permisos para consultar empresas de transporte.'], 403, $config);
json_response(['empresas' => $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []], 200, $config);
```

### handle_notas_traslado_transporte_externo

Fuente: [xenios-movil/lib/notas_traslado.php:1434](../xenios-movil/lib/notas_traslado.php#L1434).

JSON/formulario: `apellido`, `dni`, `empresaId`, `nombre`, `transporteExternoApellido`, `transporteExternoDni`, `transporteExternoEmpresaId`, `transporteExternoNombre`.

Controles locales: `notas_traslado_require_auth`, `notas_traslado_require_role`.

Respuestas declaradas:

```php
json_response(['message' => 'Sin permisos para asignar transporte externo.'], 403, $config);
json_response(['message' => 'Debe seleccionar empresa y cargar nombre, apellido y DNI del transportista.'], 400, $config);
json_response(['message' => 'El DNI del transportista no es válido.'], 400, $config);
json_response(['message' => 'La empresa seleccionada no es un proveedor de servicio de transporte activo.'], 400, $config);
json_response(['message' => 'Nota de Traslado no encontrada.'], 404, $config);
json_response(['message' => 'No tiene permisos sobre esta nota.'], 403, $config);
json_response(['message' => 'La nota debe estar en Recepción Despacho para asignar transporte externo.'], 409, $config);
json_response(['ok' => true, 'message' => 'Transporte externo asignado correctamente.'], 200, $config);
```

### handle_notas_traslado_entregado

Fuente: [xenios-movil/lib/notas_traslado.php:1754](../xenios-movil/lib/notas_traslado.php#L1754).

Controles locales: `notas_traslado_require_auth`, `notas_traslado_require_role`.

Respuestas declaradas:

```php
json_response(['message' => 'Sin permisos para entregar traslados'], 403, $config);
json_response(['message' => 'Nota de Traslado no encontrada'], 404, $config);
json_response(['message' => 'El traslado no puede marcarse como entregado.'], 409, $config);
json_response(['ok' => true, 'message' => 'Entrega registrada correctamente.'], 200, $config);
```

### handle_notas_traslado_recibido

Fuente: [xenios-movil/lib/notas_traslado.php:1826](../xenios-movil/lib/notas_traslado.php#L1826).

Controles locales: `notas_traslado_require_auth`, `notas_traslado_require_role`.

Respuestas declaradas:

```php
json_response(['message' => 'Nota de Traslado no encontrada'], 404, $config);
json_response(['message' => 'El traslado no puede marcarse como recibido.'], 409, $config);
json_response(['ok' => true, 'message' => 'Recepción registrada correctamente.'], 200, $config);
```

### handle_notas_traslado_aprobacion

Fuente: [xenios-movil/lib/notas_traslado.php:1892](../xenios-movil/lib/notas_traslado.php#L1892).

Controles locales: `notas_traslado_require_auth`, `notas_traslado_require_role`.

Respuestas declaradas:

```php
json_response(['message' => 'Sin permisos para aprobar notas de traslado'], 403, $config);
json_response(['message' => 'Nota de Traslado no encontrada'], 404, $config);
json_response(['message' => 'La nota no se encuentra pendiente de aprobación.'], 409, $config);
json_response([
            'ok' => true,
            'message' => $aprobar
                ? ($pdfSize > 0 ? 'Nota de Traslado aprobada correctamente.' : 'Nota de Traslado aprobada, pero no se pudo generar el PDF.')
                : 'Nota de Traslado rechazada correctamente.',
            'pdfGenerado' => !$aprobar || $pdfSize > 0,
            'pdfSize' => $pdfSize,
        ], 200, $config);
```

### handle_notas_traslado_regenerar_pdf

Fuente: [xenios-movil/lib/notas_traslado.php:1965](../xenios-movil/lib/notas_traslado.php#L1965).

Controles locales: `notas_traslado_require_auth`, `notas_traslado_require_role`.

Respuestas declaradas:

```php
json_response(['message' => 'Nota de Traslado no válida'], 400, $config);
json_response(['message' => 'Nota de Traslado no encontrada'], 404, $config);
json_response(['message' => 'Sin permisos para visualizar la Nota de Traslado'], 403, $config);
json_response(['message' => 'La Nota de Traslado no se encuentra en un estado válido para generar el PDF.'], 409, $config);
json_response(['ok' => true, 'message' => 'PDF generado correctamente.', 'pdfSize' => strlen($pdf)], 200, $config);
json_response(['message' => 'No se pudo generar el PDF de la Nota de Traslado.'], 500, $config);
```

### handle_notas_traslado_documento

Fuente: [xenios-movil/lib/notas_traslado.php:2009](../xenios-movil/lib/notas_traslado.php#L2009).

Controles locales: `notas_traslado_require_auth`, `notas_traslado_require_role`.

Respuestas declaradas:

```php
json_response(['message' => 'Nota de Traslado no válida'], 400, $config);
json_response(['message' => 'Nota de Traslado no encontrada'], 404, $config);
json_response(['message' => 'Sin permisos para visualizar la Nota de Traslado'], 403, $config);
json_response(['message' => 'La Nota de Traslado se encuentra anulada'], 409, $config);
json_response(['message' => 'La Nota de Traslado fue rechazada.'], 409, $config);
json_response(['message' => 'Solo un usuario aprobador puede visualizar el PDF pendiente de aprobación.'], 403, $config);
json_response(['message' => 'No se pudo generar el PDF de la Nota de Traslado desde la API.'], 500, $config);
```

### handle_notas_traslado_documento_firmado

Fuente: [xenios-movil/lib/notas_traslado.php:2092](../xenios-movil/lib/notas_traslado.php#L2092).

Controles locales: `notas_traslado_require_auth`, `notas_traslado_require_role`.

Respuestas declaradas:

```php
json_response(['message' => 'Nota de Traslado no válida'], 400, $config);
json_response(['message' => 'Nota de Traslado no encontrada'], 404, $config);
json_response(['message' => 'Sin permisos para visualizar la copia firmada'], 403, $config);
json_response(['message' => 'La Nota de Traslado no tiene una copia firmada válida'], 404, $config);
json_response(['message' => 'No se encontró la copia firmada'], 404, $config);
json_response(['message' => 'Tipo de copia firmada no permitido'], 400, $config);
```

### handle_inout_seguridad

Fuente: [xenios-movil/lib/inout_seguridad.php:292](../xenios-movil/lib/inout_seguridad.php#L292).

Query: `fecha`, `legajo`, `pagina`, `q`, `recurso`, `tipoPersona`.

JSON/formulario: `empresaId`, `empresaOtra`, `horario`, `legajo`, `legajos`, `motivoId`, `movimiento`, `nota`, `patentesLegajos`, `personas`, `tipoPersona`, `transaccionId`, `usuarioVisitaId`, `usuariosVisitaIds`.

Controles locales: `inout_require_permission`, `require_auth`, `require_auth_or_web_bridge`.

Respuestas declaradas:

```php
json_response(['message' => 'Aviso de visita no encontrado.'], 404, $config);
json_response(['novedad' => $items[0]], 200, $config);
json_response($result, 200, $config);
json_response(['ok' => novedades_marcar_leida($pdo, $uid, $id)], 200, $config);
json_response(['ok' => true], 200, $config);
json_response(['personas' => $personas, 'sede' => $sede], 200, $config);
json_response(['opciones' => $stmt->fetchAll(), 'sede' => $sede], 200, $config);
json_response(['personal' => $rows[0]], 200, $config);
json_response(['registros' => $stmt->fetchAll(), 'total' => (int) $count->fetchColumn(), 'pagina' => $page, 'sede' => $sede, 'fecha' => $fecha], 200, $config);
json_response(['ok' => true, 'cantidad' => (int) $existing['Cantidad'], 'transaccionId' => $transaction, 'reintento' => true], 200, $config);
json_response(['ok' => true, 'cantidad' => $count, 'transaccionId' => $transaction, 'reintento' => true], 200, $config);
json_response(['ok' => true, 'cantidad' => count($personas), 'transaccionId' => $transaction, 'notificacionPendiente' => !$push['ok']], 201, $config);
```

## Funciones fuera del router

| Método/ruta | Entrada | Acceso | Respuesta |
|---|---|---|---|
| GET `/` | Ninguna | Público | JSON `{"service":"Xenios API"}` |
| GET/POST `/xenios-movil/reset-password/` | GET token; POST token, clave, clave2 | Token temporal | HTML |
| POST `/actions/uploadAvatar/upload.php` | Multipart archivo JPG/PNG hasta 100 KB | X-Service-Key | Texto `Archivo recibido y guardado como: {nombre}` |
| POST `/actions/uploadImgPersonal/upload.php` | Multipart archivo JPG/PNG hasta 512 KB | X-Service-Key | Mismo contrato |
| POST `/actions/uploadAuthSalida/upload.php` | Multipart archivo PDF hasta 10 MB | X-Service-Key | Mismo contrato |
| POST `/actions/alicuotas/update.php` | Ver contrato específico en FUNCIONALIDADES.md | X-Service-Key y X-API-KEY | JSON |
| GET `/avatar/{nombre}` | Nombre generado | X-Service-Key | Imagen |
| GET `/personal/{nombre}` | Nombre generado | X-Service-Key | Imagen |
| GET `/authsalida/{nombre}` | Nombre generado | X-Service-Key | PDF |

Los dispositivos deben usar `/auth/avatar/imagen` y `/autorizaciones-salida/{id}/archivo`, que verifican propiedad; no reciben la clave de servicio.

## Ejemplos

```http
POST /xenios-movil/auth/login HTTP/1.1
Host: api.xenios.com.ar
X-API-KEY: <clave-cliente>
Content-Type: application/json

{"correo":"usuario@example.com","clave":"<contraseña>"}
```

Si corresponde MFA, continuar con POST `/auth/doble-factor` y los campos `desafio` y `codigo`. Usar la sesión obtenida de esta forma:

```http
GET /xenios-movil/auth/me HTTP/1.1
Host: api.xenios.com.ar
X-API-KEY: <clave-cliente>
Authorization: Bearer <sesion>
```
