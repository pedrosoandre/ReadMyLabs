-- ReadMyLabs F4 — histórico criptografado por usuário (LGPD-aware).
-- Aplicar uma vez por ambiente via auth/aplicar_schema_f4.php (PDO).
-- Idempotente: usa IF NOT EXISTS em colunas/índices/constraints (MariaDB 10.x).
--
-- Modelo:
--   - exames.usuario_id NULL  => anônimo (mesmo comportamento de hoje, zero persistência)
--   - exames.usuario_id NOT NULL => histórico do usuário, com payload encriptado (AES-256-GCM)
--   - ON DELETE CASCADE: apagar conta apaga histórico junto (LGPD art. 18, direito à eliminação)

SET NAMES utf8mb4;

ALTER TABLE exames ADD COLUMN IF NOT EXISTS usuario_id      BIGINT       NULL AFTER id;
ALTER TABLE exames ADD COLUMN IF NOT EXISTS marcadores_enc  MEDIUMBLOB   NULL;
ALTER TABLE exames ADD COLUMN IF NOT EXISTS resultado_enc   MEDIUMBLOB   NULL;
ALTER TABLE exames ADD COLUMN IF NOT EXISTS enc_iv          VARBINARY(12) NULL;
ALTER TABLE exames ADD COLUMN IF NOT EXISTS enc_tag         VARBINARY(16) NULL;
ALTER TABLE exames ADD COLUMN IF NOT EXISTS titulo          VARCHAR(180)  NULL;
ALTER TABLE exames ADD COLUMN IF NOT EXISTS resumo_publico  VARCHAR(255)  NULL;

ALTER TABLE exames ADD INDEX IF NOT EXISTS idx_usuario_criado (usuario_id, criado_em);

ALTER TABLE exames ADD CONSTRAINT IF NOT EXISTS fk_exames_usuario
    FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE;
