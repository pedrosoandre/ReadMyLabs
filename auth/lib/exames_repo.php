<?php
// ReadMyLabs — repositório de exames (histórico do usuário).
// Porta única para a tabela `exames`: todo SELECT/INSERT/UPDATE/DELETE em
// linhas vinculadas a usuário passa por aqui. Regra de arquitetura:
//   "Nenhum SQL a exames.usuario_id fora deste arquivo."
//
// usuario_id NUNCA vem de input do request — vem de sessaoAtual()['id'].
// Encriptação (AES-256-GCM) acontece antes do INSERT; decriptação só no SELECT
// de uma linha que pertence ao usuário (check de ownership SEMPRE no WHERE).

declare(strict_types=1);

require_once __DIR__ . '/../../db.php';
require_once __DIR__ . '/../../lib/crypto.php';
require_once __DIR__ . '/../../lib/rede.php';
require_once __DIR__ . '/sessao.php';

/**
 * Persiste uma análise no histórico do usuário. Encripta `marcadores` e `resultado`.
 * - $titulo: rótulo curto para a listagem (ex.: nome do arquivo, ou "Sintomas — 22/06").
 * - $resumoPublico: resumo curto NÃO sensível para o card da lista (ex.: "3 de 8 alterados").
 *                  Fica em claro no banco (é o que aparece na listagem antes de abrir).
 * - $marcadoresJson / $resultadoJson: payload sensível, serão encriptados.
 * - $telemetria: tokens_in/out/cache_hits (opcional, em claro como hoje).
 *
 * Retorna o id inserido. Lança RuntimeException em falha crítica (crypto ausente).
 */
function exameSalvar(
    int $usuarioId,
    string $tipo,
    string $titulo,
    string $resumoPublico,
    string $marcadoresJson,
    string $resultadoJson,
    array $telemetria = []
): int {
    if ($usuarioId <= 0) {
        throw new InvalidArgumentException('exameSalvar: usuario_id inválido.');
    }
    if (!in_array($tipo, ['exame', 'sintomas', 'explicar', 'imagem'], true)) {
        throw new InvalidArgumentException('exameSalvar: tipo inválido.');
    }

    // Encripta payload sensível. Mesmo IV/tag para ambos os blobs simplifica
    // (1 par de IV/tag por linha) — mas isso significa encriptar UM blob só.
    // Concatenamos com separador improvável e separamos no decrypt.
    // Alternativa seria 2 IVs; este caminho é mais simples e o GCM autentica
    // a integridade do conjunto inteiro (atacante não consegue trocar uma parte).
    $sep = "\x1f\x1eRML_SEP\x1e\x1f";
    $blob = $marcadoresJson . $sep . $resultadoJson;
    $enc = criptoEncriptar($blob);

    $ip = hash('sha256', ipCliente());
    $stmt = db()->prepare(
        'INSERT INTO exames
            (usuario_id, ip_hash, tipo, arquivo_nome, status,
             titulo, resumo_publico,
             marcadores_enc, resultado_enc, enc_iv, enc_tag,
             tokens_in, tokens_out, cache_hits)
         VALUES
            (:uid, :ip, :tipo, :arq, :st,
             :tit, :rp,
             :menc, :renc, :iv, :tag,
             :ti, :to, :ch)'
    );
    $stmt->execute([
        ':uid'  => $usuarioId,
        ':ip'   => $ip,
        ':tipo' => $tipo,
        ':arq'  => mb_substr($titulo, 0, 255),
        ':st'   => 'concluido',
        ':tit'  => mb_substr($titulo, 0, 180),
        ':rp'   => mb_substr($resumoPublico, 0, 255),
        ':menc' => $enc['ciphertext'],
        ':renc' => null,                 // tudo no marcadores_enc (concat); reservado p/ futuro
        ':iv'   => $enc['iv'],
        ':tag'  => $enc['tag'],
        ':ti'   => $telemetria['tokens_in']  ?? null,
        ':to'   => $telemetria['tokens_out'] ?? null,
        ':ch'   => $telemetria['cache_hits'] ?? 0,
    ]);
    return (int) db()->lastInsertId();
}

/**
 * Lista o histórico do usuário (só metadados — sem decriptar nada).
 * Mais recente primeiro. Suporta paginação simples.
 */
function exameListar(int $usuarioId, int $limite = 50, int $offset = 0): array {
    if ($usuarioId <= 0) return [];
    $limite = max(1, min(200, $limite));
    $offset = max(0, $offset);

    $stmt = db()->prepare(
        'SELECT id, tipo, titulo, resumo_publico, criado_em
         FROM exames
         WHERE usuario_id = :uid AND marcadores_enc IS NOT NULL
         ORDER BY criado_em DESC, id DESC
         LIMIT ' . (int) $limite . ' OFFSET ' . (int) $offset
    );
    $stmt->execute([':uid' => $usuarioId]);
    return $stmt->fetchAll() ?: [];
}

/**
 * Abre uma análise específica do usuário. Retorna o payload decriptado em arrays PHP
 * ('marcadores' e 'resultado') ou null se não pertencer / integridade quebrada.
 * O WHERE força o ownership — atacante com id de outro user não recebe nada.
 */
function exameAbrir(int $usuarioId, int $exameId): ?array {
    if ($usuarioId <= 0 || $exameId <= 0) return null;

    $stmt = db()->prepare(
        'SELECT id, tipo, titulo, resumo_publico, criado_em,
                marcadores_enc, enc_iv, enc_tag
         FROM exames
         WHERE id = :id AND usuario_id = :uid
         LIMIT 1'
    );
    $stmt->execute([':id' => $exameId, ':uid' => $usuarioId]);
    $row = $stmt->fetch();
    if (!$row || $row['marcadores_enc'] === null) return null;

    $plain = criptoDecriptar($row['marcadores_enc'], $row['enc_iv'], $row['enc_tag']);
    if ($plain === null) return null;  // tag falhou: chave trocada ou linha corrompida

    $sep = "\x1f\x1eRML_SEP\x1e\x1f";
    $parts = explode($sep, $plain, 2);
    $marcadoresJson = $parts[0] ?? '';
    $resultadoJson  = $parts[1] ?? '';

    return [
        'id'             => (int) $row['id'],
        'tipo'           => $row['tipo'],
        'titulo'         => $row['titulo'],
        'resumo_publico' => $row['resumo_publico'],
        'criado_em'      => $row['criado_em'],
        'marcadores'     => $marcadoresJson !== '' ? json_decode($marcadoresJson, true) : null,
        'resultado'      => $resultadoJson  !== '' ? json_decode($resultadoJson,  true) : null,
    ];
}

/**
 * Apaga uma linha do histórico do usuário. Retorna true se apagou algo.
 * Ownership forçado no WHERE — outro usuário nunca apaga linha alheia.
 */
function exameApagar(int $usuarioId, int $exameId): bool {
    if ($usuarioId <= 0 || $exameId <= 0) return false;
    $stmt = db()->prepare('DELETE FROM exames WHERE id = :id AND usuario_id = :uid');
    $stmt->execute([':id' => $exameId, ':uid' => $usuarioId]);
    return $stmt->rowCount() > 0;
}

/**
 * Apaga TODO o histórico do usuário (botão "apagar tudo" e exclusão de conta).
 * Retorna a quantidade de linhas apagadas.
 */
function exameApagarTudo(int $usuarioId): int {
    if ($usuarioId <= 0) return 0;
    $stmt = db()->prepare('DELETE FROM exames WHERE usuario_id = :uid');
    $stmt->execute([':uid' => $usuarioId]);
    return $stmt->rowCount();
}

/** Quantidade de análises no histórico do usuário (para o painel da conta). */
function exameContar(int $usuarioId): int {
    if ($usuarioId <= 0) return 0;
    $stmt = db()->prepare(
        'SELECT COUNT(*) FROM exames WHERE usuario_id = :uid AND marcadores_enc IS NOT NULL'
    );
    $stmt->execute([':uid' => $usuarioId]);
    return (int) $stmt->fetchColumn();
}

/**
 * Série temporal de cada marcador ao longo dos exames do usuário (Onda 2 — Evolução).
 * Descriptografa APENAS o histórico do próprio usuário (ownership forçado no WHERE) e
 * extrai, para cada marcador com valor numérico, uma série {data, valor, status}.
 * O cálculo da tendência é local (zero token do Claude) — só a redação do resumo,
 * quando existir, custaria IA. Retorna apenas marcadores com 2+ pontos (o que faz
 * sentido para uma linha do tempo), ordenados por quantidade de pontos (desc).
 *
 * @return array<int,array{nome:string,unidade:string,categoria:string,
 *   ref_min:?float,ref_max:?float,pontos:array<int,array{data:string,valor:float,status:string}>}>
 */
function exameSerieMarcadores(int $usuarioId, int $maxExames = 80): array {
    if ($usuarioId <= 0) return [];
    if (!function_exists('criptoDecriptar')) return []; // crypto não carregada — falha-para-desligado

    $maxExames = max(1, min(200, $maxExames));
    $stmt = db()->prepare(
        'SELECT criado_em, marcadores_enc, enc_iv, enc_tag
         FROM exames
         WHERE usuario_id = :uid AND tipo = :tp AND marcadores_enc IS NOT NULL
         ORDER BY criado_em ASC, id ASC
         LIMIT ' . (int) $maxExames
    );
    $stmt->execute([':uid' => $usuarioId, ':tp' => 'exame']);

    $sep    = "\x1f\x1eRML_SEP\x1e\x1f";
    $series = [];
    foreach ($stmt as $row) {
        $plain = criptoDecriptar($row['marcadores_enc'], $row['enc_iv'], $row['enc_tag']);
        if ($plain === null) continue; // integridade quebrada / chave trocada — pula
        $parts = explode($sep, $plain, 2);
        $marc  = json_decode($parts[0] ?? '', true);
        if (!is_array($marc)) continue;
        $data = (string) $row['criado_em'];
        foreach ($marc as $m) {
            if (!is_array($m) || !isset($m['nome'])) continue;
            $valor = $m['valor'] ?? null;
            if (!is_numeric($valor)) continue;
            $nome = (string) $m['nome'];
            if (!isset($series[$nome])) {
                $series[$nome] = [
                    'nome'      => $nome,
                    'unidade'   => (string) ($m['unidade'] ?? ''),
                    'categoria' => (string) ($m['categoria'] ?? ''),
                    'ref_min'   => isset($m['ref_min']) && is_numeric($m['ref_min']) ? (float) $m['ref_min'] : null,
                    'ref_max'   => isset($m['ref_max']) && is_numeric($m['ref_max']) ? (float) $m['ref_max'] : null,
                    'pontos'    => [],
                ];
            }
            // mantém a referência/unidade mais recente (última leitura vence)
            if (isset($m['ref_min']) && is_numeric($m['ref_min'])) $series[$nome]['ref_min'] = (float) $m['ref_min'];
            if (isset($m['ref_max']) && is_numeric($m['ref_max'])) $series[$nome]['ref_max'] = (float) $m['ref_max'];
            if (!empty($m['unidade'])) $series[$nome]['unidade'] = (string) $m['unidade'];
            $series[$nome]['pontos'][] = [
                'data'   => $data,
                'valor'  => (float) $valor,
                'status' => (string) ($m['status'] ?? 'normal'),
            ];
        }
    }

    $out = [];
    foreach ($series as $s) {
        if (count($s['pontos']) >= 2) $out[] = $s; // 2+ pontos = tem tendência
    }
    usort($out, fn($a, $b) => count($b['pontos']) - count($a['pontos']));
    return $out;
}
