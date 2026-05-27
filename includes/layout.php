<?php
// includes/layout.php — Render helpers
function renderHead(string $title = 'Cedeka World Cup'): void { ?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= h($title) ?> — Cedeka World Cup ⚽</title>
<link rel="stylesheet" href="/assets/css/main.css">
</head>
<body>
<?php }

function renderNav(?array $user): void { ?>
<nav class="navbar">
  <a href="/index.php" class="nav-logo">
    <img src="/assets/logo.png" alt="Cedeka WC">
  </a>
  <div class="nav-links">
    <a href="/index.php?page=home">🏠 <span>Inicio</span></a>
    <?php if ($user): ?>
      <a href="/index.php?page=matches">⚽ <span>Partidos</span></a>
      <a href="/index.php?page=my_bets">🎯 <span>Mis Apuestas</span></a>
      <a href="/index.php?page=ranking">📊 <span>Ranking</span></a>
      <a href="/index.php?page=wallet">💰 <span>Wallet</span></a>
      <?php if ($user['role'] === 'admin'): ?>
        <a href="/admin/index.php" style="color:var(--gold)">👑 Admin</a>
      <?php endif; ?>
      <span class="nav-balance" data-tip="Saldo en Cedenas"><?= formatCedenas((float)($user['balance'] ?? 0)) ?></span>
      <!-- Avatar con dropdown -->
      <div style="position:relative" id="avatarMenu">
        <button onclick="toggleAvatarMenu()" style="background:var(--bg3);border:1px solid rgba(201,168,76,0.2);border-radius:50%;width:36px;height:36px;display:flex;align-items:center;justify-content:center;font-size:18px;cursor:pointer;transition:all 0.2s" id="avatarBtn">
          <?= h($user['avatar'] ?? '⚽') ?>
        </button>
        <div id="avatarDropdown" style="display:none;position:absolute;right:0;top:44px;background:var(--bg2);border:1px solid rgba(201,168,76,0.2);border-radius:10px;min-width:180px;box-shadow:0 8px 24px rgba(0,0,0,0.4);z-index:999;overflow:hidden">
          <div style="padding:12px 16px;border-bottom:1px solid var(--border)">
            <div style="font-family:var(--font-sub);font-weight:700;font-size:14px;color:#fff"><?= h($user['full_name'] ?? '') ?></div>
            <div style="font-size:11px;color:var(--text-dim)"><?= h($user['email'] ?? '') ?></div>
          </div>
          <a href="/index.php?page=profile" style="display:flex;align-items:center;gap:10px;padding:10px 16px;color:var(--text);text-decoration:none;font-size:14px;transition:background 0.15s" onmouseover="this.style.background='rgba(255,255,255,0.05)'" onmouseout="this.style.background='transparent'">👤 Mi Perfil</a>
          <a href="/index.php?page=wallet" style="display:flex;align-items:center;gap:10px;padding:10px 16px;color:var(--text);text-decoration:none;font-size:14px;transition:background 0.15s" onmouseover="this.style.background='rgba(255,255,255,0.05)'" onmouseout="this.style.background='transparent'">💰 Mi Wallet</a>
          <a href="/index.php?page=ranking" style="display:flex;align-items:center;gap:10px;padding:10px 16px;color:var(--text);text-decoration:none;font-size:14px;transition:background 0.15s" onmouseover="this.style.background='rgba(255,255,255,0.05)'" onmouseout="this.style.background='transparent'">📊 Ranking</a>
          <?php if ($user['role'] === 'admin'): ?>
          <a href="/admin/index.php" style="display:flex;align-items:center;gap:10px;padding:10px 16px;color:var(--gold);text-decoration:none;font-size:14px;transition:background 0.15s" onmouseover="this.style.background='rgba(201,168,76,0.05)'" onmouseout="this.style.background='transparent'">👑 Panel Admin</a>
          <?php endif; ?>
          <div style="border-top:1px solid var(--border)">
            <a href="/index.php?page=logout" style="display:flex;align-items:center;gap:10px;padding:10px 16px;color:var(--red);text-decoration:none;font-size:14px;transition:background 0.15s" onmouseover="this.style.background='rgba(255,61,90,0.05)'" onmouseout="this.style.background='transparent'">🚪 Cerrar Sesión</a>
          </div>
        </div>
      </div>
      <script>
      function toggleAvatarMenu() {
        const d = document.getElementById('avatarDropdown');
        d.style.display = d.style.display === 'none' ? 'block' : 'none';
      }
      document.addEventListener('click', function(e) {
        if (!document.getElementById('avatarMenu').contains(e.target)) {
          document.getElementById('avatarDropdown').style.display = 'none';
        }
      });
      </script>
    <?php else: ?>
      <a href="/index.php?page=login" class="btn btn-ghost btn-sm">Entrar</a>
      <a href="/index.php?page=register" class="btn btn-primary btn-sm">Registrarse</a>
    <?php endif; ?>
  </div>
</nav>
<?php }

function renderFoot(): void { ?>
<footer style="text-align:center;padding:24px;color:var(--text-dim);font-size:12px;border-top:1px solid var(--border);margin-top:40px">
  Cedeka World Cup ⚽ &nbsp;·&nbsp; El 10% de cada pozo va a la plataforma &nbsp;·&nbsp; Juega responsable
</footer>

<!-- =============================================
     MODAL INTRO — aparece solo la primera vez
     ============================================= -->
<div id="introModal" style="display:none;position:fixed;inset:0;z-index:999;background:rgba(0,0,0,0.85);backdrop-filter:blur(6px);overflow-y:auto;padding:24px 16px">
  <div style="max-width:680px;margin:0 auto;background:var(--bg2);border:1px solid rgba(245,200,66,0.25);border-radius:16px;padding:36px 32px;position:relative">

    <!-- Header -->
    <div style="text-align:center;margin-bottom:32px">
      <div style="font-size:52px;margin-bottom:8px">⚽</div>
      <h2 style="font-family:var(--font-head);font-size:38px;letter-spacing:3px;color:#fff;line-height:1">
        BIENVENIDO A<br><span style="color:var(--gold)">CEDEKA WC</span>
      </h2>
      <p style="color:var(--text-dim);font-size:14px;margin-top:10px">La quiniela donde el minuto exacto del gol lo es todo</p>
    </div>

    <!-- Cards de pasos -->
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:28px">

      <div style="background:var(--bg3);border:1px solid var(--border);border-radius:10px;padding:20px">
        <div style="font-size:32px;margin-bottom:10px">💰</div>
        <div style="font-family:var(--font-sub);font-weight:700;font-size:15px;color:var(--gold);margin-bottom:6px;text-transform:uppercase;letter-spacing:1px">1. Carga Cedenas</div>
        <p style="font-size:13px;color:var(--text-dim);line-height:1.5">Recarga tu billetera con Cedenas enviando un comprobante de pago. El admin aprueba y te acredita el saldo.</p>
      </div>

      <div style="background:var(--bg3);border:1px solid var(--border);border-radius:10px;padding:20px">
        <div style="font-size:32px;margin-bottom:10px">⚽</div>
        <div style="font-family:var(--font-sub);font-weight:700;font-size:15px;color:var(--gold);margin-bottom:6px;text-transform:uppercase;letter-spacing:1px">2. Elige un Partido</div>
        <p style="font-size:13px;color:var(--text-dim);line-height:1.5">Selecciona cualquier partido abierto de la lista. Verás el pozo acumulado y cuántas apuestas hay.</p>
      </div>

      <div style="background:var(--bg3);border:1px solid var(--border);border-radius:10px;padding:20px">
        <div style="font-size:32px;margin-bottom:10px">🎯</div>
        <div style="font-family:var(--font-sub);font-weight:700;font-size:15px;color:var(--gold);margin-bottom:6px;text-transform:uppercase;letter-spacing:1px">3. Apuesta el Minuto</div>
        <p style="font-size:13px;color:var(--text-dim);line-height:1.5">Elige qué equipo mete el gol y el <strong style="color:#fff">minuto exacto</strong> (1–90). Cuanto más apostar, más ganas si aciertas.</p>
      </div>

      <div style="background:var(--bg3);border:1px solid var(--border);border-radius:10px;padding:20px">
        <div style="font-size:32px;margin-bottom:10px">🏆</div>
        <div style="font-family:var(--font-sub);font-weight:700;font-size:15px;color:var(--gold);margin-bottom:6px;text-transform:uppercase;letter-spacing:1px">4. Gana el Pozo</div>
        <p style="font-size:13px;color:var(--text-dim);line-height:1.5">Si el equipo mete gol en ese minuto exacto, ¡ganaste! El 90% del pozo se reparte entre los acertadores.</p>
      </div>

    </div>

    <!-- Regla del pozo -->
    <div style="background:rgba(245,200,66,0.07);border:1px solid rgba(245,200,66,0.2);border-radius:10px;padding:16px 20px;margin-bottom:24px;display:flex;gap:14px;align-items:flex-start">
      <div style="font-size:24px;flex-shrink:0">💡</div>
      <div>
        <div style="font-family:var(--font-sub);font-weight:700;font-size:13px;color:var(--gold);text-transform:uppercase;letter-spacing:1px;margin-bottom:4px">¿Nadie acierta?</div>
        <p style="font-size:13px;color:var(--text-dim);line-height:1.5;margin:0">El pozo se <strong style="color:#fff">acumula</strong> al siguiente partido. Mientras más partidos sin ganador, ¡más grande el premio!</p>
      </div>
    </div>

    <!-- Distribución -->
    <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:10px;margin-bottom:28px;text-align:center">
      <div style="background:var(--bg3);border-radius:8px;padding:14px">
        <div style="font-family:var(--font-head);font-size:28px;color:var(--green)">90%</div>
        <div style="font-size:11px;color:var(--text-dim);text-transform:uppercase;letter-spacing:1px;margin-top:2px">Para ganadores</div>
      </div>
      <div style="background:var(--bg3);border-radius:8px;padding:14px">
        <div style="font-family:var(--font-head);font-size:28px;color:var(--gold)">10%</div>
        <div style="font-size:11px;color:var(--text-dim);text-transform:uppercase;letter-spacing:1px;margin-top:2px">Comisión plataforma</div>
      </div>
      <div style="background:var(--bg3);border-radius:8px;padding:14px">
        <div style="font-family:var(--font-head);font-size:28px;color:var(--blue)">1–90</div>
        <div style="font-size:11px;color:var(--text-dim);text-transform:uppercase;letter-spacing:1px;margin-top:2px">Minutos disponibles</div>
      </div>
    </div>

    <!-- Botón cerrar -->
    <button onclick="closeIntro()" class="btn btn-primary btn-block btn-lg" style="font-size:16px">
      ¡Entendido, quiero apostar! 🎯
    </button>
    <p style="text-align:center;font-size:12px;color:var(--text-dim);margin-top:12px">
      <a href="#" onclick="closeIntro()" style="color:var(--text-dim)">No mostrar de nuevo</a>
    </p>
  </div>
</div>

<script src="/assets/js/app.js"></script>
<script>
// Mostrar modal solo la primera vez (usa localStorage)
(function() {
  if (!localStorage.getItem('cedeka_intro_seen')) {
    document.getElementById('introModal').style.display = 'block';
    document.body.style.overflow = 'hidden';
  }
})();

function closeIntro() {
  localStorage.setItem('cedeka_intro_seen', '1');
  document.getElementById('introModal').style.display = 'none';
  document.body.style.overflow = '';
}

// Cerrar con Escape
document.addEventListener('keydown', function(e) {
  if (e.key === 'Escape') closeIntro();
});
</script>
</body>
</html>
<?php }

function renderFlash(): void {
    foreach (['success','error','info','warn'] as $type) {
        $msg = flash($type);
        if ($msg) {
            $icon = match($type) { 'success'=>'✅','error'=>'❌','warn'=>'⚠️', default=>'ℹ️' };
            echo "<div class=\"alert alert-$type\">$icon " . h($msg) . "</div>";
        }
    }
}