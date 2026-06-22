<?php
// ReadMyLabs — gera uma chave RML_ENC_KEY base64 (32 bytes).
// CLI only. Uso: php auth/gerar_chave.php
//   Copie a saída para o .env: RML_ENC_KEY=<saída>
// ATENÇÃO: trocar a chave depois de já ter histórico encriptado deixa
// o histórico ilegível (a tag GCM falha) — só rotacionar com plano de migração.

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}

require_once __DIR__ . '/../lib/crypto.php';
echo criptoGerarChaveBase64() . "\n";
