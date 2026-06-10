<?php
// Erros só em log, nunca expostos ao cliente
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(E_ALL);
ini_set('log_errors', 1);

require_once __DIR__ . '/loads_env.php';
loadEnv();

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: strict-origin-when-cross-origin');

require __DIR__ . '/vendor/autoload.php';

use Smalot\PdfParser\Parser;

$secretKey    = getenv('RECAPTCHA_SECRET');
$anthropicKey = getenv('ANTHROPIC_API_KEY');

if (!$secretKey || !$anthropicKey) {
    http_response_code(500);
    echo json_encode(['resposta' => '❌ Configuração do servidor incompleta.']);
    exit;
}

// Rate limiting por IP — sem confiar em headers forjáveis
$ip_usuario = $_SERVER['REMOTE_ADDR'];
$data_hoje  = date('Y-m-d');
$limite_dir = __DIR__ . '/limite_ip';
if (!is_dir($limite_dir)) {
    mkdir($limite_dir, 0700, true);
}
$limite_arquivo = "$limite_dir/" . hash('sha256', $ip_usuario) . ".txt";
$limite_max     = 1;
$dados_limite   = ['data' => $data_hoje, 'contagem' => 0];

if (file_exists($limite_arquivo)) {
    $raw  = file_get_contents($limite_arquivo);
    $dados = json_decode($raw, true);
    if ($dados && $dados['data'] === $data_hoje) {
        $dados_limite = $dados;
    }
}

if ($dados_limite['data'] === $data_hoje && $dados_limite['contagem'] >= $limite_max) {
    echo json_encode([
        'resposta'        => 'Você atingiu o limite de 1 análise para hoje. Tente novamente amanhã.',
        'limite_atingido' => true,
        'tipo'            => 'limite_diario',
    ]);
    exit;
}

$dados_limite['data'] = $data_hoje;
$dados_limite['contagem']++;
file_put_contents($limite_arquivo, json_encode($dados_limite), LOCK_EX);

// ------------------------------------------------------------------

function verificarRecaptcha(string $token, string $secretKey): bool {
    $ch = curl_init('https://www.google.com/recaptcha/api/siteverify');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query(['secret' => $secretKey, 'response' => $token]),
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);
    $result = curl_exec($ch);
    $err    = curl_error($ch);
    curl_close($ch);

    if ($err || !$result) {
        return false;
    }
    $json = json_decode($result, true);
    return !empty($json['success']);
}

function sanitizarInput(string $texto, int $maxLen = 4000): string {
    $texto = strip_tags(trim($texto));
    if (mb_strlen($texto) > $maxLen) {
        $texto = mb_substr($texto, 0, $maxLen);
    }
    return $texto;
}

function chamarClaude(string $prompt, string $systemPrompt = '', string $model = 'claude-opus-4-7', int $maxTokens = 3000): string {
    global $anthropicKey;

    $messages = [['role' => 'user', 'content' => $prompt]];

    $payload = [
        'model'      => $model,
        'max_tokens' => $maxTokens,
        'messages'   => $messages,
    ];
    if ($systemPrompt !== '') {
        $payload['system'] = $systemPrompt;
    }

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => 'https://api.anthropic.com/v1/messages',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_TIMEOUT        => 90,
        CURLOPT_CONNECTTIMEOUT => 15,
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
    $erroCurl = curl_error($ch);
    curl_close($ch);

    if ($erroCurl) {
        error_log('Claude API cURL error: ' . $erroCurl);
        return '❌ Erro de conexão com a API.';
    }

    if ($httpCode !== 200) {
        $erroJson = json_decode($resposta, true);
        $detalhe  = $erroJson['error']['message'] ?? '';
        error_log('Claude API HTTP ' . $httpCode . ': ' . $detalhe);
        return '❌ Erro ao comunicar com a IA. Código: ' . $httpCode . ($detalhe ? ' — ' . $detalhe : '');
    }

    $resultado = json_decode($resposta, true);
    $conteudo  = $resultado['content'][0]['text'] ?? null;
    if ($conteudo === null) {
        error_log('Claude API resposta inesperada: ' . substr($resposta, 0, 300));
        return '❌ Não foi possível interpretar a resposta da IA.';
    }

    return $conteudo;
}

function extrairInformacoesLaboratorio(string $texto): array {
    $info = [
        'laboratorio'        => '',
        'endereco'           => '',
        'telefone'           => '',
        'data_coleta'        => '',
        'data_liberacao'     => '',
        'medico_responsavel' => '',
        'crm'                => '',
    ];

    $padroes = [
        'laboratorio'        => ['/laborat[oó]rio\s*:?\s*([^\n\r]+)/i', '/cl[ií]nica\s*:?\s*([^\n\r]+)/i'],
        'endereco'           => ['/endere[cç]o\s*:?\s*([^\n\r]+)/i', '/rua\s+([^\n\r]+)/i'],
        'telefone'           => ['/telefone\s*:?\s*([\d\s\-\(\)]+)/i', '/(\(\d{2}\)\s*\d{4,5}\-\d{4})/'],
        'data_coleta'        => ['/data\s+da\s+coleta\s*:?\s*(\d{1,2}[\/\-]\d{1,2}[\/\-]\d{2,4})/i', '/coleta\s*:?\s*(\d{1,2}[\/\-]\d{1,2}[\/\-]\d{2,4})/i'],
        'data_liberacao'     => ['/data\s+da\s+libera[cç][aã]o\s*:?\s*(\d{1,2}[\/\-]\d{1,2}[\/\-]\d{2,4})/i'],
        'medico_responsavel' => ['/m[eé]dico\s+respons[aá]vel\s*:?\s*([^\n\r]+)/i', '/dr\.?\s+([^\n\r]+)/i'],
        'crm'                => ['/crm\s*:?\s*(\d+)/i'],
    ];

    foreach ($padroes as $tipo => $listaPadroes) {
        foreach ($listaPadroes as $padrao) {
            if (preg_match($padrao, $texto, $matches)) {
                $info[$tipo] = trim($matches[1]);
                break;
            }
        }
    }

    $laboratoriosConhecidos = ['fleury', 'delboni', 'einstein', 'sírio libanês', 'hermes pardini', 'sabin', 'richet'];
    foreach ($laboratoriosConhecidos as $lab) {
        if (empty($info['laboratorio']) && stripos($texto, $lab) !== false) {
            if (preg_match('/(' . preg_quote($lab, '/') . '[^\n\r]*)/i', $texto, $matches)) {
                $info['laboratorio'] = trim($matches[1]);
            }
        }
    }

    return $info;
}

function extrairTextoPDFAgressivo(string $arquivoTmp): string {
    try {
        $parser = new Parser();
        $pdf    = $parser->parseFile($arquivoTmp);
        $texto  = $pdf->getText();
        if (!empty(trim($texto))) {
            return $texto;
        }
    } catch (Exception $e) {
        error_log('PDFParser falhou: ' . $e->getMessage());
    }

    if (function_exists('shell_exec')) {
        $tmp   = tempnam(sys_get_temp_dir(), 'pdf');
        $saida = shell_exec('pdftotext ' . escapeshellarg($arquivoTmp) . ' ' . escapeshellarg($tmp) . ' 2>/dev/null && cat ' . escapeshellarg($tmp));
        @unlink($tmp);
        if (!empty(trim((string) $saida))) {
            return $saida;
        }
    }

    return '❌ Não foi possível extrair texto do PDF. Verifique se o arquivo não está corrompido ou protegido.';
}

function processarPDFGrande(string $conteudo): string {
    $linhas            = explode("\n", $conteudo);
    $linhasProcessadas = [];
    $contador          = 0;

    foreach ($linhas as $linha) {
        $linha = trim($linha);
        if (preg_match('/(nome|data|idade|sexo|resultado|valor|referência|unidade|laboratório|médico|crm|conclusão|diagnóstico)/i', $linha)
            || preg_match('/\d+[.,]\d+|\d+%|\d+\/\d+|\d+mg|\d+ml/i', $linha)
            || preg_match('/(hemograma|glicemia|colesterol|triglicerídeos|creatinina|ureia|tsh|hemoglobina|leucócitos|plaquetas)/i', $linha)
        ) {
            $linhasProcessadas[] = $linha;
            $contador++;
        }
        if ($contador >= 80) {
            break;
        }
    }

    $conteudoProcessado = implode("\n", $linhasProcessadas);
    if (strlen($conteudoProcessado) > 6000) {
        $conteudoProcessado = substr($conteudoProcessado, 0, 6000) . "\n\n[Conteúdo processado e truncado para análise...]";
    }
    return $conteudoProcessado;
}

// ------------------------------------------------------------------
// Roteamento principal
// ------------------------------------------------------------------

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['resposta' => '❌ Método não permitido.']);
    exit;
}

$tipo = $_POST['tipo'] ?? '';

if (!in_array($tipo, ['sintomas', 'exame'], true)) {
    http_response_code(400);
    echo json_encode(['resposta' => '❌ Tipo de análise inválido.']);
    exit;
}

$token = $_POST['g-recaptcha-response'] ?? '';
if (!$token) {
    echo json_encode(['resposta' => '❌ Token do reCAPTCHA ausente.']);
    exit;
}
if (!verificarRecaptcha($token, $secretKey)) {
    echo json_encode(['resposta' => '❌ Verificação do reCAPTCHA falhou. Tente novamente.']);
    exit;
}

// ------------------------------------------------------------------

if ($tipo === 'sintomas') {
    $sintomas    = sanitizarInput($_POST['sintomas']    ?? '', 2000);
    $duracao     = sanitizarInput($_POST['duracao']     ?? '', 100);
    $intensidade = sanitizarInput($_POST['intensidade'] ?? '', 100);

    if (!$sintomas) {
        echo json_encode(['resposta' => '❌ Por favor, insira os sintomas.']);
        exit;
    }

    $systemPrompt = 'Você é um médico especialista com mais de 20 anos de experiência em medicina clínica e análise de sintomas. Siga rigorosamente o protocolo médico estabelecido. Forneça análises precisas baseadas em evidências científicas (PubMed, OMS). Use linguagem clara, mas mantenha precisão técnica. Jamais inclua dados pessoais identificáveis; use "o paciente" ou "a pessoa".';

    $prompt = <<<EOT
Analise os seguintes sintomas seguindo o PROTOCOLO MÉDICO ESTABELECIDO:

Sintomas: $sintomas
Duração: $duracao
Intensidade: $intensidade

PROTOCOLO DE ANÁLISE:

1. CLASSIFICAÇÃO DE URGÊNCIA (primeiro item):
   - 🟢 BAIXO RISCO: sintomas leves, sem sinais de alerta
   - 🟡 RISCO MODERADO: sintomas persistentes, atenção médica em 24-48h
   - 🔴 ALTO RISCO: sinais de alerta, atendimento IMEDIATO

2. AVALIAÇÃO INICIAL: localização, qualidade, intensidade, evolução, fatores agravantes/atenuantes

3. ANÁLISE CLÍNICA ESTRUTURADA:
   A. Caracterização dos Sintomas
   B. Diferenciais Diagnósticos (top 3-5)
   C. Sinais de Alerta (Red Flags)
   D. Avaliação de Risco
   E. Recomendações Práticas

4. RESUMO CLÍNICO:
   - 🩺 Sintomas:
   - 📊 Classificação:
   - ❗ Principais diagnósticos:
   - 📌 Ação recomendada:

FORMATAÇÃO: Títulos simples sem asteriscos ou markdown. Esta é orientação educativa, não diagnóstico definitivo.

MEDICAMENTOS SUGERIDOS: Ao final, seção "MEDICAMENTOS SUGERIDOS:" com:
- Para cada condição: 1. Referência - R$ XX,XX  2. Similar - R$ XX,XX  3. Genérico - R$ XX,XX
- Preços realistas mercado brasileiro (2024-2025), organize por categoria terapêutica
EOT;

    $respostaIA = chamarClaude($prompt, $systemPrompt);
    echo json_encode(['resposta' => $respostaIA]);
    exit;
}

// tipo === 'exame'
$conteudoOCR = sanitizarInput($_POST['conteudo_ocr'] ?? '', 8000);
$nomeArquivo = sanitizarInput($_POST['nome_arquivo'] ?? 'exame.pdf', 255);

if (!empty($conteudoOCR)) {
    $conteudoPDF = $conteudoOCR;
} else {
    if (!isset($_FILES['arquivo']) || $_FILES['arquivo']['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(['resposta' => '❌ Erro ao fazer upload do arquivo.']);
        exit;
    }

    $arquivoTmp  = $_FILES['arquivo']['tmp_name'];
    $nomeArquivo = sanitizarInput($_FILES['arquivo']['name'], 255);
    $ext         = strtolower(pathinfo($nomeArquivo, PATHINFO_EXTENSION));

    if ($ext !== 'pdf') {
        echo json_encode(['resposta' => '❌ Apenas arquivos PDF são suportados.']);
        exit;
    }

    if ($_FILES['arquivo']['size'] > 5 * 1024 * 1024) {
        echo json_encode(['resposta' => '❌ Arquivo muito grande. Limite: 5 MB.']);
        exit;
    }

    $conteudoPDF = extrairTextoPDFAgressivo($arquivoTmp);
}

if (empty(trim($conteudoPDF)) || str_starts_with($conteudoPDF, '❌')) {
    echo json_encode(['resposta' => $conteudoPDF ?: '❌ Não foi possível extrair texto do PDF.']);
    exit;
}

$infoLaboratorio = extrairInformacoesLaboratorio($conteudoPDF);
$infoLabStr = '';
foreach (['laboratorio' => 'Laboratório', 'endereco' => 'Endereço', 'telefone' => 'Telefone',
          'data_coleta' => 'Data da Coleta', 'data_liberacao' => 'Data da Liberação',
          'medico_responsavel' => 'Médico Responsável', 'crm' => 'CRM'] as $key => $label) {
    if (!empty($infoLaboratorio[$key])) {
        $infoLabStr .= "$label: {$infoLaboratorio[$key]}\n";
    }
}

if (strlen($conteudoPDF) > 6000) {
    $conteudoPDF = substr($conteudoPDF, 0, 6000) . "\n\n[Conteúdo truncado devido ao tamanho...]";
}
if (strlen($conteudoPDF) > 4000) {
    $conteudoPDF = processarPDFGrande($conteudoPDF);
}

$systemPrompt = 'Você é um médico especialista com mais de 20 anos de experiência em interpretação de exames laboratoriais, de imagem e análise clínica. Forneça análises precisas, responsáveis e baseadas em evidências científicas. Use linguagem clara para o paciente, mas mantenha precisão técnica. Dados pessoais do paciente devem aparecer apenas no cabeçalho da análise.';

$prompt = <<<EOT
Analise o seguinte exame médico seguindo o PROTOCOLO MÉDICO ESTABELECIDO:

INFORMAÇÕES DO LABORATÓRIO:
$infoLabStr

CONTEÚDO DO EXAME:
$conteudoPDF

1. VERIFICAÇÃO INICIAL: identificação, laboratório, datas, tipo de exame

2. INTERPRETAÇÃO CLÍNICA:
   A. Como o exame foi realizado e margem de erro
   B. Comparação com valores de referência: ✅ Normal / ⚠️ Limítrofe / ❌ Alterado
   C. Associação clínica
   D. Implicações diagnósticas
   E. Relevância prática e conduta necessária

3. RESUMO LAUDADO:
   - 🏥 Laboratório:
   - 🧪 Exame:
   - 📈 Resultado:
   - ❗ Conclusão:
   - 📌 Recomendação:

FORMATAÇÃO: Títulos simples sem asteriscos ou markdown. Máximo 1500 palavras. Seja direto e objetivo.
Esta é orientação educativa, não diagnóstico definitivo.

MEDICAMENTOS SUGERIDOS: Ao final, seção "MEDICAMENTOS SUGERIDOS:" com:
- Para cada condição: 1. Referência - R$ XX,XX  2. Similar - R$ XX,XX  3. Genérico - R$ XX,XX
- Preços realistas mercado brasileiro (2024-2025)
EOT;

$respostaIA = chamarClaude($prompt, $systemPrompt);

if (str_contains($respostaIA, 'maximum context length') || str_contains($respostaIA, 'tokens')) {
    $promptReduzido = "Analise este exame de forma concisa:\n\n$conteudoPDF\n\nForneça: 1. Principais achados 2. Interpretação clínica 3. Recomendações. Seja direto e objetivo.";
    $respostaIA = chamarClaude($promptReduzido, $systemPrompt, 'claude-haiku-4-5-20251001', 2000);
}

echo json_encode(['resposta' => $respostaIA]);
