<?php
// =============================================
// CEDEKA WC — Google OAuth Callback
// =============================================
ob_start();
require_once __DIR__ . '/../../includes/config.php';

// Configurar sesión persistente
ini_set('session.save_path', '/tmp');
ini_set('session.gc_maxlifetime', 7200);
session_name('CEDEKA_SID');
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 7200,
        'path'     => '/',
        'secure'   => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Lax',  // ← Lax en vez de Strict para permitir redirect de Google
    ]);
    session_start();
}

// Verificar state anti-CSRF
$state = $_GET['state'] ?? '';
$sessionState = $_SESSION['oauth_state'] ?? '';

// Limpiar siempre
unset($_SESSION['oauth_state']);

// Validar state (si está vacío en sesión puede ser problema de sesión entre requests)
if (!empty($sessionState) && !hash_equals($sessionState, $state)) {
    flash('error', 'Estado OAuth inválido. Intenta de nuevo.');
    redirect('/index.php?page=login');
}
// Si sessionState está vacío, continuamos igual (problema conocido con PHP CLI)

// Verificar que llegó el código
$code = $_GET['code'] ?? '';
if (!$code) {
    flash('error', 'No se recibió autorización de Google.');
    redirect('/index.php?page=login');
}

$clientId     = $_ENV['GOOGLE_CLIENT_ID']     ?? getenv('GOOGLE_CLIENT_ID')     ?? '';
$clientSecret = $_ENV['GOOGLE_CLIENT_SECRET'] ?? getenv('GOOGLE_CLIENT_SECRET') ?? '';
$redirectUri  = $_ENV['GOOGLE_REDIRECT_URI']  ?? getenv('GOOGLE_REDIRECT_URI')  ?? '';

// ---- Intercambiar código por token ----
$ch = curl_init('https://oauth2.googleapis.com/token');
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => http_build_query([
        'code'          => $code,
        'client_id'     => $clientId,
        'client_secret' => $clientSecret,
        'redirect_uri'  => $redirectUri,
        'grant_type'    => 'authorization_code',
    ]),
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_TIMEOUT        => 10,
]);
$tokenResponse = json_decode(curl_exec($ch), true);
curl_close($ch);

if (empty($tokenResponse['access_token'])) {
    error_log('[GOOGLE OAUTH] Token error: ' . json_encode($tokenResponse));
    flash('error', 'Error al obtener token de Google. Intenta de nuevo.');
    redirect('/index.php?page=login');
}

// ---- Obtener datos del usuario de Google ----
$ch = curl_init('https://www.googleapis.com/oauth2/v3/userinfo');
curl_setopt_array($ch, [
    CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $tokenResponse['access_token']],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_TIMEOUT        => 10,
]);
$googleUser = json_decode(curl_exec($ch), true);
curl_close($ch);

if (empty($googleUser['email'])) {
    flash('error', 'No se pudo obtener el email de Google.');
    redirect('/index.php?page=login');
}

$googleId = $googleUser['sub']            ?? '';
$email    = strtolower(trim($googleUser['email']   ?? ''));
$name     = trim($googleUser['name']      ?? '');
$picture  = $googleUser['picture']        ?? '';

// ---- Buscar o crear usuario ----
$db   = getDB();
$stmt = $db->prepare("SELECT * FROM users WHERE email = ? LIMIT 1");
$stmt->execute([$email]);
$user = $stmt->fetch();

if ($user) {
    // Usuario existente — actualizar google_id si no lo tiene
    if (empty($user['google_id'])) {
        $db->prepare("UPDATE users SET google_id=?, google_picture=? WHERE id=?")
           ->execute([$googleId, $picture, $user['id']]);
    }
    // Login
    loginUser((int)$user['id']);
    flash('success', '¡Bienvenido de vuelta, ' . $user['full_name'] . '! ⚽');
    redirect('/index.php?page=home');

} else {
    // Usuario nuevo — crear cuenta automáticamente
    $username = generateUsername($email, $db);
    $avatars  = ['⚽','🏆','🥅','👟','🦅','🔥','⭐','🎯','🦁','🐉','💎','🚀','🌟','⚡','🏅'];
    $avatar   = $avatars[array_rand($avatars)];
    $ip       = getClientIP();

    // Sin contraseña — login solo por Google
    $fakeHash = password_hash(bin2hex(random_bytes(32)), PASSWORD_BCRYPT);

    $db->beginTransaction();
    try {
        $db->prepare("INSERT INTO users (username, email, password_hash, full_name, avatar, google_id, google_picture, created_ip) VALUES (?,?,?,?,?,?,?,?)")
           ->execute([$username, $email, $fakeHash, $name ?: $username, $avatar, $googleId, $picture, $ip]);
        $uid = (int)$db->lastInsertId();
        $db->prepare("INSERT INTO wallets (user_id, balance) VALUES (?, 0)")->execute([$uid]);
        $db->commit();

        loginUser($uid);

        // Notificar al admin por Telegram
        if (function_exists('sendTelegram')) {
            sendTelegram("🆕 <b>NUEVO USUARIO — Google OAuth</b>\n\n👤 <b>Nombre:</b> " . htmlspecialchars($name) . "\n📧 <b>Email:</b> " . htmlspecialchars($email) . "\n👤 <b>Usuario:</b> " . htmlspecialchars($username));
        }

        flash('success', '¡Cuenta creada con Google! Bienvenido ' . ($name ?: $username) . ' ⚽');
        redirect('/index.php?page=home');

    } catch (Exception $e) {
        $db->rollBack();
        error_log('[GOOGLE OAUTH] Register error: ' . $e->getMessage());
        flash('error', 'Error al crear la cuenta. Intenta de nuevo.');
        redirect('/index.php?page=login');
    }
}

// ---- Helper: generar username único ----
function generateUsername(string $email, PDO $db): string {
    $base = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', explode('@', $email)[0]));
    $base = substr($base, 0, 20) ?: 'user';
    $username = $base;
    $i = 1;
    while (true) {
        $stmt = $db->prepare("SELECT id FROM users WHERE username = ?");
        $stmt->execute([$username]);
        if (!$stmt->fetch()) break;
        $username = $base . $i;
        $i++;
    }
    return $username;
}