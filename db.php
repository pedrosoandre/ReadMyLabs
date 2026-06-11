<?php
// ReadMyLabs — conexão com o banco (MySQL / MariaDB) via PDO.
// Requer que loadEnv() já tenha sido chamado (loads_env.php).
// Credenciais nunca ficam no código: vêm do .env.

require_once __DIR__ . '/loads_env.php';

/**
 * Retorna uma conexão PDO singleton com o banco.
 * Em caso de falha, registra no log e lança RuntimeException
 * (o chamador decide como responder ao usuário).
 */
function db(): PDO {
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    loadEnv();

    $host = getenv('DB_HOST') ?: 'localhost';
    $name = getenv('DB_NAME') ?: '';
    $user = getenv('DB_USER') ?: '';
    $pass = getenv('DB_PASS') ?: '';
    $port = getenv('DB_PORT') ?: '3306';

    if ($name === '' || $user === '') {
        error_log('db(): DB_NAME ou DB_USER ausentes no .env');
        throw new RuntimeException('Configuração do banco incompleta.');
    }

    $dsn = "mysql:host=$host;port=$port;dbname=$name;charset=utf8mb4";

    try {
        $pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    } catch (PDOException $e) {
        error_log('db(): falha de conexão — ' . $e->getMessage());
        throw new RuntimeException('Não foi possível conectar ao banco.');
    }

    return $pdo;
}
