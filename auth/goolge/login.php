<?php
// =============================================
// CEDEKA WC — Google OAuth Login
// =============================================
ob_start();
require_once __DIR__ . '/../../includes/config.php';

startSession();

$clientId    = getenv('GOOGLE_CLIENT_ID') ?: '';
$redirectUri = getenv('GOOGLE_REDIRECT_URI') ?: '';

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