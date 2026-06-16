<?php
// ReadMyLabs — endpoint pequeno para o front saber se o usuário está logado.
// Usado pelo nav do index.html (renderiza "Entrar" vs "Minha conta / Sair").

require_once __DIR__ . '/auth/lib/sessao.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

$u = sessaoAtual();
if (!$u) {
    echo json_encode(['logged_in' => false]);
    exit;
}
echo json_encode([
    'logged_in'        => true,
    'nome'             => $u['nome'] ?: explode('@', $u['email'])[0],
    'email'            => $u['email'],
    'plano'            => $u['plano'],
    'email_verificado' => $u['email_verificado'],
]);
