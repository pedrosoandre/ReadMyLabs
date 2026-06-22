<?php
// Semeia kb_marcadores com termos radiológicos curados (domínio 'radio').
// São ~150 termos comuns em laudos de RX/TC/RM/USG brasileiros, com
// descrições LEIGAS escritas por nós — sem afirmar significado clínico,
// sempre direcionando ao médico para interpretação.
//
// Idempotente: ON DUPLICATE atualiza; rodar várias vezes não polui.
// Rodar: php rag/ingest/04_seed_radio.php

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only\n"); }

require_once __DIR__ . '/../../loads_env.php';
require_once __DIR__ . '/../../db.php';
loadEnv(__DIR__ . '/../../.env');

$db = db();

// Fonte de rastreio
$db->exec("INSERT IGNORE INTO kb_fontes (nome, licenca, observacao)
           VALUES ('Seed Radiologia ReadMyLabs', 'interno',
                   'Curadoria interna — termos de laudos radiológicos comuns no Brasil. Descrições leigas escritas por nós, sem afirmação clínica.')");
$fonteId = (int) $db->query("SELECT id FROM kb_fontes WHERE nome='Seed Radiologia ReadMyLabs'")->fetchColumn();

// ─────────────────────────────────────────────────────────────────
// Termos curados — cada um vai como marcador com domínio 'radio'.
// Schema: nome_canonico => [sinonimos (|-separado), categoria, descricao_leiga]
// ─────────────────────────────────────────────────────────────────
$termos = [
    // ── DESCRITORES DE ACHADO (tórax/pulmão) ──────────────────────
    'Consolidação' => ['consolidações|área de consolidação', 'Radio: achado pulmonar',
        'Termo que descreve uma região do pulmão onde o ar normalmente presente foi substituído por outro material (como líquido, células ou tecido). Pode ter muitas causas diferentes — quem interpreta o que isso significa neste exame específico é o médico.'],
    'Opacidade' => ['opacidades|área opaca|opacificação', 'Radio: achado pulmonar',
        'Região no exame que aparece mais clara/branca do que o tecido pulmonar normal (que deveria ser escuro por conter ar). Significa apenas que há algo ali que bloqueou o raio-x — a causa é definida pelo médico.'],
    'Infiltrado' => ['infiltrados|infiltrado pulmonar', 'Radio: achado pulmonar',
        'Padrão difuso ou em manchas onde substâncias se acumularam no tecido pulmonar. É uma descrição visual, não um diagnóstico — diversas condições podem produzir esse padrão.'],
    'Atelectasia' => ['atelectasias|colabamento|colapso pulmonar', 'Radio: achado pulmonar',
        'Termo técnico para quando uma parte do pulmão fica colabada (sem ar), aparecendo retraída no exame. Pode ser pequena e sem importância ou maior — o contexto clínico determina.'],
    'Derrame pleural' => ['derrames pleurais|efusão pleural', 'Radio: achado pulmonar',
        'Acúmulo de líquido no espaço entre o pulmão e a parede do tórax (pleura). O exame mostra a presença e a quantidade aproximada; a causa é determinada pela investigação clínica.'],
    'Pneumotórax' => ['pneumotóraces|ar no espaço pleural', 'Radio: achado pulmonar',
        'Presença de ar no espaço pleural (entre o pulmão e a parede torácica). Aparece como uma área muito escura sem trama pulmonar. É um achado que merece avaliação médica.'],
    'Nódulo pulmonar' => ['nódulos pulmonares|nódulo|nodulação', 'Radio: achado pulmonar',
        'Pequena área arredondada que se destaca no parênquima pulmonar (geralmente menor que 3 cm). É um achado descritivo — pode ter muitas causas, e o significado depende de tamanho, contorno, contexto e histórico do paciente.'],
    'Massa' => ['massas|formação expansiva', 'Radio: achado',
        'Área arredondada maior que um nódulo (acima de 3 cm). O termo é descritivo; a natureza só é definida com avaliação clínica e, frequentemente, exames complementares.'],
    'Cavitação' => ['cavidades|lesão escavada', 'Radio: achado pulmonar',
        'Área que aparece com um espaço vazio (escuro) dentro de outra alteração. Descreve apenas a forma do achado, não a causa.'],
    'Espessamento' => ['espessamentos|espessado', 'Radio: descritor',
        'Estrutura que está mais grossa que o esperado (de pleura, paredes brônquicas, septos etc). É descritivo — pode ser inespecífico ou ter significado clínico, depende do contexto.'],
    'Fibrose' => ['fibroses|alteração fibrótica', 'Radio: achado pulmonar',
        'Padrão que sugere tecido cicatricial (substituição de tecido normal por tecido fibroso). Geralmente é uma alteração estável; o significado clínico depende do histórico do paciente.'],
    'Enfisema' => ['enfisematoso', 'Radio: achado pulmonar',
        'Padrão de hipertransparência (áreas mais escuras) por destruição/dilatação dos pequenos espaços aéreos do pulmão. Costuma estar associado a doença pulmonar crônica — confirme com seu médico.'],
    'Bronquiectasia' => ['bronquiectasias|dilatação brônquica', 'Radio: achado pulmonar',
        'Dilatação permanente de pequenos brônquios (vias aéreas). É um achado anatômico que pode existir há muito tempo; o médico avalia se está causando sintomas.'],
    'Broncograma aéreo' => ['broncogramas aéreos', 'Radio: descritor pulmonar',
        'Sinal radiológico em que os brônquios cheios de ar ficam visíveis dentro de uma área de consolidação, formando "linhas escuras" sobre uma área clara. É um descritor técnico de imagem.'],
    'Hipertransparência' => ['hipertransparências|área hipertransparente', 'Radio: descritor',
        'Região que aparece mais escura/preta do que o esperado, indicando mais ar ou menos tecido naquele ponto. É uma descrição visual, sem significado único.'],
    'Hipotransparência' => ['hipotransparências|velamento', 'Radio: descritor',
        'Região que aparece mais clara/branca do que o esperado. O médico interpreta a causa no contexto do exame.'],
    'Calcificação' => ['calcificações|área calcificada', 'Radio: descritor',
        'Depósito de cálcio que aparece muito branco no exame (mais branco que osso é raro, mas próximo). Pode ser antiga e sem importância, ou ter relevância clínica — depende do local e contexto.'],
    'Cardiomegalia' => ['aumento da área cardíaca|coração aumentado', 'Radio: achado cardíaco',
        'Termo que indica que a silhueta do coração no exame está maior do que o esperado para a idade e o tamanho do paciente. A causa só é determinada com avaliação cardiológica.'],
    'Congestão' => ['congestão pulmonar|congestão hilar', 'Radio: achado pulmonar',
        'Padrão sugestivo de acúmulo de sangue/líquido nos vasos pulmonares. É um achado clínico-radiológico — o médico avalia o significado no contexto.'],

    // ── DESCRITORES (neuro/abdome/outros) ─────────────────────────
    'Hipodensidade' => ['hipodenso|área hipodensa', 'Radio: descritor TC',
        'Em tomografia: região que aparece mais escura que o tecido vizinho (mais "preta" na escala da TC). É uma descrição visual; o significado depende do local e da história clínica.'],
    'Hiperdensidade' => ['hiperdenso|área hiperdensa', 'Radio: descritor TC',
        'Em tomografia: região que aparece mais clara/branca que o tecido vizinho. Pode indicar calcificação, sangue, contraste ou outras substâncias — quem interpreta é o médico.'],
    'Hipossinal' => ['hipossinal em T1|hipossinal em T2', 'Radio: descritor RM',
        'Em ressonância magnética: região que aparece mais escura na sequência indicada (T1, T2 etc). É um descritor técnico — o radiologista combina vários sinais para descrever o achado.'],
    'Hipersinal' => ['hipersinal em T1|hipersinal em T2', 'Radio: descritor RM',
        'Em ressonância magnética: região que aparece mais clara/brilhante na sequência indicada. É um descritor técnico que ganha sentido junto com outras informações do laudo.'],
    'Restrição à difusão' => ['restrição difusional', 'Radio: descritor RM',
        'Achado em ressonância magnética que sugere que a água nos tecidos está mais "presa" do que o normal. Aparece em várias situações; só o médico interpreta no contexto.'],
    'Realce' => ['realce pelo contraste|impregnação pelo contraste', 'Radio: descritor',
        'Aumento de brilho de uma região após injeção de contraste no exame. Indica que o contraste chegou ali; o padrão de realce ajuda o radiologista a caracterizar o achado.'],
    'Edema' => ['edema cerebral|edema vasogênico|edema citotóxico', 'Radio: achado',
        'Acúmulo anormal de líquido em um tecido. É um achado descritivo — quando o radiologista diz "edema" ele descreve um padrão visual, não diagnostica a causa.'],
    'Hemorragia' => ['hematoma|sangramento', 'Radio: achado urgência',
        'Sangue fora dos vasos sanguíneos no tecido. Achado que costuma exigir avaliação médica imediata — leve este laudo ao médico com prioridade.'],
    'Isquemia' => ['lesão isquêmica|área isquêmica', 'Radio: achado urgência',
        'Achado que sugere redução do fluxo de sangue para uma região do tecido. Geralmente exige avaliação médica rápida — procure seu médico com prioridade.'],
    'Lesão expansiva' => ['lesão com efeito expansivo|massa expansiva', 'Radio: achado',
        'Termo que descreve um achado que empurra/desloca estruturas vizinhas. É um descritor de comportamento visual; a natureza da lesão é definida pela investigação clínica.'],
    'Desvio de linha média' => ['desvio da linha mediana', 'Radio: achado neuro urgência',
        'Em exames de crânio: deslocamento das estruturas centrais do cérebro para um lado, sugerindo que algo está empurrando. É um achado que costuma exigir avaliação imediata.'],
    'Efeito de massa' => ['efeito expansivo', 'Radio: descritor',
        'Quando um achado pressiona/desloca estruturas vizinhas. É descritivo do comportamento da lesão, não diagnóstico.'],
    'Dilatação ventricular' => ['hidrocefalia|ventrículos dilatados', 'Radio: achado neuro',
        'Ventrículos cerebrais (cavidades normais do cérebro) maiores que o esperado. Pode ter várias causas — só o médico interpreta.'],
    'Atrofia' => ['atrofia cerebral|redução volumétrica', 'Radio: achado',
        'Redução de volume de um órgão ou estrutura. É um achado descritivo; a interpretação clínica considera idade, histórico e outros fatores.'],
    'Fratura' => ['fraturas|linha de fratura', 'Radio: achado ósseo urgência',
        'Quebra ou descontinuidade do osso visível no exame. Procure avaliação médica para definir tratamento, especialmente se houve trauma recente.'],
    'Aneurisma' => ['aneurismas|dilatação aneurismática', 'Radio: achado vascular',
        'Dilatação anormal localizada de um vaso sanguíneo. É um achado que requer acompanhamento médico — leve o laudo ao médico.'],
    'Trombose' => ['trombo|trombótica', 'Radio: achado vascular urgência',
        'Coágulo de sangue dentro de um vaso. Achado que costuma exigir avaliação médica imediata.'],

    // ── ESTRUTURAS ANATÔMICAS (orientação ao paciente) ────────────
    'Lobo superior direito' => ['LSD|lobo superior do pulmão direito', 'Radio: anatomia pulmonar',
        'Uma das três partes principais do pulmão direito, localizada na região superior do tórax do lado direito. Os pulmões são divididos em lobos para descrever onde os achados estão.'],
    'Lobo médio' => ['lobo médio do pulmão direito|LM', 'Radio: anatomia pulmonar',
        'Lobo central do pulmão direito, entre o lobo superior e o inferior. Existe só no pulmão direito (o esquerdo não tem lobo médio).'],
    'Lobo inferior direito' => ['LID', 'Radio: anatomia pulmonar',
        'Lobo da parte inferior do pulmão direito.'],
    'Lobo superior esquerdo' => ['LSE', 'Radio: anatomia pulmonar',
        'Lobo da parte superior do pulmão esquerdo. O pulmão esquerdo só tem dois lobos (superior e inferior) — o espaço do lobo médio é ocupado pelo coração.'],
    'Lobo inferior esquerdo' => ['LIE', 'Radio: anatomia pulmonar',
        'Lobo da parte inferior do pulmão esquerdo.'],
    'Língula' => ['região lingular', 'Radio: anatomia pulmonar',
        'Parte do lobo superior esquerdo que corresponde anatomicamente ao "lobo médio" do lado direito. Termo só usado no pulmão esquerdo.'],
    'Hilo pulmonar' => ['hilos|região hilar', 'Radio: anatomia pulmonar',
        'Região central de cada pulmão por onde entram e saem brônquios, vasos sanguíneos e linfáticos. Aparece como uma área mais densa no exame.'],
    'Mediastino' => ['mediastinal', 'Radio: anatomia torácica',
        'Espaço central do tórax entre os dois pulmões, onde estão coração, aorta, traqueia, esôfago e linfonodos.'],
    'Seio costofrênico' => ['seios costofrênicos', 'Radio: anatomia pulmonar',
        'Ângulo formado entre o diafragma e a parede do tórax — uma região onde costuma se acumular líquido se houver derrame pleural.'],
    'Ápice pulmonar' => ['ápices pulmonares|região apical', 'Radio: anatomia pulmonar',
        'Parte mais alta de cada pulmão (próxima do pescoço/clavícula).'],
    'Base pulmonar' => ['bases pulmonares', 'Radio: anatomia pulmonar',
        'Parte mais baixa de cada pulmão, apoiada sobre o diafragma.'],
    'Traqueia' => ['traqueal', 'Radio: anatomia',
        'Tubo principal que leva ar do pescoço até os brônquios. Aparece na linha média do tórax.'],
    'Brônquios' => ['brônquio principal|árvore brônquica', 'Radio: anatomia pulmonar',
        'Tubos que conduzem o ar da traqueia para o interior dos pulmões.'],
    'Parênquima pulmonar' => ['parênquima', 'Radio: anatomia pulmonar',
        'Tecido funcional do pulmão (alvéolos e estruturas próximas) — a parte "esponjosa" que faz a troca de oxigênio.'],
    'Pleura' => ['membrana pleural', 'Radio: anatomia',
        'Membrana fina que reveste os pulmões e a parede interna do tórax.'],
    'Diafragma' => ['cúpula diafragmática|diafragmático', 'Radio: anatomia',
        'Músculo em formato de cúpula que separa o tórax do abdome e é o principal responsável pela respiração.'],
    'Área cardíaca' => ['silhueta cardíaca|sombra cardíaca', 'Radio: anatomia cardíaca',
        'Sombra do coração no exame. O tamanho relativo dela é usado pelo médico para avaliar se o coração parece aumentado.'],
    'Aorta' => ['arco aórtico|aorta torácica|aorta abdominal', 'Radio: anatomia vascular',
        'Maior vaso sanguíneo do corpo, que sai do coração e desce pelo tórax e abdome.'],
    'Substância branca' => ['substância branca cerebral', 'Radio: anatomia neuro',
        'Camada interna do cérebro formada principalmente por fibras nervosas. Aparece com cor/intensidade característica em RM e TC.'],
    'Substância cinzenta' => ['substância cinzenta cerebral|córtex', 'Radio: anatomia neuro',
        'Camada externa do cérebro, onde ficam os corpos das células nervosas.'],
    'Ventrículos cerebrais' => ['sistema ventricular', 'Radio: anatomia neuro',
        'Cavidades normais dentro do cérebro, preenchidas por líquido cefalorraquidiano.'],
    'Cerebelo' => ['cerebelar', 'Radio: anatomia neuro',
        'Parte do sistema nervoso localizada na fossa posterior (parte de trás e baixa do crânio), responsável principalmente por coordenação e equilíbrio.'],
    'Tronco cerebral' => ['tronco encefálico', 'Radio: anatomia neuro',
        'Parte do sistema nervoso que conecta o cérebro à medula espinhal — passa por funções vitais como respiração e batimento cardíaco.'],
    'Sulcos corticais' => ['sulcos cerebrais', 'Radio: anatomia neuro',
        'Dobras normais da superfície do cérebro. A profundidade e o padrão delas variam com idade.'],
    'Cisternas da base' => ['cisternas basais', 'Radio: anatomia neuro',
        'Espaços normais cheios de líquido na base do crânio, em volta do tronco cerebral.'],
    'Hipocampo' => ['hipocampos', 'Radio: anatomia neuro',
        'Estrutura cerebral profunda, envolvida com memória — frequentemente avaliada em RM de crânio.'],
    'Sela túrcica' => ['região selar', 'Radio: anatomia neuro',
        'Pequena depressão óssea na base do crânio onde fica a glândula hipófise.'],
    'Fígado' => ['hepático|parênquima hepático', 'Radio: anatomia abdominal',
        'Maior órgão sólido do abdome, localizado no quadrante superior direito.'],
    'Baço' => ['esplênico', 'Radio: anatomia abdominal',
        'Órgão localizado no quadrante superior esquerdo do abdome, atrás do estômago.'],
    'Pâncreas' => ['pancreático', 'Radio: anatomia abdominal',
        'Órgão alongado atrás do estômago, responsável por digestão e produção de hormônios (como insulina).'],
    'Rins' => ['rim direito|rim esquerdo|renal', 'Radio: anatomia abdominal',
        'Órgãos pareados localizados na parte de trás do abdome, responsáveis por filtrar o sangue e produzir urina.'],
    'Vesícula biliar' => ['vesícula', 'Radio: anatomia abdominal',
        'Pequeno órgão em forma de pera abaixo do fígado, que armazena a bile.'],
    'Vias biliares' => ['ductos biliares|colédoco', 'Radio: anatomia abdominal',
        'Canais que conduzem a bile do fígado e da vesícula para o intestino.'],
    'Bexiga' => ['bexiga urinária|vesical', 'Radio: anatomia abdominal',
        'Órgão da pelve que armazena urina.'],
    'Próstata' => ['prostático', 'Radio: anatomia abdominal',
        'Glândula pequena abaixo da bexiga no homem, em volta da uretra.'],
    'Útero' => ['uterino', 'Radio: anatomia pélvica',
        'Órgão reprodutivo feminino na pelve, entre a bexiga e o reto.'],
    'Ovários' => ['ovários direito e esquerdo', 'Radio: anatomia pélvica',
        'Órgãos reprodutivos femininos pareados, ao lado do útero.'],

    // ── TIPOS DE EXAME ────────────────────────────────────────────
    'Raio-X de tórax' => ['radiografia de tórax|RX tórax|telerradiografia', 'Radio: tipo de exame',
        'Exame mais simples para olhar os pulmões e o coração. Usa uma pequena quantidade de radiação. Útil para uma primeira avaliação, mas não mostra detalhes finos como a tomografia.'],
    'Raio-X simples' => ['radiografia simples|RX', 'Radio: tipo de exame',
        'Exame de imagem usando raio-x, sem contraste. Bom para ver ossos, ar nos pulmões e cálculos densos.'],
    'Tomografia computadorizada' => ['TC|tomografia|CT|TAC', 'Radio: tipo de exame',
        'Exame que combina raio-x com computador para gerar "fatias" do corpo, mostrando órgãos internos com muito mais detalhe que o raio-x simples. Pode usar contraste para realçar vasos e lesões.'],
    'TC de tórax' => ['tomografia de tórax|TC tórax', 'Radio: tipo de exame',
        'Tomografia focada nos pulmões, coração, mediastino e parede do tórax. Permite avaliar nódulos, derrames e doenças pulmonares com detalhe que o raio-x simples não dá.'],
    'TC de crânio' => ['tomografia de crânio|TC crânio', 'Radio: tipo de exame',
        'Tomografia da cabeça. Muito usada em emergências para avaliar trauma, sangramentos e suspeita de AVC. Bom para ver osso e sangue agudo; o cérebro em detalhe fino prefere a RM.'],
    'TC de abdome' => ['tomografia de abdome|TC abdome', 'Radio: tipo de exame',
        'Tomografia da barriga, que mostra fígado, baço, pâncreas, rins, intestinos e órgãos pélvicos.'],
    'Ressonância magnética' => ['RM|ressonância|RMN|MRI', 'Radio: tipo de exame',
        'Exame que usa um campo magnético forte (não usa radiação) e gera imagens muito detalhadas, especialmente de tecidos moles como cérebro, medula, músculos e articulações. Mais demorado e barulhento que a tomografia.'],
    'RM de crânio' => ['ressonância de crânio|RM cerebral', 'Radio: tipo de exame',
        'Ressonância da cabeça. Considerada o melhor exame para avaliar o tecido cerebral em detalhe (substância branca, cinzenta, AVC subagudo, esclerose etc).'],
    'RM de coluna' => ['ressonância de coluna|RM cervical|RM lombar', 'Radio: tipo de exame',
        'Ressonância da coluna vertebral, ótima para ver discos, medula e raízes nervosas.'],
    'RM de joelho' => ['ressonância de joelho', 'Radio: tipo de exame',
        'Ressonância do joelho, padrão para avaliar ligamentos, meniscos e cartilagem.'],
    'RM de ombro' => ['ressonância de ombro', 'Radio: tipo de exame',
        'Ressonância do ombro, padrão para avaliar tendões do manguito rotador, lábio glenoidal e cartilagem.'],
    'Ultrassom' => ['ultrassonografia|USG|ecografia|US', 'Radio: tipo de exame',
        'Exame de imagem que usa ondas sonoras (sem radiação). Útil para órgãos abdominais, vasos, tireoide e na obstetrícia. Operador-dependente — depende da experiência de quem faz.'],
    'USG abdominal' => ['ultrassom de abdome|ultrassonografia abdominal', 'Radio: tipo de exame',
        'Ultrassom da barriga, útil para ver fígado, vesícula biliar, baço, rins e bexiga.'],
    'USG transvaginal' => ['ultrassonografia transvaginal', 'Radio: tipo de exame',
        'Ultrassom ginecológico feito por uma sonda interna — vê útero e ovários com mais detalhe que o ultrassom pélvico externo.'],
    'USG obstétrico' => ['ultrassom de gravidez|ultrassonografia obstétrica', 'Radio: tipo de exame',
        'Ultrassom feito durante a gravidez para acompanhar o feto.'],
    'USG morfológico' => ['ultrassonografia morfológica', 'Radio: tipo de exame',
        'Ultrassom obstétrico detalhado, geralmente entre 18 e 24 semanas de gestação, que avalia a anatomia do feto.'],
    'Mamografia' => ['mamografia bilateral', 'Radio: tipo de exame',
        'Raio-x específico das mamas. Principal exame de rastreamento de alterações na mama.'],
    'Densitometria óssea' => ['densitometria|DEXA', 'Radio: tipo de exame',
        'Exame que mede a densidade dos ossos, usado para avaliar osteoporose. Usa uma quantidade muito pequena de radiação.'],
    'Cintilografia' => ['medicina nuclear|cintilograma', 'Radio: tipo de exame',
        'Exame de medicina nuclear: o paciente recebe uma pequena quantidade de substância levemente radioativa que se concentra em algum órgão, e uma câmera detecta onde ela se acumulou.'],
    'PET-CT' => ['PET|PET-scan', 'Radio: tipo de exame',
        'Exame que combina medicina nuclear (PET) com tomografia (CT). Avalia funcionamento e anatomia juntos. Bastante usado em oncologia.'],
    'Angiografia' => ['arteriografia|angiotomografia|angiorressonância', 'Radio: tipo de exame',
        'Exame focado em ver os vasos sanguíneos. Pode ser feito por tomografia, ressonância ou cateterismo.'],

    // ── MAGNITUDE / QUALIFICADORES ────────────────────────────────
    'Discreto' => ['discreta|leve', 'Radio: qualificador',
        'Quando o radiologista usa "discreto" ou "leve" no laudo, está descrevendo a INTENSIDADE do achado — não está dizendo se é grave ou não.'],
    'Moderado' => ['moderada', 'Radio: qualificador',
        'Indica intensidade intermediária do achado. Não diz, por si só, se é grave — depende de qual achado, contexto e histórico.'],
    'Acentuado' => ['acentuada|importante|exuberante', 'Radio: qualificador',
        'Indica que o achado é proeminente/intenso. Significa que o radiologista o destacou — leve o laudo ao médico para entender o que isso quer dizer para você.'],
    'Focal' => ['focais|localizado', 'Radio: qualificador',
        'O achado está em um único ponto, não espalhado.'],
    'Difuso' => ['difusa|generalizado', 'Radio: qualificador',
        'O achado está espalhado, não em um ponto único.'],
    'Multifocal' => ['multifocais|em vários focos', 'Radio: qualificador',
        'Achados em mais de um local, sem necessariamente estarem espalhados de forma difusa.'],
    'Bilateral' => ['nos dois lados|à direita e à esquerda', 'Radio: qualificador',
        'O achado está dos dois lados.'],
    'Unilateral' => ['de um lado só|à direita|à esquerda', 'Radio: qualificador',
        'O achado está de apenas um lado.'],
    'Bem delimitado' => ['contornos bem definidos|limites precisos', 'Radio: qualificador',
        'A borda do achado é nítida — costuma ser uma característica que o radiologista usa para descrever o tipo do achado.'],
    'Mal delimitado' => ['contornos mal definidos|limites imprecisos', 'Radio: qualificador',
        'A borda do achado não é nítida.'],
    'Homogêneo' => ['homogênea', 'Radio: qualificador',
        'O achado tem aspecto uniforme por dentro.'],
    'Heterogêneo' => ['heterogênea', 'Radio: qualificador',
        'O achado tem aspecto não-uniforme por dentro (mistura de áreas com brilho/densidade diferente).'],

    // ── MARCAÇÕES DO PROFISSIONAL (triagem objetiva) ──────────────
    'Calipers' => ['caliper|paquímetro|medições com régua', 'Radio: marcação profissional',
        'Marcações feitas pelo profissional na imagem para medir uma região específica. Quando o radiologista coloca calipers, está destacando um ponto para avaliação. Procure o laudo e seu médico — não posso te dizer o que a marcação significa.'],
    'Régua' => ['marcação de régua|medida linear', 'Radio: marcação profissional',
        'Marcações de medida feitas pelo profissional sobre a imagem, indicando uma região destacada.'],
    'Setas' => ['setas no exame|seta indicativa', 'Radio: marcação profissional',
        'Setas desenhadas pelo radiologista para apontar uma região específica. É um sinal objetivo de que algo foi destacado — leve ao médico.'],
    'Círculo' => ['círculo no exame|região circulada', 'Radio: marcação profissional',
        'Círculo desenhado pelo profissional para marcar uma área. Marcação objetiva — procure o laudo escrito e seu médico.'],
];

$insertados = 0; $atualizados = 0;
$sel = $db->prepare("SELECT id FROM kb_marcadores WHERE nome_canonico = :n AND dominio = 'radio' LIMIT 1");
$ins = $db->prepare(
    "INSERT INTO kb_marcadores (nome_canonico, sinonimos, categoria, dominio, descricao_leiga, fonte_id)
     VALUES (:n, :s, :c, 'radio', :d, :f)"
);
$upd = $db->prepare(
    "UPDATE kb_marcadores SET sinonimos=:s, categoria=:c, descricao_leiga=:d WHERE id=:id"
);

foreach ($termos as $nome => $t) {
    [$sinonimos, $categoria, $descricao] = $t;
    $sel->execute([':n' => $nome]);
    $id = $sel->fetchColumn();
    if ($id) {
        $upd->execute([':s' => $sinonimos, ':c' => $categoria, ':d' => $descricao, ':id' => $id]);
        $atualizados++;
    } else {
        $ins->execute([':n' => $nome, ':s' => $sinonimos, ':c' => $categoria, ':d' => $descricao, ':f' => $fonteId]);
        $insertados++;
    }
}

echo "kb_marcadores (radio) semeado: $insertados novos, $atualizados atualizados.\n";
echo "Total de termos no seed: " . count($termos) . "\n";
echo "Próximo: php rag/ingest/03_embeddings.php (gera vetores p/ o que ainda não tem)\n";
