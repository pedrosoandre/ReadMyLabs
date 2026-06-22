<?php
// ReadMyLabs — resolução semântica de nome de marcador.
// Dado um nome livre que o regex local NÃO reconheceu (ex.: "Transaminase
// glutâmico-oxalacética"), encontra o marcador canônico correspondente
// via embedding + cosseno. Custa 1 chamada de embedding (barata) e zero
// token do Claude. Retorna null se o RAG estiver desligado ou sem confiança.

require_once __DIR__ . '/voyage.php';
require_once __DIR__ . '/vetor.php';

/**
 * @param float $limiar similaridade mínima (cosseno) para aceitar o match.
 *                      0.78 é conservador; ajuste após avaliar na prática.
 * @return array{id:int, loinc_code:?string, nome_canonico:string, categoria:?string,
 *               unidade_padrao:?string, descricao_leiga:?string, score:float}|null
 */
function resolverMarcador(PDO $db, string $termo, float $limiar = 0.78): ?array {
    $termo = trim($termo);
    if ($termo === '' || !ragAtivo()) return null;

    $vec = gerarEmbedding($termo, 'query');
    if ($vec === null) return null;

    $hits = vetorBuscar($db, vetorNormalizar($vec), 'marcador', 1);
    if (!$hits || $hits[0]['score'] < $limiar) return null;

    $stmt = $db->prepare(
        'SELECT id, loinc_code, nome_canonico, categoria, unidade_padrao, descricao_leiga
           FROM kb_marcadores WHERE id = :id LIMIT 1'
    );
    $stmt->execute([':id' => $hits[0]['ref_id']]);
    $row = $stmt->fetch();
    if (!$row) return null;

    $row['score'] = $hits[0]['score'];
    return $row;
}

/**
 * Busca trechos de referência relevantes a uma pergunta livre ("o que é
 * o exame X?"). Recupera por cosseno e, se houver chave, reordena com o
 * reranker da Voyage. Retorna os textos prontos para o Claude redigir.
 *
 * @return array<int,array{titulo:?string, texto:string, score:float}>
 */
function recuperarContexto(PDO $db, string $pergunta, int $topK = 5): array {
    $pergunta = trim($pergunta);
    if ($pergunta === '' || !ragAtivo()) return [];

    $vec = gerarEmbedding($pergunta, 'query');
    if ($vec === null) return [];

    // Recupera um pool maior por cosseno, depois rerankeia para o topK.
    $hits = vetorBuscar($db, vetorNormalizar($vec), 'chunk', max($topK * 4, 12));
    if (!$hits) return [];

    $ids = array_column($hits, 'ref_id');
    $ph  = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $db->prepare("SELECT id, titulo, texto FROM kb_chunks WHERE id IN ($ph)");
    $stmt->execute($ids);
    $porId = [];
    foreach ($stmt as $r) $porId[(int) $r['id']] = $r;

    // Mantém a ordem do cosseno
    $docs = [];
    foreach ($hits as $h) {
        if (isset($porId[$h['ref_id']])) {
            $docs[] = [
                'titulo' => $porId[$h['ref_id']]['titulo'],
                'texto'  => $porId[$h['ref_id']]['texto'],
                'score'  => $h['score'],
            ];
        }
    }

    // Reranker (opcional): reordena pelos textos
    $rer = voyageRerank($pergunta, array_column($docs, 'texto'), $topK);
    if ($rer) {
        $reord = [];
        foreach ($rer as $r) {
            if (isset($docs[$r['index']])) {
                $d = $docs[$r['index']];
                $d['score'] = $r['score'];
                $reord[] = $d;
            }
        }
        return $reord;
    }

    return array_slice($docs, 0, $topK);
}

/**
 * Recupera definições/glossário para um LAUDO de exame de imagem.
 *
 * Estratégia: o laudo inteiro vira a query — sua semântica casa com os
 * marcadores e chunks de domínio 'radio' previamente embedados. Pega top-K
 * marcadores (definições curtas) e top-K chunks (texto educativo longo),
 * filtra por limiar de confiança, e devolve trechos prontos pra serem
 * injetados como bloco <glossario> no prompt do Claude.
 *
 * Para o Claude redigir ATERRADO: ele usa só esse glossário pra definir
 * termos técnicos, não inventa. Falha-para-desligado: sem RAG ou sem
 * termos populados, devolve [] e a redação cai no caminho legado.
 *
 * @param float $limiar similaridade mínima (0.72 é mais permissivo que o
 *                      laboratorial 0.78 — radiologia tem mais sinônimos curtos).
 * @return array<int,array{titulo:?string, texto:string, score:float, fonte:string}>
 */
function recuperarContextoRadio(PDO $db, string $textoLaudo, int $topK = 6, float $limiar = 0.72): array {
    $textoLaudo = trim($textoLaudo);
    if ($textoLaudo === '' || !ragAtivo()) return [];

    $vec = gerarEmbedding($textoLaudo, 'query');
    if ($vec === null) return [];
    $vecNorm = vetorNormalizar($vec);

    // Pool maior pra alimentar o reranker depois (se Voyage tiver chave).
    $pool = max($topK * 3, 10);

    // Marcadores (definições curtas, descricao_leiga)
    $hitsMarc   = vetorBuscar($db, $vecNorm, 'marcador', $pool, null, 'radio');
    // Chunks (texto educativo longo)
    $hitsChunks = vetorBuscar($db, $vecNorm, 'chunk',    $pool, null, 'radio');

    $docs = [];

    if ($hitsMarc) {
        $ids = array_column(array_filter($hitsMarc, fn($h) => $h['score'] >= $limiar), 'ref_id');
        if ($ids) {
            $ph = implode(',', array_fill(0, count($ids), '?'));
            $stmt = $db->prepare(
                "SELECT id, nome_canonico, descricao_leiga
                   FROM kb_marcadores
                  WHERE id IN ($ph) AND descricao_leiga IS NOT NULL AND descricao_leiga != ''"
            );
            $stmt->execute($ids);
            $porId = [];
            foreach ($stmt as $r) $porId[(int) $r['id']] = $r;
            foreach ($hitsMarc as $h) {
                if ($h['score'] < $limiar || !isset($porId[$h['ref_id']])) continue;
                $row = $porId[$h['ref_id']];
                $docs[] = [
                    'titulo' => $row['nome_canonico'],
                    'texto'  => $row['descricao_leiga'],
                    'score'  => $h['score'],
                    'fonte'  => 'marcador',
                ];
            }
        }
    }

    if ($hitsChunks) {
        $ids = array_column(array_filter($hitsChunks, fn($h) => $h['score'] >= $limiar), 'ref_id');
        if ($ids) {
            $ph = implode(',', array_fill(0, count($ids), '?'));
            $stmt = $db->prepare("SELECT id, titulo, texto FROM kb_chunks WHERE id IN ($ph)");
            $stmt->execute($ids);
            $porId = [];
            foreach ($stmt as $r) $porId[(int) $r['id']] = $r;
            foreach ($hitsChunks as $h) {
                if ($h['score'] < $limiar || !isset($porId[$h['ref_id']])) continue;
                $row = $porId[$h['ref_id']];
                $docs[] = [
                    'titulo' => $row['titulo'],
                    'texto'  => $row['texto'],
                    'score'  => $h['score'],
                    'fonte'  => 'chunk',
                ];
            }
        }
    }

    if (!$docs) return [];

    // Reranker opcional: usa o laudo inteiro como query, reordena os textos
    // para colocar primeiro o que é mais relevante para ESTE laudo.
    $rer = voyageRerank($textoLaudo, array_column($docs, 'texto'), $topK);
    if ($rer) {
        $reord = [];
        foreach ($rer as $r) {
            if (isset($docs[$r['index']])) {
                $d = $docs[$r['index']];
                $d['score'] = $r['score'];
                $reord[] = $d;
            }
        }
        return $reord;
    }

    usort($docs, fn($a, $b) => $b['score'] <=> $a['score']);
    return array_slice($docs, 0, $topK);
}
