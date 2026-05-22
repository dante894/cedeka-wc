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
  <a href="/index.php" class="nav-logo">CEDEKA <span>WC</span></a>
  <div class="nav-links">
    <a href="/index.php?page=home">🏠 <span>Inicio</span></a>
    <?php if ($user): ?>
      <a href="/index.php?page=matches">⚽ <span>Partidos</span></a>
      <a href="/index.php?page=my_bets">🎯 <span>Mis Apuestas</span></a>
      <a href="/index.php?page=wallet">💰 <span>Wallet</span></a>
      <?php if ($user['role'] === 'admin'): ?>
        <a href="/admin/index.php" style="color:var(--gold)">👑 Admin</a>
      <?php endif; ?>
      <span class="nav-balance" data-tip="Saldo en Cedenas"><?= formatCedenas((float)($user['balance'] ?? 0)) ?></span>
      <a href="/index.php?page=logout" class="nav-avatar" title="Cerrar sesión"><?= h($user['avatar'] ?? '⚽') ?></a>
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
<script src="/assets/js/app.js"></script>
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
