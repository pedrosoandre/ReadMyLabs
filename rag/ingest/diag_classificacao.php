<?php
// Teste automatizado da classificação LOCAL (sem Anthropic, sem Voyage, ZERO token):
// monta um exame fictício usando APELIDOS e confirma que o app os reconhece —
// provando que a camada de sinônimos do RAG (expandirTermosComKb) está ativa.
// Rodar (no servidor): php rag/ingest/diag_classificacao.php

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only\n"); }

require_once __DIR__ . '/../../loads_env.php';
require_once __DIR__ . '/../../db.php';
require_once __DIR__ . '/../../lib/referencia.php';
loadEnv(__DIR__ . '/../../.env');

$db = db();

// 1) Quantos sinônimos extras o RAG injeta na classificação
$grupos = carregarMarcadores($db);
$extra  = expandirTermosComKb($db, array_keys($grupos));
echo "Sinônimos extras injetados pelo RAG (kb_marcadores): " . count($extra) . "\n\n";

// 2) Exame fictício escrito SÓ com apelidos (não os nomes canônicos)
$texto = "Paciente exemplo
ASAT (TGO): 45 U/L
ALT: 60 U/L
HGB: 13.5 g/dL
Glicemia de jejum: 110 mg/dL
HbA1c: 6.2 %
Colesterol HDL: 38 mg/dL
TSH ultrassensível: 5.2 uUI/mL";

echo "== Marcadores reconhecidos ==\n";
$marc = classificarExame($texto, null, null, $db);
if (!$marc) { echo "NENHUM reconhecido — algo está errado.\n"; exit(1); }
foreach ($marc as $m) {
    printf("  %-22s %6s %-8s -> %s\n", $m['nome'], $m['valor'], $m['unidade'], strtoupper($m['status']));
}
echo "Total: " . count($marc) . "\n\n";

// 3) Confere apelido -> nome canônico
echo "== Apelido => reconhecido? ==\n";
$nomes = array_column($marc, 'nome');
$casos = [['ASAT','TGO'], ['ALT','TGP'], ['HGB','Hemoglobina'],
          ['Glicemia de jejum','Glicose'], ['HbA1c','Hemoglobina glicada'],
          ['TSH ultrassensível','TSH']];
$ok = 0;
foreach ($casos as [$apelido, $canon]) {
    $achou = in_array($canon, $nomes, true);
    if ($achou) $ok++;
    echo "  " . str_pad($apelido, 22) . " => " . ($achou ? "OK ($canon)" : "FALHOU ($canon)") . "\n";
}
echo "\nResultado: $ok/" . count($casos) . " apelidos resolvidos.\n";
exit($ok === count($casos) ? 0 : 2);
