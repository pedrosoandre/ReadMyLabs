<?php
// ReadMyLabs — token CSRF por sessão PHP.
// Não depende de banco. Carregue ANTES de iniciar uma resposta HTML/POST.

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
    $atual = $_SESSION['csrf_token'] ?? '';
    return $atual !== '' && hash_equals($atual, $enviado);
}

function csrfCampoOculto(): string {
    return '<input type="hidden" name="csrf" value="' . htmlspecialchars(csrfTokenAtual(), ENT_QUOTES) . '">';
}
