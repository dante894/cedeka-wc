<?php
// =============================================
// CEDEKA WC — Google OAuth Login
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
        'samesite' => 'Lax',
    ]);
    session_start();
}

$clientId    = $_ENV['GOOGLE_CLIENT_ID']    ?? getenv('GOOGLE_CLIENT_ID')    ?? '';
$redirectUri = $_ENV['GOOGLE_REDIRECT_URI'] ?? getenv('GOOGLE_REDIRECT_URI') ?? '';

if (empty($clientId)) {
    die('Error: GOOGLE_CLIENT_ID no configurado. Configura las variables de entorno en Railway.');
}

// Generar state para prevenir CSRF
$state = bin2hex(random_bytes(16));
$_SESSION['oauth_state'] = $state;

$params = http_build_query([
    'client_id'     => $clientId,
    'redirect_uri'  => $redirectUri,
    'response_type' => 'code',
    'scope'         => 'openid email profile',
    'access_type'   => 'online',
    'state'         => $state,
    'prompt'        => 'select_account',
]);

header('Location: https://accounts.google.com/o/oauth2/v2/auth?' . $params);
exit;