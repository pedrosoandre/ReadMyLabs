<?php
// ReadMyLabs — apaga a conta do usuário logado (direito ao apagamento LGPD art. 18).
// CASCADE da FK em `exames.usuario_id` apaga o histórico junto.
// Exige: sessão, CSRF, confirmação (campo "confirmar" precisa ser exatamente o e-mail).
// Encerra TODAS as sessões do usuário (não só a atual) antes do DELETE.

declare(strict_types=1);

require_once __DIR__ . '/loads_env.php';
loadEnv();

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth/lib/sessao.php';
require_once __DIR__ . '/auth/lib/csrf.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Location: /conta.php'); exit;
}

$u = sessaoAtual();
if (!$u) { header('Location: /entrar.php'); exit; }

if (!csrfValidar($_POST['csrf'] ?? null)) {
    header('Location: /conta.php?erro=csrf'); exit;
}

// Confirmação dupla: o usuário precisa digitar o próprio e-mail.
$digitado = trim((string) ($_POST['confirmar'] ?? ''));
if (strcasecmp($digitado, (string) $u['email']) !== 0) {
    header('Location: /conta.php?erro=confirmacao'); exit;
}

$db = db();
try {
    $db->beginTransaction();
    // Apaga todas as sessões do usuário (revoga em todos os dispositivos).
    $db->prepare('DELETE FROM sessoes WHERE usuario_id = ?')->execute([(int) $u['id']]);
    // FK CASCADE: exames, usuario_uso_diario, verificacao_emails, recuperacoes_senha vão junto.
    $db->prepare('DELETE FROM usuarios WHERE id = ?')->execute([(int) $u['id']]);
    $db->commit();
} catch (\Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    error_log('apagar-conta.php: falha — ' . $e->getMessage());
    header('Location: /conta.php?erro=interno'); exit;
}

// Limpa o cookie e a sessão PHP local.
setcookie(RML_AUTH_COOKIE, '', authCookieOpts(time() - 3600));
if (session_status() === PHP_SESSION_ACTIVE) {
    $_SESSION = [];
    session_destroy();
}

header('Location: /?conta_apagada=1');
