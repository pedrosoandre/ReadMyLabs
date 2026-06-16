<?php
// ReadMyLabs — confirma e-mail via token. Loga o usuário automaticamente em sucesso.

require_once __DIR__ . '/auth/lib/sessao.php';
require_once __DIR__ . '/auth/lib/tokens.php';
require_once __DIR__ . '/auth/lib/layout.php';

$tok = (string) ($_GET['t'] ?? '');
$uid = consumirTokenVerificacao($tok);

if (!$uid) {
    authRenderTopo('Confirmação', 'Link inválido ou já utilizado. Se já confirmou, basta entrar.', 'erro');
    echo '<h1>Confirmação</h1>';
    echo '<div class="auth-foot"><a href="/entrar.php">Ir para o login</a></div>';
    authRenderRodape();
    exit;
}

iniciarSessaoUsuario($uid);
header('Location: /conta.php?confirmado=1');
exit;
