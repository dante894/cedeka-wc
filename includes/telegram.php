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
    <a href="/index.php?page=como-funciona">❓ <span>Cómo Funciona</span></a>
    <?php if ($user): ?>
      <?php if ($user['role'] === 'admin'): ?>
        <a href="/admin/index.php" style="color:var(--gold)">👑 <span>Dashboard</span></a>
        <a href="/admin/index.php?page=matches">⚽ <span>Partidos</span></a>
        <a href="/admin/index.php?page=recharges">💰 <span>Recargas</span></a>
        <a href="/admin/index.php?page=users">👥 <span>Usuarios</span></a>
      <?php else: ?>
        <a href="/index.php?page=matches">⚽ <span>Partidos</span></a>
        <a href="/index.php?page=my_bets">🎯 <span>Mis Apuestas</span></a>
        <a href="/index.php?page=ranking">📊 <span>Ranking</span></a>
      <a href="/index.php?page=ganadores">🏆 <span>Ganadores</span></a>
        <a href="/index.php?page=wallet">💰 <span>Wallet</span></a>
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
  Cedeka World Cup ⚽ &nbsp;·&nbsp; El 20% de cada pozo va a la plataforma &nbsp;·&nbsp; Juega responsable
</footer>


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