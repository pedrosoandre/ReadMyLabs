<?php
// Diagnóstico da Voyage: valida a VOYAGE_API_KEY com uma chamada real,
// conta o que falta embutir e estima o custo de embutir os marcadores.
// NÃO grava vetores (é só diagnóstico; faz ~2 chamadas pequenas).
// Rodar (no servidor): php rag/ingest/diag_voyage.php

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only\n"); }

require_once __DIR__ . '/../../loads_env.php';
require_once __DIR__ . '/../../db.php';
require_once __DIR__ . '/../lib/voyage.php';
loadEnv(__DIR__ . '/../../.env');

// Preço por 1M de tokens (USD). Confirme o valor atual em voyageai.com/pricing.
$PRECO_POR_MILHAO = (float) (getenv('VOYAGE_PRECO_1M') ?: 0.18);

echo "== Diagnóstico Voyage ==\n";

if (!getenv('VOYAGE_API_KEY')) {
    fwrite(STDERR, "VOYAGE_API_KEY ausente no .env do servidor.\n");
    fwrite(STDERR, "Pegue uma chave em https://dash.voyageai.com e adicione ao .env, depois rode de novo.\n");
    exit(1);
}

$modelo = voyageModeloEmbed();
echo "Modelo de embeddings: $modelo\n";

// 1) Teste da chave — embute 1 texto curto
$t = gerarEmbeddings(['Hemoglobina'], 'document', $modelo);
if (!$t['ok'] || empty($t['vetores'][0])) {
    fwrite(STDERR, "FALHA: a API recusou. Verifique se a VOYAGE_API_KEY está correta e ativa.\n");
    exit(1);
}
echo 'Chave OK  |  dimensões=' . count($t['vetores'][0]) . '  |  tokens (teste)=' . $t['tokens'] . "\n";

$db = db();

// 2) Quanto falta embutir
$qModelo = $db->quote($modelo);
$mTot  = (int) $db->query('SELECT COUNT(*) FROM kb_marcadores')->fetchColumn();
$mPend = (int) $db->query(
    "SELECT COUNT(*) FROM kb_marcadores m
       LEFT JOIN kb_vetores v ON v.ref_tipo='marcador' AND v.ref_id=m.id AND v.modelo=$qModelo
      WHERE v.id IS NULL"
)->fetchColumn();
$cTot  = (int) $db->query('SELECT COUNT(*) FROM kb_chunks')->fetchColumn();
echo "kb_marcadores: $mTot (faltam embutir: $mPend)  |  kb_chunks: $cTot\n";

// 3) Estimativa de custo — mede tokens numa amostra real e extrapola
$rows = $db->query('SELECT nome_canonico, sinonimos, descricao_leiga FROM kb_marcadores LIMIT 8')
           ->fetchAll(PDO::FETCH_ASSOC);
$amostra = array_map(
    fn($m) => trim($m['nome_canonico'] . '. ' . str_replace('|', ', ', (string) $m['sinonimos']) . '. ' . (string) $m['descricao_leiga']),
    $rows
);

if ($amostra) {
    $s = gerarEmbeddings($amostra, 'document', $modelo);
    if ($s['ok'] && $s['tokens'] > 0) {
        $tokPorItem = $s['tokens'] / count($amostra);
        $alvo       = max($mPend, 1);
        $totalTok   = (int) round($tokPorItem * $alvo);
        $custo      = $totalTok / 1000000 * $PRECO_POR_MILHAO;
        echo sprintf("Estimativa p/ embutir %d marcadores: ~%d tokens (~%.0f tok/item)\n", $alvo, $totalTok, $tokPorItem);
        echo sprintf("Custo estimado: ~US\$ %.4f  (a US\$ %.2f por 1M tokens — confirme em voyageai.com/pricing)\n", $custo, $PRECO_POR_MILHAO);
    } else {
        echo "Não consegui medir a amostra para estimar o custo (a chamada de amostra falhou).\n";
    }
}

echo "\nTudo certo. Para embutir de verdade: php rag/ingest/03_embeddings.php\n";
