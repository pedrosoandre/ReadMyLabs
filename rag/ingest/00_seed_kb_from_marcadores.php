<?php
// Semeia kb_marcadores a partir dos marcadores que JÁ existem em
// marcadores_referencia. Deixa o RAG testável imediatamente, antes mesmo
// de baixar o LOINC. Idempotente (ON DUPLICATE KEY pelo nome canônico via
// busca prévia). Rodar: php rag/ingest/00_seed_kb_from_marcadores.php

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only\n"); }

require_once __DIR__ . '/../../loads_env.php';
require_once __DIR__ . '/../../db.php';
loadEnv(__DIR__ . '/../../.env');

$db = db();

// Fonte
$db->exec("INSERT IGNORE INTO kb_fontes (nome, licenca, observacao)
           VALUES ('Seed marcadores_referencia', 'interno', 'Curadoria inicial ReadMyLabs')");
$fonteId = (int) $db->query("SELECT id FROM kb_fontes WHERE nome='Seed marcadores_referencia'")->fetchColumn();

// Agrupa por nome (a tabela tem variações por sexo/idade)
$rows = $db->query(
    'SELECT nome, sinonimos, categoria, unidade, descricao
       FROM marcadores_referencia'
)->fetchAll(PDO::FETCH_ASSOC);

$porNome = [];
foreach ($rows as $r) {
    $n = $r['nome'];
    if (!isset($porNome[$n])) { $porNome[$n] = $r; }
    // une sinônimos de todas as variações
    if (!empty($r['sinonimos'])) {
        $porNome[$n]['sinonimos'] = trim(($porNome[$n]['sinonimos'] ?? '') . '|' . $r['sinonimos'], '|');
    }
}

$sel = $db->prepare('SELECT id FROM kb_marcadores WHERE nome_canonico = :n LIMIT 1');
$ins = $db->prepare(
    'INSERT INTO kb_marcadores (nome_canonico, sinonimos, categoria, unidade_padrao, descricao_leiga, fonte_id)
     VALUES (:n, :s, :c, :u, :d, :f)'
);
$upd = $db->prepare(
    'UPDATE kb_marcadores SET sinonimos=:s, categoria=:c, unidade_padrao=:u, descricao_leiga=:d WHERE id=:id'
);

$novos = 0; $atualizados = 0;
foreach ($porNome as $nome => $r) {
    // dedup de sinônimos
    $sin = array_values(array_unique(array_filter(array_map('trim', explode('|', $r['sinonimos'] ?? '')))));
    $sinStr = implode('|', $sin) ?: null;

    $sel->execute([':n' => $nome]);
    $id = $sel->fetchColumn();
    if ($id) {
        $upd->execute([':s' => $sinStr, ':c' => $r['categoria'], ':u' => $r['unidade'], ':d' => $r['descricao'], ':id' => $id]);
        $atualizados++;
    } else {
        $ins->execute([':n' => $nome, ':s' => $sinStr, ':c' => $r['categoria'], ':u' => $r['unidade'], ':d' => $r['descricao'], ':f' => $fonteId]);
        $novos++;
    }
}

echo "kb_marcadores semeado: $novos novos, $atualizados atualizados.\n";
echo "Próximo: php rag/ingest/01_loinc_import.php (superset LOINC) e depois 03_embeddings.php\n";
