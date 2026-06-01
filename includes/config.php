<?php
// =============================================
// CEDEKA WORLD CUP - config.php HARDENED v2
// =============================================

// ⚠️  CAMBIAR ANTES DE PRODUCCIÓN
define('DB_HOST',    $_ENV['DB_HOST']    ?? getenv('DB_HOST')    ?: 'localhost');
define('DB_NAME',    $_ENV['DB_NAME']    ?? getenv('DB_NAME')    ?: 'cedeka_quiniela');
define('DB_USER',    $_ENV['DB_USER']    ?? getenv('DB_USER')    ?: 'root');
define('DB_PASS',    $_ENV['DB_PASS']    ?? getenv('DB_PASS')    ?: '');
define('DB_PORT',    $_ENV['DB_PORT']    ?? getenv('DB_PORT')    ?: '3306');
define('DB_CHARSET', 'utf8mb4');

define('SITE_NAME',        'Cedeka World Cup');
define('SITE_COMMISSION',  0.20);
define('MIN_BET',          250);
define('MAX_BET',          10000);
define('SESSION_LIFETIME', 7200);

// Rate limiting
define('LOGIN_MAX_ATTEMPTS', 5);
define('LOGIN_LOCKOUT_SECS', 900);   // 15 minutos
define('REG_MAX_PER_IP_DAY', 3);


// =============================================
// CONEXIÓN PDO — errores no expuestos al usuario
// =============================================
function getDB(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = "mysql:host=".DB_HOST.";port=".DB_PORT.";dbname=".DB_NAME.";charset=".DB_CHARSET;
        $opts = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_TIMEOUT            => 5,
        ];
        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $opts);
        } catch (PDOException $e) {
            error_log('[CEDEKA DB] ' . $e->getMessage());
            http_response_code(503);
            die('Servicio no disponible. Intenta más tarde.');
        }
    }
    return $pdo;
}

// =============================================
// CSRF — token por sesión, validación con timing-safe compare
// =============================================
function csrfToken(): string {
    startSession();
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrfField(): void {
    echo '<input type="hidden" name="csrf_token" value="' . h(csrfToken()) . '">';
}

function csrfVerify(): void {
    $token = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!is_string($token) || !hash_equals(csrfToken(), $token)) {
        http_response_code(403);
        die('Sesión expirada o solicitud inválida. <a href="/index.php">Volver al inicio</a>');
    }
}

// =============================================
// SESIÓN SEGURA
// =============================================
function startSession(): void {
    if (session_status() === PHP_SESSION_NONE) {
        $secure = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
        session_set_cookie_params([
            'lifetime' => SESSION_LIFETIME,
            'path'     => '/',
            'domain'   => '',
            'secure'   => $secure,
            'httponly' => true,       // JS no puede leer la cookie
            'samesite' => 'Lax',   // Bloquea CSRF cross-site
        ]);
        session_name('CEDEKA_SID');
        session_start();
    }
}

function getCurrentUser(): ?array {
    startSession();
    if (!isset($_SESSION['user_id'])) return null;

    // Detectar session hijacking por User-Agent
    $uaHash = md5($_SERVER['HTTP_USER_AGENT'] ?? '');
    if (isset($_SESSION['ua_hash']) && !hash_equals($_SESSION['ua_hash'], $uaHash)) {
        logoutUser();
        return null;
    }

    // Expiración de sesión inactiva (30 min)
    if (isset($_SESSION['last_activity']) && time() - $_SESSION['last_activity'] > 1800) {
        logoutUser();
        return null;
    }
    $_SESSION['last_activity'] = time();

    $db   = getDB();
    $stmt = $db->prepare("SELECT u.*, w.balance FROM users u LEFT JOIN wallets w ON u.id = w.user_id WHERE u.id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch();
    if (!$user) return null;

    // Verificar que el token de sesión coincide (single session)
    $sessionToken = $_SESSION['session_token'] ?? '';
    if (!empty($user['session_token']) && !empty($sessionToken)) {
        if (!hash_equals($user['session_token'], $sessionToken)) {
            // Otra ventana/dispositivo inició sesión — cerrar esta
            logoutUser();
            return null;
        }
    }

    return $user;
}

function loginUser(int $userId): void {
    startSession();
    session_regenerate_id(true);

    // Generar token único de sesión
    $sessionToken = bin2hex(random_bytes(32));

    $_SESSION['user_id']      = $userId;
    $_SESSION['ua_hash']      = md5($_SERVER['HTTP_USER_AGENT'] ?? '');
    $_SESSION['ip']           = getClientIP();
    $_SESSION['login_at']     = time();
    $_SESSION['last_activity']= time();
    $_SESSION['csrf_token']   = bin2hex(random_bytes(32));
    $_SESSION['session_token']= $sessionToken;

    // Guardar token en BD — invalida sesiones anteriores
    $db = getDB();
    $db->prepare("UPDATE users SET session_token=?, session_at=NOW() WHERE id=?")
       ->execute([$sessionToken, $userId]);
}

function logoutUser(): void {
    startSession();
    $_SESSION = [];
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 3600,
        $params['path'], $params['domain'],
        $params['secure'], $params['httponly']
    );
    session_destroy();
}

function requireLogin(): array {
    $user = getCurrentUser();
    if (!$user) redirect('/index.php?page=login');
    return $user;
}

function requireAdmin(): array {
    $user = requireLogin();
    if ($user['role'] !== 'admin') {
        http_response_code(403);
        redirect('/index.php?page=home');
    }
    return $user;
}

function isLoggedIn(): bool { return getCurrentUser() !== null; }

// =============================================
// RATE LIMITING — login y registro
// =============================================
function checkLoginRateLimit(string $email): bool {
    $db    = getDB();
    $ip    = getClientIP();
    $since = date('Y-m-d H:i:s', time() - LOGIN_LOCKOUT_SECS);
    $stmt  = $db->prepare("
        SELECT COUNT(*) FROM login_attempts
        WHERE (ip = ? OR email = ?) AND attempted_at > ? AND success = 0
    ");
    $stmt->execute([$ip, strtolower(trim($email)), $since]);
    return (int)$stmt->fetchColumn() < LOGIN_MAX_ATTEMPTS;
}

function recordLoginAttempt(string $email, bool $success): void {
    $db = getDB();
    $db->prepare("INSERT INTO login_attempts (ip, email, success) VALUES (?,?,?)")
       ->execute([getClientIP(), strtolower(trim($email)), $success ? 1 : 0]);
    // Limpiar intentos > 24h
    $db->exec("DELETE FROM login_attempts WHERE attempted_at < DATE_SUB(NOW(), INTERVAL 24 HOUR)");
}

function checkRegisterRateLimit(): bool {
    $db   = getDB();
    $ip   = getClientIP();
    $stmt = $db->prepare("
        SELECT COUNT(*) FROM users WHERE created_ip = ? AND created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)
    ");
    $stmt->execute([$ip]);
    return (int)$stmt->fetchColumn() < REG_MAX_PER_IP_DAY;
}

function getClientIP(): string {
    foreach ([
        $_SERVER['HTTP_CF_CONNECTING_IP'] ?? '',   // Cloudflare
        $_SERVER['HTTP_X_FORWARDED_FOR']  ?? '',
        $_SERVER['REMOTE_ADDR']           ?? '',
    ] as $ip) {
        $ip = trim(explode(',', $ip)[0]);
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return $ip;
        }
    }
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

// =============================================
// WALLET — bloqueo atómico anti-race-condition
// =============================================
function getBalance(int $userId): float {
    $db   = getDB();
    $stmt = $db->prepare("SELECT balance FROM wallets WHERE user_id = ?");
    $stmt->execute([$userId]);
    return (float)($stmt->fetchColumn() ?? 0.0);
}

// Descuenta SOLO si hay saldo suficiente — una sola query atómica
function deductBalance(int $userId, float $amount): bool {
    if ($amount <= 0) return false;
    $db   = getDB();
    $stmt = $db->prepare("
        UPDATE wallets SET balance = balance - ?
        WHERE user_id = ? AND balance >= ?
    ");
    $stmt->execute([$amount, $userId, $amount]);
    return $stmt->rowCount() === 1;
}

function adjustBalance(int $userId, float $amount, string $type, string $description, ?int $refId = null): bool {
    $db = getDB();
    $db->beginTransaction();
    try {
        if ($amount < 0) {
            if (!deductBalance($userId, abs($amount))) {
                $db->rollBack();
                return false;
            }
        } else {
            $db->prepare("UPDATE wallets SET balance = balance + ? WHERE user_id = ?")
               ->execute([$amount, $userId]);
        }
        $newBal = getBalance($userId);
        $db->prepare("INSERT INTO transactions (user_id,type,amount,balance_after,description,reference_id) VALUES (?,?,?,?,?,?)")
           ->execute([$userId, $type, $amount, $newBal, $description, $refId]);
        $db->commit();
        return true;
    } catch (Exception $e) {
        $db->rollBack();
        error_log('[CEDEKA WALLET] uid='.$userId.' '.$e->getMessage());
        return false;
    }
}

// =============================================
// RESOLVER PARTIDO — mutex MySQL para evitar doble ejecución
// =============================================
function resolveMatch(int $matchId): array {
    $db       = getDB();
    $lockName = 'resolve_match_' . $matchId;

    // GET_LOCK garantiza que solo UN proceso resuelva a la vez
    $locked = $db->query("SELECT GET_LOCK('$lockName', 10)")->fetchColumn();
    if (!$locked) return ['error' => 'El partido ya está siendo procesado. Intenta en unos segundos.'];

    try {
        $stmt = $db->prepare("SELECT status FROM matches WHERE id = ?");
        $stmt->execute([$matchId]);
        $match = $stmt->fetch();
        if (!$match || $match['status'] === 'finished') return ['error' => 'Este partido ya fue resuelto'];

        $stmt = $db->prepare("SELECT team, minute FROM goals WHERE match_id = ?");
        $stmt->execute([$matchId]);
        $goals = $stmt->fetchAll();
        if (empty($goals)) return ['error' => 'No hay goles registrados'];

        $stmt = $db->prepare("SELECT SUM(amount_cedenas) as total FROM bets WHERE match_id = ? AND status = 'pending'");
        $stmt->execute([$matchId]);
        $potTotal   = (float)($stmt->fetch()['total'] ?? 0);
        if ($potTotal <= 0) return ['error' => 'No hay apuestas en el pozo'];

        $commission = $potTotal * SITE_COMMISSION;
        $potToShare = $potTotal - $commission;

        // Buscar apuestas ganadoras (equipo + minuto + jugador si aplica)
        $conds = []; $params = [$matchId];
        foreach ($goals as $g) {
            // Si el gol tiene scorer registrado, verificar también el jugador
            if (!empty($g['scorer'])) {
                $conds[]  = "(team = ? AND minute = ? AND (player_name = ? OR player_name IS NULL OR player_name = ''))";
                $params[] = $g['team'];
                $params[] = (int)$g['minute'];
                $params[] = $g['scorer'];
            } else {
                $conds[]  = "(team = ? AND minute = ?)";
                $params[] = $g['team'];
                $params[] = (int)$g['minute'];
            }
        }
        $stmt = $db->prepare("SELECT * FROM bets WHERE match_id = ? AND status = 'pending' AND (".implode(' OR ',$conds).")");
        $stmt->execute($params);
        $allMatching = $stmt->fetchAll();

        // Si hay jugador en el gol, priorizar quien acertó los 3
        $winners = [];
        foreach ($allMatching as $bet) {
            foreach ($goals as $g) {
                if ($bet['team'] === $g['team'] && (int)$bet['minute'] === (int)$g['minute']) {
                    // Si el gol tiene scorer y la apuesta tiene jugador, deben coincidir
                    if (!empty($g['scorer']) && !empty($bet['player_name'])) {
                        if (strtolower(trim($bet['player_name'])) === strtolower(trim($g['scorer']))) {
                            $winners[] = $bet;
                            break;
                        }
                    } else {
                        // Sin jugador en apuesta o gol — solo equipo+minuto
                        $winners[] = $bet;
                        break;
                    }
                }
            }
        }
        // Deduplicar
        $winnerIds = [];
        $winners = array_filter($winners, function($b) use (&$winnerIds) {
            if (in_array($b['id'], $winnerIds)) return false;
            $winnerIds[] = $b['id'];
            return true;
        });

        $db->beginTransaction();

        if (!empty($winners)) {
            $prize = round($potToShare / count($winners), 4);
            foreach ($winners as $bet) {
                $db->prepare("UPDATE bets SET status='won', prize_cedenas=? WHERE id=?")->execute([$prize, $bet['id']]);
                $db->prepare("UPDATE wallets SET balance = balance + ? WHERE user_id = ?")->execute([$prize, $bet['user_id']]);
                $newBal = getBalance($bet['user_id']);
                $db->prepare("INSERT INTO transactions (user_id,type,amount,balance_after,description,reference_id) VALUES (?,?,?,?,?,?)")
                   ->execute([$bet['user_id'], 'prize', $prize, $newBal, "Premio partido #$matchId — min {$bet['minute']}", $matchId]);
            }
        }

        $db->prepare("UPDATE bets SET status='lost' WHERE match_id=? AND status='pending'")->execute([$matchId]);
        $db->prepare("UPDATE matches SET status='finished', commission_taken=?, pot_total=? WHERE id=?")->execute([$commission, $potTotal, $matchId]);

        // Guardar notificación en perfil de cada ganador
        if (!empty($winners)) {
            $matchStmt = $db->prepare("SELECT home_team, away_team FROM matches WHERE id=?");
            $matchStmt->execute([$matchId]);
            $matchRow = $matchStmt->fetch();
            $matchName = ($matchRow['home_team'] ?? '') . ' vs ' . ($matchRow['away_team'] ?? '');
            $prizeEach = round($potToShare / count($winners), 4);
            foreach ($winners as $bet) {
                // Insertar notificación en tabla user_notifications si existe
                try {
                    $db->prepare("INSERT INTO user_notifications (user_id, type, message, created_at) VALUES (?, 'prize', ?, NOW())")
                       ->execute([$bet['user_id'], "🏆 ¡Ganaste " . number_format($prizeEach, 2, '.', ',') . " ₵ en el partido $matchName! Apostaste " . $bet['team'] . " min " . $bet['minute'] . ". Premio acreditado en tu wallet."]);
                } catch (Exception $ne) { /* tabla opcional */ }
            }
        }

        $db->commit();

        // Notificar por Telegram post-commit
        $matchStmt2 = $db->prepare("SELECT home_team, away_team FROM matches WHERE id=?");
        $matchStmt2->execute([$matchId]);
        $matchRow2 = $matchStmt2->fetch();
        $matchNameNotif = ($matchRow2['home_team'] ?? '') . ' vs ' . ($matchRow2['away_team'] ?? '');
        if (function_exists('notifyMatchWinners')) { notifyMatchWinners($matchNameNotif, $winners, $potTotal, $commission); }

        return [
            'success'       => true,
            'pot_total'     => $potTotal,
            'commission'    => $commission,
            'pot_shared'    => $potToShare,
            'winners_count' => count($winners),
            'prize_each'    => empty($winners) ? 0 : round($potToShare / count($winners), 4),
            'accumulated'   => empty($winners),
            'winners'       => $winners,
        ];

    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        error_log('[CEDEKA RESOLVE] match='.$matchId.' '.$e->getMessage());
        return ['error' => 'Error interno al resolver el partido'];
    } finally {
        $db->query("SELECT RELEASE_LOCK('$lockName')");
    }
}

// =============================================
// VALIDACIONES DE INPUT
// =============================================
function validateUsername(string $v): ?string {
    $v = trim($v);
    if (strlen($v) < 3)  return 'Usuario mínimo 3 caracteres';
    if (strlen($v) > 30) return 'Usuario máximo 30 caracteres';
    if (!preg_match('/^[a-zA-Z0-9_\-]+$/', $v)) return 'Solo letras, números, _ y -';
    return null;
}

function validatePassword(string $v): ?string {
    if (strlen($v) < 8)             return 'Contraseña mínima 8 caracteres';
    if (strlen($v) > 128)           return 'Contraseña máxima 128 caracteres';
    if (!preg_match('/[A-Z]/', $v)) return 'Debe tener al menos una mayúscula';
    if (!preg_match('/[0-9]/', $v)) return 'Debe tener al menos un número';
    return null;
}

function validateEmail(string $v): ?string {
    if (strlen($v) > 150)                       return 'Email demasiado largo';
    if (!filter_var($v, FILTER_VALIDATE_EMAIL)) return 'Email inválido';
    return null;
}

function validateBetAmount(float $amount, float $balance): ?string {
    if ($amount <= 0)           return 'El monto debe ser mayor a 0';
    if ($amount < MIN_BET)      return 'Monto mínimo: '.formatCedenas(MIN_BET);
    if ($amount > MAX_BET)      return 'Monto máximo: '.formatCedenas(MAX_BET);
    if ($balance <= 0)          return 'No tenés saldo disponible. Cargá Cedenas primero.';
    if ($amount > $balance)     return 'Saldo insuficiente. Tu saldo es: '.formatCedenas($balance);
    return null;
}

// Open redirect: solo URLs internas
function redirect(string $url): void {
    if (!preg_match('#^/#', $url) || preg_match('#^//|^/\\\\#', $url)) {
        $url = '/index.php';
    }
    header('Location: ' . $url);
    exit;
}

// =============================================
// UTILIDADES
// =============================================
function formatCedenas(float $amount): string {
    return number_format($amount, 2, '.', ',') . ' ₵';
}

function timeAgo(string $datetime): string {
    $t = time() - strtotime($datetime);
    if ($t < 60)    return 'hace '.$t.'s';
    if ($t < 3600)  return 'hace '.round($t/60).'min';
    if ($t < 86400) return 'hace '.round($t/3600).'h';
    return 'hace '.round($t/86400).'d';
}

function matchStatus(string $s): string {
    return match($s) {
        'open'     => '🟢 Abierto',
        'closed'   => '🔒 Cerrado',
        'finished' => '✅ Finalizado',
        default    => '🔒 Cerrado',
    };
}

function h(mixed $val): string {
    return htmlspecialchars((string)$val, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

function flash(string $key, string $msg = ''): ?string {
    startSession();
    if ($msg !== '') { $_SESSION['flash'][$key] = $msg; return null; }
    $val = $_SESSION['flash'][$key] ?? null;
    unset($_SESSION['flash'][$key]);
    return $val;
}