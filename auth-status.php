<?php
// ReadMyLabs — endpoint pequeno para o front saber se o usuário está logado.
// Usado pelo nav do index.html (renderiza "Entrar" vs "Minha conta / Sair").

require_once __DIR__ . '/loads_env.php';
require_once __DIR__ . '/auth/lib/sessao.php';
loadEnv();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

// IP whitelist: mesma var de analisar.php — expõe o flag pro front pular paywall
// quando o IP estiver na lista. Vazio = lista vazia = ninguém whitelisted.
$ipWhitelist    = array_values(array_filter(array_map('trim', explode(',', (string) (getenv('IP_WHITELIST') ?: '')))));
$ipWhitelisted  = $ipWhitelist && in_array($_SERVER['REMOTE_ADDR'] ?? '', $ipWhitelist, true);

$u = sessaoAtual();
if (!$u) {
    echo json_encode(['logged_in' => false, 'ip_whitelisted' => $ipWhitelisted]);
    exit;
}
echo json_encode([
    'logged_in'        => true,
    'nome'             => $u['nome'] ?: explode('@', $u['email'])[0],
    'email'            => $u['email'],
    'plano'            => $u['plano'],
    'email_verificado' => $u['email_verificado'],
    'ip_whitelisted'   => $ipWhitelisted,
]);
