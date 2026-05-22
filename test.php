<?php
$host = getenv('DB_HOST') ?: 'no definido';
$port = getenv('DB_PORT') ?: 'no definido';
$name = getenv('DB_NAME') ?: 'no definido';
$user = getenv('DB_USER') ?: 'no definido';

echo "HOST: $host <br>";
echo "PORT: $port <br>";
echo "NAME: $name <br>";
echo "USER: $user <br>";

try {
    $dsn = "mysql:host=$host;port=$port;dbname=$name;charset=utf8mb4";
    $pdo = new PDO($dsn, $user, getenv('DB_PASS'));
    echo "<br>✅ Conexión exitosa!";
} catch (Exception $e) {
    echo "<br>❌ Error: " . $e->getMessage();
}