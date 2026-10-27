# Funcionalidades

Esta documentación describe las funciones presentes en el código. Las restricciones específicas por usuario, sede, destinatario y estado se aplican en los handlers enlazados en [API.md](API.md).

## Identidad y sesión

- Login mediante correo y contraseña; solo usuarios activos. Sin secreto de sesión válido se devuelve 503.
- Doble factor para usuarios que lo requieren: iniciar desafío y verificar código de seis dígitos mediante el puente al backend web. El token incorpora la versión MFA; cambiar esa versión invalida su verificación.
- Token firmado con HMAC SHA-256 y vencimiento de una hora. El formato actual es propio de dos partes; no es JWT estándar de tres partes.
- Consulta de perfil, roles y permisos; cambio de contraseña con política de longitud y complejidad; cierre de sesión.
- Recuperación: solicitud con respuesta genérica, validación del enlace, cambio de contraseña y página pública con marca Xenios y enlace `xenios://login`. Los enlaces vencen en 30 minutos.
- Perfil: subir JPG/PNG (100 KB), consultar imagen y eliminar avatar cuando el rol lo permite.
- Versiones Android/iOS: versión publicada, enlaces de tiendas y consulta de Google Play.

## Asistencia y recursos humanos

- Fichadas del empleado asociado al correo, consultables por fecha.
- Calendario de asistencia y resumen entre fechas; resolución de personal, jornadas nocturnas, descansos, feriados y horas extra en las bibliotecas de cálculo compartidas de esta API.
- Vacaciones por año: disponibilidad, consumo e historial según los datos del empleado.
- Autorizaciones de salida: listado personal, descarga del PDF propio y escaneo QR con validación de vigencia, salida y regreso.
- Reportes para integración de servidor: asistencia mensual, detalle mensual, horas trabajadas por período y contratación, y horas extra diarias.
- Notificación por correo de motivos de horas extra pendientes. La API utiliza el backend web y SMTP donde lo exige cada operación.

## Novedades y notificaciones

- Creación de novedades por integración de servidor con título, cuerpo, remitente y destinatarios.
- Bandeja del usuario, detalle individual e historial por año/mes. Solo el destinatario puede acceder o confirmar su lectura.
- Lectura explícita mediante endpoint; consultar una novedad no sustituye la confirmación.
- Registro de tokens nativos FCM/APNs asociados al usuario y plataforma.
- Procesamiento de fichadas nuevas, preparación de una línea de base y envío de push evitando duplicados.
- Registro de eventos de recepción y visualización de fichadas desde el dispositivo autenticado.
- Reintentos de alertas pendientes y cancelación después de la lectura. El sonido persistente depende del binario móvil y requiere prueba física; el envío aceptado por FCM no demuestra sonido en el teléfono.

## Portería: ingresos y salidas

- Roles de gestión: `SEGURIDAD_INOUT`, `ADMIN` y `SYSADMIN`; sede obtenida del usuario.
- Listado por sede, búsqueda de personal por legajo, catálogos de motivos, empresas y usuarios.
- Personas externas e invitados actualmente presentes para registrar salida.
- Carga de empleados, externos e invitados; ingreso/salida, horario, motivo, empresa, patente y destinatarios conforme al tipo de movimiento.
- Carga conjunta de varios legajos: datos comunes y transacción compartida, con registros individuales y lista editable; validación de legajos y duplicados en el servidor.
- Avisos de visitas agrupados por transacción; consulta de pendientes, historial y confirmación de lectura personal. Estas operaciones admiten sesión móvil o puente web firmado.

## Notas de traslado

- Listado por período, visibilidad del usuario, filtros y resumen de pendientes.
- Escaneo del QR y transición según rol, sede, participante y estado de la nota.
- Envíos internos, externos y a empleados; transportista externo con empresa, nombre, apellido y DNI.
- Catálogo de empresas de transporte activas.
- Aprobación y rechazo para envíos sujetos a autorización. Los envíos a empleados requieren aprobación; también los externos cuando la empresa no es frecuente.
- Confirmación de entrega y recepción por los participantes autorizados.
- Descarga del documento y del documento firmado; regeneración del PDF mediante el backend web.
- El PDF generado por la API toma el logo y la identidad de la empresa propietaria de la nota, desde `Hoteles`. Usa la copia JPEG derivada del SVG optimizado. La caché incorpora una huella de logo/nombre/datos fiscales y se renueva al cambiar la marca; los archivos firmados conservan su circuito independiente.
- Estados existentes: 1 generada; 2 recepción despacho; 3 en tránsito; 4 recepción sede; 5 entregado; 6 recibida; 7 anulada; 8 pendiente aprobación; 9 rechazada. No todas las transiciones son válidas para todos los roles.
- Roles admitidos por el módulo: `SYSADMIN`, `ADMIN`, `NOTA_TRASLADO`, `NOTA_TRASLADO_FULL_USERS`, `NOTA_TRASLADO_DESPACHO`, `NOTA_TRASLADO_TRANSPORTE`, `NOTA_TRASLADO_APROBACION`, `NOTA_TRASLADO_SEGURIDAD`, `EMPLEADO`. Cada acción agrega controles específicos.

## Archivos e integraciones

- Repositorios separados para avatar, foto de personal y PDF de autorización. Configuración central en `_inc/rutas_archivos.php`.
- Subidas web autenticadas con clave de servicio. Se validan extensión, contenido y tamaño; nombres aleatorios evitan sobrescribir archivos elegidos por el cliente.
- Descarga directa de repositorios reservada a servidores autenticados. Los dispositivos acceden por endpoints de usuario que verifican propiedad.
- Respaldos remotos: solo las bases configuradas por el servidor, HTTPS con certificado válido y sin redirecciones automáticas. El nombre recibido no puede agregar una URL arbitraria.
- Actualización de alícuotas mediante integración de servidor; consulta de proveedores externos según configuración del script.
- Listados de usuarios y áreas activos para integraciones autorizadas.

## Roles y permisos transversales

Toda ruta REST salvo OPTIONS necesita `X-API-KEY`. Las operaciones de usuario requieren además sesión y usuario activo; las de integración privilegiada requieren `X-Service-Key`.
El rol exclusivamente `EMPLEADO` limita escrituras a las operaciones expresamente habilitadas: cambio de clave, avatar, tokens push, eventos de fichadas, escaneo de traslado, recepción propia y confirmaciones de lectura. Los permisos finales se validan en cada módulo.

## Contratos de integraciones y carga de portería

Alícuotas: `POST /actions/alicuotas/update.php` con `X-API-KEY` y `X-Service-Key`. Admite `modo=empresa` (predeterminado) con `empresaId` o `cuit`, o `modo=todas` para empresas activas de tipos 1/2/3. `vigenciaDesde` predetermina al primer día del mes y `vigenciaHasta` es opcional. Consulta proveedores configurados, inserta/actualiza alícuotas y devuelve `success`, `actualizadas`, `sinAlicuota` y `message`. Revisar permisos y alcance antes de usar `todas`.

Portería: `POST /inout-seguridad` exige `transaccionId` UUID v4, `tipoPersona` (`XENIOS`, `Externo`, `Invitado`), `movimiento` (`Ingreso`, `Egreso`) y `horario` HH:mm. `nota` admite hasta 2000 caracteres. Para empleados, `legajos` es una lista de 1 a 50 números distintos de hasta cuatro dígitos; `legajo` es la alternativa individual. `patentesLegajos` asocia vehículos. Para visitas, `usuariosVisitaIds` admite hasta 50 destinatarios; `usuarioVisitaId` es la alternativa individual; invitados requieren destinatario. Externos/invitados presentes se cierran con `ingresosIds` y opcionalmente `patentesIngresos`. Los demás campos de identificación y empresa se validan por tipo en el handler. Repetir una transacción propia devuelve `reintento`; reutilizar la de otro usuario produce conflicto. La respuesta de alta incluye `ok`, `cantidad`, `transaccionId` y estado de notificación pendiente.

El renombre cambia también el valor de negocio `tipoPersona` a `XENIOS`; coordinar su uso con Xenios.App, app móvil y registros históricos antes de publicar. No se alteraron datos existentes en este checkout.
