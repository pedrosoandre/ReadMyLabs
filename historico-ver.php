<?php
// ReadMyLabs — abre 1 análise do histórico do usuário logado.
// POST JSON-like: id=<int>, csrf=<token>. Retorna JSON com a análise decriptada.
// IDs NUNCA aparecem em URL pública (POST + CSRF + ownership server-side).

declare(strict_types=1);

require_once __DIR__ . '/loads_env.php';
loadEnv();

require_once __DIR__ . '/auth/lib/sessao.php';
require_once __DIR__ . '/auth/lib/csrf.php';
require_once __DIR__ . '/auth/lib/exames_repo.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');
header('Referrer-Policy: strict-origin-when-cross-origin');

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

$id = (int) ($_POST['id'] ?? 0);
$dados = exameAbrir((int) $u['id'], $id);
if (!$dados) jr(['ok' => false, 'erro' => 'Análise não encontrada.'], 404);

jr(['ok' => true, 'analise' => $dados]);
