<?php
// ReadMyLabs — aplicador one-off do schema_rag_radio.sql.
// Modelo: auth/aplicar_schema_f4.php. CLI-only, apaga após uso.
//
//   pscp ... rag/aplicar_schema_radio.php user@host:~/.../public_html/rag/
//   plink ... 'cd ~/.../public_html && php rag/aplicar_schema_radio.php'
//   plink ... 'rm ~/.../public_html/rag/aplicar_schema_radio.php'

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}

require_once __DIR__ . '/../loads_env.php';
require_once __DIR__ . '/../db.php';
loadEnv();

$sqlPath = $argv[1] ?? (__DIR__ . '/schema_rag_radio.sql');
if (!is_file($sqlPath)) {
    fwrite(STDERR, "schema_rag_radio.sql não encontrado em $sqlPath\n");
    exit(1);
}

$sql = file_get_contents($sqlPath);
$pdo = db();

$stmts = array_values(array_filter(array_map('trim', preg_split('/;\s*\R/', $sql))));

$okN = 0; $skipN = 0; $errN = 0;
foreach ($stmts as $s) {
    if ($s === '' || str_starts_with($s, '--')) continue;
    try {
        $pdo->exec($s);
        echo "[OK ] " . substr(preg_replace('/\s+/', ' ', $s), 0, 90) . "...\n";
        $okN++;
    } catch (\PDOException $e) {
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
