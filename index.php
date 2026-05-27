<?php
ob_start();
// =============================================
// CEDEKA WORLD CUP — Router Principal (HARDENED)
// =============================================
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/telegram.php';
require_once __DIR__ . '/includes/telegram.php';


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
    case 'profile':  pageProfile($user);  break;
    case 'ranking':   pageRanking($user);  break;
    case 'profile':  pageProfile($user);  break;
    case 'ranking':   pageRanking($user);  break;
    case 'profile':  pageProfile($user);  break;
    case 'ranking':   pageRanking($user);  break;
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
            // Notificar al admin por Telegram
            $matchName = $match['home_team'] . ' vs ' . $match['away_team'];
            notifyNewBet($user, $matchName, $team, $minute, $amount);
            flash('success', "🎯 ¡Apuesta registrada! {$team} en el minuto {$minute}");
            redirect('/index.php?page=my_bets');
        } catch (Exception $e) {
            $db->rollBack();
            error_log('[CEDEKA BET] uid='.$user['id'].' '.$e->getMessage());
            flash('error', 'Error al registrar la apuesta. Intenta de nuevo.');
            redirect("/index.php?page=bet&id=$matchId");
        }
    }

    // ---- UPDATE PROFILE ----
    if ($action === 'update_profile') {
        csrfVerify();
        if (!$user) redirect('/index.php?page=login');

        $name   = mb_substr(trim($_POST['full_name'] ?? ''), 0, 80);
        $avatar = trim($_POST['avatar'] ?? '⚽');
        $avatars = ['⚽','🏆','🥅','👟','🦅','🔥','⭐','🎯','🦁','🐉','💎','🎪','🌟','⚡','🦊','🎭'];
        if (!in_array($avatar, $avatars, true)) $avatar = '⚽';
        if (strlen($name) < 2) { flash('error', 'Nombre muy corto'); redirect('/index.php?page=profile'); }

        // Change password (optional)
        $newPass  = $_POST['new_password']     ?? '';
        $currPass = $_POST['current_password'] ?? '';

        if ($newPass !== '') {
            if (!password_verify($currPass, $user['password_hash'])) {
                flash('error', 'Contraseña actual incorrecta');
                redirect('/index.php?page=profile');
            }
            if ($err = validatePassword($newPass)) { flash('error', $err); redirect('/index.php?page=profile'); }
            $hash = password_hash($newPass, PASSWORD_BCRYPT, ['cost' => 12]);
            $db->prepare("UPDATE users SET full_name=?, avatar=?, password_hash=? WHERE id=?")
               ->execute([$name, $avatar, $hash, $user['id']]);
        } else {
            $db->prepare("UPDATE users SET full_name=?, avatar=? WHERE id=?")->execute([$name, $avatar, $user['id']]);
        }

        flash('success', 'Perfil actualizado ✅');
        redirect('/index.php?page=profile');
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
        // Notificar al admin por Telegram
        notifyRechargeRequest($user, $amount, $notes);
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
    <!-- Login con Google -->
    <a href="/auth/google/login.php" class="btn btn-block btn-lg mb-3" style="background:#fff;color:#1f1f1f;border:1px solid #ddd;font-family:var(--font-body);font-weight:600;text-transform:none;letter-spacing:0;font-size:15px;gap:10px">
      <svg width="20" height="20" viewBox="0 0 48 48"><path fill="#EA4335" d="M24 9.5c3.54 0 6.71 1.22 9.21 3.6l6.85-6.85C35.9 2.38 30.47 0 24 0 14.62 0 6.51 5.38 2.56 13.22l7.98 6.19C12.43 13.08 17.74 9.5 24 9.5z"/><path fill="#4285F4" d="M46.98 24.55c0-1.57-.15-3.09-.38-4.55H24v9.02h12.94c-.58 2.96-2.26 5.48-4.78 7.18l7.73 6c4.51-4.18 7.09-10.36 7.09-17.65z"/><path fill="#FBBC05" d="M10.53 28.59c-.48-1.45-.76-2.99-.76-4.59s.27-3.14.76-4.59l-7.98-6.19C.92 16.46 0 20.12 0 24c0 3.88.92 7.54 2.56 10.78l7.97-6.19z"/><path fill="#34A853" d="M24 48c6.48 0 11.93-2.13 15.89-5.81l-7.73-6c-2.15 1.45-4.92 2.3-8.16 2.3-6.26 0-11.57-3.59-13.46-8.91l-7.98 6.19C6.51 42.62 14.62 48 24 48z"/></svg>
      Continuar con Google
    </a>

    <div style="display:flex;align-items:center;gap:12px;margin-bottom:20px">
      <hr style="flex:1;border:none;border-top:1px solid var(--border)">
      <span style="font-size:12px;color:var(--text-dim)">o ingresá con email</span>
      <hr style="flex:1;border:none;border-top:1px solid var(--border)">
    </div>

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
    <!-- Registro con Google -->
    <a href="/auth/google/login.php" class="btn btn-block btn-lg mb-3" style="background:#fff;color:#1f1f1f;border:1px solid #ddd;font-family:var(--font-body);font-weight:600;text-transform:none;letter-spacing:0;font-size:15px;gap:10px">
      <svg width="20" height="20" viewBox="0 0 48 48"><path fill="#EA4335" d="M24 9.5c3.54 0 6.71 1.22 9.21 3.6l6.85-6.85C35.9 2.38 30.47 0 24 0 14.62 0 6.51 5.38 2.56 13.22l7.98 6.19C12.43 13.08 17.74 9.5 24 9.5z"/><path fill="#4285F4" d="M46.98 24.55c0-1.57-.15-3.09-.38-4.55H24v9.02h12.94c-.58 2.96-2.26 5.48-4.78 7.18l7.73 6c4.51-4.18 7.09-10.36 7.09-17.65z"/><path fill="#FBBC05" d="M10.53 28.59c-.48-1.45-.76-2.99-.76-4.59s.27-3.14.76-4.59l-7.98-6.19C.92 16.46 0 20.12 0 24c0 3.88.92 7.54 2.56 10.78l7.97-6.19z"/><path fill="#34A853" d="M24 48c6.48 0 11.93-2.13 15.89-5.81l-7.73-6c-2.15 1.45-4.92 2.3-8.16 2.3-6.26 0-11.57-3.59-13.46-8.91l-7.98 6.19C6.51 42.62 14.62 48 24 48z"/></svg>
      Registrarse con Google
    </a>

    <div style="display:flex;align-items:center;gap:12px;margin-bottom:16px">
      <hr style="flex:1;border:none;border-top:1px solid var(--border)">
      <span style="font-size:12px;color:var(--text-dim)">o creá tu cuenta</span>
      <hr style="flex:1;border:none;border-top:1px solid var(--border)">
    </div>

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
<div class="page-wrap" style="max-width:600px">
  <?php renderFlash(); ?>
  <a href="/index.php?page=wallet" class="btn btn-ghost btn-sm mb-3">← Volver</a>
  <h1 class="page-title">CARGAR <span>CEDENAS</span></h1>
  <p class="page-subtitle">1 peso argentino = 1 Cedena ₵</p>

  <!-- Paso 1 -->
  <div class="card mb-3">
    <div style="display:flex;align-items:center;gap:12px;margin-bottom:16px">
      <div style="width:32px;height:32px;background:var(--gold);border-radius:50%;display:flex;align-items:center;justify-content:center;font-family:var(--font-head);font-size:18px;color:#000;flex-shrink:0">1</div>
      <div>
        <div style="font-family:var(--font-sub);font-weight:700;font-size:16px;text-transform:uppercase;letter-spacing:1px">Doná en Ceneka</div>
        <div style="font-size:12px;color:var(--text-dim)">Elegí el monto que querés cargar en pesos</div>
      </div>
    </div>
    <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:10px;margin-bottom:16px">
      <?php foreach ([500,1000,2000,5000,10000,20000] as $m): ?>
      <a href="https://ceneka.net/Dnt894" target="_blank"
         style="background:var(--bg3);border:1px solid var(--border);border-radius:8px;padding:12px;text-align:center;text-decoration:none;display:block;transition:all 0.2s"
         onmouseover="this.style.borderColor='rgba(245,200,66,0.4)'"
         onmouseout="this.style.borderColor='var(--border)'">
        <div style="font-family:var(--font-head);font-size:22px;color:var(--gold)">₵<?= number_format($m) ?></div>
        <div style="font-size:11px;color:var(--text-dim);margin-top:2px">$<?= number_format($m) ?> ARS</div>
      </a>
      <?php endforeach; ?>
    </div>
    <a href="https://ceneka.net/Dnt894" target="_blank" class="btn btn-primary btn-block btn-lg">
      💜 Ir a Ceneka a Donar
    </a>
    <p style="text-align:center;font-size:11px;color:var(--text-dim);margin-top:8px">
      Mercado Pago · Tarjetas · Crypto · y más
    </p>
  </div>

  <!-- Paso 2 -->
  <div class="card mb-3">
    <div style="display:flex;align-items:center;gap:12px;margin-bottom:16px">
      <div style="width:32px;height:32px;background:var(--green);border-radius:50%;display:flex;align-items:center;justify-content:center;font-family:var(--font-head);font-size:18px;color:#000;flex-shrink:0">2</div>
      <div>
        <div style="font-family:var(--font-sub);font-weight:700;font-size:16px;text-transform:uppercase;letter-spacing:1px">Avisá que donaste</div>
        <div style="font-size:12px;color:var(--text-dim)">Completá el formulario para que el admin acredite tus Cedenas</div>
      </div>
    </div>
    <form method="POST" action="/index.php?page=recharge">
      <?php csrfField(); ?>
      <input type="hidden" name="action" value="recharge_request">
      <input type="hidden" name="payment_method" value="ceneka">
      <div class="form-group">
        <label class="form-label">Cuánto donaste en pesos ARS</label>
        <div style="position:relative">
          <span style="position:absolute;left:14px;top:50%;transform:translateY(-50%);color:var(--text-dim);font-family:var(--font-sub);font-weight:700">$</span>
          <input type="number" name="amount" class="form-control" min="1" max="100000" step="1"
                 placeholder="Ej: 1000" required style="padding-left:28px"
                 oninput="updateCedenas(this.value)">
        </div>
        <div id="cedenasPreview" style="margin-top:6px;font-size:13px;color:var(--text-dim)">= 0 Cedenas ₵</div>
      </div>
      <div class="form-group">
        <label class="form-label">Tu nombre en Ceneka o referencia del pago</label>
        <input type="text" name="receipt_notes" class="form-control" maxlength="500"
               placeholder="Ej: DanteTrb · o el ID de transacción de MP" required>
        <div style="font-size:11px;color:var(--text-dim);margin-top:4px">Esto nos ayuda a identificar tu donación en Ceneka</div>
      </div>
      <button type="submit" class="btn btn-green btn-block btn-lg">
        ✅ Ya doné, acreditá mis Cedenas
      </button>
    </form>
  </div>

  <!-- Paso 3 -->
  <div class="card" style="background:rgba(61,169,252,0.05);border-color:rgba(61,169,252,0.2)">
    <div style="display:flex;align-items:center;gap:12px">
      <div style="width:32px;height:32px;background:var(--blue);border-radius:50%;display:flex;align-items:center;justify-content:center;font-family:var(--font-head);font-size:18px;color:#000;flex-shrink:0">3</div>
      <div>
        <div style="font-family:var(--font-sub);font-weight:700;font-size:16px;text-transform:uppercase;letter-spacing:1px;color:var(--blue)">Recibí tus Cedenas</div>
        <div style="font-size:12px;color:var(--text-dim)">El admin verifica tu donación en Ceneka y acredita tus Cedenas. Normalmente en menos de 24 horas.</div>
      </div>
    </div>
  </div>
</div>
<script>
function updateCedenas(val) {
  const n = parseInt(val) || 0;
  const el = document.getElementById('cedenasPreview');
  el.textContent = '= ' + n.toLocaleString('es-AR') + ' Cedenas ₵';
  el.style.color = n > 0 ? 'var(--gold)' : 'var(--text-dim)';
}
</script>
<?php }

function pageProfile(?array $user): void {
    $user = requireLogin();
    $db   = getDB();

    // Estadísticas del usuario
    $stmt = $db->prepare("SELECT
        COUNT(*) as total_bets,
        SUM(CASE WHEN status='won'  THEN 1 ELSE 0 END) as won_bets,
        SUM(CASE WHEN status='lost' THEN 1 ELSE 0 END) as lost_bets,
        SUM(CASE WHEN status='pending' THEN 1 ELSE 0 END) as pending_bets,
        COALESCE(SUM(amount_cedenas), 0) as total_wagered,
        COALESCE(SUM(prize_cedenas), 0) as total_won
        FROM bets WHERE user_id = ?");
    $stmt->execute([$user['id']]);
    $stats = $stmt->fetch();

    $winRate = $stats['total_bets'] > 0 ? round(($stats['won_bets'] / $stats['total_bets']) * 100) : 0;

    // Mejor racha
    $stmt = $db->prepare("SELECT status FROM bets WHERE user_id=? AND status != 'pending' ORDER BY created_at DESC LIMIT 20");
    $stmt->execute([$user['id']]);
    $recent = $stmt->fetchAll();
    $streak = 0;
    foreach ($recent as $b) {
        if ($b['status'] === 'won') $streak++;
        else break;
    }

    // Apuestas recientes
    $stmt = $db->prepare("SELECT b.*, m.home_team, m.away_team FROM bets b JOIN matches m ON b.match_id=m.id WHERE b.user_id=? ORDER BY b.created_at DESC LIMIT 5");
    $stmt->execute([$user['id']]);
    $recentBets = $stmt->fetchAll();

    $avatars = ['⚽','🏆','🥅','👟','🦅','🔥','⭐','🎯','🦁','🐉','💎','🎪','🌟','⚡','🦊','🎭'];
?>
<div class="page-wrap" style="max-width:800px">
  <?php renderFlash(); ?>

  <!-- Header perfil -->
  <div class="card mb-3" style="background:linear-gradient(135deg,var(--bg2) 0%,rgba(26,58,107,0.3) 100%);border-color:rgba(201,168,76,0.2)">
    <div style="display:flex;align-items:center;gap:20px;flex-wrap:wrap">
      <div style="width:80px;height:80px;background:var(--bg3);border:2px solid rgba(201,168,76,0.3);border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:42px;flex-shrink:0">
        <?= h($user['avatar']) ?>
      </div>
      <div style="flex:1;min-width:0">
        <div style="font-family:var(--font-head);font-size:28px;letter-spacing:2px;color:#fff"><?= h($user['full_name']) ?></div>
        <div style="color:var(--text-dim);font-size:13px">@<?= h($user['username']) ?> · <?= h($user['email']) ?></div>
        <div style="margin-top:6px;display:flex;gap:8px;flex-wrap:wrap">
          <?php if ($user['role']==='admin'): ?>
            <span class="badge badge-done">👑 Admin</span>
          <?php else: ?>
            <span class="badge badge-open">🎮 Jugador</span>
          <?php endif; ?>
          <?php if ($streak >= 3): ?>
            <span class="badge badge-won">🔥 Racha de <?= $streak ?></span>
          <?php endif; ?>
          <?php if ($stats['won_bets'] >= 5): ?>
            <span class="badge badge-pending">⭐ Veterano</span>
          <?php endif; ?>
        </div>
      </div>
      <div style="text-align:right">
        <div style="font-family:var(--font-head);font-size:32px;color:var(--gold)"><?= formatCedenas((float)$user['balance']) ?></div>
        <div style="font-size:11px;color:var(--text-dim);text-transform:uppercase;letter-spacing:1px">Saldo actual</div>
      </div>
    </div>
  </div>

  <!-- Stats -->
  <div class="grid-3 mb-3" style="grid-template-columns:repeat(3,1fr)">
    <div class="stat-box">
      <div class="stat-value"><?= (int)$stats['total_bets'] ?></div>
      <div class="stat-label">Apuestas</div>
    </div>
    <div class="stat-box">
      <div class="stat-value text-green"><?= $winRate ?>%</div>
      <div class="stat-label">Win Rate</div>
    </div>
    <div class="stat-box">
      <div class="stat-value text-gold"><?= formatCedenas((float)$stats['total_won']) ?></div>
      <div class="stat-label">Total Ganado</div>
    </div>
  </div>

  <div class="grid-2 mb-3">
    <!-- Apuestas recientes -->
    <div class="card">
      <div class="card-header">Últimas Apuestas</div>
      <?php if (empty($recentBets)): ?>
        <div class="empty-state" style="padding:24px">
          <div class="icon">🎯</div>
          <h3>Sin apuestas aún</h3>
          <a href="/index.php?page=matches" class="btn btn-primary btn-sm mt-1">Apostar ahora</a>
        </div>
      <?php else: ?>
        <?php foreach ($recentBets as $b): ?>
        <div class="flex-between mb-2" style="padding-bottom:8px;border-bottom:1px solid var(--border)">
          <div>
            <div class="fs-sm fw-bold font-sub"><?= h($b['team']) ?> — Min <?= (int)$b['minute'] ?></div>
            <div class="fs-xs text-muted"><?= h($b['home_team']) ?> vs <?= h($b['away_team']) ?></div>
          </div>
          <div style="text-align:right">
            <?php if ($b['status']==='won'): ?>
              <span class="badge badge-won">🏆 +<?= formatCedenas((float)$b['prize_cedenas']) ?></span>
            <?php elseif ($b['status']==='lost'): ?>
              <span class="badge badge-lost">❌</span>
            <?php else: ?>
              <span class="badge badge-pending">⏳</span>
            <?php endif; ?>
          </div>
        </div>
        <?php endforeach; ?>
        <a href="/index.php?page=my_bets" class="btn btn-ghost btn-sm btn-block mt-2">Ver todas →</a>
      <?php endif; ?>
    </div>

    <!-- Editar perfil -->
    <div class="card">
      <div class="card-header">Editar Perfil</div>
      <form method="POST" action="/index.php?page=profile">
        <?php csrfField(); ?>
        <input type="hidden" name="action" value="update_profile">
        <div class="form-group">
          <label class="form-label">Nombre</label>
          <input type="text" name="full_name" class="form-control" value="<?= h($user['full_name']) ?>" required maxlength="80">
        </div>
        <div class="form-group">
          <label class="form-label">Elige tu avatar</label>
          <div style="display:flex;flex-wrap:wrap;gap:8px;margin-top:4px">
            <?php foreach ($avatars as $av): ?>
            <label style="cursor:pointer">
              <input type="radio" name="avatar" value="<?= h($av) ?>" <?= $user['avatar']===$av?'checked':'' ?> style="display:none">
              <span class="avatar-option" style="width:36px;height:36px;display:flex;align-items:center;justify-content:center;font-size:22px;border-radius:8px;border:2px solid <?= $user['avatar']===$av?'var(--gold)':'var(--border)' ?>;background:<?= $user['avatar']===$av?'rgba(201,168,76,0.1)':'var(--bg3)' ?>;transition:all 0.15s;cursor:pointer"
                onclick="selectAvatar(this, '<?= h($av) ?>')"><?= h($av) ?></span>
            </label>
            <?php endforeach; ?>
          </div>
        </div>
        <hr class="divider">
        <div class="form-group">
          <label class="form-label">Contraseña actual (solo si querés cambiarla)</label>
          <input type="password" name="current_password" class="form-control" placeholder="••••••••" autocomplete="current-password">
        </div>
        <div class="form-group">
          <label class="form-label">Nueva contraseña</label>
          <input type="password" name="new_password" class="form-control" placeholder="Min 8 · 1 mayús · 1 número" autocomplete="new-password">
        </div>
        <button type="submit" class="btn btn-primary btn-block">Guardar Cambios</button>
      </form>
    </div>
  </div>
</div>

<script>
function selectAvatar(el, avatar) {
  document.querySelectorAll('.avatar-option').forEach(a => {
    a.style.borderColor = 'var(--border)';
    a.style.background  = 'var(--bg3)';
  });
  el.style.borderColor = 'var(--gold)';
  el.style.background  = 'rgba(201,168,76,0.1)';
}
</script>
<?php }

// ---- PROFILE ----
function page404(): void { ?>
<div class="page-wrap text-center" style="padding-top:80px">
  <div style="font-size:80px">⚽</div>
  <h1 class="page-title mt-2">404</h1>
  <p class="text-muted">Página no encontrada</p>
  <a href="/index.php" class="btn btn-primary mt-3">Volver al Inicio</a>
</div>
<?php }

// =============================================
// PÁGINA DE PERFIL — agregada al final
// =============================================

// =============================================
// PÁGINA DE PERFIL
// =============================================
function pageRanking(?array $user): void {
    $db = getDB();

    // Stats globales
    $stats = [
        'total_bets'     => (int)$db->query("SELECT COUNT(*) FROM bets")->fetchColumn(),
        'total_won'      => (int)$db->query("SELECT COUNT(*) FROM bets WHERE status='won'")->fetchColumn(),
        'total_pot'      => (float)$db->query("SELECT COALESCE(SUM(pot_total),0) FROM matches")->fetchColumn(),
        'total_prizes'   => (float)$db->query("SELECT COALESCE(SUM(prize_cedenas),0) FROM bets WHERE status='won'")->fetchColumn(),
        'total_players'  => (int)$db->query("SELECT COUNT(*) FROM users WHERE role='user'")->fetchColumn(),
        'total_matches'  => (int)$db->query("SELECT COUNT(*) FROM matches")->fetchColumn(),
        'open_matches'   => (int)$db->query("SELECT COUNT(*) FROM matches WHERE status='open'")->fetchColumn(),
        'active_pot'     => (float)$db->query("SELECT COALESCE(SUM(pot_total),0) FROM matches WHERE status IN ('open','in_progress')")->fetchColumn(),
    ];

    // Ranking por ganancias totales
    $rankingGanancias = $db->query("
        SELECT u.username, u.avatar, u.full_name,
               COUNT(b.id) as total_bets,
               SUM(CASE WHEN b.status='won' THEN 1 ELSE 0 END) as won_bets,
               COALESCE(SUM(b.prize_cedenas),0) as total_won,
               COALESCE(SUM(b.amount_cedenas),0) as total_bet,
               ROUND(SUM(CASE WHEN b.status='won' THEN 1 ELSE 0 END) * 100.0 / NULLIF(COUNT(b.id),0), 1) as win_rate
        FROM users u
        LEFT JOIN bets b ON u.id = b.user_id
        WHERE u.role = 'user'
        GROUP BY u.id, u.username, u.avatar, u.full_name
        HAVING total_bets > 0
        ORDER BY total_won DESC
        LIMIT 20
    ")->fetchAll();

    // Ranking por win rate (mínimo 3 apuestas)
    $rankingWinRate = $db->query("
        SELECT u.username, u.avatar,
               COUNT(b.id) as total_bets,
               SUM(CASE WHEN b.status='won' THEN 1 ELSE 0 END) as won_bets,
               COALESCE(SUM(b.prize_cedenas),0) as total_won,
               ROUND(SUM(CASE WHEN b.status='won' THEN 1 ELSE 0 END) * 100.0 / NULLIF(COUNT(b.id),0), 1) as win_rate
        FROM users u
        LEFT JOIN bets b ON u.id = b.user_id
        WHERE u.role = 'user'
        GROUP BY u.id, u.username, u.avatar
        HAVING total_bets >= 3
        ORDER BY win_rate DESC, total_won DESC
        LIMIT 10
    ")->fetchAll();

    // Minutos más apostados
    $hotMinutes = $db->query("
        SELECT minute, COUNT(*) as cnt
        FROM bets
        GROUP BY minute
        ORDER BY cnt DESC
        LIMIT 10
    ")->fetchAll();

    // Equipos más apostados
    $hotTeams = $db->query("
        SELECT team, COUNT(*) as cnt, 
               SUM(CASE WHEN status='won' THEN 1 ELSE 0 END) as wins
        FROM bets
        GROUP BY team
        ORDER BY cnt DESC
        LIMIT 8
    ")->fetchAll();

    // Partidos con más acción
    $hotMatches = $db->query("
        SELECT m.home_team, m.away_team, m.home_flag, m.away_flag, m.status,
               COUNT(b.id) as bet_count,
               COALESCE(SUM(b.amount_cedenas),0) as pot
        FROM matches m
        LEFT JOIN bets b ON m.id = b.match_id
        GROUP BY m.id
        ORDER BY bet_count DESC
        LIMIT 5
    ")->fetchAll();

    // Posición del usuario actual en el ranking
    $myRank = null;
    if ($user) {
        $myStats = $db->prepare("
            SELECT COUNT(*) as total_bets,
                   SUM(CASE WHEN status='won' THEN 1 ELSE 0 END) as won_bets,
                   COALESCE(SUM(prize_cedenas),0) as total_won
            FROM bets WHERE user_id=?
        ");
        $myStats->execute([$user['id']]);
        $myStats = $myStats->fetch();
    }
?>
<div class="page-wrap-wide">
  <h1 class="page-title">RANKING <span>&amp; STATS</span></h1>
  <p class="page-subtitle">Estadísticas globales y los mejores jugadores de Cedeka WC</p>

  <!-- Stats globales -->
  <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:28px">
    <div class="stat-box">
      <div class="stat-value"><?= number_format($stats['total_players']) ?></div>
      <div class="stat-label">Jugadores</div>
    </div>
    <div class="stat-box">
      <div class="stat-value"><?= number_format($stats['total_bets']) ?></div>
      <div class="stat-label">Apuestas totales</div>
    </div>
    <div class="stat-box">
      <div class="stat-value text-gold"><?= formatCedenas($stats['active_pot']) ?></div>
      <div class="stat-label">En juego ahora</div>
    </div>
    <div class="stat-box">
      <div class="stat-value text-green"><?= formatCedenas($stats['total_prizes']) ?></div>
      <div class="stat-label">Total repartido</div>
    </div>
  </div>

  <?php if ($user && $myStats && (int)$myStats['total_bets'] > 0): ?>
  <!-- Tu posición -->
  <div class="card mb-4" style="background:linear-gradient(135deg,rgba(26,58,107,0.3),rgba(201,168,76,0.08));border-color:rgba(201,168,76,0.25)">
    <div style="display:flex;align-items:center;gap:16px;flex-wrap:wrap">
      <div style="font-size:40px"><?= h($user['avatar']) ?></div>
      <div style="flex:1">
        <div style="font-family:var(--font-sub);font-weight:700;font-size:16px;color:var(--gold)">TU RENDIMIENTO</div>
        <div style="font-size:13px;color:var(--text-dim)">@<?= h($user['username']) ?></div>
      </div>
      <div style="display:flex;gap:24px;flex-wrap:wrap">
        <div style="text-align:center">
          <div style="font-family:var(--font-head);font-size:28px;color:#fff"><?= (int)$myStats['total_bets'] ?></div>
          <div style="font-size:10px;color:var(--text-dim);text-transform:uppercase">Apuestas</div>
        </div>
        <div style="text-align:center">
          <div style="font-family:var(--font-head);font-size:28px;color:var(--green)"><?= (int)$myStats['won_bets'] ?></div>
          <div style="font-size:10px;color:var(--text-dim);text-transform:uppercase">Ganadas</div>
        </div>
        <div style="text-align:center">
          <div style="font-family:var(--font-head);font-size:28px;color:var(--gold)"><?= formatCedenas((float)$myStats['total_won']) ?></div>
          <div style="font-size:10px;color:var(--text-dim);text-transform:uppercase">Ganado</div>
        </div>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <div class="grid-2" style="gap:20px;margin-bottom:20px">

    <!-- Ranking por ganancias -->
    <div class="card">
      <div class="card-header">🏆 TOP GANADORES — Por Cedenas Ganadas</div>
      <?php if (empty($rankingGanancias)): ?>
        <div class="empty-state" style="padding:24px"><div class="icon">🏆</div><h3>Sin datos aún</h3></div>
      <?php else: ?>
      <?php foreach ($rankingGanancias as $i => $p): ?>
      <?php
        $medal = match($i) { 0 => '🥇', 1 => '🥈', 2 => '🥉', default => '#'.($i+1) };
        $isMe  = $user && $user['username'] === $p['username'];
      ?>
      <div style="display:flex;align-items:center;gap:12px;padding:10px 0;border-bottom:1px solid var(--border);<?= $isMe?'background:rgba(201,168,76,0.05);margin:0 -16px;padding:10px 16px;':'' ?>">
        <div style="font-size:<?= $i<3?'22px':'14px' ?>;width:28px;text-align:center;font-family:var(--font-head);color:var(--text-dim)"><?= $medal ?></div>
        <div style="font-size:24px"><?= h($p['avatar']) ?></div>
        <div style="flex:1;min-width:0">
          <div style="font-family:var(--font-sub);font-weight:700;font-size:14px;<?= $isMe?'color:var(--gold)':'' ?>"><?= h($p['username']) ?> <?= $isMe?'← vos':'' ?></div>
          <div style="font-size:11px;color:var(--text-dim)"><?= $p['total_bets'] ?> apuestas · <?= $p['win_rate'] ?>% win</div>
        </div>
        <div style="text-align:right">
          <div style="font-family:var(--font-head);font-size:18px;color:var(--gold)"><?= formatCedenas((float)$p['total_won']) ?></div>
        </div>
      </div>
      <?php endforeach; ?>
      <?php endif; ?>
    </div>

    <!-- Ranking por win rate -->
    <div class="card">
      <div class="card-header">🎯 TOP WIN RATE — Mín. 3 apuestas</div>
      <?php if (empty($rankingWinRate)): ?>
        <div class="empty-state" style="padding:24px"><div class="icon">🎯</div><h3>Sin datos aún</h3></div>
      <?php else: ?>
      <?php foreach ($rankingWinRate as $i => $p): ?>
      <?php
        $medal = match($i) { 0 => '🥇', 1 => '🥈', 2 => '🥉', default => '#'.($i+1) };
        $isMe  = $user && $user['username'] === $p['username'];
      ?>
      <div style="display:flex;align-items:center;gap:12px;padding:10px 0;border-bottom:1px solid var(--border)">
        <div style="font-size:<?= $i<3?'22px':'14px' ?>;width:28px;text-align:center;font-family:var(--font-head);color:var(--text-dim)"><?= $medal ?></div>
        <div style="font-size:24px"><?= h($p['avatar']) ?></div>
        <div style="flex:1">
          <div style="font-family:var(--font-sub);font-weight:700;font-size:14px;<?= $isMe?'color:var(--gold)':'' ?>"><?= h($p['username']) ?></div>
          <div style="font-size:11px;color:var(--text-dim)"><?= $p['won_bets'] ?>/<?= $p['total_bets'] ?> acertadas</div>
        </div>
        <div style="text-align:right">
          <div style="font-family:var(--font-head);font-size:22px;color:var(--green)"><?= $p['win_rate'] ?>%</div>
        </div>
      </div>
      <?php endforeach; ?>
      <?php endif; ?>
    </div>

  </div>

  <div class="grid-2" style="gap:20px">

    <!-- Minutos más apostados -->
    <div class="card">
      <div class="card-header">⏱ MINUTOS MÁS APOSTADOS</div>
      <?php if (empty($hotMinutes)): ?>
        <div class="empty-state" style="padding:24px"><div class="icon">⏱</div><h3>Sin datos aún</h3></div>
      <?php else: ?>
      <?php
        $maxCnt = max(array_column($hotMinutes, 'cnt'));
        foreach ($hotMinutes as $i => $m):
          $pct = $maxCnt > 0 ? ($m['cnt'] / $maxCnt) * 100 : 0;
      ?>
      <div style="margin-bottom:10px">
        <div style="display:flex;justify-content:space-between;margin-bottom:4px">
          <span style="font-family:var(--font-sub);font-weight:700;font-size:13px">Minuto <?= (int)$m['minute'] ?></span>
          <span style="font-size:12px;color:var(--text-dim)"><?= (int)$m['cnt'] ?> apuestas</span>
        </div>
        <div style="background:var(--bg3);border-radius:4px;height:6px;overflow:hidden">
          <div style="width:<?= $pct ?>%;background:linear-gradient(90deg,var(--blue2),var(--gold));height:100%;border-radius:4px;transition:width 0.5s"></div>
        </div>
      </div>
      <?php endforeach; ?>
      <?php endif; ?>
    </div>

    <!-- Equipos más apostados -->
    <div class="card">
      <div class="card-header">🏳 EQUIPOS MÁS APOSTADOS</div>
      <?php if (empty($hotTeams)): ?>
        <div class="empty-state" style="padding:24px"><div class="icon">🏳</div><h3>Sin datos aún</h3></div>
      <?php else: ?>
      <?php
        $maxT = max(array_column($hotTeams, 'cnt'));
        foreach ($hotTeams as $t):
          $pct = $maxT > 0 ? ($t['cnt'] / $maxT) * 100 : 0;
          $wr  = $t['cnt'] > 0 ? round(($t['wins'] / $t['cnt']) * 100, 1) : 0;
      ?>
      <div style="margin-bottom:12px">
        <div style="display:flex;justify-content:space-between;margin-bottom:4px">
          <span style="font-family:var(--font-sub);font-weight:700;font-size:13px"><?= h($t['team']) ?></span>
          <span style="font-size:11px;color:var(--text-dim)"><?= (int)$t['cnt'] ?> apuestas · <?= $wr ?>% acertaron</span>
        </div>
        <div style="background:var(--bg3);border-radius:4px;height:6px;overflow:hidden">
          <div style="width:<?= $pct ?>%;background:linear-gradient(90deg,var(--gold),var(--gold2));height:100%;border-radius:4px"></div>
        </div>
      </div>
      <?php endforeach; ?>
      <?php endif; ?>
    </div>

  </div>
</div>
<?php }