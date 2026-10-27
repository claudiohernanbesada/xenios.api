# Sonido repetido de Novedades y Visitas en Android

Los binarios Android con el módulo `xenios-notification-alert` registran su token
como `android:ring-v1`. Se conserva el token FCM original y se usa el campo
`MovilPushToken.Plataforma` existente (varchar(16), capacidad de 15 caracteres).
No hay cambios de esquema. Las apps anteriores siguen registrándose como
`android` y reciben el push visible habitual.

Para esos binarios, únicamente Novedades y Visitas usan FCM de alta prioridad
con `data.title`, `data.message`, `data.body`, `channelId` y sonido. El receptor
de expo-notifications presenta el aviso aun sin JavaScript activo. El receptor
nativo XENIOS agrega `Notification.FLAG_INSISTENT` a la notificación presentada.
Fichadas y los demás avisos mantienen el comportamiento anterior.

Android repite el sonido hasta cancelar la notificación o abrir el panel de
notificaciones. Abrir el panel no confirma lectura en XENIOS: el diálogo interno
conserva el botón `Leído · Confirmar`. Se respetan permisos, volumen, silencio,
No molestar y las restricciones del fabricante. No se fuerza pantalla completa.

Referencia: https://developer.android.com/reference/android/app/Notification#FLAG_INSISTENT

## Publicación

1. Publicar `xenios-movil/lib/push_native.php` y `xenios-movil/lib/novedades.php` juntos.
2. Compilar e instalar la app móvil que incluye `modules/xenios-notification-alert`.
   Una actualización solo de JavaScript no incorpora el receptor nativo.
3. Abrir la app con sesión y permisos para actualizar el registro del token.
4. Repetir la prueba CLI ya usada, con la pantalla bloqueada:

```bash
php xenios-movil/tools/enviar_novedad_email.php "CORREO_DEL_USUARIO" "Prueba sonora" "Prueba de sonido repetido" --verificar
```

La respuesta del proveedor debe indicar plataforma `android:ring-v1`. Verificar
que el sonido se repita, abrir el panel y comprobar que se detenga; entrar a XENIOS
y confirmar lectura. Repetir con Visitas, con app activa y al minimizarla durante
una alerta. No confundir aceptación de FCM con verificación física del teléfono.

Si la app nueva se instala antes que la API, la API anterior normaliza la
plataforma a `android`: conserva la entrega habitual. Abrir nuevamente la app
después de publicar ambos archivos permite registrar la capacidad nueva.

## Validación local

```bash
php xenios-movil/tools/test_push_visitas.php
```

Las pruebas de payload no envían notificaciones ni requieren credenciales.
En la app: `npx tsc --noEmit`, las pruebas de registro y cola, y la resolución de
autolinking verifican TypeScript, compatibilidad con binarios anteriores y la
detección del módulo. La compilación Android y la prueba física siguen siendo
necesarias para validar el receptor nativo y el comportamiento de cada teléfono.
