<?php
// ============================================================
//  DIGITALMARKET — config/database.php
//  Ligação à base de dados MySQL via PDO
// ============================================================

define('DB_HOST', 'localhost');
define('DB_NAME', 'digitalmarket');
define('DB_USER', 'root');        // utilizador phpMyAdmin local
define('DB_PASS', '');            // password vazia no XAMPP/WAMP
define('DB_CHARSET', 'utf8mb4');

define('JWT_SECRET', 'dm_estg_esh_2025_secret_key_change_in_production');
define('JWT_EXPIRE', 3600 * 24 * 7);   // 7 dias
define('UPLOAD_DIR', __DIR__ . '/../uploads/products/');
define('UPLOAD_MAX_MB', 100);

function getDB(): PDO {
    static $pdo = null;
    if ($pdo !== null) return $pdo;

    $dsn = sprintf(
        'mysql:host=%s;dbname=%s;charset=%s',
        DB_HOST, DB_NAME, DB_CHARSET
    );
    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];
    try {
        $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
    } catch (PDOException $e) {
        http_response_code(500);
        die(json_encode([
            'success' => false,
            'error'   => 'Erro de ligação à BD: ' . $e->getMessage()
        ]));
    }
    return $pdo;
}
