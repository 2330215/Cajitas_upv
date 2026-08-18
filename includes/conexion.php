<?php
// Configuración de la base de datos
require_once __DIR__ . '/config.php';

$host = 'localhost';
$db   = 'esp32_attendance_v3';
$user = 'root';
$pass = 'TuContraseñaSegura123';
$charset = 'utf8mb4';

$dsn = "mysql:host=$host;dbname=$db;charset=$charset";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
    $pdo = new PDO($dsn, $user, $pass, $options);

    // Alinear la zona horaria de MySQL con la de PHP.
    // Se manda el desplazamiento (ej. "-06:00") porque los servidores nuevos
    // no siempre tienen cargadas las tablas de zonas horarias con nombre.
    $offset = (new DateTime('now', new DateTimeZone(APP_TIMEZONE)))->format('P');
    $pdo->exec("SET time_zone = '$offset'");
} catch (\PDOException $e) {
    error_log('[Conexion] ' . $e->getMessage());

    // verificacion.php define esta constante para poder mostrar el error
    // en pantalla en vez de cortar la página.
    if (defined('CONEXION_SILENCIOSA')) {
        $pdo = null;
        $conexionError = $e->getMessage();
    } elseif (PHP_SAPI === 'cli') {
        throw $e;
    } else {
        // No mostrar la traza de MySQL al usuario final
        http_response_code(500);
        exit('No se pudo conectar con la base de datos. Avisa al administrador.');
    }
}
