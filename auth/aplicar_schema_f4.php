<?php
// ReadMyLabs — aplicador one-off do schema F4 (histórico criptografado).
// Uso: enviar para o servidor e rodar via PHP/PDO (MySQL CLI da Hostinger
// não loga; PDO loga). Depois APAGAR este arquivo.
//
//   pscp -batch -hostkey '...' -P 65002 auth/aplicar_schema_f4.php user@host:~/
//   pscp -batch -hostkey '...' -P 65002 auth/schema_f4.sql       user@host:~/
//   plink ... 'cd ~/domains/readmylabs.com.br/public_html && php ~/aplicar_schema_f4.php'
//   plink ... 'rm ~/aplicar_schema_f4.php ~/schema_f4.sql'

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}

require_once __DIR__ . '/../loads_env.php';
require_once __DIR__ . '/../db.php';
loadEnv();

$sqlPath = $argv[1] ?? (__DIR__ . '/schema_f4.sql');
if (!is_file($sqlPath)) {
    // procura no HOME (cenário do plink acima)
    $alt = (getenv('HOME') ?: '~') . '/schema_f4.sql';
    if (is_file($alt)) $sqlPath = $alt;
}
if (!is_file($sqlPath)) {
    fwrite(STDERR, "schema_f4.sql não encontrado.\n");
    exit(1);
}

$sql = file_get_contents($sqlPath);
$pdo = db();

// Split por ';' no fim de linha (suficiente p/ este arquivo).
$stmts = array_values(array_filter(array_map('trim', preg_split('/;\s*\R/', $sql))));

$okN = 0; $skipN = 0; $errN = 0;
foreach ($stmts as $s) {
    if ($s === '' || str_starts_with($s, '--')) continue;
    try {
        $pdo->exec($s);
        echo "[OK ] " . substr(preg_replace('/\s+/', ' ', $s), 0, 90) . "...\n";
        $okN++;
    } catch (\PDOException $e) {
        // Idempotência: alguns servidores não aceitam IF NOT EXISTS em FK/INDEX. Aceitamos
        // como skip se a mensagem indicar "duplicate" / "exists" / "Duplicate column".
        $m = $e->getMessage();
        if (preg_match('/(duplicate|exists|errno: 121|errno: 1060|errno: 1061|errno: 1826)/i', $m)) {
            echo "[SKIP] já aplicado: " . substr(preg_replace('/\s+/', ' ', $s), 0, 70) . "...\n";
            $skipN++;
        } else {
            echo "[ERR ] $m\n  STMT: " . substr(preg_replace('/\s+/', ' ', $s), 0, 100) . "...\n";
            $errN++;
        }
    }
}

echo "\nResumo: $okN ok, $skipN já aplicados, $errN erros.\n";
exit($errN > 0 ? 1 : 0);
