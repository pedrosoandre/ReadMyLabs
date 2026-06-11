<?php
// ReadMyLabs — camada de comunicação com o Claude.
// Duas economias de token vivem aqui:
//   1) prompt caching: o prefixo estável (system) é marcado com
//      cache_control, então o Claude o relê do cache (~0,1x do custo).
//   2) cache de explicações: explicação por (marcador, status, sexo,
//      faixa etária) é guardada no MySQL — hit = ZERO token.

// Modelo das explicações. Trocar por 'claude-sonnet-4-6' ou
// 'claude-haiku-4-5' reduz custo (a precisão médica do Opus é maior).
const MODELO_EXPLICACAO = 'claude-opus-4-8';
const ANTHROPIC_URL     = 'https://api.anthropic.com/v1/messages';

/**
 * Chamada de baixo nível ao Claude.
 * @param array|string $system  string simples ou array de blocos (p/ cache_control)
 * @return array{texto:string, tokens_in:int, tokens_out:int, cache_read:int, ok:bool}
 */
function chamarClaude(string $prompt, $system = '', string $model = MODELO_EXPLICACAO, int $maxTokens = 2000): array {
    $apiKey = getenv('ANTHROPIC_API_KEY');
    if (!$apiKey) {
        error_log('chamarClaude: ANTHROPIC_API_KEY ausente');
        return ['texto' => '', 'tokens_in' => 0, 'tokens_out' => 0, 'cache_read' => 0, 'ok' => false];
    }

    $payload = [
        'model'      => $model,
        'max_tokens' => $maxTokens,
        'messages'   => [['role' => 'user', 'content' => $prompt]],
    ];
    if ($system !== '' && $system !== []) {
        $payload['system'] = $system;
    }

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => ANTHROPIC_URL,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_TIMEOUT        => 90,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'x-api-key: ' . $apiKey,
            'anthropic-version: 2023-06-01',
        ],
        CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
    ]);

    $resposta = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $erroCurl = curl_error($ch);
    curl_close($ch);

    if ($erroCurl || $httpCode !== 200) {
        $detalhe = '';
        if ($resposta) {
            $j = json_decode($resposta, true);
            $detalhe = $j['error']['message'] ?? '';
        }
        error_log("chamarClaude: HTTP $httpCode $erroCurl $detalhe");
        return ['texto' => '', 'tokens_in' => 0, 'tokens_out' => 0, 'cache_read' => 0, 'ok' => false];
    }

    $r = json_decode($resposta, true);
    if (!is_array($r)) {
        error_log('chamarClaude: resposta JSON inválida');
        return ['texto' => '', 'tokens_in' => 0, 'tokens_out' => 0, 'cache_read' => 0, 'ok' => false];
    }
    return [
        'texto'      => $r['content'][0]['text'] ?? '',
        'tokens_in'  => $r['usage']['input_tokens'] ?? 0,
        'tokens_out' => $r['usage']['output_tokens'] ?? 0,
        'cache_read' => $r['usage']['cache_read_input_tokens'] ?? 0,
        'ok'         => true,
    ];
}

/** Faixa etária em bucket de 10 anos: 34 -> "30-39". Null se idade desconhecida. */
function faixaEtaria(?int $idade): ?string {
    if ($idade === null) return null;
    $base = intdiv($idade, 10) * 10;
    return $base . '-' . ($base + 9);
}

/** Chave determinística do cache de explicações. */
function chaveCache(string $marcador, string $status, ?string $sexo, ?string $faixa): string {
    return hash('sha256', implode('|', [$marcador, $status, $sexo ?? 'ambos', $faixa ?? '-']));
}

/**
 * Recebe os marcadores já classificados e devolve uma explicação humana
 * para cada um que esteja alterado. Usa o cache MySQL; só os que faltam
 * vão ao Claude, em UMA única chamada em lote.
 *
 * @param array $marcadores saída de classificarExame()
 * @return array{explicacoes: array<string,string>, tokens_in:int, tokens_out:int, cache_hits:int}
 */
function explicarMarcadores(array $marcadores, ?string $sexo, ?int $idade, PDO $db): array {
    $faixa       = faixaEtaria($idade);
    $explicacoes = [];
    $faltando    = [];
    $cacheHits   = 0;

    // Explica apenas os alterados (normal não precisa de texto detalhado).
    $alterados = array_filter($marcadores, fn($m) => $m['status'] !== 'normal');

    // 1) tenta o cache
    $stmtGet = $db->prepare('SELECT explicacao FROM cache_explicacoes WHERE chave = :c LIMIT 1');
    $stmtHit = $db->prepare('UPDATE cache_explicacoes SET usos = usos + 1 WHERE chave = :c');

    foreach ($alterados as $m) {
        $chave = chaveCache($m['nome'], $m['status'], $sexo, $faixa);
        $stmtGet->execute([':c' => $chave]);
        $hit = $stmtGet->fetchColumn();
        $stmtGet->closeCursor();
        if ($hit !== false) {
            $explicacoes[$m['nome']] = $hit;
            $stmtHit->execute([':c' => $chave]);
            $cacheHits++;
        } else {
            $faltando[] = $m;
        }
    }

    $tokensIn = 0; $tokensOut = 0;

    // 2) os que faltam vão ao Claude em lote, pedindo JSON por marcador
    if ($faltando) {
        $system = [[
            'type' => 'text',
            'text' => 'Você é um médico que explica resultados de exames laboratoriais '
                . 'para leigos, em português do Brasil. Para cada marcador, escreva 2 a 3 '
                . 'frases claras: o que ele indica e o que o valor alterado pode significar, '
                . 'sem alarmar e sem dar diagnóstico. Sempre lembre que apenas um médico pode '
                . 'avaliar o caso. Use "a pessoa", nunca dados pessoais.',
            // Atenção: cache_control só rende quando o prefixo passa do mínimo do
            // modelo (~4096 tokens no Opus). Aqui o system é curto, então NÃO
            // cacheia — o ganho real vem do cache de explicações no MySQL. Mantido
            // para quando o prefixo crescer (Fase 2, system do laudo completo).
            'cache_control' => ['type' => 'ephemeral'],
        ]];

        $itens = [];
        foreach ($faltando as $idx => $m) {
            $id  = 'm' . $idx;
            $ref = '';
            if ($m['ref_min'] !== null && $m['ref_max'] !== null) $ref = "{$m['ref_min']}–{$m['ref_max']}";
            elseif ($m['ref_max'] !== null) $ref = "até {$m['ref_max']}";
            elseif ($m['ref_min'] !== null) $ref = "acima de {$m['ref_min']}";
            $itens[] = "[$id] {$m['nome']}: {$m['valor']} {$m['unidade']} (referência: $ref) — está {$m['status']}";
        }
        $lista  = implode("\n", $itens);
        $prompt = "Explique os marcadores abaixo. Responda APENAS um objeto JSON onde a "
            . "chave é o identificador entre colchetes (m0, m1, ...) e o valor é a "
            . "explicação em texto puro, sem markdown.\n\n$lista";

        $r = chamarClaude($prompt, $system, MODELO_EXPLICACAO, 2500);
        $tokensIn  = $r['tokens_in'];
        $tokensOut = $r['tokens_out'];

        if ($r['ok'] && $r['texto'] !== '') {
            $json = extrairJSON($r['texto']);
            $stmtSet = $db->prepare(
                'INSERT INTO cache_explicacoes (chave, marcador, status, sexo, faixa_etaria, explicacao)
                 VALUES (:chave, :marc, :status, :sexo, :faixa, :exp)
                 ON DUPLICATE KEY UPDATE explicacao = VALUES(explicacao)'
            );
            foreach ($faltando as $idx => $m) {
                $texto = $json['m' . $idx] ?? $json[$m['nome']] ?? null;
                if ($texto === null) {
                    continue;
                }
                $explicacoes[$m['nome']] = $texto;
                $stmtSet->execute([
                    ':chave'  => chaveCache($m['nome'], $m['status'], $sexo, $faixa),
                    ':marc'   => $m['nome'],
                    ':status' => $m['status'],
                    ':sexo'   => $sexo ?? 'ambos',
                    ':faixa'  => $faixa,
                    ':exp'    => $texto,
                ]);
            }
        }
    }

    return [
        'explicacoes' => $explicacoes,
        'tokens_in'   => $tokensIn,
        'tokens_out'  => $tokensOut,
        'cache_hits'  => $cacheHits,
    ];
}

/** Extrai o primeiro objeto JSON de um texto (tolerante a cercas ```). */
function extrairJSON(string $texto): array {
    $texto = trim($texto);
    $direct = json_decode($texto, true);
    if (is_array($direct)) return $direct;
    if (preg_match('/```(?:json)?\s*(\{.*?\})\s*```/s', $texto, $m)) {
        $dados = json_decode($m[1], true);
        if (is_array($dados)) return $dados;
    }
    if (preg_match('/\{.*\}/s', $texto, $m)) {
        $dados = json_decode($m[0], true);
        if (is_array($dados)) return $dados;
    }
    return [];
}
