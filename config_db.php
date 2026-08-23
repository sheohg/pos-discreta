<?php
// Datos de Conexión a Servidor
define('DB_HOST', 'localhost');

// 1. Base de Datos POS Central
define('DB_POS_NAME', 'discreta_pos');
define('DB_POS_USER', 'discreta_posuser');
define('DB_POS_PASS', 'tea[8{Z9Flm~%HAF');

// 2. Base de Datos WordPress / WooCommerce / Fidelización
define('DB_WOO_NAME', 'discreta_wp165');
define('DB_WOO_USER', 'discreta_wp165');
define('DB_WOO_PASS', 'p!S!pS60g9');

// Función PDO unificada
function getDBConnection($dbName, $dbUser, $dbPass) {
    try {
        $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . $dbName . ";charset=utf8mb4", $dbUser, $dbPass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]);
        return $pdo;
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(["error" => "Error al conectar a $dbName: " . $e->getMessage()]);
        exit;
    }
}
?>