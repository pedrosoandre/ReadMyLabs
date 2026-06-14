<?php
// Seed CURADO de exames comuns no Brasil → kb_marcadores.
// ALTERNATIVA ao 01_loinc_import.php: roda SEM download e SEM Voyage.
// Ganho imediato: enriquece os sinônimos que a classificação local
// reconhece (ex.: "ALT"/"ASAT" passam a casar com TGP/TGO). As descrições
// são escritas por nós (linguagem leiga) — LGPD/direito autoral OK.
//
// Idempotente: ao rodar de novo, MESCLA sinônimos e só preenche campos vazios
// (não sobrescreve dado bom já existente).
//
// Rodar: php rag/ingest/01_seed_curado.php

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only\n"); }

require_once __DIR__ . '/../../loads_env.php';
require_once __DIR__ . '/../../db.php';
loadEnv(__DIR__ . '/../../.env');

// [nome_canonico, sinônimos (| separado), categoria, unidade, descrição leiga]
$CURADOS = [
    // ── Hemograma ───────────────────────────────────────────────
    ['Hemoglobina','Hb|Hemoglobina total|HGB','Hemograma','g/dL','Proteína das hemácias que transporta oxigênio pelo corpo.'],
    ['Hematócrito','Ht|HCT|Volume globular','Hemograma','%','Proporção do sangue ocupada pelas hemácias.'],
    ['Hemácias','Eritrócitos|Glóbulos vermelhos|RBC|Contagem de hemácias','Hemograma','milhões/mm³','Células que levam oxigênio aos tecidos.'],
    ['Leucócitos','Glóbulos brancos|Leucograma|WBC|Contagem de leucócitos','Hemograma','/mm³','Células de defesa do organismo.'],
    ['Plaquetas','Trombócitos|PLT|Contagem de plaquetas','Hemograma','/mm³','Fragmentos que ajudam o sangue a coagular.'],
    ['VCM','Volume corpuscular médio|MCV','Hemograma','fL','Tamanho médio das hemácias.'],
    ['HCM','Hemoglobina corpuscular média|MCH','Hemograma','pg','Quantidade média de hemoglobina por hemácia.'],
    ['CHCM','Concentração de hemoglobina corpuscular média|MCHC','Hemograma','g/dL','Concentração de hemoglobina dentro das hemácias.'],
    ['RDW','Amplitude de distribuição dos eritrócitos|Anisocitose','Hemograma','%','Variação de tamanho entre as hemácias.'],
    ['Neutrófilos','Neutrófilos segmentados|Neutro|Segmentados','Hemograma','%','Glóbulos brancos que combatem infecções bacterianas.'],
    ['Linfócitos','Linfo','Hemograma','%','Glóbulos brancos ligados à defesa contra vírus.'],
    ['Monócitos','Mono','Hemograma','%','Glóbulos brancos que ajudam a limpar infecções.'],
    ['Eosinófilos','Eosino','Hemograma','%','Glóbulos brancos ligados a alergias e parasitas.'],
    ['Basófilos','Baso','Hemograma','%','Glóbulos brancos ligados a reações alérgicas.'],
    ['VPM','Volume plaquetário médio|MPV','Hemograma','fL','Tamanho médio das plaquetas.'],

    // ── Glicemia / metabólico ───────────────────────────────────
    ['Glicose','Glicemia|Glicemia de jejum|Glicose em jejum','Glicemia','mg/dL','Açúcar no sangue; avalia o metabolismo e o risco de diabetes.'],
    ['Hemoglobina glicada','HbA1c|Hemoglobina glicosilada|A1c|Glicada','Glicemia','%','Média do açúcar no sangue dos últimos 2 a 3 meses.'],
    ['Insulina','Insulina de jejum','Glicemia','µUI/mL','Hormônio que controla a entrada de açúcar nas células.'],
    ['Curva glicêmica','Teste de tolerância à glicose|TOTG|GTT','Glicemia','mg/dL','Mede o açúcar antes e depois de tomar glicose.'],
    ['Peptídeo C','Peptideo C','Glicemia','ng/mL','Indica quanta insulina o próprio corpo produz.'],

    // ── Lipídico ────────────────────────────────────────────────
    ['Colesterol total','CT|Colesterol','Lipídico','mg/dL','Soma das gorduras do tipo colesterol no sangue.'],
    ['Colesterol HDL','HDL|HDL-c|Colesterol bom','Lipídico','mg/dL','Colesterol "bom", que ajuda a limpar as artérias.'],
    ['Colesterol LDL','LDL|LDL-c|Colesterol ruim','Lipídico','mg/dL','Colesterol "ruim", que pode se acumular nas artérias.'],
    ['Colesterol VLDL','VLDL|VLDL-c','Lipídico','mg/dL','Fração do colesterol ligada aos triglicerídeos.'],
    ['Triglicerídeos','Triglicérides|TG|Triglicerideos','Lipídico','mg/dL','Tipo de gordura ligada à dieta e à reserva de energia.'],

    // ── Tireoide ────────────────────────────────────────────────
    ['TSH','Hormônio tireoestimulante|Tireotrofina|TSH ultrassensível','Tireoide','µUI/mL','Hormônio que comanda a tireoide; principal triagem da glândula.'],
    ['T4 livre','Tiroxina livre|FT4|T4L','Tireoide','ng/dL','Forma ativa e livre do principal hormônio da tireoide.'],
    ['T4','Tiroxina|T4 total','Tireoide','µg/dL','Principal hormônio produzido pela tireoide.'],
    ['T3','Triiodotironina|T3 total','Tireoide','ng/dL','Hormônio da tireoide ligado ao metabolismo.'],
    ['T3 livre','FT3|T3L','Tireoide','pg/mL','Forma livre e ativa do T3.'],
    ['Anti-TPO','Anticorpo antiperoxidase|Anti-tireoperoxidase|TPO','Tireoide','UI/mL','Anticorpo ligado a doenças autoimunes da tireoide.'],

    // ── Função renal ────────────────────────────────────────────
    ['Creatinina','Creatinina sérica','Função renal','mg/dL','Resíduo muscular filtrado pelos rins; avalia a função renal.'],
    ['Ureia','Uréia','Função renal','mg/dL','Resíduo das proteínas, filtrado pelos rins.'],
    ['Ácido úrico','Acido urico|Urato','Função renal','mg/dL','Resíduo que, elevado, se relaciona à gota.'],
    ['Taxa de filtração glomerular','TFG|eTFG|Clearance de creatinina|Ritmo de filtração glomerular','Função renal','mL/min','Estima a velocidade com que os rins filtram o sangue.'],
    ['Microalbuminúria','Albumina urinária|Relação albumina/creatinina|RAC','Função renal','mg/g','Albumina na urina; sinal precoce de lesão renal.'],

    // ── Função hepática ─────────────────────────────────────────
    ['TGO','AST|Aspartato aminotransferase|Transaminase glutâmico-oxalacética|ASAT','Função hepática','U/L','Enzima do fígado e dos músculos; avalia o fígado.'],
    ['TGP','ALT|Alanina aminotransferase|Transaminase glutâmico-pirúvica|ALAT','Função hepática','U/L','Enzima mais específica do fígado.'],
    ['Gama GT','GGT|Gama-glutamil transferase|Gama-glutamiltransferase|Gama-GT','Função hepática','U/L','Enzima ligada às vias biliares e ao álcool.'],
    ['Fosfatase alcalina','FA|FAL','Função hepática','U/L','Enzima presente no fígado e nos ossos.'],
    ['Bilirrubina total','BT|Bilirrubinas','Função hepática','mg/dL','Pigmento da quebra das hemácias, processado pelo fígado.'],
    ['Bilirrubina direta','BD|Bilirrubina conjugada','Função hepática','mg/dL','Fração da bilirrubina já processada pelo fígado.'],
    ['Bilirrubina indireta','BI|Bilirrubina não conjugada','Função hepática','mg/dL','Fração da bilirrubina antes do processamento hepático.'],
    ['Albumina','Albumina sérica','Função hepática','g/dL','Principal proteína do sangue, produzida pelo fígado.'],
    ['Proteínas totais','Proteína total','Função hepática','g/dL','Soma das proteínas presentes no sangue.'],

    // ── Vitaminas e minerais ────────────────────────────────────
    ['Vitamina D','25-hidroxivitamina D|25(OH)D|Vitamina D 25 hidroxi|Calcidiol','Vitaminas','ng/mL','Vitamina ligada à saúde dos ossos e à imunidade.'],
    ['Vitamina B12','B12|Cobalamina','Vitaminas','pg/mL','Vitamina essencial ao sangue e aos nervos.'],
    ['Ácido fólico','Folato|Vitamina B9','Vitaminas','ng/mL','Vitamina importante para a formação das células.'],
    ['Ferro','Ferro sérico|Fe','Minerais','µg/dL','Mineral essencial ao transporte de oxigênio.'],
    ['Ferritina','Ferritina sérica','Minerais','ng/mL','Reserva de ferro do corpo.'],
    ['Transferrina','','Minerais','mg/dL','Proteína que transporta o ferro no sangue.'],
    ['Saturação de transferrina','IST|Índice de saturação de transferrina','Minerais','%','Quanto do transporte de ferro está ocupado.'],
    ['Cálcio','Cálcio total|Ca','Minerais','mg/dL','Mineral dos ossos, músculos e nervos.'],
    ['Cálcio iônico','Cálcio ionizado','Minerais','mg/dL','Fração ativa do cálcio no sangue.'],
    ['Magnésio','Mg','Minerais','mg/dL','Mineral ligado a músculos e nervos.'],
    ['Fósforo','Fosfato|P','Minerais','mg/dL','Mineral ligado aos ossos e à energia.'],
    ['Zinco','Zn','Minerais','µg/dL','Mineral ligado à imunidade e à cicatrização.'],

    // ── Eletrólitos ─────────────────────────────────────────────
    ['Sódio','Na|Natremia','Eletrólitos','mEq/L','Sal que regula a água e a pressão do corpo.'],
    ['Potássio','K|Calemia','Eletrólitos','mEq/L','Mineral essencial ao coração e aos músculos.'],
    ['Cloro','Cloreto|Cl','Eletrólitos','mEq/L','Sal que ajuda no equilíbrio dos líquidos.'],

    // ── Inflamação / imunológico ────────────────────────────────
    ['PCR','Proteína C reativa|Proteína C-reativa|CRP|PCR ultrassensível','Inflamação','mg/L','Marcador de inflamação no corpo.'],
    ['VHS','Velocidade de hemossedimentação|Velocidade de sedimentação|ESR','Inflamação','mm/h','Marcador indireto e mais lento de inflamação.'],
    ['Fator reumatoide','FR|Látex','Inflamação','UI/mL','Anticorpo ligado a doenças reumáticas.'],
    ['FAN','Fator antinuclear|Anticorpo antinuclear|ANA','Inflamação','título','Anticorpo ligado a doenças autoimunes.'],

    // ── Hormônios ───────────────────────────────────────────────
    ['Testosterona total','Testo|Testosterona','Hormônios','ng/dL','Principal hormônio sexual masculino.'],
    ['Testosterona livre','','Hormônios','pg/mL','Fração ativa da testosterona.'],
    ['Estradiol','E2','Hormônios','pg/mL','Principal hormônio sexual feminino.'],
    ['Progesterona','','Hormônios','ng/mL','Hormônio ligado ao ciclo menstrual e à gravidez.'],
    ['Prolactina','PRL','Hormônios','ng/mL','Hormônio ligado à mama e à fertilidade.'],
    ['FSH','Hormônio folículo-estimulante','Hormônios','mUI/mL','Hormônio que regula óvulos e espermatozoides.'],
    ['LH','Hormônio luteinizante','Hormônios','mUI/mL','Hormônio ligado à ovulação e à testosterona.'],
    ['Cortisol','Cortisol matinal','Hormônios','µg/dL','Hormônio do estresse e do metabolismo.'],
    ['PTH','Paratormônio|Hormônio da paratireoide','Hormônios','pg/mL','Hormônio que regula o cálcio no corpo.'],
    ['Beta HCG','HCG|Gonadotrofina coriônica|Beta-HCG','Hormônios','mUI/mL','Hormônio da gravidez.'],
    ['DHEA-S','Sulfato de DHEA|DHEA sulfato','Hormônios','µg/dL','Hormônio precursor dos esteroides sexuais.'],

    // ── Cardíaco ────────────────────────────────────────────────
    ['Troponina','Troponina I|Troponina T|TnI|TnT','Cardíaco','ng/mL','Marca lesão do músculo do coração.'],
    ['CK','Creatinoquinase|CPK|Creatina quinase','Cardíaco','U/L','Enzima dos músculos, incluindo o coração.'],
    ['CK-MB','CKMB','Cardíaco','U/L','Fração da CK mais ligada ao coração.'],
    ['BNP','Peptídeo natriurético|NT-proBNP|Pró-BNP','Cardíaco','pg/mL','Marcador de sobrecarga do coração.'],

    // ── Marcadores / próstata ───────────────────────────────────
    ['PSA','Antígeno prostático específico|PSA total','Marcadores','ng/mL','Marcador ligado à próstata.'],
    ['PSA livre','','Marcadores','ng/mL','Fração livre do PSA.'],

    // ── Coagulação ──────────────────────────────────────────────
    ['TAP','Tempo de protrombina|TP|INR|RNI','Coagulação','s','Avalia a coagulação e o uso de anticoagulantes.'],
    ['TTPA','Tempo de tromboplastina parcial ativada|PTT|KTTP','Coagulação','s','Avalia outra via da coagulação do sangue.'],
    ['Fibrinogênio','','Coagulação','mg/dL','Proteína essencial à formação do coágulo.'],
    ['D-dímero','D dimero|Dímero D','Coagulação','ng/mL','Marcador relacionado a coágulos.'],

    // ── Urina ───────────────────────────────────────────────────
    ['EAS','Urina tipo 1|Urina I|Sumário de urina|Urinálise|Elementos anormais e sedimento','Urina','-','Exame geral da urina.'],
    ['Urocultura','Cultura de urina','Urina','-','Pesquisa de bactérias na urina.'],
];

$db = db();
$db->exec("INSERT IGNORE INTO kb_fontes (nome, licenca, observacao)
           VALUES ('Curadoria ReadMyLabs', 'interno', 'Lista curada de exames comuns no Brasil')");
$fonteId = (int) $db->query("SELECT id FROM kb_fontes WHERE nome='Curadoria ReadMyLabs'")->fetchColumn();

$sel = $db->prepare('SELECT id, sinonimos, categoria, unidade_padrao, descricao_leiga
                       FROM kb_marcadores WHERE nome_canonico = :n LIMIT 1');
$ins = $db->prepare('INSERT INTO kb_marcadores (nome_canonico, sinonimos, categoria, unidade_padrao, descricao_leiga, fonte_id)
                     VALUES (:n, :s, :c, :u, :d, :f)');
$upd = $db->prepare('UPDATE kb_marcadores SET sinonimos=:s, categoria=:c, unidade_padrao=:u, descricao_leiga=:d WHERE id=:id');

$novos = 0; $atualizados = 0;
foreach ($CURADOS as [$nome, $sin, $cat, $uni, $desc]) {
    $sel->execute([':n' => $nome]);
    $row = $sel->fetch();
    if ($row) {
        // mescla sinônimos; só preenche campos que estão vazios
        $upd->execute([
            ':s'  => mesclarSinonimos($row['sinonimos'], $sin),
            ':c'  => $row['categoria']      ?: ($cat  ?: null),
            ':u'  => $row['unidade_padrao'] ?: ($uni  ?: null),
            ':d'  => $row['descricao_leiga']?: ($desc ?: null),
            ':id' => $row['id'],
        ]);
        $atualizados++;
    } else {
        $ins->execute([
            ':n' => $nome,
            ':s' => ($sin !== '' ? $sin : null),
            ':c' => ($cat !== '' ? $cat : null),
            ':u' => ($uni !== '' ? $uni : null),
            ':d' => ($desc !== '' ? $desc : null),
            ':f' => $fonteId,
        ]);
        $novos++;
    }
}

echo "Seed curado aplicado: $novos novos, $atualizados atualizados (" . count($CURADOS) . " exames).\n";
echo "Os sinônimos já melhoram a classificação. Para o \"o que é este exame?\", rode 03_embeddings.php (precisa Voyage).\n";

/** Une duas listas de sinônimos (| separadas), sem duplicatas (case-insensitive). */
function mesclarSinonimos(?string $existente, ?string $novo): ?string {
    $todos = array_merge(
        array_map('trim', explode('|', (string) $existente)),
        array_map('trim', explode('|', (string) $novo))
    );
    $vistos = []; $out = [];
    foreach ($todos as $s) {
        if ($s === '') continue;
        $k = mb_strtolower($s);
        if (!isset($vistos[$k])) { $vistos[$k] = true; $out[] = $s; }
    }
    return $out ? implode('|', $out) : null;
}
