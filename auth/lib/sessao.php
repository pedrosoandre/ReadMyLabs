<?php
// ReadMyLabs — sessão de usuário autenticado.
// Cookie HttpOnly+Secure+SameSite=Lax aponta para uma linha em `sessoes`.
// PHP `$_SESSION` cuida só de CSRF / flash; identidade vem do banco.

require_once __DIR__ . '/../../db.php';

const RML_AUTH_COOKIE   = 'rml_sess';
const RML_AUTH_DIAS     = 30;            // duração do cookie
const RML_AUTH_REFRESH  = 86400;         // atualiza ultimo_uso se >1 dia

function authCookieOpts(int $expira): array {
    $emHttps = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    return [
        'expires'  => $expira,
        'path'     => '/',
        'domain'   => '',
        'secure'   => $emHttps,
        'httponly' => true,
        'samesite' => 'Lax',
    ];
}

function iniciarSessaoUsuario(int $usuarioId): string {
    $db   = db();
    $tok  = bin2hex(random_bytes(32));            // 64 hex
    $exp  = (new DateTime('+' . RML_AUTH_DIAS . ' days'))->format('Y-m-d H:i:s');
    $ipH  = hash('sha256', $_SERVER['REMOTE_ADDR'] ?? '');
    $uaH  = hash('sha256', $_SERVER['HTTP_USER_AGENT'] ?? '');

    $db->prepare(
        'INSERT INTO sessoes (token, usuario_id, expira_em, ip_hash, user_agent_hash) VALUES (?,?,?,?,?)'
    )->execute([$tok, $usuarioId, $exp, $ipH, $uaH]);

    setcookie(RML_AUTH_COOKIE, $tok, authCookieOpts(time() + RML_AUTH_DIAS * 86400));
    return $tok;
}

/**
 * Retorna usuário da sessão atual ou null.
 * Lê o cookie; valida no banco; atualiza ultimo_uso se passou o refresh.
 */
function sessaoAtual(): ?array {
    static $cache = false;
    if ($cache !== false) return $cache;

    $tok = $_COOKIE[RML_AUTH_COOKIE] ?? '';
    if (!preg_match('/^[a-f0-9]{64}$/', $tok)) return $cache = null;

    $db = db();
    $stmt = $db->prepare(
        'SELECT s.token, s.usuario_id, s.expira_em, s.ultimo_uso, u.email, u.nome, u.plano, u.email_verificado
         FROM sessoes s JOIN usuarios u ON u.id = s.usuario_id
         WHERE s.token = ? LIMIT 1'
    );
    $stmt->execute([$tok]);
    $row = $stmt->fetch();
    if (!$row) return $cache = null;

    if (strtotime($row['expira_em']) < time()) {
        $db->prepare('DELETE FROM sessoes WHERE token = ?')->execute([$tok]);
        return $cache = null;
    }

    // Refresh suave (no máximo 1x por dia para não martelar o banco)
    if (time() - strtotime($row['ultimo_uso']) > RML_AUTH_REFRESH) {
        $db->prepare('UPDATE sessoes SET ultimo_uso = NOW() WHERE token = ?')->execute([$tok]);
    }

    return $cache = [
        'id'               => (int) $row['usuario_id'],
        'email'            => $row['email'],
        'nome'             => $row['nome'],
        'plano'            => $row['plano'],
        'email_verificado' => (int) $row['email_verificado'] === 1,
    ];
}

function encerrarSessao(): void {
    $tok = $_COOKIE[RML_AUTH_COOKIE] ?? '';
    if ($tok !== '') {
        try { db()->prepare('DELETE FROM sessoes WHERE token = ?')->execute([$tok]); } catch (\Throwable $e) {}
        setcookie(RML_AUTH_COOKIE, '', authCookieOpts(time() - 3600));
    }
}

function exigirLogin(string $destino = '/entrar.php'): array {
    $u = sessaoAtual();
    if (!$u) {
        header('Location: ' . $destino);
        exit;
    }
    return $u;
}
