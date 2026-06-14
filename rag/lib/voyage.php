<?php
// ReadMyLabs — cliente Voyage AI (embeddings + rerank).
// A Anthropic NÃO tem API de embeddings; o RAG usa a Voyage.
// Espelha o estilo de lib/claude.php: cURL, error_log, retorno em array.

const VOYAGE_URL_EMBED  = 'https://api.voyageai.com/v1/embeddings';
const VOYAGE_URL_RERANK = 'https://api.voyageai.com/v1/rerank';

// Modelo padrão de embeddings. 'voyage-3-large' lidera em domínio médico;
// 'voyage-4-large' é o melhor multilíngue (PT-BR). Sobrescrevível via .env.
const VOYAGE_MODELO_EMBED  = 'voyage-3-large';
const VOYAGE_MODELO_RERANK = 'rerank-2';
const VOYAGE_DIMS          = 1024;

/**
 * RAG ligado? Exige VOYAGE_API_KEY. RAG_ATIVO=off no .env desliga em dev.
 * Falha "para desligado": sem chave, as features de RAG viram no-op e o
 * app segue funcionando como antes.
 */
function ragAtivo(): bool {
    if (!getenv('VOYAGE_API_KEY')) return false;
    $flag = strtolower((string) (getenv('RAG_ATIVO') ?: '1'));
    return !in_array($flag, ['0', 'off', 'false', 'no'], true);
}

function voyageModeloEmbed(): string {
    return getenv('VOYAGE_MODELO_EMBED') ?: VOYAGE_MODELO_EMBED;
}

/**
 * Gera embeddings para uma lista de textos (em lote).
 * @param string[] $textos
 * @param string   $inputType 'document' (ingestão) | 'query' (consulta)
 * @return array{ok:bool, vetores:array<int,array<int,float>>, modelo:string, tokens:int}
 */
function gerarEmbeddings(array $textos, string $inputType = 'document', ?string $modelo = null): array {
    $modelo = $modelo ?: voyageModeloEmbed();
    $vazio  = ['ok' => false, 'vetores' => [], 'modelo' => $modelo, 'tokens' => 0];

    $apiKey = getenv('VOYAGE_API_KEY');
    if (!$apiKey) { error_log('gerarEmbeddings: VOYAGE_API_KEY ausente'); return $vazio; }
    $textos = array_values(array_filter($textos, fn($t) => trim((string) $t) !== ''));
    if (!$textos) return $vazio;

    $payload = [
        'input'      => $textos,
        'model'      => $modelo,
        'input_type' => in_array($inputType, ['query', 'document'], true) ? $inputType : null,
    ];

    $r = voyageHttp(VOYAGE_URL_EMBED, $payload, $apiKey);
    if (!$r['ok']) return $vazio;

    $vetores = [];
    foreach (($r['json']['data'] ?? []) as $item) {
        $vetores[$item['index'] ?? count($vetores)] = $item['embedding'] ?? [];
    }
    ksort($vetores);

    return [
        'ok'      => count($vetores) === count($textos),
        'vetores' => array_values($vetores),
        'modelo'  => $modelo,
        'tokens'  => $r['json']['usage']['total_tokens'] ?? 0,
    ];
}

/** Conveniência: embedding de um único texto. Retorna o vetor ou null. */
function gerarEmbedding(string $texto, string $inputType = 'query', ?string $modelo = null): ?array {
    $r = gerarEmbeddings([$texto], $inputType, $modelo);
    return ($r['ok'] && isset($r['vetores'][0])) ? $r['vetores'][0] : null;
}

/**
 * Reordena documentos por relevância à query (reranker).
 * @param string[] $documentos
 * @return array<int,array{index:int,score:float}> ordenado por score desc
 */
function voyageRerank(string $query, array $documentos, int $topK = 5): array {
    $apiKey = getenv('VOYAGE_API_KEY');
    if (!$apiKey || !$documentos) return [];
    $r = voyageHttp(VOYAGE_URL_RERANK, [
        'query'     => $query,
        'documents' => array_values($documentos),
        'model'     => getenv('VOYAGE_MODELO_RERANK') ?: VOYAGE_MODELO_RERANK,
        'top_k'     => $topK,
    ], $apiKey);
    if (!$r['ok']) return [];

    $out = [];
    foreach (($r['json']['data'] ?? []) as $d) {
        $out[] = ['index' => $d['index'] ?? 0, 'score' => $d['relevance_score'] ?? 0.0];
    }
    return $out;
}

/** POST JSON genérico para a Voyage. @return array{ok:bool, json:array} */
function voyageHttp(string $url, array $payload, string $apiKey): array {
    $payload = array_filter($payload, fn($v) => $v !== null);

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey,
        ],
        CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
    ]);

    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($err || $code !== 200) {
        $detalhe = '';
        if ($resp) { $j = json_decode($resp, true); $detalhe = $j['detail'] ?? $j['error']['message'] ?? ''; }
        error_log("voyageHttp: HTTP $code $err $detalhe");
        return ['ok' => false, 'json' => []];
    }
    $j = json_decode($resp, true);
    return is_array($j) ? ['ok' => true, 'json' => $j] : ['ok' => false, 'json' => []];
}
