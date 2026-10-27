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

## Estado

El repositorio contiene el renombre y controles de seguridad locales. No equivale a una publicación validada.
El 26/10/2026 se verificó HTTPS válido y redirección HTTP 301 a HTTPS. La raíz pública responde 404: falta desplegar esta API, configurar las credenciales de Xenios y verificar los circuitos contra su base de datos, web y dispositivos.

## Preparación local

Copiar `.env.example` a `.env` y completar las credenciales propias de Xenios. Generar valores independientes de al menos 32 bytes para API, servicio, sesión y puente web. Nunca enviar la clave de servicio o la del puente al navegador ni incluirlas en la aplicación móvil.

La API rechaza credenciales ausentes. La base de datos no se crea ni se migra desde este repositorio.
