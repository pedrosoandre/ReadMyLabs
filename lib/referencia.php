<?php
// ReadMyLabs — extração e classificação LOCAL de marcadores.
// Esta é a camada que economiza tokens: lê os valores do texto do
// exame e os compara com a tabela `marcadores_referencia` SEM chamar
// o Claude. Só o que precisa de explicação humana segue para a IA.

/**
 * Carrega todos os marcadores de referência do banco, agrupados por nome.
 * @return array<string, array<int, array>> nome => lista de faixas
 */
function carregarMarcadores(PDO $db): array {
    $stmt = $db->query(
        'SELECT nome, sinonimos, categoria, unidade, sexo, idade_min, idade_max,
                ref_min, ref_max, descricao
         FROM marcadores_referencia'
    );
    $grupos = [];
    foreach ($stmt as $row) {
        $grupos[$row['nome']][] = $row;
    }
    return $grupos;
}

/**
 * Normaliza um número escrito no padrão brasileiro/internacional.
 * "13,8" -> 13.8 | "1.200,50" -> 1200.5 | "11.200" -> 11200 | "5.9" -> 5.9
 */
function normalizarNumero(string $raw): ?float {
    $raw = trim($raw);
    if ($raw === '') {
        return null;
    }
    $temVirgula = str_contains($raw, ',');
    $temPonto   = str_contains($raw, '.');

    if ($temVirgula && $temPonto) {
        // ponto = milhar, vírgula = decimal
        $raw = str_replace('.', '', $raw);
        $raw = str_replace(',', '.', $raw);
    } elseif ($temVirgula) {
        // vírgula = decimal
        $raw = str_replace(',', '.', $raw);
    } elseif ($temPonto) {
        // ponto ambíguo: 3 dígitos após = milhar; 1-2 = decimal
        if (preg_match('/\.\d{3}$/', $raw)) {
            $raw = str_replace('.', '', $raw);
        }
    }
    return is_numeric($raw) ? (float) $raw : null;
}

/**
 * Dada a lista de faixas de um marcador, escolhe a faixa efetiva
 * conforme sexo/idade. Se o sexo for desconhecido e houver faixas
 * por sexo, mescla para a faixa mais ampla (evita falso alarme).
 */
function selecionarFaixa(array $faixas, ?string $sexo, ?int $idade): array {
    $candidatas = [];
    foreach ($faixas as $f) {
        // filtro de idade
        if ($idade !== null) {
            if ($f['idade_min'] !== null && $idade < (int) $f['idade_min']) continue;
            if ($f['idade_max'] !== null && $idade > (int) $f['idade_max']) continue;
        }
        // filtro de sexo
        if ($sexo !== null && $f['sexo'] !== 'ambos' && $f['sexo'] !== $sexo) continue;
        $candidatas[] = $f;
    }
    if (!$candidatas) {
        $candidatas = $faixas; // fallback: usa todas
    }
    if (count($candidatas) === 1) {
        return $candidatas[0];
    }
    // mescla para a faixa mais ampla
    $base = $candidatas[0];
    $min  = null; $max = null;
    foreach ($candidatas as $c) {
        if ($c['ref_min'] !== null) $min = ($min === null) ? (float) $c['ref_min'] : min($min, (float) $c['ref_min']);
        if ($c['ref_max'] !== null) $max = ($max === null) ? (float) $c['ref_max'] : max($max, (float) $c['ref_max']);
    }
    $base['ref_min'] = $min;
    $base['ref_max'] = $max;
    $base['sexo']    = 'ambos';
    return $base;
}

/**
 * Classifica um valor contra a faixa de referência.
 * Retorna: 'baixo' | 'normal' | 'alto'. O nível 'critico' é decidido
 * mais à frente (heurística de quão fora está).
 */
function classificarValor(float $valor, array $ref): string {
    $min = $ref['ref_min'] !== null ? (float) $ref['ref_min'] : null;
    $max = $ref['ref_max'] !== null ? (float) $ref['ref_max'] : null;
    if ($min !== null && $valor < $min) return 'baixo';
    if ($max !== null && $valor > $max) return 'alto';
    return 'normal';
}

/**
 * Percorre o texto do exame e extrai os marcadores conhecidos com
 * seu valor e classificação. Cada nome de marcador é capturado no
 * máximo uma vez (primeira ocorrência plausível).
 *
 * @return array<int, array{nome,categoria,valor,unidade,ref_min,ref_max,status,descricao}>
 */
function classificarExame(string $texto, ?string $sexo, ?int $idade, PDO $db): array {
    $grupos = carregarMarcadores($db);

    // Monta lista (termo => nome canônico), termos mais longos primeiro
    // para casar "Colesterol HDL" antes de "Colesterol".
    $termos = [];
    foreach ($grupos as $nome => $faixas) {
        $lista = [$nome];
        $sin = $faixas[0]['sinonimos'] ?? '';
        if ($sin) {
            foreach (explode('|', $sin) as $s) {
                $s = trim($s);
                if ($s !== '') $lista[] = $s;
            }
        }
        foreach ($lista as $termo) {
            $termos[] = ['termo' => $termo, 'nome' => $nome];
        }
    }
    usort($termos, fn($a, $b) => mb_strlen($b['termo']) <=> mb_strlen($a['termo']));

    $resultados = [];
    $jaVistos   = [];

    // Número BR/intl: 13,8 | 11.200 | 250.000 | 1.200,50 | 8500 | 250000.
    // Quantificadores possessivos (++ e *+) impedem backtracking que poderia
    // capturar um prefixo (ex.: "13" de "13.5-17.5") ou truncar inteiros grandes.
    $num = '[0-9]++(?:[.,][0-9]++)*+';

    foreach ($termos as $t) {
        $nome = $t['nome'];
        if (isset($jaVistos[$nome])) {
            continue;
        }
        // Termo com fronteira de palavra (evita "K" dentro de "Kit"),
        // abreviação opcional entre parênteses — "(K)", "(HDL)" — e o valor.
        // O lookahead final descarta o limite inferior de um intervalo
        // de referência ("13,5 - 17,5").
        $padrao = '/(?<![\p{L}\p{N}])'
            . preg_quote($t['termo'], '/')
            . '(?![\p{L}])'
            . '(?:\s*\([^)]{0,12}\))?'
            . '\s*[:=]?\s*(?:result\w*\s*[:=]?\s*)?'
            . '(' . $num . ')'
            . '(?!\s*[-–—]\s*[0-9])'
            . '/iu';

        if (preg_match($padrao, $texto, $m)) {
            $valor = normalizarNumero($m[1]);
            if ($valor === null) {
                continue;
            }
            $ref    = selecionarFaixa($grupos[$nome], $sexo, $idade);
            $status = classificarValor($valor, $ref);

            $resultados[] = [
                'nome'      => $nome,
                'categoria' => $ref['categoria'],
                'valor'     => $valor,
                'unidade'   => $ref['unidade'],
                'ref_min'   => $ref['ref_min'] !== null ? (float) $ref['ref_min'] : null,
                'ref_max'   => $ref['ref_max'] !== null ? (float) $ref['ref_max'] : null,
                'status'    => $status,
                'descricao' => $ref['descricao'],
            ];
            $jaVistos[$nome] = true;
        }
    }

    return $resultados;
}

/**
 * Tenta inferir sexo e idade a partir do texto do exame (opcional).
 * @return array{sexo: ?string, idade: ?int}
 */
function inferirSexoIdade(string $texto): array {
    $sexo = null;
    if (preg_match('/sexo\s*[:=]?\s*(masculino|feminino|m|f)\b/iu', $texto, $m)) {
        $v = mb_strtolower($m[1]);
        $sexo = ($v === 'masculino' || $v === 'm') ? 'M' : 'F';
    }
    $idade = null;
    if (preg_match('/idade\s*[:=]?\s*(\d{1,3})\s*anos?/iu', $texto, $m)) {
        $idade = (int) $m[1];
    }
    return ['sexo' => $sexo, 'idade' => $idade];
}
