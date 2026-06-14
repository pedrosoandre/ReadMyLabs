<?php
// Importa o LOINC (com tradução PT-BR) para kb_marcadores.
//
// Baixe os arquivos (grátis, exige cadastro) em https://loinc.org/downloads/
//   - Loinc.csv                 (tabela principal)
//   - a variante linguística pt-BR (ex.: ptBR<n>LinguisticVariant.csv)
// e coloque em rag/ingest/fontes/.
//
// Uso:
//   php rag/ingest/01_loinc_import.php ingest/fontes/Loinc.csv [ingest/fontes/ptBR.csv]
//
// Por padrão importa apenas classes laboratoriais comuns (filtro abaixo);
// passe --all como 3º argumento para importar tudo (NÃO recomendado: ~100k).

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only\n"); }

require_once __DIR__ . '/../../loads_env.php';
require_once __DIR__ . '/../../db.php';
loadEnv(__DIR__ . '/../../.env');

$loincCsv = $argv[1] ?? null;
$ptbrCsv  = $argv[2] ?? null;
$importarTudo = in_array('--all', $argv, true);

if (!$loincCsv || !is_file($loincCsv)) {
    fwrite(STDERR, "Arquivo Loinc.csv não encontrado.\n");
    fwrite(STDERR, "Uso: php rag/ingest/01_loinc_import.php caminho/Loinc.csv [caminho/ptBR.csv]\n");
    exit(1);
}

// Classes LOINC laboratoriais mais comuns (mantém o RAG focado e rápido).
$classesLab = ['CHEM','HEM/BC','SERO','DRUG/TOX','MICRO','UA','ABXBACT','COAG','ALLERGY','ENDO','BLDBK','CELLMARK','TUMOR','MOLPATH'];

$db = db();
$db->exec("INSERT IGNORE INTO kb_fontes (nome, url, licenca)
           VALUES ('LOINC PT-BR', 'https://loinc.org', 'LOINC License')");
$fonteId = (int) $db->query("SELECT id FROM kb_fontes WHERE nome='LOINC PT-BR'")->fetchColumn();

// 1) Carrega traduções PT-BR (LOINC_NUM -> nome traduzido), se houver.
$ptbr = [];
if ($ptbrCsv && is_file($ptbrCsv)) {
    $ptbr = lerCsvIndexado($ptbrCsv, 'LOINC_NUM', ['LONG_COMMON_NAME','COMPONENT','SHORTNAME']);
    echo 'Traduções PT-BR carregadas: ' . count($ptbr) . "\n";
}

// 2) Percorre a tabela principal
$fh = fopen($loincCsv, 'r');
$header = fgetcsv($fh);
$col = array_flip($header);
$req = ['LOINC_NUM','COMPONENT','LONG_COMMON_NAME','CLASS','STATUS'];
foreach ($req as $c) {
    if (!isset($col[$c])) { fwrite(STDERR, "Coluna ausente no CSV: $c\n"); exit(1); }
}

$ins = $db->prepare(
    'INSERT INTO kb_marcadores (loinc_code, nome_canonico, sinonimos, categoria, unidade_padrao, fonte_id)
     VALUES (:code, :nome, :sin, :cat, :uni, :f)
     ON DUPLICATE KEY UPDATE
        nome_canonico = VALUES(nome_canonico),
        sinonimos     = VALUES(sinonimos),
        categoria     = VALUES(categoria),
        unidade_padrao= VALUES(unidade_padrao)'
);

$n = 0; $pulados = 0;
$db->beginTransaction();
while (($r = fgetcsv($fh)) !== false) {
    $code   = $r[$col['LOINC_NUM']] ?? '';
    $status = $r[$col['STATUS']] ?? '';
    $classe = $r[$col['CLASS']] ?? '';
    if ($code === '' || $status !== 'ACTIVE') { $pulados++; continue; }
    if (!$importarTudo && !classeEhLab($classe, $classesLab)) { $pulados++; continue; }

    // Nome preferido: tradução PT-BR > LONG_COMMON_NAME em inglês
    $nome = $ptbr[$code]['LONG_COMMON_NAME']
        ?? $ptbr[$code]['COMPONENT']
        ?? $r[$col['LONG_COMMON_NAME']]
        ?? $r[$col['COMPONENT']];
    $nome = trim((string) $nome);
    if ($nome === '') { $pulados++; continue; }

    // Sinônimos: componente, shortname, e a versão PT-BR/EN alternativa
    $sin = [];
    foreach (['COMPONENT','SHORTNAME'] as $c) {
        if (isset($col[$c]) && trim((string) $r[$col[$c]]) !== '') $sin[] = trim($r[$col[$c]]);
    }
    if (isset($ptbr[$code])) {
        foreach (['COMPONENT','SHORTNAME'] as $c) {
            if (!empty($ptbr[$code][$c])) $sin[] = trim($ptbr[$code][$c]);
        }
    }
    $sin = array_values(array_unique(array_filter($sin, fn($s) => $s !== '' && $s !== $nome)));

    $uni = isset($col['EXAMPLE_UCUM_UNITS']) ? trim((string) ($r[$col['EXAMPLE_UCUM_UNITS']] ?? '')) : '';

    $ins->execute([
        ':code' => $code,
        ':nome' => mb_substr($nome, 0, 160),
        ':sin'  => $sin ? mb_substr(implode('|', $sin), 0, 65000) : null,
        ':cat'  => mb_substr($classe, 0, 60) ?: null,
        ':uni'  => $uni !== '' ? mb_substr($uni, 0, 30) : null,
        ':f'    => $fonteId,
    ]);
    if (++$n % 1000 === 0) { $db->commit(); $db->beginTransaction(); echo "  $n importados...\n"; }
}
$db->commit();
fclose($fh);

echo "LOINC importado: $n marcadores ($pulados pulados).\n";
echo "Próximo: php rag/ingest/03_embeddings.php\n";

// ---------- helpers ----------

function classeEhLab(string $classe, array $classesLab): bool {
    foreach ($classesLab as $c) {
        if (stripos($classe, $c) === 0) return true;
    }
    return false;
}

/** Lê um CSV e indexa por uma coluna-chave, guardando só as colunas pedidas. */
function lerCsvIndexado(string $caminho, string $chave, array $colunas): array {
    $fh = fopen($caminho, 'r');
    if (!$fh) return [];
    $header = fgetcsv($fh);
    $col = array_flip($header);
    if (!isset($col[$chave])) { fclose($fh); return []; }
    $out = [];
    while (($r = fgetcsv($fh)) !== false) {
        $k = $r[$col[$chave]] ?? '';
        if ($k === '') continue;
        $reg = [];
        foreach ($colunas as $c) {
            if (isset($col[$c])) $reg[$c] = $r[$col[$c]] ?? '';
        }
        $out[$k] = $reg;
    }
    fclose($fh);
    return $out;
}
