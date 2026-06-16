<?php
// ReadMyLabs — cadastro, login, throttling e helpers de usuário.
// Throttling: 5 falhas em 15min por email_norm OU por ip_hash -> bloqueia.

require_once __DIR__ . '/../../db.php';

const RML_AUTH_THROTTLE_JANELA = 900;   // 15min
const RML_AUTH_THROTTLE_MAX    = 5;

function normalizarEmail(string $e): string {
    return strtolower(trim($e));
}

function validarEmail(string $e): bool {
    return (bool) filter_var($e, FILTER_VALIDATE_EMAIL) && strlen($e) <= 190;
}

function validarSenha(string $s, ?string &$erro = null): bool {
    if (strlen($s) < 8)   { $erro = 'A senha precisa ter pelo menos 8 caracteres.';      return false; }
    if (strlen($s) > 200) { $erro = 'Senha muito longa.';                                return false; }
    if (!preg_match('/[a-zA-Z]/', $s) || !preg_match('/\d/', $s)) {
        $erro = 'Use letras e números na senha.'; return false;
    }
    return true;
}

function usuarioPorEmail(string $email): ?array {
    $stmt = db()->prepare('SELECT * FROM usuarios WHERE email_norm = ? LIMIT 1');
    $stmt->execute([normalizarEmail($email)]);
    $r = $stmt->fetch();
    return $r ?: null;
}

function usuarioPorId(int $id): ?array {
    $stmt = db()->prepare('SELECT * FROM usuarios WHERE id = ? LIMIT 1');
    $stmt->execute([$id]);
    $r = $stmt->fetch();
    return $r ?: null;
}

/**
 * Cria usuário. Retorna id do usuário criado; lança RuntimeException com mensagem amigável em caso de erro.
 * Se `$exigeVerificacao` for false (sem SMTP configurado), marca email_verificado=1.
 */
function criarUsuario(string $email, string $senha, ?string $nome, bool $exigeVerificacao, bool $aceitouTermos): int {
    if (!validarEmail($email))                       throw new RuntimeException('E-mail inválido.');
    if (!$aceitouTermos)                             throw new RuntimeException('É necessário aceitar os termos.');
    $erroSenha = null;
    if (!validarSenha($senha, $erroSenha))           throw new RuntimeException($erroSenha);

    $emailNorm = normalizarEmail($email);
    if (usuarioPorEmail($emailNorm))                 throw new RuntimeException('Já existe uma conta com este e-mail. Entre ou recupere a senha.');

    $hash  = password_hash($senha, PASSWORD_DEFAULT);
    $ipH   = hash('sha256', $_SERVER['REMOTE_ADDR'] ?? '');
    $nomeN = $nome !== null ? mb_substr(trim($nome), 0, 120) : null;
    $verif = $exigeVerificacao ? 0 : 1;

    $db = db();
    $db->prepare(
        'INSERT INTO usuarios (email, email_norm, senha_hash, nome, email_verificado, ip_hash_cadastro, aceitou_termos_em)
         VALUES (?, ?, ?, ?, ?, ?, NOW())'
    )->execute([$email, $emailNorm, $hash, $nomeN, $verif, $ipH]);

    return (int) $db->lastInsertId();
}

function loginThrottlado(string $email): bool {
    $en  = normalizarEmail($email);
    $ipH = hash('sha256', $_SERVER['REMOTE_ADDR'] ?? '');
    $sql = 'SELECT COUNT(*) FROM auth_tentativas
            WHERE sucesso = 0 AND criada_em > (NOW() - INTERVAL ? SECOND)
              AND (email_norm = ? OR ip_hash = ?)';
    $stmt = db()->prepare($sql);
    $stmt->execute([RML_AUTH_THROTTLE_JANELA, $en, $ipH]);
    return ((int) $stmt->fetchColumn()) >= RML_AUTH_THROTTLE_MAX;
}

function registrarTentativa(string $email, bool $sucesso): void {
    $ipH = hash('sha256', $_SERVER['REMOTE_ADDR'] ?? '');
    db()->prepare('INSERT INTO auth_tentativas (email_norm, ip_hash, sucesso) VALUES (?, ?, ?)')
        ->execute([normalizarEmail($email), $ipH, $sucesso ? 1 : 0]);
}

/**
 * Verifica e-mail+senha. Retorna usuário (array) ou null.
 * Sempre registra a tentativa. Caller deve checar `loginThrottlado()` ANTES.
 */
function validarLogin(string $email, string $senha): ?array {
    $u = usuarioPorEmail($email);
    if (!$u || !$u['senha_hash'] || !password_verify($senha, $u['senha_hash'])) {
        registrarTentativa($email, false);
        return null;
    }
    if (password_needs_rehash($u['senha_hash'], PASSWORD_DEFAULT)) {
        db()->prepare('UPDATE usuarios SET senha_hash = ? WHERE id = ?')
            ->execute([password_hash($senha, PASSWORD_DEFAULT), $u['id']]);
    }
    registrarTentativa($email, true);
    return $u;
}

function setSenhaUsuario(int $usuarioId, string $novaSenha): void {
    $erro = null;
    if (!validarSenha($novaSenha, $erro)) throw new RuntimeException($erro);
    db()->prepare('UPDATE usuarios SET senha_hash = ? WHERE id = ?')
        ->execute([password_hash($novaSenha, PASSWORD_DEFAULT), $usuarioId]);
    // Invalida todas as sessões existentes — segurança após troca de senha.
    db()->prepare('DELETE FROM sessoes WHERE usuario_id = ?')->execute([$usuarioId]);
}
