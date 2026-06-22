<?php
// ReadMyLabs — criptografia em repouso (AES-256-GCM).
// Único ponto que conhece o algoritmo; o resto do app chama criptoEncriptar/criptoDecriptar.
// Chave em RML_ENC_KEY (.env) — base64 de 32 bytes. Gerar com criptoGerarChaveBase64().
//
// Modelo de ameaça coberto:
//   - dump SQL vazado em isolado (phpMyAdmin invadido, backup só do banco) -> NÃO lê
//   - banco lido por alguém com acesso só ao MySQL -> NÃO lê
// Modelo de ameaça NÃO coberto:
//   - servidor comprometido por completo (atacante lê .env) -> lê tudo
// Para zero-knowledge real seria preciso derivar chave da senha do usuário (e perder
// senha = perder histórico). Trade-off documentado no CLAUDE.md.

declare(strict_types=1);

const RML_CRYPTO_ALG    = 'aes-256-gcm';
const RML_CRYPTO_KEYLEN = 32; // 256 bits
const RML_CRYPTO_IVLEN  = 12; // 96 bits recomendado p/ GCM
const RML_CRYPTO_TAGLEN = 16; // 128 bits

/** Lê e valida a chave do .env. Lança RuntimeException se ausente/inválida. */
function criptoChave(): string {
    static $cache = null;
    if ($cache !== null) return $cache;

    $b64 = getenv('RML_ENC_KEY') ?: '';
    if ($b64 === '') {
        throw new RuntimeException('RML_ENC_KEY ausente no .env.');
    }
    $key = base64_decode($b64, true);
    if ($key === false || strlen($key) !== RML_CRYPTO_KEYLEN) {
        throw new RuntimeException('RML_ENC_KEY inválida (esperado base64 de 32 bytes).');
    }
    return $cache = $key;
}

/** True se a chave está configurada e válida (uso para falha-para-desligado). */
function criptoHabilitado(): bool {
    try { criptoChave(); return true; } catch (\Throwable $e) { return false; }
}

/**
 * Encripta texto com AES-256-GCM. Retorna ['ciphertext','iv','tag'] em binário.
 * Sempre IV novo por mensagem (random_bytes, criptograficamente seguro).
 */
function criptoEncriptar(string $plaintext): array {
    $key = criptoChave();
    $iv  = random_bytes(RML_CRYPTO_IVLEN);
    $tag = '';
    $ct  = openssl_encrypt(
        $plaintext, RML_CRYPTO_ALG, $key,
        OPENSSL_RAW_DATA, $iv, $tag, '', RML_CRYPTO_TAGLEN
    );
    if ($ct === false) {
        throw new RuntimeException('Falha ao encriptar: ' . openssl_error_string());
    }
    return ['ciphertext' => $ct, 'iv' => $iv, 'tag' => $tag];
}

/**
 * Decripta. Retorna o plaintext ou null se a tag falhar (integridade quebrada).
 * Não lança — null sinaliza "não confie nessa linha".
 */
function criptoDecriptar(string $ciphertext, string $iv, string $tag): ?string {
    try {
        $key = criptoChave();
    } catch (\Throwable $e) {
        return null;
    }
    if (strlen($iv) !== RML_CRYPTO_IVLEN || strlen($tag) !== RML_CRYPTO_TAGLEN) {
        return null;
    }
    $pt = openssl_decrypt(
        $ciphertext, RML_CRYPTO_ALG, $key,
        OPENSSL_RAW_DATA, $iv, $tag
    );
    return $pt === false ? null : $pt;
}

/** Helper one-off para gerar uma chave nova (CLI). */
function criptoGerarChaveBase64(): string {
    return base64_encode(random_bytes(RML_CRYPTO_KEYLEN));
}
