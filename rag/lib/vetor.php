<?php
// ReadMyLabs — armazenamento e busca vetorial em MariaDB (sem extensão).
// Os vetores são guardados NORMALIZADOS, então similaridade de cosseno
// = produto escalar (mais rápido). A busca é em PHP; para escalar além de
// ~dezenas de milhares de itens, troque o interior de vetorBuscar() por
// um banco vetorial (Qdrant/pgvector) mantendo a mesma assinatura.

/** Normaliza um vetor para norma 1 (no-op se norma 0). */
function vetorNormalizar(array $v): array {
    $soma = 0.0;
    foreach ($v as $x) $soma += $x * $x;
    if ($soma <= 0.0) return $v;
    $n = sqrt($soma);
    return array_map(fn($x) => $x / $n, $v);
}

/** Produto escalar entre dois vetores de mesmo tamanho. */
function vetorDot(array $a, array $b): float {
    $s = 0.0;
    $len = min(count($a), count($b));
    for ($i = 0; $i < $len; $i++) $s += $a[$i] * $b[$i];
    return $s;
}

/**
 * Guarda (ou atualiza) o embedding de um marcador/chunk. Normaliza antes.
 * @param string $refTipo 'marcador' | 'chunk'
 */
function vetorGuardar(PDO $db, string $refTipo, int $refId, array $vetor, string $modelo): void {
    $norm = vetorNormalizar($vetor);
    $db->prepare(
        'INSERT INTO kb_vetores (ref_tipo, ref_id, modelo, dims, embedding)
         VALUES (:t, :i, :m, :d, :e)
         ON DUPLICATE KEY UPDATE embedding = VALUES(embedding), dims = VALUES(dims)'
    )->execute([
        ':t' => $refTipo,
        ':i' => $refId,
        ':m' => $modelo,
        ':d' => count($norm),
        ':e' => json_encode($norm),
    ]);
}

/**
 * Busca os topK itens mais similares ao vetor da query (já normalizado).
 * Opcionalmente filtra marcadores/chunks por categoria e/ou domínio.
 *
 * @param array  $queryVetorNorm vetor da query, já normalizado
 * @param string $refTipo 'marcador' | 'chunk'
 * @param string|null $categoria filtra pela categoria do marcador (opcional)
 * @param string|null $dominio   'lab' | 'radio' — filtra por domínio (opcional).
 *                               Default null = comportamento legado (sem filtro).
 * @return array<int,array{ref_id:int, score:float}> ordenado por score desc
 */
function vetorBuscar(PDO $db, array $queryVetorNorm, string $refTipo, int $topK = 5, ?string $categoria = null, ?string $dominio = null): array {
    // Junta com a tabela do ref para permitir filtro por categoria e domínio.
    // Domínio passa a usar JOIN obrigatório (em vez de LEFT JOIN) p/ chunks quando
    // filtrado — só vale chunk que existe na sua tabela e tem o domínio pedido.
    if ($refTipo === 'marcador') {
        $sql = 'SELECT v.ref_id, v.embedding
                  FROM kb_vetores v
                  JOIN kb_marcadores k ON k.id = v.ref_id
                 WHERE v.ref_tipo = :t';
        if ($categoria !== null) $sql .= ' AND k.categoria = :cat';
        if ($dominio !== null)   $sql .= ' AND k.dominio = :dom';
    } else {
        $join = $dominio !== null ? 'JOIN' : 'LEFT JOIN';
        $sql  = "SELECT v.ref_id, v.embedding
                   FROM kb_vetores v
                   $join kb_chunks k ON k.id = v.ref_id
                  WHERE v.ref_tipo = :t";
        if ($categoria !== null) $sql .= ' AND k.titulo LIKE :cat';
        if ($dominio !== null)   $sql .= ' AND k.dominio = :dom';
    }

    $params = [':t' => $refTipo];
    if ($categoria !== null) $params[':cat'] = ($refTipo === 'marcador') ? $categoria : "%$categoria%";
    if ($dominio   !== null) $params[':dom'] = $dominio;

    $stmt = $db->prepare($sql);
    $stmt->execute($params);

    $scored = [];
    foreach ($stmt as $row) {
        $vec = json_decode($row['embedding'], true);
        if (!is_array($vec)) continue;
        // vetores guardados já normalizados → cosseno = produto escalar
        $scored[] = ['ref_id' => (int) $row['ref_id'], 'score' => vetorDot($queryVetorNorm, $vec)];
    }

    usort($scored, fn($a, $b) => $b['score'] <=> $a['score']);
    return array_slice($scored, 0, max(1, $topK));
}
