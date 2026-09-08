<?php
declare(strict_types=1);

/**
 * Pagina publica de restablecimiento de contraseña.
 * No llama a la API por HTTP para no exponer SGI_API_TOKEN en el navegador.
 */
require_once dirname(__DIR__) . '/lib/db.php';
require_once dirname(__DIR__) . '/lib/password_reset.php';

$config = require dirname(__DIR__) . '/config.php';
$token = trim((string) ($_POST['token'] ?? $_GET['token'] ?? ''));
$logoUrl = 'logo-raei.png';
$feedback = null;
$feedbackOk = false;
$formVisible = true;
$subtitle = 'Ingresá tu nueva contraseña para la app SGI.';
$appLoginUrl = (string) (($config['password_reset'] ?? [])['app_login_url'] ?? 'sgi://login');

try {
    $pdo = db_connect($config);

    if ($token === '') {
        $formVisible = false;
        $feedback = 'Falta el enlace de recuperación. Solicitá uno nuevo desde la app SGI.';
    } elseif (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        $nueva = (string) ($_POST['clave'] ?? '');
        $confirmacion = (string) ($_POST['clave2'] ?? '');
        $result = password_reset_complete($pdo, $config, $token, $nueva, $confirmacion);
        $feedbackOk = (bool) ($result['ok'] ?? false);
        $feedback = (string) ($result['message'] ?? 'No se pudo actualizar la contraseña.');
        if ($feedbackOk) {
            $formVisible = false;
            $subtitle = 'Contraseña actualizada';
            $feedback = 'El cambio de contraseña se realizó con éxito. Te redireccionaremos al inicio de sesión de la app móvil.';
        }
    } else {
        $validation = password_reset_validate($pdo, $token);
        if (!($validation['ok'] ?? false)) {
            $formVisible = false;
            $feedback = (string) ($validation['message'] ?? 'Enlace inválido o expirado.');
        }
    }
} catch (Throwable) {
    $formVisible = false;
    $feedback = 'No se pudo verificar el enlace. Intentá más tarde.';
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Restablecer contraseña - SGI</title>
  <style>
    * { box-sizing: border-box; }
    body {
      margin: 0;
      font-family: 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
      background: #f5f7fb;
      color: #202124;
      min-height: 100vh;
      display: flex;
      align-items: center;
      justify-content: center;
      padding: 24px;
    }
    .card {
      width: 100%;
      max-width: 420px;
      background: #fff;
      border: 1px solid #dadce0;
      border-radius: 8px;
      padding: 28px 24px 24px;
      box-shadow: 0 1px 3px rgba(60,64,67,.15);
    }
    .logo-wrap { text-align: center; margin-bottom: 20px; }
    .logo-wrap img { max-width: 120px; height: auto; }
    h1 { font-size: 1.25rem; margin: 0 0 8px; text-align: center; font-weight: 600; }
    p.sub { margin: 0 0 20px; text-align: center; color: #5f6368; font-size: 0.9rem; line-height: 1.4; }
    label { display: block; font-size: 0.85rem; color: #5f6368; margin-bottom: 6px; }
    .field { position: relative; margin-bottom: 14px; }
    input {
      width: 100%;
      padding: 12px 44px 12px 12px;
      border: 1px solid #dadce0;
      border-radius: 4px;
      font-size: 1rem;
    }
    input:focus { outline: 2px solid #1a73e8; border-color: #1a73e8; }
    .toggle-eye {
      position: absolute;
      right: 8px;
      top: 50%;
      transform: translateY(-50%);
      border: none;
      background: transparent;
      cursor: pointer;
      padding: 6px;
      color: #5f6368;
    }
    .toggle-eye svg { width: 22px; height: 22px; display: block; }
    button.primary {
      width: 100%;
      margin-top: 8px;
      padding: 12px;
      background: #1a73e8;
      color: #fff;
      border: none;
      border-radius: 4px;
      font-size: 1rem;
      font-weight: 600;
      cursor: pointer;
    }
    button.primary:disabled { opacity: 0.6; cursor: not-allowed; }
    .msg {
      margin-top: 16px;
      padding: 12px;
      border-radius: 4px;
      font-size: 0.9rem;
      line-height: 1.45;
      display: block;
    }
    .msg.error { background: #fce8e6; color: #c5221f; }
    .msg.ok { background: #e6f4ea; color: #137333; }
    .policy { font-size: 0.78rem; color: #5f6368; margin-top: 12px; line-height: 1.35; }
    a.primary-link {
      display: block;
      margin-top: 14px;
      text-align: center;
      color: #1a73e8;
      font-weight: 600;
      text-decoration: none;
    }
  </style>
</head>
<body>
  <div class="card">
    <div class="logo-wrap">
      <img src="<?= htmlspecialchars($logoUrl, ENT_QUOTES, 'UTF-8') ?>" alt="RAEI" onerror="this.style.display='none'" />
    </div>
    <h1>Restablecer contraseña</h1>
    <p class="sub" id="subtitle"><?= htmlspecialchars($subtitle, ENT_QUOTES, 'UTF-8') ?></p>

    <?php if ($formVisible): ?>
      <form id="reset-form" method="post" novalidate>
        <input type="hidden" name="token" value="<?= htmlspecialchars($token, ENT_QUOTES, 'UTF-8') ?>" />
        <div class="field">
          <label for="clave">Nueva contraseña</label>
          <input id="clave" name="clave" type="password" autocomplete="new-password" required minlength="10" />
          <button type="button" class="toggle-eye" data-target="clave" aria-label="Mostrar contraseña">
            <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12 4.5C7 4.5 2.73 7.61 1 12c1.73 4.39 6 7.5 11 7.5s9.27-3.11 11-7.5c-1.73-4.39-6-7.5-11-7.5zM12 17c-2.76 0-5-2.24-5-5s2.24-5 5-5 5 2.24 5 5-2.24 5-5 5zm0-8c-1.66 0-3 1.34-3 3s1.34 3 3 3 3-1.34 3-3-1.34-3-3-3z"/></svg>
          </button>
        </div>
        <div class="field">
          <label for="clave2">Repetir contraseña</label>
          <input id="clave2" name="clave2" type="password" autocomplete="new-password" required minlength="10" />
          <button type="button" class="toggle-eye" data-target="clave2" aria-label="Mostrar contraseña">
            <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12 4.5C7 4.5 2.73 7.61 1 12c1.73 4.39 6 7.5 11 7.5s9.27-3.11 11-7.5c-1.73-4.39-6-7.5-11-7.5zM12 17c-2.76 0-5-2.24-5-5s2.24-5 5-5 5 2.24 5 5-2.24 5-5 5zm0-8c-1.66 0-3 1.34-3 3s1.34 3 3 3 3-1.34 3-3-1.34-3-3-3z"/></svg>
          </button>
        </div>
        <p class="policy">Mínimo 10 caracteres, una mayúscula, una minúscula, dos números y un carácter especial.</p>
        <button type="submit" class="primary" id="btn-submit">Guardar contraseña</button>
      </form>
    <?php endif; ?>

    <?php if ($feedback !== null): ?>
      <div class="msg <?= $feedbackOk ? 'ok' : 'error' ?>" role="alert">
        <?= htmlspecialchars($feedback, ENT_QUOTES, 'UTF-8') ?>
      </div>
      <?php if ($feedbackOk): ?>
        <a class="primary-link" href="<?= htmlspecialchars($appLoginUrl, ENT_QUOTES, 'UTF-8') ?>">Volver al inicio de sesión</a>
      <?php endif; ?>
    <?php endif; ?>
  </div>

  <script>
    const appLoginUrl = <?= json_encode($appLoginUrl, JSON_UNESCAPED_SLASHES) ?>;
    const passwordUpdated = <?= $feedbackOk ? 'true' : 'false' ?>;
    if (passwordUpdated && appLoginUrl) {
      window.setTimeout(() => {
        window.location.href = appLoginUrl;
      }, 2500);
    }

    document.querySelectorAll('.toggle-eye').forEach((btnEye) => {
      btnEye.addEventListener('click', () => {
        const id = btnEye.getAttribute('data-target');
        const input = document.getElementById(id);
        if (!input) return;
        const show = input.type === 'password';
        input.type = show ? 'text' : 'password';
        btnEye.setAttribute('aria-label', show ? 'Ocultar contraseña' : 'Mostrar contraseña');
      });
    });
  </script>
</body>
</html>
