-- ReadMyLabs — seed de marcadores de referência (adultos)
-- Faixas baseadas em valores de referência usuais de laboratórios brasileiros.
-- ref_min NULL = sem limite inferior clínico relevante (ex.: LDL: quanto menor, melhor).
-- ref_max NULL = sem limite superior relevante.
-- Expansível: adicione linhas para chegar aos ~120 marcadores.

SET NAMES utf8mb4;

-- Reseed idempotente: limpar antes de inserir evita duplicação ao re-rodar.
DELETE FROM marcadores_referencia;

INSERT INTO marcadores_referencia
    (nome, sinonimos, categoria, unidade, sexo, idade_min, idade_max, ref_min, ref_max, descricao)
VALUES
-- ===== HEMOGRAMA =====
('Hemoglobina', 'Hb|Hemoglobina total', 'Hemograma', 'g/dL', 'M', 18, NULL, 13.5, 17.5, 'Proteína que transporta oxigênio no sangue.'),
('Hemoglobina', 'Hb|Hemoglobina total', 'Hemograma', 'g/dL', 'F', 18, NULL, 12.0, 16.0, 'Proteína que transporta oxigênio no sangue.'),
('Hematócrito', 'Ht|HCT', 'Hemograma', '%', 'M', 18, NULL, 41.0, 53.0, 'Proporção de glóbulos vermelhos no sangue.'),
('Hematócrito', 'Ht|HCT', 'Hemograma', '%', 'F', 18, NULL, 36.0, 46.0, 'Proporção de glóbulos vermelhos no sangue.'),
('Hemácias', 'Eritrócitos|RBC|Glóbulos vermelhos', 'Hemograma', 'milhões/mm³', 'M', 18, NULL, 4.5, 5.9, 'Células que transportam oxigênio.'),
('Hemácias', 'Eritrócitos|RBC|Glóbulos vermelhos', 'Hemograma', 'milhões/mm³', 'F', 18, NULL, 4.0, 5.2, 'Células que transportam oxigênio.'),
('Leucócitos', 'Leucograma|WBC|Glóbulos brancos', 'Hemograma', '/mm³', 'ambos', 18, NULL, 4000, 11000, 'Células de defesa do organismo.'),
('Plaquetas', 'PLT|Trombócitos', 'Hemograma', '/mm³', 'ambos', 18, NULL, 150000, 450000, 'Responsáveis pela coagulação do sangue.'),
('VCM', 'Volume corpuscular médio|MCV', 'Hemograma', 'fL', 'ambos', 18, NULL, 80.0, 100.0, 'Tamanho médio das hemácias.'),
('HCM', 'Hemoglobina corpuscular média|MCH', 'Hemograma', 'pg', 'ambos', 18, NULL, 27.0, 33.0, 'Quantidade média de hemoglobina por hemácia.'),
('RDW', 'Red cell distribution width', 'Hemograma', '%', 'ambos', 18, NULL, 11.5, 14.5, 'Variação no tamanho das hemácias.'),
('Neutrófilos', 'Segmentados', 'Hemograma', '%', 'ambos', 18, NULL, 40.0, 70.0, 'Tipo de glóbulo branco; combate infecções.'),
('Linfócitos', 'Linfograma', 'Hemograma', '%', 'ambos', 18, NULL, 20.0, 45.0, 'Tipo de glóbulo branco; imunidade.'),
('Eosinófilos', NULL, 'Hemograma', '%', 'ambos', 18, NULL, 1.0, 5.0, 'Glóbulos brancos ligados a alergias e parasitas.'),

-- ===== GLICEMIA / METABÓLICO =====
('Glicose', 'Glicemia|Glicemia de jejum|Glicose em jejum', 'Glicemia', 'mg/dL', 'ambos', 18, NULL, 70.0, 99.0, 'Açúcar no sangue em jejum.'),
('Hemoglobina glicada', 'HbA1c|A1C|Glicada', 'Glicemia', '%', 'ambos', 18, NULL, NULL, 5.7, 'Média do açúcar no sangue nos últimos 3 meses.'),
('Insulina', 'Insulina basal', 'Glicemia', 'µUI/mL', 'ambos', 18, NULL, 2.6, 24.9, 'Hormônio que regula o açúcar no sangue.'),

-- ===== PERFIL LIPÍDICO =====
('Colesterol total', 'CT|Colesterol', 'Lipídico', 'mg/dL', 'ambos', 18, NULL, NULL, 190.0, 'Gordura no sangue; valores altos elevam risco cardíaco.'),
('Colesterol LDL', 'LDL|LDL-c|Colesterol ruim', 'Lipídico', 'mg/dL', 'ambos', 18, NULL, NULL, 130.0, 'Colesterol "ruim"; deposita-se nas artérias.'),
('Colesterol HDL', 'HDL|HDL-c|Colesterol bom', 'Lipídico', 'mg/dL', 'M', 18, NULL, 40.0, NULL, 'Colesterol "bom"; protege o coração.'),
('Colesterol HDL', 'HDL|HDL-c|Colesterol bom', 'Lipídico', 'mg/dL', 'F', 18, NULL, 50.0, NULL, 'Colesterol "bom"; protege o coração.'),
('Triglicerídeos', 'TG|Triglicérides', 'Lipídico', 'mg/dL', 'ambos', 18, NULL, NULL, 150.0, 'Tipo de gordura no sangue.'),

-- ===== TIREOIDE =====
('TSH', 'Hormônio tireoestimulante|Tireotrofina', 'Tireoide', 'µUI/mL', 'ambos', 18, NULL, 0.4, 4.5, 'Hormônio que regula a tireoide.'),
('T4 livre', 'T4L|Tiroxina livre', 'Tireoide', 'ng/dL', 'ambos', 18, NULL, 0.7, 1.8, 'Hormônio da tireoide na forma ativa.'),
('T3', 'Triiodotironina|T3 total', 'Tireoide', 'ng/dL', 'ambos', 18, NULL, 80.0, 200.0, 'Hormônio da tireoide.'),

-- ===== FUNÇÃO RENAL =====
('Creatinina', 'Creatinina sérica', 'Função renal', 'mg/dL', 'M', 18, NULL, 0.7, 1.3, 'Mede o funcionamento dos rins.'),
('Creatinina', 'Creatinina sérica', 'Função renal', 'mg/dL', 'F', 18, NULL, 0.6, 1.1, 'Mede o funcionamento dos rins.'),
('Ureia', 'Uréia', 'Função renal', 'mg/dL', 'ambos', 18, NULL, 15.0, 45.0, 'Produto do metabolismo eliminado pelos rins.'),
('Ácido úrico', 'Acido urico', 'Função renal', 'mg/dL', 'M', 18, NULL, 3.4, 7.0, 'Excesso pode causar gota e pedras nos rins.'),
('Ácido úrico', 'Acido urico', 'Função renal', 'mg/dL', 'F', 18, NULL, 2.4, 6.0, 'Excesso pode causar gota e pedras nos rins.'),

-- ===== FUNÇÃO HEPÁTICA =====
('TGO', 'AST|Aspartato aminotransferase', 'Função hepática', 'U/L', 'ambos', 18, NULL, NULL, 40.0, 'Enzima do fígado; valores altos indicam lesão.'),
('TGP', 'ALT|Alanina aminotransferase', 'Função hepática', 'U/L', 'ambos', 18, NULL, NULL, 41.0, 'Enzima do fígado; valores altos indicam lesão.'),
('Gama GT', 'GGT|Gama-glutamil transferase', 'Função hepática', 'U/L', 'M', 18, NULL, NULL, 60.0, 'Enzima ligada ao fígado e vias biliares.'),
('Gama GT', 'GGT|Gama-glutamil transferase', 'Função hepática', 'U/L', 'F', 18, NULL, NULL, 40.0, 'Enzima ligada ao fígado e vias biliares.'),
('Bilirrubina total', 'BT', 'Função hepática', 'mg/dL', 'ambos', 18, NULL, 0.2, 1.2, 'Pigmento processado pelo fígado.'),
('Fosfatase alcalina', 'FA|ALP', 'Função hepática', 'U/L', 'ambos', 18, NULL, 40.0, 129.0, 'Enzima do fígado e ossos.'),

-- ===== VITAMINAS / MINERAIS =====
('Vitamina D', '25-OH vitamina D|25 hidroxivitamina D|Vitamina D 25-OH', 'Vitaminas', 'ng/mL', 'ambos', 18, NULL, 30.0, 100.0, 'Importante para ossos e imunidade.'),
('Vitamina B12', 'B12|Cobalamina', 'Vitaminas', 'pg/mL', 'ambos', 18, NULL, 200.0, 900.0, 'Essencial para sangue e sistema nervoso.'),
('Ferro', 'Ferro sérico', 'Vitaminas', 'µg/dL', 'ambos', 18, NULL, 60.0, 170.0, 'Mineral necessário para transportar oxigênio.'),
('Ferritina', NULL, 'Vitaminas', 'ng/mL', 'M', 18, NULL, 30.0, 400.0, 'Reserva de ferro do organismo.'),
('Ferritina', NULL, 'Vitaminas', 'ng/mL', 'F', 18, NULL, 15.0, 150.0, 'Reserva de ferro do organismo.'),
('Ácido fólico', 'Folato|Vitamina B9', 'Vitaminas', 'ng/mL', 'ambos', 18, NULL, 3.0, 17.0, 'Essencial para formação das células.'),

-- ===== ELETRÓLITOS =====
('Sódio', 'Na', 'Eletrólitos', 'mEq/L', 'ambos', 18, NULL, 135.0, 145.0, 'Eletrólito que regula líquidos no corpo.'),
('Potássio', 'K', 'Eletrólitos', 'mEq/L', 'ambos', 18, NULL, 3.5, 5.1, 'Eletrólito essencial para coração e músculos.'),
('Cálcio', 'Ca|Cálcio total', 'Eletrólitos', 'mg/dL', 'ambos', 18, NULL, 8.5, 10.5, 'Mineral dos ossos e da contração muscular.'),
('Magnésio', 'Mg', 'Eletrólitos', 'mg/dL', 'ambos', 18, NULL, 1.6, 2.6, 'Mineral envolvido em centenas de reações no corpo.');
