<?php
require_once __DIR__ . '/loads_env.php';
loadEnv();

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

ini_set('display_errors', 0);
ini_set('log_errors', 1);

$anthropicKey = getenv('ANTHROPIC_API_KEY');
if (!$anthropicKey) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Configuração do servidor incompleta.']);
    exit;
}

function consultarPrecosClaude(array $medicamentos): ?array {
    global $anthropicKey;

    $lista = json_encode($medicamentos, JSON_UNESCAPED_UNICODE);

    $prompt = "Atualize os preços dos seguintes medicamentos para o mercado brasileiro (2024-2025).
Retorne APENAS um JSON válido, sem texto adicional, no formato:
{\"medicamentos\":[{\"nome\":\"...\",\"tipo\":\"referencia|similar|generico\",\"preco_anterior\":\"XX,XX\",\"preco_atual\":\"XX,XX\",\"variacao\":\"+/-X%\"}]}

Medicamentos: $lista";

    $payload = [
        'model'      => 'claude-haiku-4-5-20251001',
        'max_tokens' => 1000,
        'messages'   => [['role' => 'user', 'content' => $prompt]],
        'system'     => 'Você é especialista em preços de medicamentos no Brasil. Responda APENAS com JSON válido, sem texto adicional.',
    ];

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => 'https://api.anthropic.com/v1/messages',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'x-api-key: ' . $anthropicKey,
            'anthropic-version: 2023-06-01',
        ],
        CURLOPT_POSTFIELDS     => json_encode($payload),
    ]);

    $resposta = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200 || !$resposta) {
        return null;
    }

    $dados   = json_decode($resposta, true);
    $conteudo = $dados['content'][0]['text'] ?? '';

    if (preg_match('/\{.*\}/s', $conteudo, $matches)) {
        $json = json_decode($matches[0], true);
        if ($json && isset($json['medicamentos'])) {
            return $json['medicamentos'];
        }
    }

    return null;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Método não permitido.']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!isset($input['medicamentos']) || !is_array($input['medicamentos'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Dados inválidos.']);
    exit;
}

// Sanitizar lista de medicamentos
$medicamentos = array_map(function ($med) {
    return [
        'nome' => strip_tags(trim($med['nome'] ?? '')),
        'tipo' => in_array($med['tipo'] ?? '', ['referencia', 'similar', 'generico']) ? $med['tipo'] : 'generico',
        'preco' => $med['preco'] ?? '',
    ];
}, $input['medicamentos']);

$precosAtualizados = consultarPrecosClaude($medicamentos);

if ($precosAtualizados) {
    echo json_encode([
        'success'      => true,
        'medicamentos' => $precosAtualizados,
        'timestamp'    => date('Y-m-d H:i:s'),
    ]);
} else {
    echo json_encode([
        'success'      => false,
        'error'        => 'Não foi possível atualizar os preços.',
        'medicamentos' => $medicamentos,
    ]);
}
