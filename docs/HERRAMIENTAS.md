# Herramientas y tareas programadas

Ejecutar únicamente por consola con el entorno privado configurado. Apache bloquea `xenios-movil/tools/`; algunas pruebas envían mensajes o consultan datos reales, por lo que deben ejecutarse con datos y destinatarios de prueba.

## Procesos operativos

| Archivo en xenios-movil/tools | Uso y efecto |
|---|---|
| procesar_fichadas_push.php | `php procesar_fichadas_push.php [horas=24] [limite=200] [--baseline]`; detecta fichadas y envía push; baseline inicializa seguimiento |
| procesar_inout_push.php | Procesa avisos pendientes; usa bloqueo MySQL para impedir dos workers simultáneos |
| ../procesar_alertas_push.php | `php procesar_alertas_push.php --desde=AAAA-MM-DDTHH:MM:SS [--dry-run]`; reintenta visitas/novedades sin lectura, comparte bloqueo con worker InOut |
| enviar_auth_salida_diario.php | `php enviar_auth_salida_diario.php [--date=YYYY-MM-DD] [--dry-run]`; reporte de autorizaciones, bloqueo por archivo |
| enviar_mails_motivos_he_pendientes.php | Envía correos de motivos de horas extra pendientes |
| enviar_novedad_email.php | `php enviar_novedad_email.php correo titulo cuerpo [--verificar]`; crea novedad y envía push al usuario activo |
| enviar_novedad_legajo.php | Crea/envía novedad dirigida a un legajo; consultar argumentos al inicio del archivo |
| run_fichadas_push.ps1 | Wrapper Windows: LookbackHours=2 y Limit=100; localiza PHP mediante XENIOS_PHP_EXE o PATH y genera log |
| run_auth_salida_daily_report.ps1 | Wrapper Windows del reporte diario; configura PHP y logs locales |

No se instaló cron, timer ni tarea de Windows en esta entrega. Definir periodicidad de negocio, evitar solapamientos, monitorear fallos/retrasos y rotar logs. El sonido persistente requiere validar el binario en teléfono físico.

## Diagnóstico

| Archivo | Propósito |
|---|---|
| diagnosticar_rendimiento.php | Diagnóstico de tiempos y consultas |
| ver_tokens_legajo.php | Inspecciona tokens de un legajo; salida sensible, solo consola administrativa |
| verificar_push_nativo.php | Comprueba configuración y disponibilidad de push nativo |

## Pruebas existentes

| Archivo | Cobertura |
|---|---|
| test_calculo_api_horas.php | Cálculo común de horas |
| test_dashboard_pendientes.php | Pendientes del dashboard |
| test_doble_factor.php | Doble factor y validación de sesión |
| test_horas_nocturnas_periodo.php | Horas nocturnas en el período |
| test_horas_trabajadas_feriados.php | Jornadas y feriados |
| test_inout_seguridad.php | Ingresos/salidas, varios legajos, validaciones y persistencia |
| test_jornada_salida_autorizada.php | Cómputo con salida autorizada |
| test_password_reset_smtp.php | Recuperación y SMTP; puede enviar correo |
| test_push_visitas.php | Avisos de visita y push; puede enviar notificaciones |
| test_registro_personal_jornadas.php | Reconstrucción de jornadas |
| test_reportes_empleados_periodo.php | Reportes de empleados por período |
| test_empresa_logo.php | Marca y aislamiento por propietario en PDF de notas de traslado; usa SQLite temporal y no consulta datos reales |

Revisar dependencias y argumentos antes de ejecutar. Una prueba no ejecutada no se considera aprobada. Las pruebas que requieren base, SMTP o dispositivo no se sustituyen por lint.

## Documentación mantenible

`python tools/generate_docs.py` regenera API.md, CONFIGURACION.md e INVENTARIO.md desde las rutas, handlers, firmas y variables PHP. Actualizar manualmente FUNCIONALIDADES.md, SEGURIDAD.md, DESPLIEGUE.md y este documento al cambiar reglas o procesos. Revisar los campos de funciones delegadas antes de publicar un contrato externo.

## Comprobación local de esta entrega

`python tests/security_check.py --php <ruta-a-php.exe> --httpd <ruta-a-httpd.exe>` inicia Apache aislado en loopback, verifica sintaxis PHP, firmas, secretos, CORS, subidas, archivos existentes, bloqueo de configuración/herramientas, exigencia HTTPS y límite de autenticación. Usa carpetas temporales y no escribe en MySQL. Está preparado para la instalación Windows de Apache/PHP con módulo php8apache2_4.dll.

Se ejecutó con PHP 8.4.0 y Apache 2.4.62.1: 96 comprobaciones aprobadas. También pasaron 14 verificaciones de cálculo de horas y 14 de doble factor. No se ejecutó la batería que depende de una base real ni las pruebas de SMTP/push/dispositivo.
