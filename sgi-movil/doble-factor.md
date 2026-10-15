# Autenticacion TOTP compartida con SGI

Requiere la migracion `20261015172827_AddUsuarioDobleFactor` y la version nueva
de SGI .NET. No se implementa otro generador TOTP en PHP: se usa el mismo
servicio, cifrado y contador de intentos de web.

Variables privadas del `.env` de la API:

```ini
SGI_2FA_BASE_URL=http://127.0.0.1:5090
SGI_2FA_BRIDGE_TOKEN=CLAVE_PRIVADA_PUENTE
SGI_JWT_SECRET=OTRA_CLAVE_PRIVADA_JWT
```

Generar claves distintas con `openssl rand -hex 32`. La de puente debe ser
igual a `Authentication__DobleFactor__BridgeToken` del servicio SGI .NET.
Nunca enviar estas claves a la app. Sin JWT privado configurado no hay login.
El cambio de clave JWT invalida todas las sesiones moviles anteriores.

`POST /auth/login` sigue recibiendo correo y clave. Si requiere 2FA devuelve
`requiereDobleFactor`, `desafio`, `configurar`, `claveManual`, `qrBase64` y
`expiraUtc`, sin JWT ni usuario. La clave/QR solo estan presentes al vincular.
`POST /auth/doble-factor` recibe desafio y codigo; devuelve la respuesta normal
con JWT y usuario solo despues de validar la prueba privada del verificador.

La autorizacion consulta el requisito y version en la DB en cada request.
Los tokens viejos no evitan 2FA y el puente de documentos web tambien exige
evidencia privada si el usuario requiere el segundo factor.

El endpoint interno .NET no debe publicarse ni pasar por el proxy. La API y
SGI deben estar en el mismo servidor o contar con un tunel local seguro.
Habilitar cURL en PHP y HTTPS para la API publica. Desplegar tambien la app
nueva antes de activar el switch a los usuarios.

Pruebas: `php sgi-movil/tools/test_doble_factor.php`; la integracion completa
PHP/.NET/web esta en los tests de autenticacion del proyecto SGI.
