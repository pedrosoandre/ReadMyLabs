-- ReadMyLabs — esquema do banco (MySQL / MariaDB)
-- Compatível com hospedagem compartilhada Hostinger (MariaDB 10.x).
-- Charset utf8mb4 para acentuação e símbolos médicos.

SET NAMES utf8mb4;
SET time_zone = '+00:00';

-- ---------------------------------------------------------------
-- 1) Valores de referência dos marcadores laboratoriais
--    O PHP usa esta tabela para classificar valores LOCALMENTE,
--    sem gastar token do Claude.
-- ---------------------------------------------------------------
CREATE TABLE IF NOT EXISTS marcadores_referencia (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    nome          VARCHAR(120)  NOT NULL,            -- "Hemoglobina"
    sinonimos     TEXT          NULL,                -- "Hb|Hemoglobina total" (separado por |)
    categoria     VARCHAR(60)   NOT NULL,            -- "Hemograma", "Lipídico", ...
    unidade       VARCHAR(30)   NOT NULL,            -- "g/dL"
    sexo          ENUM('M','F','ambos') NOT NULL DEFAULT 'ambos',
    idade_min     TINYINT UNSIGNED NULL,             -- NULL = sem limite inferior
    idade_max     TINYINT UNSIGNED NULL,             -- NULL = sem limite superior
    ref_min       DECIMAL(12,3) NULL,                -- NULL = só limite superior (ex.: LDL)
    ref_max       DECIMAL(12,3) NULL,                -- NULL = só limite inferior
    descricao     VARCHAR(255)  NULL,                -- texto curto p/ o usuário leigo
    INDEX idx_nome (nome),
    INDEX idx_categoria (categoria)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------
-- 2) Cache de explicações geradas pelo Claude
--    Chave = hash(marcador + status + sexo + faixa_etaria).
--    Hit = resposta instantânea, ZERO token.
-- ---------------------------------------------------------------
CREATE TABLE IF NOT EXISTS cache_explicacoes (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    chave         CHAR(64)     NOT NULL,             -- sha256 do contexto
    marcador      VARCHAR(120) NOT NULL,
    status        ENUM('baixo','normal','alto','critico') NOT NULL,
    sexo          ENUM('M','F','ambos') NOT NULL DEFAULT 'ambos',
    faixa_etaria  VARCHAR(20)  NULL,                 -- ex.: "30-39"
    explicacao    TEXT         NOT NULL,
    usos          INT UNSIGNED NOT NULL DEFAULT 1,   -- contador de reaproveitamento
    criado_em     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizado_em TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_chave (chave),
    INDEX idx_marcador (marcador)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------
-- 3) Histórico de exames analisados
--    usuario_id é opcional por enquanto (sem login); guardamos o
--    hash do IP para rate limiting / auditoria, nunca o IP cru.
-- ---------------------------------------------------------------
CREATE TABLE IF NOT EXISTS exames (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    usuario_id    INT          NULL,
    ip_hash       CHAR(64)     NULL,                 -- sha256 do IP
    tipo          ENUM('exame','sintomas') NOT NULL,
    arquivo_nome  VARCHAR(255) NULL,
    status        ENUM('processando','concluido','erro') NOT NULL DEFAULT 'processando',
    marcadores    LONGTEXT     NULL,                 -- JSON dos marcadores classificados
    resultado     LONGTEXT     NULL,                 -- laudo final montado
    tokens_in     INT UNSIGNED NULL,                 -- telemetria de custo
    tokens_out    INT UNSIGNED NULL,
    cache_hits    SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    criado_em     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_ip_hash (ip_hash),
    INDEX idx_criado (criado_em)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
