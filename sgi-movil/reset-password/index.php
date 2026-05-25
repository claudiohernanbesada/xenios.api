<?php
declare(strict_types=1);

/**
 * Página pública de restablecimiento de contraseña.
 * Alojar en restartpassword.sgi.raei.com.ar (alias de api.raei.com.ar).
 * Copiar logo-raei.png junto a este index (desde assets/ de la app móvil).
 */
$config = require dirname(__DIR__) . '/config.php';
$pr = $config['password_reset'] ?? [];
$apiBase = rtrim((string) ($pr['api_public_url'] ?? 'https://api.raei.com.ar/sgi-movil'), '/');
$token = trim((string) ($_GET['token'] ?? ''));
$logoUrl = 'logo-raei.png';
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
      display: none;
    }
    .msg.error { display: block; background: #fce8e6; color: #c5221f; }
    .msg.ok { display: block; background: #e6f4ea; color: #137333; }
    .policy { font-size: 0.78rem; color: #5f6368; margin-top: 12px; line-height: 1.35; }
    #form-block.hidden { display: none; }
  </style>
</head>
<body>
  <div class="card">
    <div class="logo-wrap">
      <img src="<?= htmlspecialchars($logoUrl, ENT_QUOTES, 'UTF-8') ?>" alt="RAEI" onerror="this.style.display='none'" />
    </div>
    <h1>Restablecer contraseña</h1>
    <p class="sub" id="subtitle">Ingresá tu nueva contraseña para la app SGI.</p>

    <div id="form-block">
      <form id="reset-form" novalidate>
        <div class="field">
          <label for="clave">Nueva contraseña</label>
          <input id="clave" name="clave" type="password" autocomplete="new-password" required minlength="8" />
          <button type="button" class="toggle-eye" data-target="clave" aria-label="Mostrar contraseña">
            <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12 4.5C7 4.5 2.73 7.61 1 12c1.73 4.39 6 7.5 11 7.5s9.27-3.11 11-7.5c-1.73-4.39-6-7.5-11-7.5zM12 17c-2.76 0-5-2.24-5-5s2.24-5 5-5 5 2.24 5 5-2.24 5-5 5zm0-8c-1.66 0-3 1.34-3 3s1.34 3 3 3 3-1.34 3-3-1.34-3-3-3z"/></svg>
          </button>
        </div>
        <div class="field">
          <label for="clave2">Repetir contraseña</label>
          <input id="clave2" name="clave2" type="password" autocomplete="new-password" required minlength="8" />
          <button type="button" class="toggle-eye" data-target="clave2" aria-label="Mostrar contraseña">
            <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12 4.5C7 4.5 2.73 7.61 1 12c1.73 4.39 6 7.5 11 7.5s9.27-3.11 11-7.5c-1.73-4.39-6-7.5-11-7.5zM12 17c-2.76 0-5-2.24-5-5s2.24-5 5-5 5 2.24 5 5-2.24 5-5 5zm0-8c-1.66 0-3 1.34-3 3s1.34 3 3 3 3-1.34 3-3-1.34-3-3-3z"/></svg>
          </button>
        </div>
        <p class="policy">Mínimo 8 caracteres, una mayúscula, una minúscula, dos números y un carácter especial.</p>
        <button type="submit" class="primary" id="btn-submit">Guardar contraseña</button>
      </form>
    </div>

    <div id="feedback" class="msg" role="alert"></div>
  </div>

  <script>
    const API_BASE = <?= json_encode($apiBase, JSON_UNESCAPED_UNICODE) ?>;
    const TOKEN = <?= json_encode($token, JSON_UNESCAPED_UNICODE) ?>;

    const formBlock = document.getElementById('form-block');
    const form = document.getElementById('reset-form');
    const feedback = document.getElementById('feedback');
    const subtitle = document.getElementById('subtitle');
    const btn = document.getElementById('btn-submit');

    function showMsg(text, ok) {
      feedback.textContent = text;
      feedback.className = 'msg ' + (ok ? 'ok' : 'error');
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

    async function validarToken() {
      if (!TOKEN) {
        formBlock.classList.add('hidden');
        showMsg('Falta el enlace de recuperación. Solicitá uno nuevo desde la app SGI.', false);
        return;
      }
      try {
        const res = await fetch(API_BASE + '/auth/recuperar-clave/validar?token=' + encodeURIComponent(TOKEN));
        const data = await res.json();
        if (!data.ok) {
          formBlock.classList.add('hidden');
          showMsg(data.message || 'Enlace inválido o expirado.', false);
        }
      } catch (e) {
        formBlock.classList.add('hidden');
        showMsg('No se pudo verificar el enlace. Intentá más tarde.', false);
      }
    }

    form.addEventListener('submit', async (ev) => {
      ev.preventDefault();
      const nueva = document.getElementById('clave').value;
      const confirmacion = document.getElementById('clave2').value;
      if (nueva !== confirmacion) {
        showMsg('Las contraseñas no coinciden.', false);
        return;
      }
      btn.disabled = true;
      showMsg('Guardando…', true);
      try {
        const res = await fetch(API_BASE + '/auth/recuperar-clave/completar', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
          body: JSON.stringify({ token: TOKEN, nuevaClave: nueva, confirmacion }),
        });
        const data = await res.json();
        if (!res.ok || !data.ok) {
          showMsg(data.message || 'No se pudo actualizar la contraseña.', false);
          btn.disabled = false;
          return;
        }
        formBlock.classList.add('hidden');
        subtitle.textContent = 'Contraseña actualizada';
        showMsg(
          'El cambio fue exitoso. Volvé a la aplicación SGI en tu celular e iniciá sesión con la nueva contraseña. ' +
          'Recibirás una notificación en el dispositivo donde tengas la app instalada.',
          true
        );
      } catch (e) {
        showMsg('Error de conexión con el servidor.', false);
        btn.disabled = false;
      }
    });

    void validarToken();
  </script>
</body>
</html>
