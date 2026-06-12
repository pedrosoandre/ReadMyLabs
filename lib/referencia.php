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
 * Identifica padrões clínicos nos marcadores usando regras validadas por diretrizes.
 * Zero token: o PHP avalia as condições; Claude só redige a síntese posterior.
 *
 * @param  array $marcadores saída de classificarExame()
 * @param  PDO   $db
 * @return array lista de linhas da tabela padroes_clinicos que se aplicam
 */
function identificarPadroes(array $marcadores, PDO $db): array {
    // Indexa por nome normalizado para lookup O(1)
    $m = [];
    foreach ($marcadores as $mk) {
        $m[mb_strtolower(trim($mk['nome']))] = $mk;
    }

    $baixo  = fn(string $n): bool  => ($m[mb_strtolower($n)]['status'] ?? '') === 'baixo';
    $alto   = fn(string $n): bool  => ($m[mb_strtolower($n)]['status'] ?? '') === 'alto';
    $val    = fn(string $n): ?float => isset($m[mb_strtolower($n)]) ? (float)$m[mb_strtolower($n)]['valor'] : null;
    $refmax = fn(string $n): float  => (float)($m[mb_strtolower($n)]['ref_max'] ?? 0);

    $codigos = [];

    // ─── LEUCÓCITOS ───────────────────────────────────────────────
    if ($baixo('Leucócitos')) {
        $linfo = $val('Linfócitos');
        $codigos[] = ($linfo !== null && $linfo > 35.0)
            ? 'leucopenia_linfocitose'
            : 'leucopenia';
    } elseif ($alto('Leucócitos')) {
        $leuVal = $val('Leucócitos');
        if ($leuVal !== null && $leuVal > 30000) {
            $codigos[] = 'leucocitose_grave';
        } elseif ($val('Neutrófilos') !== null && $val('Neutrófilos') > 70.0) {
            $codigos[] = 'leucocitose_neutrofilia';
        } else {
            $codigos[] = 'leucocitose';
        }
    }

    if ($alto('Eosinófilos'))                       $codigos[] = 'eosinofilia';
    if (!$baixo('Leucócitos') && $alto('Linfócitos')) $codigos[] = 'linfocitose';

    // ─── SÉRIE VERMELHA ───────────────────────────────────────────
    $temHb = isset($m['hemoglobina']);
    if ($baixo('Hemoglobina')) {
        $vcmVal = $val('VCM');
        if ($vcmVal !== null && $vcmVal < 80.0) {
            $codigos[] = $alto('RDW') ? 'anemia_ferropriva_rdw' : 'anemia_microcitica';
        } elseif ($vcmVal !== null && $vcmVal > 100.0) {
            $codigos[] = 'anemia_macrocitica';
        } else {
            $codigos[] = 'anemia_normocitica';
        }
    } elseif ($alto('Hemoglobina') || $alto('Hematócrito')) {
        $codigos[] = 'policitemia';
    }

    if ($temHb && !$baixo('Hemoglobina') && $baixo('VCM'))         $codigos[] = 'microcitose_sem_anemia';
    if ($temHb && !$baixo('Hemoglobina') && !$baixo('VCM') && $alto('RDW'))
        $codigos[] = 'anisocitose_sem_anemia';

    // ─── PLAQUETAS ────────────────────────────────────────────────
    if ($baixo('Plaquetas')) {
        $pltVal = $val('Plaquetas');
        $codigos[] = ($pltVal !== null && $pltVal < 75000) ? 'trombocitopenia_grave' : 'trombocitopenia_leve';
    } elseif ($alto('Plaquetas')) {
        $codigos[] = 'trombocitose';
    }

    // Pancitopenia sobrepõe leucopenia + anemia + trombocitopenia
    if ($baixo('Leucócitos') && $baixo('Hemoglobina') && $baixo('Plaquetas')) {
        $codigos = array_values(array_filter($codigos, fn($c) => !in_array($c, [
            'leucopenia', 'leucopenia_linfocitose', 'anemia_normocitica',
            'anemia_microcitica', 'anemia_ferropriva_rdw', 'trombocitopenia_leve', 'trombocitopenia_grave',
        ])));
        $codigos[] = 'pancitopenia';
    }

    // ─── GLICEMIA ─────────────────────────────────────────────────
    $gli = $val('Glicose');
    if ($gli !== null) {
        if ($gli >= 126)       $codigos[] = 'diabetes_sugestivo';
        elseif ($gli >= 100)   $codigos[] = 'pre_diabetes';
    }
    $a1c = $val('Hemoglobina glicada');
    if ($a1c !== null) {
        if ($a1c > 6.4)        $codigos[] = 'diabetes_hba1c';
        elseif ($a1c > 5.6)    $codigos[] = 'pre_diabetes_hba1c';
    }

    // ─── LIPÍDICO ─────────────────────────────────────────────────
    $ldlAlto = $alto('Colesterol LDL');
    $tgAlto  = $alto('Triglicerídeos');
    if ($ldlAlto && $tgAlto) {
        $codigos[] = 'dislipidemia_mista';
    } else {
        if ($ldlAlto) $codigos[] = 'dislipidemia_ldl';
        if ($tgAlto)  $codigos[] = 'hipertrigliceridemia';
    }
    if ($baixo('Colesterol HDL')) $codigos[] = 'hdl_baixo';

    // ─── TIREOIDE ─────────────────────────────────────────────────
    if ($alto('TSH')) {
        $codigos[] = $baixo('T4 livre') ? 'hipotireoidismo_primario' : 'hipotireoidismo_subclínico';
    } elseif ($baixo('TSH')) {
        $codigos[] = $alto('T4 livre') ? 'hipertireoidismo' : 'tsh_suprimido';
    }

    // ─── FUNÇÃO RENAL ─────────────────────────────────────────────
    if ($alto('Creatinina')) {
        $codigos[] = $alto('Ureia') ? 'disfuncao_renal' : 'creatinina_elevada';
    }
    if ($alto('Ácido úrico')) $codigos[] = 'hiperuricemia';

    // ─── FUNÇÃO HEPÁTICA ──────────────────────────────────────────
    if ($alto('TGO') || $alto('TGP')) {
        $tgoVal = $val('TGO');
        $tgpVal = $val('TGP');
        $grave  = ($tgoVal !== null && $tgoVal > 3 * max($refmax('TGO'), 1))
               || ($tgpVal !== null && $tgpVal > 3 * max($refmax('TGP'), 1));
        $codigos[] = $grave ? 'hepatite_enzimas_grave' : 'hepatite_enzimas';
    }

    // ─── VITAMINAS ────────────────────────────────────────────────
    if ($baixo('Vitamina D'))    $codigos[] = 'hipovitaminose_d';
    if ($baixo('Vitamina B12'))  $codigos[] = 'deficiencia_b12';
    if ($baixo('Ferro'))         $codigos[] = 'ferropenia_serica';
    if ($baixo('Ferritina'))     $codigos[] = 'ferropenia_ferritina';
    if ($baixo('Ácido fólico'))  $codigos[] = 'deficiencia_folato';

    // ─── ELETRÓLITOS ──────────────────────────────────────────────
    if ($baixo('Sódio')) {
        $naVal = $val('Sódio');
        $codigos[] = ($naVal !== null && $naVal < 125) ? 'hiponatremia' : 'hiponatremia';
    }
    if ($alto('Sódio'))   $codigos[] = 'hipernatremia';
    if ($baixo('Potássio')) {
        $kVal = $val('Potássio');
        $codigos[] = ($kVal !== null && $kVal < 3.0) ? 'hipocalemia_grave' : 'hipocalemia';
    }
    if ($alto('Potássio')) {
        $kVal = $val('Potássio');
        $codigos[] = ($kVal !== null && $kVal > 6.0) ? 'hipercalemia_grave' : 'hipercalemia';
    }

    if (!$codigos) {
        return [];
    }

    // Busca os dados textuais da tabela para os códigos encontrados
    $placeholders = implode(',', array_fill(0, count($codigos), '?'));
    $stmt = $db->prepare(
        "SELECT codigo, titulo, interpretacao, urgencia, acao, fonte
         FROM padroes_clinicos WHERE codigo IN ($placeholders) AND ativo = 1"
    );
    $stmt->execute(array_values($codigos));

    $byCode = [];
    foreach ($stmt->fetchAll() as $row) {
        $byCode[$row['codigo']] = $row;
    }

    // Devolve na ordem de prioridade (mesma ordem em que foram identificados)
    $resultado = [];
    foreach ($codigos as $codigo) {
        if (isset($byCode[$codigo])) {
            $resultado[] = $byCode[$codigo];
        }
    }
    return $resultado;
}

/** Retorna a urgência máxima entre todos os padrões: verde < amarelo < vermelho. */
function urgenciaMax(array $padroes): string {
    $nivel = ['verde' => 0, 'amarelo' => 1, 'vermelho' => 2];
    $max   = 'verde';
    foreach ($padroes as $p) {
        $u = $p['urgencia'] ?? 'verde';
        if (($nivel[$u] ?? 0) > ($nivel[$max] ?? 0)) $max = $u;
    }
    return $max;
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
