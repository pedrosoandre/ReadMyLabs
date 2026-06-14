-- ReadMyLabs — esquema do RAG de exames (MySQL / MariaDB 10.x).
-- Sem extensões: embeddings ficam como JSON normalizado e o cosseno
-- é calculado em PHP (lib/vetor.php). Compatível com Hostinger compartilhada.

SET NAMES utf8mb4;
SET time_zone = '+00:00';

-- ---------------------------------------------------------------
-- 1) Catálogo de fontes (rastreabilidade jurídica / licenças)
-- ---------------------------------------------------------------
CREATE TABLE IF NOT EXISTS kb_fontes (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    nome        VARCHAR(160) NOT NULL,          -- "LOINC PT-BR", "Manual SUS-BH"
    url         VARCHAR(500) NULL,
    licenca     VARCHAR(160) NOT NULL,          -- "LOINC License", "público", ...
    observacao  VARCHAR(500) NULL,
    criado_em   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_nome (nome)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------
-- 2) Marcadores canônicos (superset do marcadores_referencia)
--    Resolve "o que é este exame" e o problema de sinônimos
--    (TGO = AST = aspartato aminotransferase).
-- ---------------------------------------------------------------
CREATE TABLE IF NOT EXISTS kb_marcadores (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    loinc_code      VARCHAR(20)  NULL,           -- ex.: "718-7" (Hemoglobina)
    nome_canonico   VARCHAR(160) NOT NULL,       -- nome preferido em PT-BR
    sinonimos       TEXT         NULL,           -- "Hb|Hemoglobina total|HGB" (sep. por |)
    categoria       VARCHAR(60)  NULL,           -- "Hemograma", "Lipídico", ...
    unidade_padrao  VARCHAR(30)  NULL,           -- "g/dL"
    descricao_leiga VARCHAR(500) NULL,           -- explicação curta, escrita por nós
    fonte_id        INT          NULL,
    criado_em       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizado_em   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_loinc (loinc_code),
    INDEX idx_nome (nome_canonico),
    INDEX idx_categoria (categoria),
    CONSTRAINT fk_kbm_fonte FOREIGN KEY (fonte_id) REFERENCES kb_fontes(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------
-- 3) Trechos de texto de referência (para a busca "o que é")
-- ---------------------------------------------------------------
CREATE TABLE IF NOT EXISTS kb_chunks (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    marcador_id  INT          NULL,             -- vínculo opcional a um marcador
    titulo       VARCHAR(255) NULL,
    texto        MEDIUMTEXT   NOT NULL,         -- trecho a ser recuperado
    fonte_id     INT          NULL,
    criado_em    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_marcador (marcador_id),
    CONSTRAINT fk_kbc_marc  FOREIGN KEY (marcador_id) REFERENCES kb_marcadores(id) ON DELETE SET NULL,
    CONSTRAINT fk_kbc_fonte FOREIGN KEY (fonte_id)    REFERENCES kb_fontes(id)     ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------
-- 4) Vetores (embeddings). ref_tipo+ref_id apontam para marcador ou chunk.
--    embedding = JSON de floats JÁ NORMALIZADO (cosseno vira produto escalar).
-- ---------------------------------------------------------------
CREATE TABLE IF NOT EXISTS kb_vetores (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    ref_tipo    ENUM('marcador','chunk') NOT NULL,
    ref_id      INT          NOT NULL,
    modelo      VARCHAR(60)  NOT NULL,          -- "voyage-3-large"
    dims        SMALLINT UNSIGNED NOT NULL,     -- 1024
    embedding   MEDIUMTEXT   NOT NULL,          -- JSON: [0.01,-0.2,...] normalizado
    criado_em   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_ref (ref_tipo, ref_id, modelo),
    INDEX idx_reftipo (ref_tipo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------
-- 5) Migração: permite registrar consultas "o que é este exame?"
--    no histórico de exames (analisar.php, tipo=explicar).
--    Rode DEPOIS de sql/schema.sql (que cria a tabela exames).
-- ---------------------------------------------------------------
ALTER TABLE exames MODIFY tipo ENUM('exame','sintomas','explicar') NOT NULL;
