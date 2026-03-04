<?php
define('DB_HOST',    '108.167.132.55');
define('DB_NAME',    'tvdout68_crm');
define('DB_USER',    'tvdout68_crm');
define('DB_PASS',    'Suporte@2026!#@');
define('DB_CHARSET', 'utf8mb4');

// Em produção: 'https://crm.tvdoutor.com.br'
// Em localhost: 'http://localhost:8080'
define('BASE_URL', (isset($_SERVER['HTTP_HOST']) && str_contains($_SERVER['HTTP_HOST'], 'localhost'))
    ? 'http://localhost:8080'
    : 'https://crm.tvdoutor.com.br'
);

function getDB(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    }
    return $pdo;
}
