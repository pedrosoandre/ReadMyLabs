<?php
// ReadMyLabs — token CSRF por sessão PHP.
// Não depende de banco. Carregue ANTES de iniciar uma resposta HTML/POST.

// Side-effect: garante que a sessão PHP existe ANTES de qualquer output.
// Sem isto, o Set-Cookie: PHPSESSID nunca é enviado (headers já saíram),
// a sessão do GET não persiste até o POST e o CSRF parece "expirar" sempre.
if (session_status() !== PHP_SESSION_ACTIVE && !headers_sent()) {
    $emHttps = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => $emHttps,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

function csrfTokenAtual(): string {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrfValidar(?string $enviado): bool {
    if (!$enviado) return false;
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    $atual = $_SESSION['csrf_token'] ?? '';
    return $atual !== '' && hash_equals($atual, $enviado);
}

function csrfCampoOculto(): string {
    return '<input type="hidden" name="csrf" value="' . htmlspecialchars(csrfTokenAtual(), ENT_QUOTES) . '">';
}
