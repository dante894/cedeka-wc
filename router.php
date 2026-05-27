<?php
// =============================================
// CEDEKA WC — Router para PHP CLI Server
// =============================================
$uri = urldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));

// Servir archivos estáticos directamente
if ($uri !== '/' && file_exists(__DIR__ . $uri) && !is_dir(__DIR__ . $uri)) {
    return false;
}

// Rutas específicas
if ($uri === '/auth/google/login.php') {
    require __DIR__ . '/auth/google/login.php';
    exit;
}
if ($uri === '/auth/google/callback.php') {
    require __DIR__ . '/auth/google/callback.php';
    exit;
}
if ($uri === '/admin/index.php' || strpos($uri, '/admin/') === 0) {
    require __DIR__ . '/admin/index.php';
    exit;
}

// Default: index.php
require __DIR__ . '/index.php';