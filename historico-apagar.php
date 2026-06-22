<?php
// ReadMyLabs — apaga 1 análise (ou todas) do histórico do usuário logado.
// POST: csrf=<token>, id=<int> OR tudo=1.

declare(strict_types=1);

require_once __DIR__ . '/loads_env.php';
loadEnv();

require_once __DIR__ . '/auth/lib/sessao.php';
require_once __DIR__ . '/auth/lib/csrf.php';
require_once __DIR__ . '/auth/lib/exames_repo.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

function jr(array $d, int $code = 200): void {
    http_response_code($code);
    echo json_encode($d, JSON_UNESCAPED_UNICODE);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') jr(['ok' => false, 'erro' => 'Método não permitido.'], 405);

$u = sessaoAtual();
if (!$u)                           jr(['ok' => false, 'erro' => 'Sessão inválida.'], 401);
if (empty($u['email_verificado'])) jr(['ok' => false, 'erro' => 'Confirme seu e-mail.'], 403);

if (!csrfValidar($_POST['csrf'] ?? null)) jr(['ok' => false, 'erro' => 'CSRF inválido.'], 403);

if (!empty($_POST['tudo'])) {
    $n = exameApagarTudo((int) $u['id']);
    jr(['ok' => true, 'apagados' => $n]);
}

$id = (int) ($_POST['id'] ?? 0);
$ok = exameApagar((int) $u['id'], $id);
jr(['ok' => $ok, 'apagados' => $ok ? 1 : 0]);
