<?php
// Gera embeddings (Voyage) para todo kb_marcador e kb_chunk que ainda não
// tem vetor, e os guarda normalizados em kb_vetores. Idempotente: rode de
// novo após importar mais dados — só processa o que falta.
//
// Exige VOYAGE_API_KEY no .env. Rodar: php rag/ingest/03_embeddings.php

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only\n"); }

require_once __DIR__ . '/../../loads_env.php';
require_once __DIR__ . '/../../db.php';
require_once __DIR__ . '/../lib/voyage.php';
require_once __DIR__ . '/../lib/vetor.php';
loadEnv(__DIR__ . '/../../.env');

if (!getenv('VOYAGE_API_KEY')) {
    fwrite(STDERR, "VOYAGE_API_KEY ausente no .env. Pegue uma chave em https://dash.voyageai.com\n");
    exit(1);
}

$db     = db();
$modelo = voyageModeloEmbed();
$LOTE   = 96;

// ── Marcadores ────────────────────────────────────────────────
$pend = $db->prepare(
    "SELECT m.id, m.nome_canonico, m.sinonimos, m.descricao_leiga
       FROM kb_marcadores m
       LEFT JOIN kb_vetores v ON v.ref_tipo='marcador' AND v.ref_id=m.id AND v.modelo=:mod
      WHERE v.id IS NULL"
);
$pend->execute([':mod' => $modelo]);
$marcadores = $pend->fetchAll(PDO::FETCH_ASSOC);
echo 'Marcadores sem vetor: ' . count($marcadores) . "\n";
embutirLote($db, $marcadores, 'marcador', $modelo, $LOTE, function ($m) {
    // texto que representa o marcador: nome + sinônimos + descrição
    return trim($m['nome_canonico'] . '. ' . str_replace('|', ', ', (string) $m['sinonimos']) . '. ' . (string) $m['descricao_leiga']);
});

// ── Chunks ────────────────────────────────────────────────────
$pend = $db->prepare(
    "SELECT c.id, c.texto
       FROM kb_chunks c
       LEFT JOIN kb_vetores v ON v.ref_tipo='chunk' AND v.ref_id=c.id AND v.modelo=:mod
      WHERE v.id IS NULL"
);
$pend->execute([':mod' => $modelo]);
$chunks = $pend->fetchAll(PDO::FETCH_ASSOC);
echo 'Chunks sem vetor: ' . count($chunks) . "\n";
embutirLote($db, $chunks, 'chunk', $modelo, $LOTE, fn($c) => (string) $c['texto']);

echo "Embeddings concluídos (modelo: $modelo).\n";

/**
 * Processa registros em lotes: monta o texto, chama a Voyage e grava vetores.
 * @param callable $textoDe  fn(array $row): string
 */
function embutirLote(PDO $db, array $registros, string $refTipo, string $modelo, int $lote, callable $textoDe): void {
    $totalTokens = 0;
    for ($i = 0; $i < count($registros); $i += $lote) {
        $bloco  = array_slice($registros, $i, $lote);
        $textos = array_map($textoDe, $bloco);

        $r = gerarEmbeddings($textos, 'document', $modelo);
        if (!$r['ok']) {
            fwrite(STDERR, "  falha no lote a partir de $i (refTipo=$refTipo) — abortando este tipo\n");
            return;
        }
        foreach ($bloco as $j => $row) {
            if (!isset($r['vetores'][$j])) continue;
            vetorGuardar($db, $refTipo, (int) $row['id'], $r['vetores'][$j], $modelo);
        }
        $totalTokens += $r['tokens'];
        echo '  ' . min($i + $lote, count($registros)) . '/' . count($registros) . " ($refTipo)\n";
    }
    if ($totalTokens) echo "  tokens Voyage usados ($refTipo): $totalTokens\n";
}
