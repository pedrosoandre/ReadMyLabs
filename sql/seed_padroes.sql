-- ReadMyLabs — padrões clínicos validados por diretrizes
-- Cada padrão identificado localmente (zero token); Claude só redige a síntese.
-- Fontes primárias abreviadas:
--   HAR  = Harrison's Principles of Internal Medicine, 21ª ed. (2022)
--   SBAC = Manual de Hematologia Clínica, SBAC (2020)
--   SBD  = Diretrizes SBD 2023 (Soc. Brasileira de Diabetes)
--   ADA  = Standards of Care in Diabetes, ADA (2024)
--   SBC  = IV Diretriz Brasileira sobre Dislipidemias, SBC (2020)
--   SBEM = Consenso de Vitamina D, SBEM (2021)
--   KDIGO= Clinical Practice Guideline for CKD, KDIGO (2024)
--   EASL = EASL Clinical Practice Guidelines: Liver 2023

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS padroes_clinicos (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    codigo         VARCHAR(60)   NOT NULL,
    categoria      VARCHAR(40)   NOT NULL,
    titulo         VARCHAR(120)  NOT NULL,
    interpretacao  TEXT          NOT NULL,
    urgencia       ENUM('verde','amarelo','vermelho') NOT NULL DEFAULT 'amarelo',
    acao           TEXT          NOT NULL,
    fonte          VARCHAR(300)  NOT NULL,
    ativo          TINYINT(1)    NOT NULL DEFAULT 1,
    UNIQUE KEY uk_codigo (codigo),
    INDEX idx_categoria (categoria)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cache_conclusoes (
    chave          CHAR(64)      NOT NULL,
    padroes_codigos VARCHAR(500) NOT NULL,
    texto          TEXT          NOT NULL,
    usos           INT UNSIGNED  NOT NULL DEFAULT 1,
    criado_em      TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (chave)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Reseed idempotente
DELETE FROM padroes_clinicos;

INSERT INTO padroes_clinicos (codigo, categoria, titulo, interpretacao, urgencia, acao, fonte) VALUES

-- ===== HEMOGRAMA — LEUCÓCITOS =====
('leucopenia',
 'Hemograma',
 'Leucopenia',
 'Os glóbulos brancos (células de defesa) estão abaixo do esperado. Isso pode ocorrer em infecções virais recentes, uso de medicamentos, doenças autoimunes ou variações individuais benignas.',
 'amarelo',
 'Consultar médico para investigar causa; repetir hemograma em 30 dias.',
 'HAR cap. 80 "Leukocytosis and Leukopenia"; SBAC Manual de Hematologia 2020'),

('leucopenia_linfocitose',
 'Hemograma',
 'Leucopenia com padrão linfocitário — sugestivo de etiologia viral',
 'A queda dos glóbulos brancos acompanhada de proporção elevada de linfócitos é o padrão clássico de resposta a infecções virais (resfriado, gripe, mononucleose, dengue) ou a uso de certos medicamentos.',
 'amarelo',
 'Repouso, hidratação. Consultar médico se febre persistir > 5 dias ou surgir cansaço intenso.',
 'HAR cap. 80; SBAC Manual de Hematologia 2020'),

('leucocitose',
 'Hemograma',
 'Leucocitose',
 'Os glóbulos brancos estão acima do normal. Pode indicar infecção bacteriana ativa, processo inflamatório, estresse físico ou uso de corticoides.',
 'amarelo',
 'Consultar médico para identificar a causa; associar com quadro clínico.',
 'HAR cap. 80 "Leukocytosis and Leukopenia"'),

('leucocitose_neutrofilia',
 'Hemograma',
 'Leucocitose com neutrofilia — sugestivo de infecção bacteriana ou inflamação',
 'O aumento dos glóbulos brancos com predomínio de neutrófilos é o padrão típico de infecções bacterianas, inflamações agudas ou uso de corticoides.',
 'amarelo',
 'Consultar médico para avaliação clínica e possível tratamento da infecção.',
 'HAR cap. 80; SBAC Manual de Hematologia 2020'),

('leucocitose_grave',
 'Hemograma',
 'Leucocitose grave (> 30.000 /mm³)',
 'Glóbulos brancos muito elevados podem indicar infecção grave, reação inflamatória intensa ou, em casos raros, doença do sangue. Avaliação urgente é recomendada.',
 'vermelho',
 'Procurar médico com urgência para avaliação hematológica completa.',
 'HAR cap. 80; Williams Hematology 9ª ed.'),

('eosinofilia',
 'Hemograma',
 'Eosinofilia — eosinófilos elevados',
 'Eosinófilos elevados estão frequentemente associados a reações alérgicas (rinite, asma), parasitoses intestinais ou uso de medicamentos.',
 'amarelo',
 'Investigar alergia ou parasitoses; exame parasitológico de fezes pode ser útil.',
 'HAR cap. 80 "Eosinophilia"; SBAC Manual de Hematologia 2020'),

('linfocitose',
 'Hemograma',
 'Linfocitose relativa',
 'Proporção de linfócitos acima do normal. Em adultos, associa-se principalmente a infecções virais recentes ou recuperação de infecção.',
 'amarelo',
 'Repetir hemograma em 30 dias; consultar médico se surgirem outros sintomas.',
 'HAR cap. 80; SBAC Manual de Hematologia 2020'),

-- ===== HEMOGRAMA — SÉRIE VERMELHA =====
('anemia_microcitica',
 'Hemograma',
 'Anemia microcítica — hemácias pequenas',
 'A anemia com hemácias menores que o normal sugere deficiência de ferro (causa mais comum), traço talassêmico ou anemia de doença crônica com perfil microcítico.',
 'amarelo',
 'Dosar ferritina e ferro sérico para confirmar deficiência de ferro; consultar médico.',
 'HAR cap. 628 "Iron-Deficiency Anemia"; SBAC Manual de Hematologia 2020'),

('anemia_ferropriva_rdw',
 'Hemograma',
 'Padrão de anemia ferropriva — hemácias pequenas e desiguais',
 'A combinação de hemoglobina baixa, hemácias pequenas (VCM baixo) e tamanho irregular (RDW alto) é o padrão clássico de deficiência de ferro, a causa de anemia mais frequente no mundo.',
 'amarelo',
 'Dosar ferritina e ferro sérico para confirmar; avaliar suplementação com médico.',
 'HAR cap. 628; SBAC Manual de Hematologia 2020; WHO Guideline: Use of Ferritin Concentrations 2020'),

('anemia_macrocitica',
 'Hemograma',
 'Anemia macrocítica — hemácias grandes',
 'Hemácias maiores que o normal acompanhadas de anemia sugerem deficiência de vitamina B12 ou folato, uso de certos medicamentos (metotrexato, anticonvulsivantes) ou doença hepática.',
 'amarelo',
 'Dosar vitamina B12 e ácido fólico; consultar médico para investigação.',
 'HAR cap. 628 "Megaloblastic Anemias"; SBAC Manual de Hematologia 2020'),

('anemia_normocitica',
 'Hemograma',
 'Anemia normocítica — hemácias de tamanho normal',
 'Hemoglobina baixa com hemácias de tamanho normal pode indicar anemia de doença crônica (inflamação, infecção prolongada, doença renal), perda de sangue recente ou hemólise.',
 'amarelo',
 'Consultar médico para investigação da causa subjacente.',
 'HAR cap. 628 "Anemia of Chronic Disease and Inflammation"; SBAC 2020'),

('policitemia',
 'Hemograma',
 'Policitemia — hemoglobina ou hematócrito elevados',
 'Valor de hemoglobina ou hematócrito acima do esperado pode refletir desidratação, tabagismo, apneia do sono, altitude elevada ou, mais raramente, doença da medula óssea.',
 'amarelo',
 'Consultar médico; dosar saturação de oxigênio e investigar causas secundárias.',
 'HAR cap. 633 "Polycythemia Vera and Other Myeloproliferative Neoplasms"'),

('microcitose_sem_anemia',
 'Hemograma',
 'Microcitose isolada sem anemia',
 'Hemácias pequenas com hemoglobina normal é o padrão típico do traço talassêmico (portador assintomático, condição benigna e muito comum em brasileiros) ou deficiência leve de ferro sem anemia estabelecida.',
 'verde',
 'Eletroforese de hemoglobina para confirmar traço talassêmico se necessário; consultar médico.',
 'SBAC Manual de Hematologia 2020; HAR cap. 628'),

('anisocitose_sem_anemia',
 'Hemograma',
 'Anisocitose (RDW elevado) sem anemia',
 'Variação no tamanho das hemácias sem queda de hemoglobina pode indicar início de deficiência de ferro, deficiência mista de nutrientes ou recuperação de anemia.',
 'amarelo',
 'Dosar ferritina, B12 e folato para investigar deficiência nutricional.',
 'HAR cap. 628; SBAC Manual de Hematologia 2020'),

-- ===== HEMOGRAMA — PLAQUETAS =====
('trombocitopenia_leve',
 'Hemograma',
 'Trombocitopenia leve (75.000–150.000 /mm³)',
 'Plaquetas moderadamente abaixo do normal. Pode ocorrer em infecções virais, uso de medicamentos, deficiência de B12/folato ou doença autoimune. O risco de sangramento espontâneo é baixo nesse nível.',
 'amarelo',
 'Repetir hemograma em 2 semanas; evitar medicamentos que interferem na coagulação sem orientação médica.',
 'HAR cap. 111 "Thrombocytopenia"; SBAC Manual de Hematologia 2020'),

('trombocitopenia_grave',
 'Hemograma',
 'Trombocitopenia grave (< 75.000 /mm³)',
 'Plaquetas significativamente reduzidas aumentam o risco de sangramento. Requer avaliação médica urgente para identificar a causa (dengue, uso de medicamentos, PTI, entre outras).',
 'vermelho',
 'Procurar médico com urgência; evitar atividades com risco de trauma.',
 'HAR cap. 111 "Thrombocytopenia"; SBAC Manual de Hematologia 2020'),

('trombocitose',
 'Hemograma',
 'Trombocitose — plaquetas elevadas',
 'Plaquetas acima do normal são comuns em quadros inflamatórios, infecções, deficiência de ferro ou após remoção do baço. Raramente indica doença da medula óssea.',
 'amarelo',
 'Avaliar contexto clínico com médico; dosar ferritina para afastar deficiência de ferro.',
 'HAR cap. 633; SBAC Manual de Hematologia 2020'),

('pancitopenia',
 'Hemograma',
 'Pancitopenia — as três linhagens baixas',
 'A redução simultânea de glóbulos brancos, hemoglobina e plaquetas é um achado que requer avaliação hematológica urgente. Pode indicar anemia aplástica, síndrome mielodisplásica ou outra doença grave da medula óssea.',
 'vermelho',
 'Encaminhar a hematologista com urgência; não postergar a avaliação.',
 'HAR cap. 81 "Aplastic Anemia, Myelodysplasia"; Williams Hematology 9ª ed.'),

-- ===== GLICEMIA =====
('pre_diabetes',
 'Glicemia',
 'Glicemia de jejum alterada — pré-diabetes',
 'Glicose em jejum entre 100 e 125 mg/dL indica pré-diabetes: o organismo já tem dificuldade de regular o açúcar no sangue, mas ainda não chegou ao critério de diabetes. Mudanças de hábito podem reverter esse quadro.',
 'amarelo',
 'Adotar dieta com menos açúcar e carboidratos refinados, praticar exercícios e consultar médico.',
 'ADA Standards of Care in Diabetes 2024; SBD Diretrizes 2023'),

('diabetes_sugestivo',
 'Glicemia',
 'Glicemia de jejum sugestiva de diabetes (≥ 126 mg/dL)',
 'Glicose em jejum nesse nível é critério laboratorial para diabetes mellitus. O diagnóstico exige confirmação com um segundo exame. Sem tratamento, o diabetes aumenta risco cardiovascular, renal e neurológico.',
 'vermelho',
 'Consultar médico com urgência para confirmação diagnóstica e início de tratamento.',
 'ADA Standards of Care in Diabetes 2024; SBD Diretrizes 2023'),

('pre_diabetes_hba1c',
 'Glicemia',
 'Hemoglobina glicada elevada — pré-diabetes',
 'HbA1c entre 5,7% e 6,4% indica que a média de açúcar no sangue nos últimos 3 meses está acima do ideal, configurando pré-diabetes.',
 'amarelo',
 'Mudança de hábito alimentar e exercício físico; reavaliação anual com médico.',
 'ADA Standards of Care in Diabetes 2024; SBD Diretrizes 2023'),

('diabetes_hba1c',
 'Glicemia',
 'Hemoglobina glicada sugestiva de diabetes (> 6,4%)',
 'HbA1c acima de 6,4% é critério para diabetes mellitus pela média glicêmica dos últimos 3 meses. Requer confirmação e avaliação médica para início de manejo.',
 'vermelho',
 'Consultar médico; não postergar avaliação.',
 'ADA Standards of Care in Diabetes 2024; SBD Diretrizes 2023'),

-- ===== PERFIL LIPÍDICO =====
('dislipidemia_ldl',
 'Lipídico',
 'Colesterol LDL elevado',
 'O colesterol "ruim" acima de 130 mg/dL aumenta o risco de depósito nas artérias e doenças cardiovasculares. O nível ideal depende do risco cardiovascular individual.',
 'amarelo',
 'Reduzir gorduras saturadas e trans na dieta; exercício aeróbico regular; avaliar com médico se há necessidade de medicação.',
 'SBC IV Diretriz Brasileira sobre Dislipidemias 2020; ESC/EAS Guidelines 2019'),

('hipertrigliceridemia',
 'Lipídico',
 'Triglicerídeos elevados',
 'Triglicerídeos altos estão associados a alimentação rica em açúcar e carboidratos refinados, álcool, sedentarismo e diabetes. Acima de 500 mg/dL, há risco de pancreatite.',
 'amarelo',
 'Reduzir açúcar, álcool e carboidratos refinados; praticar exercícios; consultar médico.',
 'SBC IV Diretriz Brasileira sobre Dislipidemias 2020'),

('hdl_baixo',
 'Lipídico',
 'Colesterol HDL baixo',
 'O colesterol "bom" protege as artérias. Valores baixos de HDL aumentam o risco cardiovascular independentemente do LDL.',
 'amarelo',
 'Exercício aeróbico regular é o principal elevador do HDL; consultar médico para avaliação global do risco cardiovascular.',
 'SBC IV Diretriz Brasileira sobre Dislipidemias 2020; ESC/EAS Guidelines 2019'),

('dislipidemia_mista',
 'Lipídico',
 'Dislipidemia mista — LDL alto e triglicerídeos altos',
 'A combinação de LDL e triglicerídeos elevados representa risco cardiovascular aumentado e é frequentemente associada à síndrome metabólica.',
 'amarelo',
 'Consultar médico para avaliação de risco cardiovascular; mudança de hábitos alimentares e possível medicação.',
 'SBC IV Diretriz Brasileira sobre Dislipidemias 2020'),

-- ===== TIREOIDE =====
('hipotireoidismo_primario',
 'Tireoide',
 'Hipotireoidismo — tireoide com função reduzida',
 'TSH elevado com T4 livre baixo indica que a tireoide está trabalhando abaixo do esperado. Os sintomas incluem cansaço, ganho de peso, frio excessivo, constipação e queda de cabelo.',
 'amarelo',
 'Consultar endocrinologista; tratamento com reposição hormonal é simples e eficaz.',
 'HAR cap. 376 "Hypothyroidism"; ATA Guidelines 2014'),

('hipotireoidismo_subclínico',
 'Tireoide',
 'Hipotireoidismo subclínico — TSH elevado com T4 normal',
 'TSH acima do normal com T4 livre dentro da faixa indica hipotireoidismo leve (subclínico). Pode progredir para hipotireoidismo clínico e está associado a maior risco cardiovascular se não tratado.',
 'amarelo',
 'Consultar médico para monitoramento; repetir TSH e T4 livre em 3 a 6 meses.',
 'HAR cap. 376; ATA/AACE Guidelines on Hypothyroidism 2012'),

('hipertireoidismo',
 'Tireoide',
 'Hipertireoidismo — tireoide hiperativa',
 'TSH baixo com T4 livre elevado indica que a tireoide está produzindo hormônios em excesso. Pode causar palpitações, perda de peso, tremores, ansiedade e sudorese excessiva.',
 'amarelo',
 'Consultar endocrinologista com brevidade; não postergar tratamento.',
 'HAR cap. 376 "Hyperthyroidism"; ATA Guidelines 2016'),

('tsh_suprimido',
 'Tireoide',
 'TSH suprimido — investigar hipertireoidismo',
 'TSH muito baixo sem T4 livre elevado pode indicar hipertireoidismo subclínico, uso de hormônio tireoidiano em excesso ou nódulo autônomo da tireoide.',
 'amarelo',
 'Consultar médico; repetir TSH, T4 livre e T3 em 4 semanas.',
 'HAR cap. 376; ATA Guidelines 2016'),

-- ===== FUNÇÃO RENAL =====
('disfuncao_renal',
 'Função renal',
 'Disfunção renal — creatinina e ureia elevadas',
 'A elevação simultânea de creatinina e ureia indica que os rins podem estar com funcionamento reduzido. Pode ser aguda (desidratação, infecção) ou crônica (diabetes, hipertensão).',
 'vermelho',
 'Consultar médico com urgência; hidratação adequada; avaliar medicamentos em uso.',
 'KDIGO Clinical Practice Guideline for CKD 2024; HAR cap. 305 "Chronic Kidney Disease"'),

('creatinina_elevada',
 'Função renal',
 'Creatinina elevada',
 'Creatinina acima do esperado pode refletir redução do funcionamento renal, desidratação ou massa muscular aumentada. Isoladamente, requer correlação clínica.',
 'amarelo',
 'Consultar médico; garantir boa hidratação; repetir exame em jejum.',
 'KDIGO CKD Guidelines 2024; HAR cap. 305'),

('hiperuricemia',
 'Função renal',
 'Hiperuricemia — ácido úrico elevado',
 'Ácido úrico alto aumenta o risco de gota (crise de dor articular intensa) e pode contribuir para formação de cálculos renais. Está associado a dieta rica em carnes vermelhas, frutos do mar e álcool.',
 'amarelo',
 'Reduzir consumo de carnes vermelhas, vísceras e álcool; aumentar ingestão de água; consultar médico.',
 'HAR cap. 353 "Gout and Hyperuricemia"; SBR Diretrizes de Gota 2020'),

-- ===== FUNÇÃO HEPÁTICA =====
('hepatite_enzimas',
 'Função hepática',
 'Enzimas hepáticas elevadas (TGO/TGP)',
 'TGO e/ou TGP acima do normal indicam que as células do fígado estão sofrendo algum grau de agressão. Pode ocorrer por gordura no fígado, álcool, medicamentos, vírus ou outras causas.',
 'amarelo',
 'Evitar álcool; revisar medicamentos em uso; consultar médico para investigação.',
 'HAR cap. 302 "Approach to the Patient with Liver Disease"; EASL Clinical Practice Guidelines 2023'),

('hepatite_enzimas_grave',
 'Função hepática',
 'Enzimas hepáticas muito elevadas (> 3× o normal)',
 'Elevação acentuada de TGO e/ou TGP indica lesão hepática significativa que requer avaliação urgente. Pode indicar hepatite viral aguda, hepatite alcoólica, toxicidade medicamentosa ou outra doença grave do fígado.',
 'vermelho',
 'Procurar médico com urgência; suspender álcool e medicamentos não essenciais.',
 'HAR cap. 302; EASL Clinical Practice Guidelines 2023'),

-- ===== VITAMINAS E MINERAIS =====
('hipovitaminose_d',
 'Vitaminas',
 'Deficiência de vitamina D',
 'Vitamina D abaixo de 30 ng/mL é muito comum em brasileiros com pouca exposição solar. Está associada a enfraquecimento ósseo, imunidade reduzida e fadiga.',
 'amarelo',
 'Exposição solar moderada (10–15 min/dia sem protetor em braços e pernas); suplementação conforme orientação médica.',
 'SBEM Consenso de Vitamina D 2021; Endocrine Society Guidelines 2011'),

('deficiencia_b12',
 'Vitaminas',
 'Deficiência de vitamina B12',
 'Vitamina B12 baixa pode causar anemia megaloblástica, formigamento, perda de memória e fadiga. É mais comum em vegetarianos, veganos e pessoas com gastrite atrófica.',
 'amarelo',
 'Suplementação oral ou injetável conforme orientação médica; aumentar consumo de carnes, ovos e laticínios.',
 'HAR cap. 628 "Megaloblastic Anemias"; SBAC Manual de Hematologia 2020'),

('ferropenia_serica',
 'Vitaminas',
 'Ferro sérico baixo',
 'Ferro sérico abaixo do normal indica aporte ou absorção reduzida de ferro. É a causa mais comum de anemia no mundo, especialmente em mulheres em idade fértil.',
 'amarelo',
 'Dosar ferritina para confirmar reservas; consultar médico; aumentar consumo de carnes vermelhas e feijão.',
 'HAR cap. 628; SBAC Manual de Hematologia 2020; WHO 2020'),

('ferropenia_ferritina',
 'Vitaminas',
 'Ferritina baixa — reserva de ferro reduzida',
 'Ferritina é o melhor indicador das reservas de ferro no organismo. Valores baixos indicam que os depósitos estão esgotados, mesmo antes de surgir anemia.',
 'amarelo',
 'Consultar médico para suplementação adequada; investigar causa da perda de ferro.',
 'HAR cap. 628; WHO Guideline: Use of Ferritin Concentrations 2020'),

('deficiencia_folato',
 'Vitaminas',
 'Deficiência de ácido fólico',
 'Ácido fólico baixo pode causar anemia megaloblástica e, em gestantes, aumenta o risco de defeitos do tubo neural no bebê. Frequente em dietas pobres em vegetais folhosos.',
 'amarelo',
 'Suplementação oral conforme orientação médica; aumentar consumo de vegetais verdes escuros, feijões e cereais enriquecidos.',
 'HAR cap. 628; MS Brasil — Protocolos Clínicos PCDT 2022'),

-- ===== ELETRÓLITOS =====
('hiponatremia',
 'Eletrólitos',
 'Hiponatremia — sódio baixo',
 'Sódio abaixo do normal pode causar tontura, náusea, confusão mental e fraqueza. As causas incluem vômitos, diarreia, uso de diuréticos, insuficiência cardíaca ou renal.',
 'vermelho',
 'Procurar avaliação médica; não corrigir o sódio por conta própria — a correção rápida pode ser perigosa.',
 'HAR cap. 55 "Fluid and Electrolyte Disturbances"; ESC/ERA Guidelines 2023'),

('hipernatremia',
 'Eletrólitos',
 'Hipernatremia — sódio alto',
 'Sódio elevado geralmente indica desidratação significativa. Pode causar confusão, sede intensa e fraqueza muscular.',
 'vermelho',
 'Avaliação médica urgente; reidratação cuidadosa conforme orientação profissional.',
 'HAR cap. 55 "Fluid and Electrolyte Disturbances"'),

('hipocalemia',
 'Eletrólitos',
 'Hipocalemia — potássio baixo',
 'Potássio abaixo do normal pode causar fraqueza muscular, cãibras e alterações do ritmo cardíaco. Associa-se a vômitos, diarreia, uso de diuréticos ou ingestão insuficiente.',
 'amarelo',
 'Consultar médico; aumentar consumo de banana, laranja, batata e feijão; não suplementar sem orientação.',
 'HAR cap. 55; SBC Diretriz de Arritmias 2023'),

('hipocalemia_grave',
 'Eletrólitos',
 'Hipocalemia grave (< 3,0 mEq/L)',
 'Potássio muito baixo representa risco cardíaco real, com possibilidade de arritmias graves. Requer correção médica urgente.',
 'vermelho',
 'Procurar avaliação médica imediatamente.',
 'HAR cap. 55; SBC Diretriz de Arritmias 2023'),

('hipercalemia',
 'Eletrólitos',
 'Hipercalemia — potássio alto',
 'Potássio elevado pode causar fraqueza muscular e arritmias cardíacas. Ocorre em insuficiência renal, uso de certos medicamentos (IECA, poupadores de potássio) ou acidose.',
 'amarelo',
 'Consultar médico; evitar suplementos de potássio e substitutos de sal.',
 'HAR cap. 55; KDIGO CKD Guidelines 2024'),

('hipercalemia_grave',
 'Eletrólitos',
 'Hipercalemia grave (> 6,0 mEq/L)',
 'Potássio muito elevado é uma emergência médica com risco de parada cardíaca. Requer avaliação e tratamento imediatos.',
 'vermelho',
 'Procurar pronto-socorro imediatamente.',
 'HAR cap. 55; KDIGO CKD Guidelines 2024');
