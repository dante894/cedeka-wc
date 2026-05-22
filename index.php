<?php
// =============================================
// CEDEKA WORLD CUP — Router Principal (HARDENED)
// =============================================
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/init-db.php';
require_once __DIR__ . '/includes/layout.php';

initDB();
startSession();
$page = $_GET['page'] ?? 'home';
$user = getCurrentUser();

// Logout
if ($page === 'logout') {
    logoutUser();
    redirect('/index.php?page=login');
}

// POST: validar CSRF en TODOS los POST (excepto login/register que lo validan internamente)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    handlePost($page, $user);
}

renderHead(ucfirst($page));
renderNav($user);
echo '<main>';

switch ($page) {
    case 'home':     pageHome($user);     break;
    case 'login':    pageLogin();         break;
    case 'register': pageRegister();      break;
    case 'matches':  pageMatches($user);  break;
    case 'bet':      pageBet($user);      break;
    case 'my_bets':  pageMyBets($user);   break;
    case 'wallet':   pageWallet($user);   break;
    case 'recharge': pageRecharge($user); break;
    default:         page404();
}

echo '</main>';
renderFoot();

// =============================================
// POST HANDLER — CSRF verificado en cada acción
// =============================================
function handlePost(string $page, ?array $user): void {
    $db     = getDB();
    $action = $_POST['action'] ?? '';

    // ---- LOGIN ----
    if ($action === 'login') {
        csrfVerify();
        $email = strtolower(trim($_POST['email'] ?? ''));
        $pass  = $_POST['password'] ?? '';

        // Rate limit por IP/email
        if (!checkLoginRateLimit($email)) {
            flash('error', 'Demasiados intentos fallidos. Espera 15 minutos.');
            redirect('/index.php?page=login');
        }

        // Siempre ejecutar hash aunque el usuario no exista (evita timing attack)
        $stmt = $db->prepare("SELECT * FROM users WHERE email = ? LIMIT 1");
        $stmt->execute([$email]);
        $u = $stmt->fetch();

        $hash = $u['password_hash'] ?? '$2y$10$invalido.invalido.invalido.invalido.invalido.invalido.';
        $ok   = password_verify($pass, $hash) && $u;

        recordLoginAttempt($email, (bool)$ok);

        if ($ok) {
            loginUser((int)$u['id']);
            flash('success', '¡Bienvenido ' . $u['full_name'] . '! ⚽');
            redirect('/index.php?page=home');
        }

        // Mensaje genérico (no revela si el email existe)
        flash('error', 'Credenciales incorrectas');
        redirect('/index.php?page=login');
    }

    // ---- REGISTER ----
    if ($action === 'register') {
        csrfVerify();

        if (!checkRegisterRateLimit()) {
            flash('error', 'Límite de registros desde esta IP. Intenta mañana.');
            redirect('/index.php?page=register');
        }

        $name  = trim($_POST['full_name'] ?? '');
        $uname = trim($_POST['username'] ?? '');
        $email = strtolower(trim($_POST['email'] ?? ''));
        $pass  = $_POST['password'] ?? '';
        $pass2 = $_POST['password2'] ?? '';

        // Validar cada campo con helpers
        if ($err = validateUsername($uname))   { flash('error', $err); redirect('/index.php?page=register'); }
        if ($err = validateEmail($email))       { flash('error', $err); redirect('/index.php?page=register'); }
        if ($err = validatePassword($pass))     { flash('error', $err); redirect('/index.php?page=register'); }
        if ($pass !== $pass2)                   { flash('error', 'Las contraseñas no coinciden'); redirect('/index.php?page=register'); }
        if (strlen($name) < 2 || strlen($name) > 80) { flash('error', 'Nombre inválido'); redirect('/index.php?page=register'); }

        $avatars = ['⚽','🏆','🥅','👟','🦅','🔥','⭐','🎯'];
        $hash    = password_hash($pass, PASSWORD_BCRYPT, ['cost' => 12]);
        $ip      = getClientIP();

        try {
            $db->prepare("INSERT INTO users (username,email,password_hash,full_name,avatar,created_ip) VALUES (?,?,?,?,?,?)")
               ->execute([$uname, $email, $hash, $name, $avatars[array_rand($avatars)], $ip]);
            $uid = (int)$db->lastInsertId();
            $db->prepare("INSERT INTO wallets (user_id, balance) VALUES (?, 0)")->execute([$uid]);
            loginUser($uid);
            flash('success', '¡Cuenta creada! Empieza apostando ⚽');
            redirect('/index.php?page=home');
        } catch (PDOException $e) {
            // No revelar si es email o username el duplicado
            flash('error', 'Email o usuario ya registrado');
            redirect('/index.php?page=register');
        }
    }

    // ---- PLACE BET ----
    if ($action === 'place_bet') {
        csrfVerify();
        if (!$user) redirect('/index.php?page=login');

        $matchId = (int)($_POST['match_id'] ?? 0);
        $team    = trim($_POST['team'] ?? '');
        $minute  = (int)($_POST['minute'] ?? 0);
        $amount  = round((float)($_POST['amount'] ?? 0), 4);

        // Obtener partido y validar estado
        $stmt = $db->prepare("SELECT * FROM matches WHERE id = ? AND status = 'open'");
        $stmt->execute([$matchId]);
        $match = $stmt->fetch();
        if (!$match) {
            flash('error', 'Partido no disponible para apostar');
            redirect('/index.php?page=matches');
        }

        // Validar equipo contra BD (no confiar en POST)
        if (!in_array($team, [$match['home_team'], $match['away_team']], true)) {
            flash('error', 'Equipo inválido');
            redirect("/index.php?page=bet&id=$matchId");
        }

        // Validar minuto
        if ($minute < 1 || $minute > 90) {
            flash('error', 'Minuto inválido (1-90)');
            redirect("/index.php?page=bet&id=$matchId");
        }

        // Validar monto contra saldo real de BD (no del POST)
        $realBalance = getBalance($user['id']);
        if ($err = validateBetAmount($amount, $realBalance)) {
            flash('error', $err);
            redirect("/index.php?page=bet&id=$matchId");
        }

        // Verificar apuesta duplicada
        $stmt = $db->prepare("SELECT id FROM bets WHERE user_id=? AND match_id=? AND team=? AND minute=?");
        $stmt->execute([$user['id'], $matchId, $team, $minute]);
        if ($stmt->fetch()) {
            flash('error', '¡Ya tienes esa apuesta! Elige otro minuto o equipo');
            redirect("/index.php?page=bet&id=$matchId");
        }

        // Transacción: descontar y registrar apuesta ATÓMICAMENTE
        $db->beginTransaction();
        try {
            // Descontar saldo (query atómica con CHECK de balance)
            $ok = deductBalance($user['id'], $amount);
            if (!$ok) {
                $db->rollBack();
                flash('error', 'Saldo insuficiente (verificado)');
                redirect("/index.php?page=bet&id=$matchId");
            }

            $db->prepare("INSERT INTO bets (user_id,match_id,team,minute,amount_cedenas) VALUES (?,?,?,?,?)")
               ->execute([$user['id'], $matchId, $team, $minute, $amount]);
            $betId = (int)$db->lastInsertId();

            $db->prepare("UPDATE matches SET pot_total = pot_total + ? WHERE id = ?")->execute([$amount, $matchId]);

            // Registrar transacción
            $newBal = getBalance($user['id']);
            $db->prepare("INSERT INTO transactions (user_id,type,amount,balance_after,description,reference_id) VALUES (?,?,?,?,?,?)")
               ->execute([$user['id'], 'bet', -$amount, $newBal, "Apuesta {$team} min {$minute} — partido #{$matchId}", $betId]);

            $db->commit();
            flash('success', "🎯 ¡Apuesta registrada! {$team} en el minuto {$minute}");
            redirect('/index.php?page=my_bets');
        } catch (Exception $e) {
            $db->rollBack();
            error_log('[CEDEKA BET] uid='.$user['id'].' '.$e->getMessage());
            flash('error', 'Error al registrar la apuesta. Intenta de nuevo.');
            redirect("/index.php?page=bet&id=$matchId");
        }
    }

    // ---- RECHARGE REQUEST ----
    if ($action === 'recharge_request') {
        csrfVerify();
        if (!$user) redirect('/index.php?page=login');

        $amount = round((float)($_POST['amount'] ?? 0), 4);
        $method = trim($_POST['payment_method'] ?? 'transferencia');
        $notes  = trim($_POST['receipt_notes'] ?? '');

        // Validar monto
        if ($amount < 1 || $amount > 100000) {
            flash('error', 'Monto inválido (1 – 100,000 Cedenas)');
            redirect('/index.php?page=recharge');
        }

        // Validar método contra lista blanca
        $allowedMethods = ['transferencia','efectivo','crypto','otro'];
        if (!in_array($method, $allowedMethods, true)) {
            flash('error', 'Método de pago inválido');
            redirect('/index.php?page=recharge');
        }

        // Limitar longitud de notas
        $notes = mb_substr($notes, 0, 500);

        // Verificar que no tenga una solicitud pendiente ya
        $stmt = $db->prepare("SELECT id FROM recharge_requests WHERE user_id=? AND status='pending'");
        $stmt->execute([$user['id']]);
        if ($stmt->fetch()) {
            flash('warn', 'Ya tienes una solicitud pendiente. Espera a que sea revisada.');
            redirect('/index.php?page=wallet');
        }

        $db->prepare("INSERT INTO recharge_requests (user_id,amount_cedenas,payment_method,receipt_notes) VALUES (?,?,?,?)")
           ->execute([$user['id'], $amount, $method, $notes]);
        flash('success', 'Solicitud enviada. El admin la revisará pronto ✅');
        redirect('/index.php?page=wallet');
    }
}

// =============================================
// PÁGINAS
// =============================================
function pageHome(?array $user): void { ?>
<div class="hero">
  <div class="hero-ball">⚽</div>
  <h1 class="hero-title">
    <span class="small">Cedeka</span>
    <span class="accent">WORLD</span>
    CUP
  </h1>
  <p class="hero-sub">Apuesta el minuto exacto del gol. El que más sabe de fútbol, gana el pozo.</p>
  <?php if ($user): ?>
    <a href="/index.php?page=matches" class="btn btn-primary btn-lg">Ver Partidos ⚽</a>
    <a href="/index.php?page=wallet" class="btn btn-ghost btn-lg" style="margin-left:10px">Mi Wallet 💰</a>
  <?php else: ?>
    <a href="/index.php?page=register" class="btn btn-primary btn-lg">Comenzar a Apostar</a>
    <a href="/index.php?page=login" class="btn btn-ghost btn-lg" style="margin-left:10px">Iniciar Sesión</a>
  <?php endif; ?>
</div>

<?php
$db = getDB();
$matches = $db->query("SELECT m.*, (SELECT COUNT(*) FROM bets b WHERE b.match_id=m.id) as bet_count FROM matches m WHERE m.status IN ('open','in_progress') ORDER BY m.match_date ASC LIMIT 6")->fetchAll();
if ($matches): ?>
<div class="page-wrap">
  <h2 style="font-family:var(--font-head);font-size:28px;letter-spacing:2px;margin-bottom:16px">PRÓXIMOS PARTIDOS</h2>
  <div class="grid-matches mb-4">
  <?php foreach ($matches as $m): ?>
    <a href="/index.php?page=bet&id=<?= (int)$m['id'] ?>" class="match-card status-<?= h($m['status']) ?>">
      <div class="match-teams">
        <div class="match-team"><span class="team-flag"><?= h($m['home_flag']) ?></span><span><?= h($m['home_team']) ?></span></div>
        <span class="match-vs">VS</span>
        <div class="match-team away"><span class="team-flag"><?= h($m['away_flag']) ?></span><span><?= h($m['away_team']) ?></span></div>
      </div>
      <div class="match-meta">
        <span><?= date('d M · H:i', strtotime($m['match_date'])) ?></span>
        <span class="match-pot"><?= formatCedenas((float)$m['pot_total']) ?> en el pozo</span>
      </div>
    </a>
  <?php endforeach; ?>
  </div>
  <div class="grid-3">
    <div class="stat-box"><div class="stat-value"><?= (int)$db->query("SELECT COUNT(*) FROM bets WHERE status='pending'")->fetchColumn() ?></div><div class="stat-label">Apuestas Activas</div></div>
    <div class="stat-box"><div class="stat-value"><?= formatCedenas((float)$db->query("SELECT COALESCE(SUM(pot_total),0) FROM matches WHERE status IN ('open','in_progress')")->fetchColumn()) ?></div><div class="stat-label">Total en Pozos</div></div>
    <div class="stat-box"><div class="stat-value"><?= (int)$db->query("SELECT COUNT(*) FROM users WHERE role='user'")->fetchColumn() ?></div><div class="stat-label">Jugadores</div></div>
  </div>
</div>
<?php endif; ?>
<?php }

// ---- LOGIN ----
function pageLogin(): void { ?>
<div class="auth-wrap">
  <div class="auth-card">
    <div class="auth-logo">CEDEKA <span>WC</span></div>
    <?php renderFlash(); ?>
    <form method="POST" action="/index.php?page=login">
      <?php csrfField(); ?>
      <input type="hidden" name="action" value="login">
      <div class="form-group">
        <label class="form-label">Email</label>
        <input type="email" name="email" class="form-control" placeholder="tu@email.com" required autocomplete="email">
      </div>
      <div class="form-group">
        <label class="form-label">Contraseña</label>
        <input type="password" name="password" class="form-control" placeholder="••••••••" required autocomplete="current-password">
      </div>
      <button type="submit" class="btn btn-primary btn-block btn-lg">Entrar al Estadio ⚽</button>
    </form>
    <p class="text-center mt-2 text-muted fs-sm">¿Sin cuenta? <a href="/index.php?page=register" class="text-gold">Regístrate aquí</a></p>
  </div>
</div>
<?php }

// ---- REGISTER ----
function pageRegister(): void { ?>
<div class="auth-wrap">
  <div class="auth-card">
    <div class="auth-logo">CEDEKA <span>WC</span></div>
    <p class="text-center text-muted fs-sm mb-3">Crea tu cuenta de jugador</p>
    <?php renderFlash(); ?>
    <form method="POST" action="/index.php?page=register">
      <?php csrfField(); ?>
      <input type="hidden" name="action" value="register">
      <div class="form-row">
        <div class="form-group">
          <label class="form-label">Nombre completo</label>
          <input type="text" name="full_name" class="form-control" placeholder="Lionel Cedeka" required maxlength="80">
        </div>
        <div class="form-group">
          <label class="form-label">Usuario</label>
          <input type="text" name="username" class="form-control" placeholder="cedeka10" required maxlength="30" pattern="[a-zA-Z0-9_\-]+" title="Solo letras, números, _ y -">
        </div>
      </div>
      <div class="form-group">
        <label class="form-label">Email</label>
        <input type="email" name="email" class="form-control" placeholder="tu@email.com" required maxlength="150">
      </div>
      <div class="form-row">
        <div class="form-group">
          <label class="form-label">Contraseña</label>
          <input type="password" name="password" class="form-control" placeholder="Min 8 · 1 mayús · 1 número" required minlength="8" maxlength="128" autocomplete="new-password">
        </div>
        <div class="form-group">
          <label class="form-label">Confirmar</label>
          <input type="password" name="password2" class="form-control" placeholder="••••••••" required autocomplete="new-password">
        </div>
      </div>
      <div class="alert alert-info fs-xs mb-2">🔒 Mínimo 8 caracteres · 1 mayúscula · 1 número</div>
      <button type="submit" class="btn btn-primary btn-block btn-lg">Crear Cuenta 🎯</button>
    </form>
    <p class="text-center mt-2 text-muted fs-sm">¿Ya tienes cuenta? <a href="/index.php?page=login" class="text-gold">Inicia sesión</a></p>
  </div>
</div>
<?php }

// ---- MATCHES ----
function pageMatches(?array $user): void {
    $user = requireLogin();
    $db   = getDB();
    $tab  = $_GET['tab'] ?? 'open';
    $statusMap = ['open'=>['open'], 'live'=>['in_progress'], 'done'=>['closed','finished']];
    $statuses  = $statusMap[$tab] ?? ['open'];
    $ph = implode(',', array_fill(0, count($statuses), '?'));
    $stmt = $db->prepare("SELECT m.*, (SELECT COUNT(*) FROM bets b WHERE b.match_id=m.id) as bet_count, (SELECT COUNT(*) FROM bets b WHERE b.match_id=m.id AND b.user_id=?) as my_bets FROM matches m WHERE m.status IN ($ph) ORDER BY m.match_date ASC");
    $stmt->execute(array_merge([$user['id']], $statuses));
    $matches = $stmt->fetchAll();
?>
<div class="page-wrap">
  <?php renderFlash(); ?>
  <h1 class="page-title">PARTI<span>DOS</span></h1>
  <p class="page-subtitle">Elige tu partido y apuesta el minuto exacto del gol</p>
  <div class="flex gap-1 mb-3">
    <a href="?page=matches&tab=open" class="btn <?= $tab==='open'?'btn-primary':'btn-ghost' ?> btn-sm">🟢 Abiertos</a>
    <a href="?page=matches&tab=live" class="btn <?= $tab==='live'?'btn-primary':'btn-ghost' ?> btn-sm">🔴 En Vivo</a>
    <a href="?page=matches&tab=done" class="btn <?= $tab==='done'?'btn-primary':'btn-ghost' ?> btn-sm">✅ Finalizados</a>
  </div>
  <?php if (empty($matches)): ?>
  <div class="empty-state"><div class="icon">⚽</div><h3>Sin partidos en esta categoría</h3></div>
  <?php else: ?>
  <div class="grid-matches">
    <?php foreach ($matches as $m): ?>
    <a href="/index.php?page=bet&id=<?= (int)$m['id'] ?>" class="match-card status-<?= h($m['status']) ?>">
      <div class="flex-between mb-1">
        <?php echo match($m['status']) {
            'open'        => '<span class="badge badge-open">🟢 Abierto</span>',
            'in_progress' => '<span class="badge badge-live">🔴 En Vivo</span>',
            'closed'      => '<span class="badge badge-closed">🔒 Cerrado</span>',
            'finished'    => '<span class="badge badge-done">✅ Finalizado</span>',
            default       => ''
        }; ?>
        <?php if ($m['my_bets'] > 0): ?><span class="badge badge-pending">🎯 Tu apuesta</span><?php endif; ?>
      </div>
      <div class="match-teams">
        <div class="match-team"><span class="team-flag"><?= h($m['home_flag']) ?></span><span><?= h($m['home_team']) ?></span></div>
        <span class="match-vs">VS</span>
        <div class="match-team away"><span class="team-flag"><?= h($m['away_flag']) ?></span><span><?= h($m['away_team']) ?></span></div>
      </div>
      <div class="match-meta">
        <span class="text-muted fs-xs"><?= date('d M · H:i', strtotime($m['match_date'])) ?></span>
        <span class="match-pot"><?= formatCedenas((float)$m['pot_total']) ?></span>
      </div>
    </a>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</div>
<?php }

// ---- BET PAGE ----
function pageBet(?array $user): void {
    $user    = requireLogin();
    $db      = getDB();
    $matchId = (int)($_GET['id'] ?? 0);

    $stmt = $db->prepare("SELECT * FROM matches WHERE id = ?");
    $stmt->execute([$matchId]);
    $match = $stmt->fetch();
    if (!$match) {
        echo '<div class="page-wrap"><div class="alert alert-error">❌ Partido no encontrado</div><a href="/index.php?page=matches" class="btn btn-ghost btn-sm mt-2">← Volver</a></div>';
        return;
    }

    $stmt = $db->prepare("SELECT team, minute FROM bets WHERE match_id=? AND user_id=?");
    $stmt->execute([$matchId, $user['id']]);
    $takenByMe = array_map(fn($b) => $b['team'].'_'.$b['minute'], $stmt->fetchAll());

    $stmt = $db->prepare("SELECT team, minute, COUNT(*) as cnt FROM bets WHERE match_id=? GROUP BY team, minute");
    $stmt->execute([$matchId]);
    $allBets = [];
    foreach ($stmt->fetchAll() as $b) $allBets[$b['team'].'_'.$b['minute']] = (int)$b['cnt'];

    $stmt = $db->prepare("SELECT * FROM goals WHERE match_id=?");
    $stmt->execute([$matchId]);
    $goals = $stmt->fetchAll();
?>
<div class="page-wrap">
  <?php renderFlash(); ?>
  <a href="/index.php?page=matches" class="btn btn-ghost btn-sm mb-3">← Volver</a>
  <div class="card mb-3">
    <div class="flex-between mb-2">
      <?php echo match($match['status']) {
          'open'        => '<span class="badge badge-open">🟢 Abierto</span>',
          'in_progress' => '<span class="badge badge-live">🔴 En Vivo</span>',
          'closed'      => '<span class="badge badge-closed">🔒 Cerrado</span>',
          'finished'    => '<span class="badge badge-done">✅ Finalizado</span>',
          default       => ''
      }; ?>
      <span class="text-muted fs-xs"><?= date('d M Y · H:i', strtotime($match['match_date'])) ?></span>
    </div>
    <div class="match-teams" style="padding:16px 0">
      <div class="match-team" style="font-size:24px;gap:14px">
        <span style="font-size:42px"><?= h($match['home_flag']) ?></span><span><?= h($match['home_team']) ?></span>
      </div>
      <div style="text-align:center">
        <div style="font-family:var(--font-head);font-size:32px;letter-spacing:4px;color:var(--muted)">VS</div>
        <div style="color:var(--gold);font-family:var(--font-sub);font-weight:700;font-size:13px;margin-top:4px">POZO: <?= formatCedenas((float)$match['pot_total']) ?></div>
      </div>
      <div class="match-team away" style="font-size:24px;gap:14px">
        <span style="font-size:42px"><?= h($match['away_flag']) ?></span><span><?= h($match['away_team']) ?></span>
      </div>
    </div>
    <?php if ($goals): ?>
    <div class="card-sm" style="background:var(--bg3);border-radius:8px;margin-top:8px">
      <div class="card-header mb-1">⚽ Goles Registrados</div>
      <?php foreach ($goals as $g): ?>
        <div class="flex-between fs-sm mb-1">
          <span><?= h($g['team']) ?><?= $g['scorer']?' — '.h($g['scorer']):'' ?></span>
          <span class="badge badge-open">Min <?= (int)$g['minute'] ?></span>
        </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>

  <?php if ($match['status'] !== 'open'): ?>
  <div class="alert alert-info">ℹ️ Este partido ya no acepta nuevas apuestas</div>
  <?php
    $stmt = $db->prepare("SELECT * FROM bets WHERE match_id=? AND user_id=? ORDER BY created_at DESC");
    $stmt->execute([$matchId, $user['id']]);
    $myResults = $stmt->fetchAll();
    if ($myResults): ?>
    <div class="card mt-3">
      <div class="card-header">Tus apuestas en este partido</div>
      <div class="table-wrap">
        <table class="data-table">
          <thead><tr><th>Equipo</th><th>Minuto</th><th>Apostado</th><th>Estado</th><th>Premio</th></tr></thead>
          <tbody>
          <?php foreach ($myResults as $b): ?>
          <tr>
            <td><?= h($b['team']) ?></td>
            <td><strong>Min <?= (int)$b['minute'] ?></strong></td>
            <td><?= formatCedenas((float)$b['amount_cedenas']) ?></td>
            <td><?php
              if ($b['status']==='won')  echo '<span class="badge badge-won">🏆 Ganó</span>';
              elseif ($b['status']==='lost') echo '<span class="badge badge-lost">❌ Perdió</span>';
              else echo '<span class="badge badge-pending">⏳ Pendiente</span>';
            ?></td>
            <td class="text-gold fw-bold"><?= $b['prize_cedenas']>0?formatCedenas((float)$b['prize_cedenas']):'—' ?></td>
          </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  <?php endif;
  else: ?>

  <form method="POST" action="/index.php?page=bet" id="betForm">
    <?php csrfField(); ?>
    <input type="hidden" name="action" value="place_bet">
    <input type="hidden" name="match_id" value="<?= (int)$matchId ?>">
    <input type="hidden" name="team" id="teamInput" value="">
    <input type="hidden" name="minute" id="minuteInput" value="">

    <div class="grid-2 mb-3" style="gap:12px">
      <button type="button" class="team-btn card" onclick="selectTeam(<?= json_encode($match['home_team']) ?>)" id="btn-home" style="cursor:pointer;text-align:center;transition:all 0.2s;border:2px solid transparent">
        <div style="font-size:40px"><?= h($match['home_flag']) ?></div>
        <div style="font-family:var(--font-sub);font-weight:700;font-size:18px;margin-top:8px"><?= h($match['home_team']) ?></div>
        <div class="text-muted fs-xs mt-1">Equipo Local</div>
      </button>
      <button type="button" class="team-btn card" onclick="selectTeam(<?= json_encode($match['away_team']) ?>)" id="btn-away" style="cursor:pointer;text-align:center;transition:all 0.2s;border:2px solid transparent">
        <div style="font-size:40px"><?= h($match['away_flag']) ?></div>
        <div style="font-family:var(--font-sub);font-weight:700;font-size:18px;margin-top:8px"><?= h($match['away_team']) ?></div>
        <div class="text-muted fs-xs mt-1">Equipo Visitante</div>
      </button>
    </div>

    <div class="card mb-3" id="minuteSection" style="display:none">
      <div class="card-header">2. ELIGE EL MINUTO DEL GOL (1–90)</div>
      <div class="minute-grid" id="minuteGrid"></div>
      <div id="selectedMinuteDisplay" class="mt-2 text-center text-muted fs-sm" style="min-height:20px"></div>
    </div>

    <div class="card mb-3" id="amountSection" style="display:none">
      <div class="card-header">3. MONTO DE TU APUESTA</div>
      <div class="flex-between mb-2">
        <span class="text-muted fs-sm">Saldo disponible</span>
        <span class="text-gold fw-bold font-sub"><?= formatCedenas((float)$user['balance']) ?></span>
      </div>
      <div class="flex gap-1 mb-2" style="flex-wrap:wrap">
        <?php foreach ([5,10,25,50,100,250] as $q): ?>
          <button type="button" class="btn btn-ghost btn-sm" onclick="setAmount(<?= $q ?>)"><?= formatCedenas($q) ?></button>
        <?php endforeach; ?>
      </div>
      <input type="number" name="amount" id="amountInput" class="form-control" min="<?= MIN_BET ?>" max="<?= min(MAX_BET, (float)$user['balance']) ?>" step="0.01" placeholder="Monto en Cedenas (₵)" required>
      <div class="card mt-3" style="background:var(--bg3)">
        <div class="flex-between fs-sm mb-1"><span class="text-muted">Equipo</span><span id="sumTeam">—</span></div>
        <div class="flex-between fs-sm mb-1"><span class="text-muted">Minuto</span><span id="sumMin">—</span></div>
        <div class="flex-between fs-sm mb-1"><span class="text-muted">Apuesta</span><span id="sumAmt">—</span></div>
        <hr class="divider">
        <div class="flex-between fs-sm"><span class="text-muted">Comisión sitio (10%)</span><span class="text-muted" id="sumComm">—</span></div>
        <div class="flex-between fw-bold mt-1"><span>Premio potencial</span><span class="text-gold">Depende del pozo</span></div>
      </div>
      <button type="submit" class="btn btn-primary btn-block btn-lg mt-3" onclick="return validateBet()">🎯 CONFIRMAR APUESTA</button>
    </div>
  </form>

  <script>
  const takenByMe = <?= json_encode($takenByMe) ?>;
  const allBets   = <?= json_encode($allBets) ?>;
  const homeTeam  = <?= json_encode($match['home_team']) ?>;
  const awayTeam  = <?= json_encode($match['away_team']) ?>;
  let currentTeam = '', currentMinute = 0;

  function selectTeam(team) {
    currentTeam = team;
    document.getElementById('teamInput').value = team;
    document.querySelectorAll('.team-btn').forEach(b => { b.style.borderColor='transparent'; b.style.background='var(--bg2)'; });
    const isHome = (team === homeTeam);
    const btn = document.getElementById(isHome ? 'btn-home' : 'btn-away');
    if (btn) { btn.style.borderColor='var(--gold)'; btn.style.background='rgba(245,200,66,0.07)'; }
    buildMinuteGrid(team);
    document.getElementById('minuteSection').style.display = 'block';
    document.getElementById('amountSection').style.display = 'none';
    currentMinute = 0;
    document.getElementById('minuteInput').value = '';
    document.getElementById('sumTeam').textContent = team;
  }

  function buildMinuteGrid(team) {
    const grid = document.getElementById('minuteGrid');
    grid.innerHTML = '';
    for (let i = 1; i <= 90; i++) {
      const key   = team + '_' + i;
      const taken = takenByMe.includes(key);
      const count = allBets[key] || 0;
      const btn   = document.createElement('button');
      btn.type      = 'button';
      btn.className = 'minute-btn' + (taken ? ' taken' : '');
      btn.textContent = i;
      btn.title = taken ? 'Ya apostaste este minuto' : (count > 0 ? count + ' apuesta(s)' : 'Libre');
      if (count > 0 && !taken) btn.style.opacity = '0.7';
      if (!taken) btn.onclick = () => selectMinute(i, btn);
      grid.appendChild(btn);
    }
  }

  function selectMinute(min, btn) {
    currentMinute = min;
    document.getElementById('minuteInput').value = min;
    document.querySelectorAll('.minute-btn').forEach(b => b.classList.remove('selected'));
    btn.classList.add('selected');
    document.getElementById('selectedMinuteDisplay').textContent = '✅ Minuto ' + min + ' seleccionado';
    document.getElementById('sumMin').textContent = 'Min ' + min;
    document.getElementById('amountSection').style.display = 'block';
    updateSummary();
  }

  function setAmount(v) { document.getElementById('amountInput').value = v; updateSummary(); }
  document.getElementById('amountInput')?.addEventListener('input', updateSummary);

  function updateSummary() {
    const amt = parseFloat(document.getElementById('amountInput')?.value || 0);
    document.getElementById('sumAmt').textContent  = amt > 0 ? '₵ ' + amt.toFixed(2) : '—';
    document.getElementById('sumComm').textContent = amt > 0 ? '₵ ' + (amt*0.1).toFixed(2) : '—';
  }

  function validateBet() {
    if (!currentTeam)   { alert('Selecciona un equipo'); return false; }
    if (!currentMinute) { alert('Selecciona un minuto'); return false; }
    const amt = parseFloat(document.getElementById('amountInput').value || 0);
    if (amt < <?= MIN_BET ?>) { alert('Monto mínimo ₵ <?= MIN_BET ?>'); return false; }
    return true;
  }
  </script>
  <?php endif; ?>
</div>
<?php }

// ---- MY BETS ----
function pageMyBets(?array $user): void {
    $user = requireLogin();
    $db   = getDB();
    $stmt = $db->prepare("SELECT b.*, m.home_team, m.away_team, m.home_flag, m.away_flag, m.match_date, m.status as match_status FROM bets b JOIN matches m ON b.match_id=m.id WHERE b.user_id=? ORDER BY b.created_at DESC");
    $stmt->execute([$user['id']]);
    $bets = $stmt->fetchAll();

    $totalWon = array_sum(array_column(array_filter($bets, fn($b)=>$b['status']==='won'), 'prize_cedenas'));
    $wonCount = count(array_filter($bets, fn($b)=>$b['status']==='won'));
?>
<div class="page-wrap">
  <?php renderFlash(); ?>
  <h1 class="page-title">MIS <span>APUESTAS</span></h1>
  <p class="page-subtitle">Historial y resultados</p>
  <div class="grid-3 mb-4">
    <div class="stat-box"><div class="stat-value"><?= count($bets) ?></div><div class="stat-label">Total</div></div>
    <div class="stat-box"><div class="stat-value text-green"><?= $wonCount ?></div><div class="stat-label">Ganadas</div></div>
    <div class="stat-box"><div class="stat-value text-gold"><?= formatCedenas($totalWon) ?></div><div class="stat-label">Total Ganado</div></div>
  </div>
  <?php if (empty($bets)): ?>
  <div class="empty-state"><div class="icon">🎯</div><h3>Sin apuestas aún</h3><p><a href="/index.php?page=matches" class="text-gold">Ver partidos →</a></p></div>
  <?php else: ?>
  <div class="card">
    <div class="table-wrap">
      <table class="data-table">
        <thead><tr><th>Partido</th><th>Equipo</th><th>Min</th><th>Apuesta</th><th>Estado</th><th>Premio</th><th>Fecha</th></tr></thead>
        <tbody>
        <?php foreach ($bets as $b): ?>
        <tr>
          <td class="fs-sm"><?= h($b['home_flag']) ?> <?= h($b['home_team']) ?> <span class="text-muted">vs</span> <?= h($b['away_team']) ?> <?= h($b['away_flag']) ?></td>
          <td class="fw-bold font-sub"><?= h($b['team']) ?></td>
          <td><span class="badge badge-pending">Min <?= (int)$b['minute'] ?></span></td>
          <td><?= formatCedenas((float)$b['amount_cedenas']) ?></td>
          <td><?php
            if ($b['status']==='won')  echo '<span class="badge badge-won">🏆 Ganó</span>';
            elseif ($b['status']==='lost') echo '<span class="badge badge-lost">❌ Perdió</span>';
            else echo '<span class="badge badge-pending">⏳</span>';
          ?></td>
          <td class="text-gold fw-bold"><?= $b['prize_cedenas']>0?formatCedenas((float)$b['prize_cedenas']):'—' ?></td>
          <td class="text-muted fs-xs"><?= timeAgo($b['created_at']) ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
  <?php endif; ?>
</div>
<?php }

// ---- WALLET ----
function pageWallet(?array $user): void {
    $user = requireLogin();
    $db   = getDB();
    $stmt = $db->prepare("SELECT * FROM transactions WHERE user_id=? ORDER BY created_at DESC LIMIT 50");
    $stmt->execute([$user['id']]);
    $txs = $stmt->fetchAll();
    $stmt = $db->prepare("SELECT COUNT(*) FROM recharge_requests WHERE user_id=? AND status='pending'");
    $stmt->execute([$user['id']]);
    $hasPending = (bool)$stmt->fetchColumn();
?>
<div class="page-wrap">
  <?php renderFlash(); ?>
  <h1 class="page-title">MI <span>WALLET</span></h1>
  <div class="wallet-balance-box mb-4">
    <div class="wallet-label">Saldo Disponible</div>
    <div class="wallet-amount"><?= formatCedenas((float)($user['balance'] ?? 0)) ?></div>
    <div class="wallet-label mt-1">Cedenas (₵)</div>
    <div class="flex-center gap-2 mt-3">
      <?php if (!$hasPending): ?>
        <a href="/index.php?page=recharge" class="btn btn-primary">+ Cargar Cedenas</a>
      <?php else: ?>
        <span class="badge badge-pending">⏳ Recarga en revisión</span>
      <?php endif; ?>
      <a href="/index.php?page=matches" class="btn btn-ghost">⚽ Apostar</a>
    </div>
  </div>
  <div class="card">
    <div class="card-header">Historial de Movimientos</div>
    <?php if (empty($txs)): ?>
      <div class="empty-state" style="padding:32px"><div class="icon">💸</div><h3>Sin movimientos</h3></div>
    <?php else: ?>
    <div class="table-wrap">
      <table class="data-table">
        <thead><tr><th>Tipo</th><th>Descripción</th><th>Monto</th><th>Saldo</th><th>Fecha</th></tr></thead>
        <tbody>
        <?php foreach ($txs as $tx): ?>
        <tr>
          <td><?php
            $icons = ['deposit'=>'💚','bet'=>'🎯','prize'=>'🏆','commission'=>'💼','refund'=>'↩️'];
            $icon  = $icons[$tx['type']] ?? '💱';
            echo "<span class=\"tx-type-{$tx['type']}\">$icon ".ucfirst(h($tx['type']))."</span>";
          ?></td>
          <td class="fs-sm"><?= h($tx['description']) ?></td>
          <td class="fw-bold <?= $tx['amount']>=0?'text-green':'text-red' ?>"><?= ($tx['amount']>=0?'+':'').formatCedenas((float)$tx['amount']) ?></td>
          <td class="text-muted fs-sm"><?= formatCedenas((float)$tx['balance_after']) ?></td>
          <td class="text-muted fs-xs"><?= timeAgo($tx['created_at']) ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>
</div>
<?php }

// ---- RECHARGE ----
function pageRecharge(?array $user): void {
    $user = requireLogin();
?>
<div class="page-wrap" style="max-width:560px">
  <?php renderFlash(); ?>
  <a href="/index.php?page=wallet" class="btn btn-ghost btn-sm mb-3">← Volver</a>
  <h1 class="page-title">CARGAR <span>CEDENAS</span></h1>
  <div class="card">
    <div class="alert alert-info mb-3">ℹ️ Envía tu transferencia y completa el formulario. El admin revisará y acreditará las Cedenas.</div>
    <form method="POST" action="/index.php?page=recharge">
      <?php csrfField(); ?>
      <input type="hidden" name="action" value="recharge_request">
      <div class="form-group">
        <label class="form-label">Monto de Cedenas</label>
        <input type="number" name="amount" class="form-control" min="1" max="100000" step="1" placeholder="Ej: 100" required>
      </div>
      <div class="form-group">
        <label class="form-label">Método de pago</label>
        <select name="payment_method" class="form-control">
          <option value="transferencia">Transferencia Bancaria</option>
          <option value="efectivo">Efectivo</option>
          <option value="crypto">Cripto</option>
          <option value="otro">Otro</option>
        </select>
      </div>
      <div class="form-group">
        <label class="form-label">Datos del comprobante</label>
        <textarea name="receipt_notes" class="form-control" rows="4" maxlength="500" placeholder="Número de transacción, banco, fecha..."></textarea>
      </div>
      <button type="submit" class="btn btn-primary btn-block btn-lg">Enviar Solicitud 💰</button>
    </form>
  </div>
</div>
<?php }

function page404(): void { ?>
<div class="page-wrap text-center" style="padding-top:80px">
  <div style="font-size:80px">⚽</div>
  <h1 class="page-title mt-2">404</h1>
  <p class="text-muted">Página no encontrada</p>
  <a href="/index.php" class="btn btn-primary mt-3">Volver al Inicio</a>
</div>
<?php }
