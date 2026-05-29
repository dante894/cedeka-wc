<?php
ob_start();
// =============================================
// CEDEKA WORLD CUP — Admin Panel (HARDENED)
// =============================================
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/telegram.php';
require_once __DIR__ . '/../includes/telegram.php';


startSession();
$user = requireAdmin();
$page = $_GET['page'] ?? 'dashboard';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfVerify();   // ← CSRF en TODO post del admin
    adminHandlePost($user);
}

renderHead('Admin — ' . ucfirst($page));
renderNav($user);

echo '<div class="admin-layout">';
renderAdminSidebar($page);
// Mobile nav
$p = $page;
echo '<nav class="admin-mobile-nav">
  <a href="/admin/index.php?page=dashboard" class="'.($p==='dashboard'?'active':''). '">📊 Dashboard</a>
  <a href="/admin/index.php?page=matches"   class="'.($p==='matches'?'active':'').   '">⚽ Partidos</a>
  <a href="/admin/index.php?page=match_new" class="'.($p==='match_new'?'active':'').'"  >➕ Nuevo</a>
  <a href="/admin/index.php?page=goals"     class="'.($p==='goals'?'active':'').     '">🥅 Goles</a>
  <a href="/admin/index.php?page=players"   class="'.($p==='players'?'active':'').   '">👕 Jugadores</a>
  <a href="/admin/index.php?page=recharges" class="'.($p==='recharges'?'active':'').'"  >💰 Recargas</a>
  <a href="/admin/index.php?page=users"     class="'.($p==='users'?'active':'').     '">👥 Usuarios</a>
</nav>';
echo '<div class="admin-content">';

switch ($page) {
    case 'dashboard':  adminDashboard(); break;
    case 'matches':    adminMatches();   break;
    case 'match_new':  adminMatchNew();  break;
    case 'goals':      adminGoals();     break;
    case 'players':    adminPlayers();   break;
    case 'recharges':  adminRecharges(); break;
    case 'users':      adminUsers();     break;
    default: echo '<div class="alert alert-error">Página no encontrada</div>';
}

echo '</div></div>';
renderFoot();

// =============================================
// POST HANDLER ADMIN
// =============================================
function adminHandlePost(array $admin): void {
    $db     = getDB();
    $action = $_POST['action'] ?? '';

    if ($action === 'delete_match') {
        $matchId = (int)$_POST['match_id'];

        // Verificar que no tenga apuestas activas
        $stmt = $db->prepare("SELECT COUNT(*) FROM bets WHERE match_id=? AND status='pending'");
        $stmt->execute([$matchId]);
        $pendingBets = (int)$stmt->fetchColumn();

        if ($pendingBets > 0) {
            flash('error', "No se puede eliminar — tiene {$pendingBets} apuesta(s) pendiente(s)");
            redirect('/admin/index.php?page=matches');
        }

        $db->beginTransaction();
        try {
            $db->prepare("DELETE FROM goals WHERE match_id=?")->execute([$matchId]);
            $db->prepare("DELETE FROM bets WHERE match_id=?")->execute([$matchId]);
            $db->prepare("DELETE FROM matches WHERE id=?")->execute([$matchId]);
            $db->commit();
            flash('success', 'Partido eliminado ✅');
        } catch (Exception $e) {
            $db->rollBack();
            flash('error', 'Error al eliminar el partido');
        }
        redirect('/admin/index.php?page=matches');
    }

    if ($action === 'add_player') {
        $matchId = (int)$_POST['match_id'];
        $team    = trim($_POST['team'] ?? '');
        $name    = mb_substr(trim($_POST['player_name'] ?? ''), 0, 100);
        $number  = !empty($_POST['jersey_number']) ? (int)$_POST['jersey_number'] : null;
        $pos     = mb_substr(trim($_POST['position'] ?? ''), 0, 30);

        // Verificar equipo válido
        $stmt = $db->prepare("SELECT home_team, away_team FROM matches WHERE id=?");
        $stmt->execute([$matchId]);
        $m = $stmt->fetch();
        if (!$m || !in_array($team, [$m['home_team'], $m['away_team']], true)) {
            flash('error', 'Equipo inválido');
            redirect('/admin/index.php?page=players&match_id='.$matchId);
        }

        $db->prepare("INSERT INTO match_players (match_id, team, player_name, jersey_number, position) VALUES (?,?,?,?,?)")
           ->execute([$matchId, $team, $name, $number, $pos ?: null]);
        flash('success', "Jugador agregado: $name ✅");
        redirect('/admin/index.php?page=players&match_id='.$matchId);
    }

    if ($action === 'delete_player') {
        $playerId = (int)$_POST['player_id'];
        $matchId  = (int)$_POST['match_id'];
        $db->prepare("DELETE FROM match_players WHERE id=?")->execute([$playerId]);
        flash('success', 'Jugador eliminado');
        redirect('/admin/index.php?page=players&match_id='.$matchId);
    }

    if ($action === 'add_match') {
        $home  = mb_substr(trim($_POST['home_team'] ?? ''), 0, 80);
        $away  = mb_substr(trim($_POST['away_team'] ?? ''), 0, 80);
        $hflag = mb_substr(trim($_POST['home_flag'] ?? '🏳'), 0, 10);
        $aflag = mb_substr(trim($_POST['away_flag'] ?? '🏳'), 0, 10);
        $date  = trim($_POST['match_date'] ?? '');

        if (!$home || !$away || !$date) { flash('error','Faltan datos'); redirect('/admin/index.php?page=match_new'); }
        if (!strtotime($date))          { flash('error','Fecha inválida'); redirect('/admin/index.php?page=match_new'); }

        $db->prepare("INSERT INTO matches (home_team,away_team,home_flag,away_flag,match_date) VALUES (?,?,?,?,?)")
           ->execute([$home, $away, $hflag, $aflag, $date]);
        flash('success','Partido creado ✅');
        redirect('/admin/index.php?page=matches');
    }

    if ($action === 'update_match_status') {
        $matchId = (int)$_POST['match_id'];
        $status  = $_POST['status'] ?? '';
        // Whitelist estricta
        if (!in_array($status, ['open','in_progress','closed','finished'], true)) {
            flash('error','Estado inválido'); redirect('/admin/index.php?page=matches');
        }
        $db->prepare("UPDATE matches SET status=? WHERE id=?")->execute([$status, $matchId]);
        flash('success','Estado actualizado');
        redirect('/admin/index.php?page=matches');
    }

    if ($action === 'add_goal') {
        $matchId = (int)$_POST['match_id'];
        $team    = trim($_POST['team'] ?? '');
        $minute  = (int)$_POST['minute'];
        $scorer  = mb_substr(trim($_POST['scorer'] ?? ''), 0, 100);

        if ($minute < 1 || $minute > 90) { flash('error','Minuto inválido'); redirect('/admin/index.php?page=goals&match_id='.$matchId); }

        // Verificar equipo contra BD
        $stmt = $db->prepare("SELECT home_team, away_team FROM matches WHERE id=?");
        $stmt->execute([$matchId]);
        $m = $stmt->fetch();
        if (!$m || !in_array($team, [$m['home_team'], $m['away_team']], true)) {
            flash('error','Equipo inválido'); redirect('/admin/index.php?page=goals&match_id='.$matchId);
        }

        $db->prepare("INSERT INTO goals (match_id,team,minute,scorer,registered_by) VALUES (?,?,?,?,?)")
           ->execute([$matchId, $team, $minute, $scorer ?: null, $admin['id']]);
        flash('success',"⚽ Gol registrado: $team min $minute");
        redirect('/admin/index.php?page=goals&match_id='.$matchId);
    }

    if ($action === 'delete_goal') {
        $goalId  = (int)$_POST['goal_id'];
        $matchId = (int)$_POST['match_id'];
        $db->prepare("DELETE FROM goals WHERE id=?")->execute([$goalId]);
        flash('success','Gol eliminado');
        redirect('/admin/index.php?page=goals&match_id='.$matchId);
    }

    if ($action === 'resolve_match') {
        $matchId = (int)$_POST['match_id'];
        $result  = resolveMatch($matchId);
        if (isset($result['error'])) {
            flash('error', 'Error: '.$result['error']);
        } else {
            $msg = $result['accumulated']
                ? '⚡ Nadie acertó. Pozo acumulado.'
                : "🏆 {$result['winners_count']} ganador(es) · Premio: ".formatCedenas($result['prize_each'])." c/u";
            flash('success', "Partido resuelto · Pozo: ".formatCedenas($result['pot_total'])." · Comisión: ".formatCedenas($result['commission'])." · $msg");
        }
        redirect('/admin/index.php?page=goals&match_id='.$matchId);
    }

    if ($action === 'approve_recharge') {
        $reqId = (int)$_POST['request_id'];
        $notes = mb_substr(trim($_POST['notes'] ?? ''), 0, 255);

        // Bloquear registro mientras se procesa (evita doble aprobación)
        $db->beginTransaction();
        try {
            $stmt = $db->prepare("SELECT * FROM recharge_requests WHERE id=? AND status='pending' FOR UPDATE");
            $stmt->execute([$reqId]);
            $req = $stmt->fetch();
            if (!$req) { $db->rollBack(); flash('error','Solicitud no encontrada o ya procesada'); redirect('/admin/index.php?page=recharges'); }

            $db->prepare("UPDATE recharge_requests SET status='approved',reviewed_by=?,review_notes=?,reviewed_at=NOW() WHERE id=?")
               ->execute([$admin['id'], $notes, $reqId]);

            // Acreditar saldo
            $db->prepare("UPDATE wallets SET balance = balance + ? WHERE user_id=?")->execute([(float)$req['amount_cedenas'], $req['user_id']]);
            $newBal = getBalance($req['user_id']);
            $db->prepare("INSERT INTO transactions (user_id,type,amount,balance_after,description,reference_id) VALUES (?,?,?,?,?,?)")
               ->execute([$req['user_id'], 'deposit', (float)$req['amount_cedenas'], $newBal, 'Recarga aprobada #'.$reqId, $reqId]);

            $db->commit();
            // Notificar al admin por Telegram
            notifyRechargeApproved(['username' => $req['username'] ?? 'Usuario'], (float)$req['amount_cedenas']);
            flash('success', formatCedenas((float)$req['amount_cedenas']).' acreditadas ✅');
        } catch (Exception $e) {
            $db->rollBack();
            error_log('[CEDEKA RECHARGE] '.$e->getMessage());
            flash('error', 'Error al procesar la recarga');
        }
        redirect('/admin/index.php?page=recharges');
    }

    if ($action === 'reject_recharge') {
        $reqId = (int)$_POST['request_id'];
        $notes = mb_substr(trim($_POST['notes'] ?? 'Rechazada'), 0, 255);
        $db->prepare("UPDATE recharge_requests SET status='rejected',reviewed_by=?,review_notes=?,reviewed_at=NOW() WHERE id=? AND status='pending'")
           ->execute([$admin['id'], $notes, $reqId]);
        flash('warn','Solicitud rechazada');
        redirect('/admin/index.php?page=recharges');
    }
}

// =============================================
// SIDEBAR
// =============================================
function renderAdminSidebar(string $active): void { ?>
<aside class="admin-sidebar">
  <div style="padding:0 20px 16px;border-bottom:1px solid var(--border)">
    <div style="font-family:var(--font-head);font-size:18px;letter-spacing:2px;color:var(--gold)">CEDEKA</div>
    <div style="font-size:10px;color:var(--text-dim);letter-spacing:2px;text-transform:uppercase">Panel Admin</div>
  </div>
  <div class="sidebar-section">General</div>
  <a href="/admin/index.php?page=dashboard" class="sidebar-link <?= $active==='dashboard'?'active':'' ?>">📊 Dashboard</a>
  <a href="/admin/index.php?page=users"     class="sidebar-link <?= $active==='users'?'active':'' ?>">👥 Usuarios</a>
  <div class="sidebar-section">Partidos</div>
  <a href="/admin/index.php?page=matches"   class="sidebar-link <?= $active==='matches'?'active':'' ?>">⚽ Partidos</a>
  <a href="/admin/index.php?page=match_new" class="sidebar-link <?= $active==='match_new'?'active':'' ?>">➕ Nuevo Partido</a>
  <a href="/admin/index.php?page=goals"     class="sidebar-link <?= $active==='goals'?'active':'' ?>">🥅 Cargar Goles</a>
  <a href="/admin/index.php?page=players"   class="sidebar-link <?= $active==='players'?'active':'' ?>">👕 Jugadores</a>
  <div class="sidebar-section">Finanzas</div>
  <a href="/admin/index.php?page=recharges" class="sidebar-link <?= $active==='recharges'?'active':'' ?>">💰 Recargas</a>
  <div class="sidebar-section"></div>
  <a href="/index.php"             class="sidebar-link">🏠 Ver Sitio</a>
  <a href="/index.php?page=logout" class="sidebar-link" style="color:var(--red)">🚪 Salir</a>
</aside>
<?php }

// =============================================
// ADMIN PAGES
// =============================================
function adminDashboard(): void {
    $db = getDB();
    $s  = [
        'users'        => $db->query("SELECT COUNT(*) FROM users WHERE role='user'")->fetchColumn(),
        'bets'         => $db->query("SELECT COUNT(*) FROM bets")->fetchColumn(),
        'open_pot'     => $db->query("SELECT COALESCE(SUM(pot_total),0) FROM matches WHERE status IN ('open','in_progress')")->fetchColumn(),
        'commission'   => $db->query("SELECT COALESCE(SUM(commission_taken),0) FROM matches WHERE status='finished'")->fetchColumn(),
        'pending_rch'  => $db->query("SELECT COUNT(*) FROM recharge_requests WHERE status='pending'")->fetchColumn(),
        'matches_open' => $db->query("SELECT COUNT(*) FROM matches WHERE status='open'")->fetchColumn(),
    ];
?>
<h1 class="page-title">DASH<span>BOARD</span></h1>
<p class="page-subtitle">Resumen general del sistema</p>
<?php renderFlash(); ?>
<div class="grid-3 mb-4">
  <div class="stat-box"><div class="stat-value"><?= (int)$s['users'] ?></div><div class="stat-label">Jugadores</div></div>
  <div class="stat-box"><div class="stat-value"><?= (int)$s['bets'] ?></div><div class="stat-label">Total Apuestas</div></div>
  <div class="stat-box"><div class="stat-value"><?= (int)$s['matches_open'] ?></div><div class="stat-label">Partidos Abiertos</div></div>
  <div class="stat-box"><div class="stat-value text-gold"><?= formatCedenas((float)$s['open_pot']) ?></div><div class="stat-label">Pozos Activos</div></div>
  <div class="stat-box"><div class="stat-value text-green"><?= formatCedenas((float)$s['commission']) ?></div><div class="stat-label">Comisión Cobrada</div></div>
  <div class="stat-box"><div class="stat-value text-red"><?= (int)$s['pending_rch'] ?></div><div class="stat-label">Recargas Pendientes</div></div>
</div>
<?php if ($s['pending_rch'] > 0): ?>
  <div class="alert alert-warn">⚠️ <?= (int)$s['pending_rch'] ?> recarga(s) pendiente(s). <a href="/admin/index.php?page=recharges" class="text-gold">Revisar →</a></div>
<?php endif; ?>
<?php
    $bets = $db->query("SELECT b.*,u.username,u.avatar,m.home_team,m.away_team FROM bets b JOIN users u ON b.user_id=u.id JOIN matches m ON b.match_id=m.id ORDER BY b.created_at DESC LIMIT 10")->fetchAll();
    if ($bets): ?>
<div class="card mt-3">
  <div class="card-header">Últimas Apuestas</div>
  <div class="table-wrap">
    <table class="data-table">
      <thead><tr><th>Usuario</th><th>Partido</th><th>Equipo</th><th>Min</th><th>Monto</th><th>Estado</th><th>Cuando</th></tr></thead>
      <tbody>
      <?php foreach ($bets as $b): ?>
      <tr>
        <td><?= h($b['avatar']) ?> <?= h($b['username']) ?></td>
        <td class="fs-sm"><?= h($b['home_team']) ?> vs <?= h($b['away_team']) ?></td>
        <td class="fw-bold font-sub fs-sm"><?= h($b['team']) ?></td>
        <td><span class="badge badge-pending"><?= (int)$b['minute'] ?></span></td>
        <td><?= formatCedenas((float)$b['amount_cedenas']) ?></td>
        <td><?php
          if ($b['status']==='won')  echo '<span class="badge badge-won">🏆</span>';
          elseif ($b['status']==='lost') echo '<span class="badge badge-lost">❌</span>';
          else echo '<span class="badge badge-pending">⏳</span>';
        ?></td>
        <td class="text-muted fs-xs"><?= timeAgo($b['created_at']) ?></td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>
<?php }

function adminMatches(): void {
    $db      = getDB();
    $matches = $db->query("SELECT m.*,(SELECT COUNT(*) FROM bets b WHERE b.match_id=m.id) as bet_count,(SELECT COUNT(*) FROM goals g WHERE g.match_id=m.id) as goal_count FROM matches m ORDER BY m.match_date DESC")->fetchAll();
?>
<h1 class="page-title">PARTI<span>DOS</span></h1>
<div class="flex-between mb-3">
  <p class="page-subtitle mb-0">Gestión de partidos</p>
  <a href="/admin/index.php?page=match_new" class="btn btn-primary btn-sm">➕ Nuevo</a>
</div>
<?php renderFlash(); ?>
<div class="card">
  <div class="table-wrap">
    <table class="data-table">
      <thead><tr><th>Partido</th><th>Fecha</th><th>Estado</th><th>Pozo</th><th>Apuestas</th><th>Goles</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($matches as $m): ?>
      <tr>
        <td class="fw-bold"><?= h($m['home_flag']) ?> <?= h($m['home_team']) ?> vs <?= h($m['away_team']) ?> <?= h($m['away_flag']) ?></td>
        <td class="fs-sm"><?= date('d M Y H:i', strtotime($m['match_date'])) ?></td>
        <td>
          <form method="POST" action="/admin/index.php?page=matches" style="display:inline-flex;gap:6px;align-items:center">
            <?php csrfField(); ?>
            <input type="hidden" name="action" value="update_match_status">
            <input type="hidden" name="match_id" value="<?= (int)$m['id'] ?>">
            <select name="status" class="form-control" style="padding:4px 8px;font-size:12px;width:auto">
              <?php foreach (['open','in_progress','closed','finished'] as $st): ?>
                <option value="<?= $st ?>" <?= $m['status']===$st?'selected':'' ?>><?= matchStatus($st) ?></option>
              <?php endforeach; ?>
            </select>
            <button type="submit" class="btn btn-ghost btn-sm">OK</button>
          </form>
        </td>
        <td class="text-gold fw-bold font-sub"><?= formatCedenas((float)$m['pot_total']) ?></td>
        <td><?= (int)$m['bet_count'] ?></td>
        <td><?= (int)$m['goal_count'] ?></td>
        <td style="display:flex;gap:6px">
          <a href="/admin/index.php?page=goals&match_id=<?= (int)$m['id'] ?>" class="btn btn-sm btn-ghost">🥅 Goles</a>
          <?php if ($m['status'] !== 'finished' || $m['bet_count'] == 0): ?>
          <form method="POST" action="/admin/index.php?page=matches" style="display:inline">
            <?php csrfField(); ?>
            <input type="hidden" name="action" value="delete_match">
            <input type="hidden" name="match_id" value="<?= (int)$m['id'] ?>">
            <button type="submit" class="btn btn-danger btn-sm" onclick="return confirm('¿Eliminar este partido? Esta acción no se puede deshacer.')">🗑 Eliminar</button>
          </form>
          <?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php }

function adminMatchNew(): void { ?>
<h1 class="page-title">NUEVO <span>PARTIDO</span></h1>
<?php renderFlash(); ?>
<div class="card" style="max-width:600px">
  <form method="POST" action="/admin/index.php?page=match_new">
    <?php csrfField(); ?>
    <input type="hidden" name="action" value="add_match">
    <div class="form-row">
      <div class="form-group"><label class="form-label">Bandera Local</label><input type="text" name="home_flag" class="form-control" placeholder="🇧🇷" value="🏳" maxlength="10"></div>
      <div class="form-group"><label class="form-label">Equipo Local</label><input type="text" name="home_team" class="form-control" placeholder='Brasil' required maxlength="80"></div>
    </div>
    <div class="form-row">
      <div class="form-group"><label class="form-label">Bandera Visitante</label><input type="text" name="away_flag" class="form-control" placeholder="🇦🇷" value="🏳" maxlength="10"></div>
      <div class="form-group"><label class="form-label">Equipo Visitante</label><input type="text" name="away_team" class="form-control" placeholder='Argentina' required maxlength="80"></div>
    </div>
    <div class="form-group"><label class="form-label">Fecha y Hora</label><input type="datetime-local" name="match_date" class="form-control" required></div>
    <button type="submit" class="btn btn-primary btn-block">Crear Partido ⚽</button>
  </form>
</div>
<?php }

function adminGoals(): void {
    $db       = getDB();
    $matchId  = (int)($_GET['match_id'] ?? 0);
    $allMatches = $db->query("SELECT * FROM matches WHERE status IN ('in_progress','open','closed') ORDER BY match_date DESC")->fetchAll();
    $match = null; $goals = []; $bets = [];

    if ($matchId) {
        $stmt = $db->prepare("SELECT * FROM matches WHERE id=?"); $stmt->execute([$matchId]); $match = $stmt->fetch();
        $stmt = $db->prepare("SELECT * FROM goals WHERE match_id=? ORDER BY minute ASC"); $stmt->execute([$matchId]); $goals = $stmt->fetchAll();
        $stmt = $db->prepare("SELECT b.*,u.username,u.avatar FROM bets b JOIN users u ON b.user_id=u.id WHERE b.match_id=? ORDER BY b.minute ASC, b.amount_cedenas DESC"); $stmt->execute([$matchId]); $bets = $stmt->fetchAll();
    }
?>
<h1 class="page-title">CARGAR <span>GOLES</span></h1>
<?php renderFlash(); ?>
<div class="form-group mb-3" style="max-width:420px">
  <label class="form-label">Seleccionar Partido</label>
  <select class="form-control" onchange="location='/admin/index.php?page=goals&match_id='+this.value">
    <option value="">— Elige un partido —</option>
    <?php foreach ($allMatches as $m): ?>
      <option value="<?= (int)$m['id'] ?>" <?= $m['id']==$matchId?'selected':'' ?>><?= h($m['home_flag'].' '.$m['home_team'].' vs '.$m['away_team'].' '.$m['away_flag']) ?> · <?= matchStatus($m['status']) ?></option>
    <?php endforeach; ?>
  </select>
</div>

<?php if ($match): ?>
<div class="grid-2" style="gap:20px;align-items:start">
  <div>
    <div class="card mb-3">
      <div class="card-header">Registrar Gol</div>
      <form method="POST" action="/admin/index.php?page=goals&match_id=<?= (int)$matchId ?>">
        <?php csrfField(); ?>
        <input type="hidden" name="action" value="add_goal">
        <input type="hidden" name="match_id" value="<?= (int)$matchId ?>">
        <div class="form-group">
          <label class="form-label">Equipo</label>
          <select name="team" class="form-control" required>
            <option value="<?= h($match['home_team']) ?>"><?= h($match['home_flag'].' '.$match['home_team']) ?> (Local)</option>
            <option value="<?= h($match['away_team']) ?>"><?= h($match['away_flag'].' '.$match['away_team']) ?> (Visitante)</option>
          </select>
        </div>
        <div class="form-group"><label class="form-label">Minuto (1–90)</label><input type="number" name="minute" class="form-control" min="1" max="90" required></div>
        <div class="form-group"><label class="form-label">Goleador (opcional)</label><input type="text" name="scorer" class="form-control" placeholder="Nombre" maxlength="100"></div>
        <button type="submit" class="btn btn-green btn-block">⚽ Registrar Gol</button>
      </form>
    </div>

    <?php if ($goals): ?>
    <div class="card mb-3">
      <div class="card-header">Goles Registrados (<?= count($goals) ?>)</div>
      <?php foreach ($goals as $g): ?>
      <div class="flex-between mb-2">
        <div><span class="fw-bold font-sub"><?= h($g['team']) ?></span><?= $g['scorer']?' <span class="text-muted fs-xs">— '.h($g['scorer']).'</span>':'' ?> <span class="badge badge-open">Min <?= (int)$g['minute'] ?></span></div>
        <form method="POST" action="/admin/index.php?page=goals&match_id=<?= (int)$matchId ?>">
          <?php csrfField(); ?>
          <input type="hidden" name="action" value="delete_goal">
          <input type="hidden" name="goal_id" value="<?= (int)$g['id'] ?>">
          <input type="hidden" name="match_id" value="<?= (int)$matchId ?>">
          <button type="submit" class="btn btn-danger btn-sm" onclick="return confirm('¿Eliminar este gol?')">✕</button>
        </form>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php if (!empty($goals) && in_array($match['status'], ['in_progress','closed'], true)): ?>
    <div class="card" style="border-color:rgba(245,200,66,0.3)">
      <div class="card-header">⚡ Resolver Apuestas</div>
      <p class="text-muted fs-sm mb-3">Pozo: <strong class="text-gold"><?= formatCedenas((float)$match['pot_total']) ?></strong></p>
      <form method="POST" action="/admin/index.php?page=goals&match_id=<?= (int)$matchId ?>">
        <?php csrfField(); ?>
        <input type="hidden" name="action" value="resolve_match">
        <input type="hidden" name="match_id" value="<?= (int)$matchId ?>">
        <button type="submit" class="btn btn-primary btn-block" onclick="return confirm('¿Resolver este partido? Los premios se distribuirán automáticamente.')">🏆 Calcular Ganadores y Distribuir</button>
      </form>
    </div>
    <?php endif; ?>
  </div>

  <div class="card">
    <div class="card-header">Apuestas del Partido (<?= count($bets) ?>)</div>
    <?php if (empty($bets)): ?>
      <div class="empty-state" style="padding:24px"><div class="icon">🎯</div><h3>Sin apuestas</h3></div>
    <?php else: ?>
    <div class="table-wrap" style="max-height:500px;overflow-y:auto">
      <table class="data-table">
        <thead><tr><th>Usuario</th><th>Equipo</th><th>Min</th><th>Monto</th><th>Estado</th></tr></thead>
        <tbody>
        <?php foreach ($bets as $b):
          $isWinner = false;
          foreach ($goals as $g) if ($g['team']===$b['team'] && (int)$g['minute']===(int)$b['minute']) $isWinner=true;
        ?>
        <tr style="<?= $isWinner?'background:rgba(0,229,122,0.06)':'' ?>">
          <td><?= h($b['avatar']) ?> <?= h($b['username']) ?></td>
          <td class="fs-sm fw-bold font-sub"><?= h($b['team']) ?></td>
          <td><span class="badge <?= $isWinner?'badge-won':'badge-pending' ?>">Min <?= (int)$b['minute'] ?></span></td>
          <td><?= formatCedenas((float)$b['amount_cedenas']) ?></td>
          <td><?php
            if ($b['status']==='won')  echo '<span class="badge badge-won">🏆</span>';
            elseif ($b['status']==='lost') echo '<span class="badge badge-lost">❌</span>';
            else echo '<span class="badge badge-pending">⏳</span>';
          ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>
<?php }

function adminRecharges(): void {
    $db  = getDB();
    $tab = $_GET['tab'] ?? 'pending';
    $stmt = $db->prepare("SELECT r.*,u.username,u.email,u.avatar FROM recharge_requests r JOIN users u ON r.user_id=u.id WHERE r.status=? ORDER BY r.created_at DESC");
    $stmt->execute([$tab]);
    $recharges = $stmt->fetchAll();
    $counts = [];
    foreach (['pending','approved','rejected'] as $s) {
        $c = $db->prepare("SELECT COUNT(*) FROM recharge_requests WHERE status=?"); $c->execute([$s]); $counts[$s] = (int)$c->fetchColumn();
    }
?>
<h1 class="page-title">RECAR<span>GAS</span></h1>
<?php renderFlash(); ?>
<div class="flex gap-1 mb-3">
  <a href="?page=recharges&tab=pending"  class="btn <?= $tab==='pending'?'btn-primary':'btn-ghost' ?> btn-sm">⏳ Pendientes (<?=$counts['pending']?>)</a>
  <a href="?page=recharges&tab=approved" class="btn <?= $tab==='approved'?'btn-primary':'btn-ghost' ?> btn-sm">✅ Aprobadas (<?=$counts['approved']?>)</a>
  <a href="?page=recharges&tab=rejected" class="btn <?= $tab==='rejected'?'btn-primary':'btn-ghost' ?> btn-sm">❌ Rechazadas (<?=$counts['rejected']?>)</a>
</div>
<?php if (empty($recharges)): ?>
  <div class="empty-state card"><div class="icon">💰</div><h3>Sin solicitudes <?= h($tab) ?></h3></div>
<?php else: ?>
<div style="display:flex;flex-direction:column;gap:12px">
<?php foreach ($recharges as $r): ?>
<div class="card">
  <div class="flex-between mb-2">
    <div class="flex gap-1" style="align-items:center">
      <span style="font-size:24px"><?= h($r['avatar']) ?></span>
      <div><div class="fw-bold font-sub"><?= h($r['username']) ?></div><div class="text-muted fs-xs"><?= h($r['email']) ?></div></div>
    </div>
    <div class="text-right">
      <div class="text-gold fw-bold font-sub" style="font-size:20px"><?= formatCedenas((float)$r['amount_cedenas']) ?></div>
      <div class="text-muted fs-xs"><?= h($r['payment_method']) ?> · <?= timeAgo($r['created_at']) ?></div>
    </div>
  </div>
  <?php if ($r['receipt_notes']): ?>
  <div class="card-sm mb-2" style="background:var(--bg3)"><div class="fs-xs text-muted mb-1">Comprobante:</div><div class="fs-sm"><?= h($r['receipt_notes']) ?></div></div>
  <?php endif; ?>
  <?php if ($tab === 'pending'): ?>
  <div class="grid-2" style="gap:10px">
    <form method="POST" action="/admin/index.php?page=recharges">
      <?php csrfField(); ?>
      <input type="hidden" name="action" value="approve_recharge">
      <input type="hidden" name="request_id" value="<?= (int)$r['id'] ?>">
      <input type="text" name="notes" class="form-control mb-1" placeholder="Nota interna" style="font-size:12px" maxlength="255">
      <button type="submit" class="btn btn-green btn-block btn-sm" onclick="return confirm('¿Aprobar y acreditar?')">✅ Aprobar</button>
    </form>
    <form method="POST" action="/admin/index.php?page=recharges">
      <?php csrfField(); ?>
      <input type="hidden" name="action" value="reject_recharge">
      <input type="hidden" name="request_id" value="<?= (int)$r['id'] ?>">
      <input type="text" name="notes" class="form-control mb-1" placeholder="Motivo del rechazo" style="font-size:12px" maxlength="255">
      <button type="submit" class="btn btn-danger btn-block btn-sm" onclick="return confirm('¿Rechazar?')">❌ Rechazar</button>
    </form>
  </div>
  <?php else: ?>
  <div class="flex-between fs-xs text-muted"><span><?= $r['review_notes']?'Nota: '.h($r['review_notes']):'' ?></span><span><?= $r['reviewed_at']?'Revisado '.timeAgo($r['reviewed_at']):'' ?></span></div>
  <?php endif; ?>
</div>
<?php endforeach; ?>
</div>
<?php endif; ?>
<?php }

function adminUsers(): void {
    $db    = getDB();
    $users = $db->query("SELECT u.*,w.balance,(SELECT COUNT(*) FROM bets b WHERE b.user_id=u.id) as bet_count FROM users u LEFT JOIN wallets w ON u.id=w.user_id WHERE u.role='user' ORDER BY w.balance DESC")->fetchAll();
?>
<h1 class="page-title">JUGA<span>DORES</span></h1>
<p class="page-subtitle"><?= count($users) ?> jugadores</p>
<?php renderFlash(); ?>
<div class="card">
  <div class="table-wrap">
    <table class="data-table">
      <thead><tr><th>Usuario</th><th>Email</th><th>Saldo</th><th>Apuestas</th><th>IP Registro</th><th>Registro</th></tr></thead>
      <tbody>
      <?php foreach ($users as $u): ?>
      <tr>
        <td><?= h($u['avatar']) ?> <strong><?= h($u['username']) ?></strong><br><span class="text-muted fs-xs"><?= h($u['full_name']) ?></span></td>
        <td class="fs-sm"><?= h($u['email']) ?></td>
        <td class="text-gold fw-bold font-sub"><?= formatCedenas((float)($u['balance']??0)) ?></td>
        <td><?= (int)$u['bet_count'] ?></td>
        <td class="text-muted fs-xs"><?= h($u['created_ip'] ?? '—') ?></td>
        <td class="text-muted fs-xs"><?= timeAgo($u['created_at']) ?></td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php }

// =============================================
// ADMIN — GESTIÓN DE JUGADORES
// =============================================
function adminPlayers(): void {
    $db      = getDB();
    $matchId = (int)($_GET['match_id'] ?? 0);
    $allMatches = $db->query("SELECT * FROM matches WHERE status IN ('open','in_progress','closed') ORDER BY match_date DESC")->fetchAll();
    $match = null; $players = [];

    if ($matchId) {
        $stmt = $db->prepare("SELECT * FROM matches WHERE id=?"); $stmt->execute([$matchId]); $match = $stmt->fetch();
        $stmt = $db->prepare("SELECT * FROM match_players WHERE match_id=? ORDER BY team, jersey_number, player_name"); $stmt->execute([$matchId]); $players = $stmt->fetchAll();
    }
?>
<h1 class="page-title">JUGA<span>DORES</span></h1>
<p class="page-subtitle">Cargá la lista de jugadores por partido para que los usuarios puedan apostar</p>
<?php renderFlash(); ?>

<div class="form-group mb-3" style="max-width:420px">
  <label class="form-label">Seleccionar Partido</label>
  <select class="form-control" onchange="location='/admin/index.php?page=players&match_id='+this.value">
    <option value="">— Elige un partido —</option>
    <?php foreach ($allMatches as $m): ?>
      <option value="<?= (int)$m['id'] ?>" <?= $m['id']==$matchId?'selected':'' ?>>
        <?= h($m['home_flag'].' '.$m['home_team'].' vs '.$m['away_team'].' '.$m['away_flag']) ?> · <?= matchStatus($m['status']) ?>
      </option>
    <?php endforeach; ?>
  </select>
</div>

<?php if ($match): ?>
<div class="grid-2" style="gap:20px;align-items:start">

  <!-- Formulario agregar jugador -->
  <div class="card">
    <div class="card-header">➕ Agregar Jugador</div>
    <form method="POST" action="/admin/index.php?page=players&match_id=<?= (int)$matchId ?>">
      <?php csrfField(); ?>
      <input type="hidden" name="action" value="add_player">
      <input type="hidden" name="match_id" value="<?= (int)$matchId ?>">
      <div class="form-group">
        <label class="form-label">Equipo</label>
        <select name="team" class="form-control" required>
          <option value="<?= h($match['home_team']) ?>"><?= h($match['home_flag'].' '.$match['home_team']) ?> (Local)</option>
          <option value="<?= h($match['away_team']) ?>"><?= h($match['away_flag'].' '.$match['away_team']) ?> (Visitante)</option>
        </select>
      </div>
      <div class="form-group">
        <label class="form-label">Nombre del Jugador</label>
        <input type="text" name="player_name" class="form-control" placeholder="Ej: Lionel Messi" required maxlength="100">
      </div>
      <div class="form-row">
        <div class="form-group">
          <label class="form-label">Número (opcional)</label>
          <input type="number" name="jersey_number" class="form-control" min="1" max="99" placeholder="10">
        </div>
        <div class="form-group">
          <label class="form-label">Posición (opcional)</label>
          <input type="text" name="position" class="form-control" placeholder="Delantero" maxlength="30">
        </div>
      </div>
      <button type="submit" class="btn btn-primary btn-block">➕ Agregar Jugador</button>
    </form>
  </div>

  <!-- Lista de jugadores -->
  <div>
    <?php
    $homeP = array_filter($players, fn($p) => $p['team'] === $match['home_team']);
    $awayP = array_filter($players, fn($p) => $p['team'] === $match['away_team']);

    foreach ([
        ['team' => $match['home_team'], 'flag' => $match['home_flag'], 'players' => $homeP],
        ['team' => $match['away_team'], 'flag' => $match['away_flag'], 'players' => $awayP],
    ] as $side):
    ?>
    <div class="card mb-3">
      <div class="card-header"><?= h($side['flag'].' '.$side['team']) ?> — <?= count($side['players']) ?> jugadores</div>
      <?php if (empty($side['players'])): ?>
        <div class="text-muted fs-sm text-center" style="padding:16px">Sin jugadores cargados</div>
      <?php else: ?>
        <?php foreach ($side['players'] as $p): ?>
        <div class="flex-between mb-2" style="padding:8px 0;border-bottom:1px solid var(--border)">
          <div style="display:flex;align-items:center;gap:10px">
            <?php if ($p['jersey_number']): ?>
              <span style="background:var(--bg3);border:1px solid var(--border);border-radius:6px;padding:2px 8px;font-family:var(--font-head);font-size:16px;color:var(--gold);min-width:32px;text-align:center"><?= (int)$p['jersey_number'] ?></span>
            <?php endif; ?>
            <div>
              <div class="fw-bold font-sub"><?= h($p['player_name']) ?></div>
              <?php if ($p['position']): ?><div class="text-muted fs-xs"><?= h($p['position']) ?></div><?php endif; ?>
            </div>
          </div>
          <form method="POST" action="/admin/index.php?page=players&match_id=<?= (int)$matchId ?>">
            <?php csrfField(); ?>
            <input type="hidden" name="action" value="delete_player">
            <input type="hidden" name="player_id" value="<?= (int)$p['id'] ?>">
            <input type="hidden" name="match_id" value="<?= (int)$matchId ?>">
            <button type="submit" class="btn btn-danger btn-sm" onclick="return confirm('¿Eliminar jugador?')">✕</button>
          </form>
        </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
    <?php endforeach; ?>

    <?php if (count($players) > 0): ?>
    <div class="alert alert-info fs-xs">
      ✅ <?= count($players) ?> jugadores cargados en total. Los usuarios ya pueden apostar por jugador en este partido.
    </div>
    <?php endif; ?>
  </div>

</div>
<?php endif; ?>
<?php }