-- ReadMyLabs — extensão do RAG para domínio de radiologia.
-- Roda DEPOIS de schema_rag.sql. ALTER aditivo + idempotente.
--
-- A coluna `dominio` separa marcadores/chunks laboratoriais dos radiológicos.
-- Default 'lab' preserva todos os registros existentes — nada quebra.
--
-- Aplicar (CLI no servidor, igual ao schema_rag.sql):
--   mysql -u USER -p DBNAME < rag/schema_rag_radio.sql
-- Ou via PDO helper (Hostinger CLI MySQL é recusada — usar o mesmo padrão
-- de aplicador PHP one-off de auth/aplicar_schema_f4.php se preferir).

SET NAMES utf8mb4;

ALTER TABLE kb_marcadores
  ADD COLUMN IF NOT EXISTS dominio ENUM('lab','radio') NOT NULL DEFAULT 'lab' AFTER categoria,
  ADD INDEX IF NOT EXISTS idx_dominio (dominio);

ALTER TABLE kb_chunks
  ADD COLUMN IF NOT EXISTS dominio ENUM('lab','radio') NOT NULL DEFAULT 'lab' AFTER fonte_id,
  ADD INDEX IF NOT EXISTS idx_chunks_dominio (dominio);
