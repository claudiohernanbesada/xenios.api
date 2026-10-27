# Xenios API

API PHP de Xenios para aplicación móvil, web, asistencia, seguridad y logística.

- Dominio: `https://api.xenios.com.ar`.
- Base REST: `https://api.xenios.com.ar/xenios-movil`.
- PHP 8.2+; MySQL mediante PDO; Apache 2.4 con rewrite y headers.
- Zona horaria: `America/Argentina/Buenos_Aires`.

## Documentación

1. [Funcionalidades y reglas de negocio](docs/FUNCIONALIDADES.md).
2. [Referencia de endpoints, campos y respuestas](docs/API.md).
3. [Configuración completa](docs/CONFIGURACION.md).
4. [Seguridad, límites y pendientes](docs/SEGURIDAD.md).
5. [Instalación, operación y migración de clientes](docs/DESPLIEGUE.md).
6. [Herramientas y tareas programadas](docs/HERRAMIENTAS.md).
7. [Inventario de funciones del código](docs/INVENTARIO.md).
8. [Carga y descarga de documentos del legajo](docs/LEGAJO.md).

## Estado

El 27/10/2026 se publicó y verificó en el servidor la lectura de `/etc/xenios/api/.env`, con propietario root:www-data y permisos 0640 (directorio 0750). Se retiró el `.env` del directorio público y del índice Git. La configuración efectiva se conservó sin cambios.
Verificaciones realizadas: HTTPS `/xenios-movil/health` con clave 200 y sin clave 401, bloqueo de archivos privados 403 y consulta MySQL `SELECT 1` como www-data. Estos controles no validan todos los circuitos de negocio, correo ni dispositivos.

## Preparación local

En Windows, copiar `.env.example` a `.env` para desarrollo. En el servidor Linux, guardar la configuración en `/etc/xenios/api/.env` (root:www-data, 0640; directorio 0750). La API lee esa ubicación automáticamente, sin buscar un `.env` dentro del sitio. `XENIOS_MOVIL_ENV_FILE` permite definir una ruta explícita. Completar las credenciales propias de Xenios. Generar valores independientes de al menos 32 bytes para API, servicio, sesión y puente web. Nunca enviar la clave de servicio o la del puente al navegador ni incluirlas en la aplicación móvil.

La API rechaza credenciales ausentes. La base de datos no se crea ni se migra desde este repositorio.
