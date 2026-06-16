-- ReadMyLabs — esquema do subsistema de autenticação (MySQL / MariaDB).
-- Sem extensões: roda em Hostinger compartilhada.
-- Aplicar uma vez por ambiente:
--   mysql -h HOST -u USER -p DB < auth/schema_auth.sql

SET NAMES utf8mb4;
SET time_zone = '+00:00';

-- ---------------------------------------------------------------
-- 1) Usuários
--    `email`     = como o usuário digitou (preserva caixa para UI/e-mails)
--    `email_norm`= chave única real (lowercase + trim) — evita duplicatas
--                  de "Joao@x.com" vs "joao@x.com".
-- ---------------------------------------------------------------
CREATE TABLE IF NOT EXISTS usuarios (
    id                   BIGINT AUTO_INCREMENT PRIMARY KEY,
    email                VARCHAR(190) NOT NULL,
    email_norm           VARCHAR(190) NOT NULL,
    senha_hash           VARCHAR(255) NULL,            -- NULL = sem senha (só OAuth/magic no futuro)
    nome                 VARCHAR(120) NULL,
    email_verificado     TINYINT(1)   NOT NULL DEFAULT 0,
    plano                VARCHAR(40)  NOT NULL DEFAULT 'free',
    aceitou_termos_em    DATETIME     NULL,
    ip_hash_cadastro     CHAR(64)     NULL,
    criado_em            TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizado_em        TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_email_norm (email_norm)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------
-- 2) Sessões (cookie -> linha; expira_em é a verdade)
-- ---------------------------------------------------------------
CREATE TABLE IF NOT EXISTS sessoes (
    token             CHAR(64)     NOT NULL PRIMARY KEY,
    usuario_id        BIGINT       NOT NULL,
    criada_em         TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    ultimo_uso        TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expira_em         DATETIME     NOT NULL,
    ip_hash           CHAR(64)     NULL,
    user_agent_hash   CHAR(64)     NULL,
    KEY idx_usuario (usuario_id),
    KEY idx_expira (expira_em),
    CONSTRAINT fk_sessoes_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------
-- 3) Tokens de verificação de e-mail
-- ---------------------------------------------------------------
CREATE TABLE IF NOT EXISTS verificacao_emails (
    token       CHAR(64)  NOT NULL PRIMARY KEY,
    usuario_id  BIGINT    NOT NULL,
    criado_em   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expira_em   DATETIME  NOT NULL,
    usado_em    DATETIME  NULL,
    KEY idx_usuario (usuario_id),
    CONSTRAINT fk_verif_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------
-- 4) Tokens de recuperação de senha
-- ---------------------------------------------------------------
CREATE TABLE IF NOT EXISTS recuperacoes_senha (
    token       CHAR(64)  NOT NULL PRIMARY KEY,
    usuario_id  BIGINT    NOT NULL,
    criado_em   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expira_em   DATETIME  NOT NULL,
    usado_em    DATETIME  NULL,
    KEY idx_usuario (usuario_id),
    CONSTRAINT fk_rec_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------
-- 5) Tentativas de login (brute force throttling)
--    Linha por tentativa; janelas analisadas em SQL.
-- ---------------------------------------------------------------
CREATE TABLE IF NOT EXISTS auth_tentativas (
    id           BIGINT AUTO_INCREMENT PRIMARY KEY,
    email_norm   VARCHAR(190) NOT NULL,
    ip_hash      CHAR(64)     NOT NULL,
    criada_em    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    sucesso      TINYINT(1)   NOT NULL DEFAULT 0,
    KEY idx_email_data (email_norm, criada_em),
    KEY idx_ip_data (ip_hash, criada_em)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------
-- 6) Uso diário por usuário (substitui o limite por IP quando logado)
--    PK composta evita duplicata por dia.
-- ---------------------------------------------------------------
CREATE TABLE IF NOT EXISTS usuario_uso_diario (
    usuario_id  BIGINT NOT NULL,
    dia         DATE   NOT NULL,
    contagem    INT    NOT NULL DEFAULT 0,
    PRIMARY KEY (usuario_id, dia),
    CONSTRAINT fk_uso_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
