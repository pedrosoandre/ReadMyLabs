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
// RAG (radio) — testa que os arquivos foram deployados e funcionam mesmo sem Voyage key.
if (is_file("$base/rag/lib/voyage.php"))   require_once "$base/rag/lib/voyage.php";
if (is_file("$base/rag/lib/vetor.php"))    require_once "$base/rag/lib/vetor.php";
if (is_file("$base/rag/lib/resolver.php")) require_once "$base/rag/lib/resolver.php";
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

// --- DB: schema RAG radio aplicado (coluna `dominio` em kb_marcadores e kb_chunks) ---
$colM = $db->query("SHOW COLUMNS FROM kb_marcadores LIKE 'dominio'")->fetch(PDO::FETCH_ASSOC);
chk('db: kb_marcadores.dominio existe', !empty($colM));
chk('db: kb_marcadores.dominio aceita radio', strpos($colM['Type'] ?? '', "'radio'") !== false);
$colC = $db->query("SHOW COLUMNS FROM kb_chunks LIKE 'dominio'")->fetch(PDO::FETCH_ASSOC);
chk('db: kb_chunks.dominio existe', !empty($colC));

// --- DB: seed radio populou 110 termos curados ---
$nRadio = (int) $db->query("SELECT COUNT(*) FROM kb_marcadores WHERE dominio='radio'")->fetchColumn();
chk("db: kb_marcadores radio populou (>=110, atual=$nRadio)", $nRadio >= 110);

// --- DB: legacy preservado (dominio='lab' default) ---
$nLab = (int) $db->query("SELECT COUNT(*) FROM kb_marcadores WHERE dominio='lab'")->fetchColumn();
chk("db: kb_marcadores lab preservado (>=80, atual=$nLab)", $nLab >= 80);

// --- Funções RAG radio carregadas ---
chk('rag: recuperarContextoRadio existe', function_exists('recuperarContextoRadio'));
chk('rag: vetorBuscar existe', function_exists('vetorBuscar'));
chk('rag: ragAtivo existe', function_exists('ragAtivo'));

// --- Falha-para-desligado: sem VOYAGE_API_KEY, recuperarContextoRadio devolve [] ---
// (Não pode lançar erro nem retornar lixo — é o ponto da arquitetura.)
$voyage = getenv('VOYAGE_API_KEY');
if ($voyage) {
    echo "INFO  VOYAGE_API_KEY presente — recuperarContextoRadio pode chamar API real\n";
} else {
    $trechos = recuperarContextoRadio($db, "Ressonancia do cranio com microangiopatia leve.", 6);
    chk('rag-radio: sem VOYAGE_API_KEY, recuperarContextoRadio devolve []', $trechos === []);
}

// --- Vetor: vetorBuscar com filtro de dominio não-quebra (mesmo sem vetores) ---
// (Sem vetores ainda; basta verificar que SQL com JOIN não dá erro)
try {
    $hits = vetorBuscar($db, array_fill(0, 1024, 0.0), 'marcador', 3, null, 'radio');
    chk('rag-radio: vetorBuscar com dominio=radio não lança erro', is_array($hits));
} catch (\Throwable $e) {
    chk("rag-radio: vetorBuscar com dominio=radio não lança erro (erro: {$e->getMessage()})", false);
}

// --- Full: conectividade real com o Claude (custa ~1 chamada) ---
if ($full) {
    $r = chamarClaude('Responda apenas: ok', '', MODELO_IMAGEM, 10);
    chk('[full] Claude API responde', $r['ok'] && trim($r['texto']) !== '');
}

echo "== resultado: $pass PASS / $fail FAIL ==\n";
exit($fail === 0 ? 0 : 1);
