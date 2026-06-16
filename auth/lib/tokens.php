<?php
// ReadMyLabs — tokens descartáveis (verificação de e-mail, recuperação de senha).
// Mesmo padrão: 64 hex, expira em N minutos, uso único.

require_once __DIR__ . '/../../db.php';

const RML_TOKEN_VERIF_MIN = 60 * 24 * 3;  // 3 dias
const RML_TOKEN_REC_MIN   = 30;            // 30 minutos

function emitirTokenVerificacao(int $usuarioId): string {
    $tok = bin2hex(random_bytes(32));
    $exp = (new DateTime('+' . RML_TOKEN_VERIF_MIN . ' minutes'))->format('Y-m-d H:i:s');
    db()->prepare('INSERT INTO verificacao_emails (token, usuario_id, expira_em) VALUES (?, ?, ?)')
        ->execute([$tok, $usuarioId, $exp]);
    return $tok;
}

/** Consome o token de verificação. Retorna o usuario_id confirmado ou null. */
function consumirTokenVerificacao(string $tok): ?int {
    if (!preg_match('/^[a-f0-9]{64}$/', $tok)) return null;
    $db = db();
    $stmt = $db->prepare(
        'SELECT usuario_id FROM verificacao_emails
         WHERE token = ? AND usado_em IS NULL AND expira_em > NOW() LIMIT 1'
    );
    $stmt->execute([$tok]);
    $row = $stmt->fetch();
    if (!$row) return null;
    $uid = (int) $row['usuario_id'];
    $db->prepare('UPDATE verificacao_emails SET usado_em = NOW() WHERE token = ?')->execute([$tok]);
    $db->prepare('UPDATE usuarios SET email_verificado = 1 WHERE id = ?')->execute([$uid]);
    return $uid;
}

function emitirTokenRecuperacao(int $usuarioId): string {
    $tok = bin2hex(random_bytes(32));
    $exp = (new DateTime('+' . RML_TOKEN_REC_MIN . ' minutes'))->format('Y-m-d H:i:s');
    // Limpa tokens não usados anteriores: só vale o último.
    db()->prepare('UPDATE recuperacoes_senha SET usado_em = NOW() WHERE usuario_id = ? AND usado_em IS NULL')
        ->execute([$usuarioId]);
    db()->prepare('INSERT INTO recuperacoes_senha (token, usuario_id, expira_em) VALUES (?, ?, ?)')
        ->execute([$tok, $usuarioId, $exp]);
    return $tok;
}

/** Resolve token de recuperação. Retorna usuario_id ou null. NÃO marca como usado ainda. */
function resolverTokenRecuperacao(string $tok): ?int {
    if (!preg_match('/^[a-f0-9]{64}$/', $tok)) return null;
    $stmt = db()->prepare(
        'SELECT usuario_id FROM recuperacoes_senha
         WHERE token = ? AND usado_em IS NULL AND expira_em > NOW() LIMIT 1'
    );
    $stmt->execute([$tok]);
    $row = $stmt->fetch();
    return $row ? (int) $row['usuario_id'] : null;
}

function marcarTokenRecuperacaoUsado(string $tok): void {
    db()->prepare('UPDATE recuperacoes_senha SET usado_em = NOW() WHERE token = ?')->execute([$tok]);
}
