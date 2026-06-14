<?php
// Testes de pipeline (CLI, roda no servidor — contorna o captcha chamando as funções direto).
// Uso:  php tests/pipeline.php [BASE_APP] [--full]
//   BASE_APP default = public_html de produção.  --full inclui 1 chamada ao Claude (custa token).
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only\n"); }
$args = array_values(array_filter($argv, fn($a) => $a !== '--full'));
$base = $args[1] ?? '/home/u854646013/domains/readmylabs.com.br/public_html';
$full = in_array('--full', $argv, true);

require "$base/loads_env.php"; loadEnv("$base/.env");
require "$base/db.php";
require "$base/lib/claude.php";
require "$base/lib/referencia.php";
$db = db();

$pass = 0; $fail = 0;
function chk(string $d, bool $cond): void {
    global $pass, $fail;
    echo ($cond ? "PASS  " : "FAIL  ") . $d . "\n";
    $cond ? $pass++ : $fail++;
}

// --- Detector + classificação local (determinístico, ZERO token) ---
$lab = "Glicose: 128 mg/dL\nColesterol total: 248 mg/dL\nHemoglobina: 10.2 g/dL\nTSH: 8.5 uUI/mL";
$pl  = inferirSexoIdade($lab);
$ml  = classificarExame($lab, $pl['sexo'], $pl['idade'], $db);
chk('lab: classifica marcadores (>0)', count($ml) > 0);
chk('lab: detecta alterados (Glicose/Colesterol)', count(array_filter($ml, fn($m) => ($m['status'] ?? '') !== 'normal')) > 0);

$laudo = "RESSONANCIA DO CRANIO. Microangiopatia leve. Sem efeito de massa. Sistema ventricular normal.";
$pr    = inferirSexoIdade($laudo);
$mr    = classificarExame($laudo, $pr['sexo'], $pr['idade'], $db);
chk('laudo: zero marcadores (=> auto-roteia p/ imagem)', count($mr) === 0);
chk('vazio: zero marcadores', count(classificarExame('', null, null, $db)) === 0);

// --- DB: ENUM exames.tipo aceita 'imagem' (migração aplicada) ---
$col = $db->query("SHOW COLUMNS FROM exames LIKE 'tipo'")->fetch(PDO::FETCH_ASSOC);
chk("db: exames.tipo aceita 'imagem'", strpos($col['Type'] ?? '', "'imagem'") !== false);

// --- Full: conectividade real com o Claude (custa ~1 chamada) ---
if ($full) {
    $r = chamarClaude('Responda apenas: ok', '', MODELO_IMAGEM, 10);
    chk('[full] Claude API responde', $r['ok'] && trim($r['texto']) !== '');
}

echo "== resultado: $pass PASS / $fail FAIL ==\n";
exit($fail === 0 ? 0 : 1);
