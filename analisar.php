<?php
// Exibir erros para depuração (remover em produção)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Sempre retornar JSON
header('Content-Type: application/json');

// Carregar o autoload do Composer para usar PDFParser
require 'vendor/autoload.php';

use Smalot\PdfParser\Parser;

$secretKey = '6Lfj2IcrAAAAAIBc_NX6O0jfRMKq54PIY4MWCdwn';
$apiKeyOpenAI = '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';

// Lista de IPs permitidos
$ips_permitidos = [
    '200.100.50.25',   // Exemplo: IP fixo da empresa
    '127.0.0.1',       // Localhost para testes
    '179.102.28.223',  // IP do usuário
    // Adicione outros IPs conforme necessário
];

// Controle de limite de 1 análise por IP por dia
$ip_usuario = $_SERVER['REMOTE_ADDR'];
$data_hoje = date('Y-m-d');
$limite_dir = __DIR__ . '/limite_ip';
if (!is_dir($limite_dir)) mkdir($limite_dir);
$limite_arquivo = "$limite_dir/" . md5($ip_usuario) . ".txt";
$limite_max = 1;
$dados_limite = ["data" => $data_hoje, "contagem" => 0];
if (file_exists($limite_arquivo)) {
    $dados = json_decode(file_get_contents($limite_arquivo), true);
    if ($dados && $dados["data"] === $data_hoje) {
        $dados_limite = $dados;
    }
}
if ($dados_limite["data"] === $data_hoje && $dados_limite["contagem"] >= $limite_max) {
    header('Content-Type: application/json');
    echo json_encode([
        'resposta' => 'Você atingiu o limite de 1 análise para hoje. Tente novamente amanhã.',
        'limite_atingido' => true,
        'tipo' => 'limite_diario'
    ]);
    exit;
}
$dados_limite["data"] = $data_hoje;
$dados_limite["contagem"]++;
file_put_contents($limite_arquivo, json_encode($dados_limite));


function verificarRecaptcha($token, $secretKey) {
  $url = 'https://www.google.com/recaptcha/api/siteverify';
  $data = [
    'secret' => $secretKey,
    'response' => $token
  ];
  $options = [
    'http' => [
      'method'  => 'POST',
      'header'  => 'Content-type: application/x-www-form-urlencoded',
      'content' => http_build_query($data)
    ]
  ];
  $context = stream_context_create($options);
  $result = file_get_contents($url, false, $context);
  $resultJson = json_decode($result, true);
  return $resultJson['success'];
}

function chamarOpenAI($prompt) {
  global $apiKeyOpenAI;
  
  $payload = [
    "model" => "gpt-4",
    "messages" => [
      ["role" => "system", "content" => "Você é um médico especialista com mais de 35 anos de experiência clínica, especializado em medicina interna, emergência médica, semiologia médica, interpretação de exames laboratoriais e diagnóstico por imagem. Você possui expertise em fisiopatologia, anatomia clínica, farmacologia clínica, bioquímica clínica, anatomia patológica e medicina baseada em evidências. Sua formação inclui residência em medicina interna, especialização em emergência médica e vasta experiência em diagnóstico diferencial, interpretação de exames complexos e conduta médica baseada em evidências científicas atuais. Você segue rigorosamente os protocolos médicos estabelecidos e fornece análises detalhadas, precisas e responsáveis. Use linguagem clara para o paciente, mas mantenha a precisão técnica médica necessária. Priorize sempre a segurança do paciente, a identificação de condições graves e a medicina baseada em evidências."],
      ["role" => "user", "content" => $prompt]
    ],
    "temperature" => 0.1,
    "max_tokens" => 5000
  ];

  $ch = curl_init();
  curl_setopt($ch, CURLOPT_URL, "https://api.openai.com/v1/chat/completions");
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
  curl_setopt($ch, CURLOPT_POST, true);
  curl_setopt($ch, CURLOPT_TIMEOUT, 60);
  curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 30);
  curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
  curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
  curl_setopt($ch, CURLOPT_HTTPHEADER, [
    "Content-Type: application/json",
    "Authorization: Bearer $apiKeyOpenAI"
  ]);
  curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));

  $resposta = curl_exec($ch);
  $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  $erroCurl = curl_error($ch);
  curl_close($ch);

  if ($erroCurl) {
    return "❌ Erro de conexão com a API: $erroCurl";
  }

  if ($httpCode !== 200) {
    $erroDetalhado = "❌ Erro ao se comunicar com a OpenAI. Código HTTP: $httpCode";
    if ($resposta) {
      $erroJson = json_decode($resposta, true);
      if ($erroJson && isset($erroJson['error']['message'])) {
        $erroDetalhado .= "\nDetalhes: " . $erroJson['error']['message'];
      }
    }
    return $erroDetalhado;
  }

  $resultado = json_decode($resposta, true);
  if (!$resultado || !isset($resultado['choices'][0]['message']['content'])) {
    return '❌ Não foi possível interpretar a resposta da IA. Resposta: ' . substr($resposta, 0, 200);
  }
  
  return $resultado['choices'][0]['message']['content'];
}

function extrairInformacoesLaboratorio($texto) {
    $info = [
        'laboratorio' => '',
        'endereco' => '',
        'telefone' => '',
        'data_coleta' => '',
        'data_liberacao' => '',
        'medico_responsavel' => '',
        'crm' => ''
    ];
    
    // Padrões para encontrar informações do laboratório
    $padroes = [
        'laboratorio' => [
            '/laborat[oó]rio\s*:?\s*([^\n\r]+)/i',
            '/cl[ií]nica\s*:?\s*([^\n\r]+)/i',
            '/centro\s*:?\s*([^\n\r]+)/i',
            '/institui[cç][aã]o\s*:?\s*([^\n\r]+)/i',
            '/empresa\s*:?\s*([^\n\r]+)/i'
        ],
        'endereco' => [
            '/endere[cç]o\s*:?\s*([^\n\r]+)/i',
            '/rua\s+([^\n\r]+)/i',
            '/av\.?\s+([^\n\r]+)/i',
            '/avenida\s+([^\n\r]+)/i',
            '/bairro\s*:?\s*([^\n\r]+)/i',
            '/cidade\s*:?\s*([^\n\r]+)/i'
        ],
        'telefone' => [
            '/telefone\s*:?\s*([\d\s\-\(\)]+)/i',
            '/fone\s*:?\s*([\d\s\-\(\)]+)/i',
            '/tel\s*:?\s*([\d\s\-\(\)]+)/i',
            '/(\(\d{2}\)\s*\d{4,5}\-\d{4})/',
            '/(\d{2}\s*\d{4,5}\s*\d{4})/'
        ],
        'data_coleta' => [
            '/data\s+da\s+coleta\s*:?\s*(\d{1,2}[\/\-]\d{1,2}[\/\-]\d{2,4})/i',
            '/coleta\s*:?\s*(\d{1,2}[\/\-]\d{1,2}[\/\-]\d{2,4})/i',
            '/coletado\s+em\s*:?\s*(\d{1,2}[\/\-]\d{1,2}[\/\-]\d{2,4})/i'
        ],
        'data_liberacao' => [
            '/data\s+da\s+libera[cç][aã]o\s*:?\s*(\d{1,2}[\/\-]\d{1,2}[\/\-]\d{2,4})/i',
            '/libera[cç][aã]o\s*:?\s*(\d{1,2}[\/\-]\d{1,2}[\/\-]\d{2,4})/i',
            '/liberado\s+em\s*:?\s*(\d{1,2}[\/\-]\d{1,2}[\/\-]\d{2,4})/i'
        ],
        'medico_responsavel' => [
            '/m[eé]dico\s+respons[aá]vel\s*:?\s*([^\n\r]+)/i',
            '/respons[aá]vel\s+t[eé]cnico\s*:?\s*([^\n\r]+)/i',
            '/assinatura\s*:?\s*([^\n\r]+)/i',
            '/dr\.?\s+([^\n\r]+)/i',
            '/dra\.?\s+([^\n\r]+)/i'
        ],
        'crm' => [
            '/crm\s*:?\s*(\d+)/i',
            '/registro\s+profissional\s*:?\s*(\d+)/i'
        ]
    ];
    
    // Extrair informações usando os padrões
    foreach ($padroes as $tipo => $listaPadroes) {
        foreach ($listaPadroes as $padrao) {
            if (preg_match($padrao, $texto, $matches)) {
                $info[$tipo] = trim($matches[1]);
                break;
            }
        }
    }
    
    // Buscar por nomes de laboratórios conhecidos
    $laboratoriosConhecidos = [
        'fleury', 'delboni', 'einstein', 'sirio libanes', 'sírio libanês', 'albert einstein',
        'hospitalsirio', 'hospitalsiriolibanes', 'hospitalsiriolibanês', 'hospitalsiriolibanes',
        'hospitalsiriolibanês', 'hospitalsiriolibanes', 'hospitalsiriolibanês',
        'diagnostico', 'diagnóstico', 'diagnostico', 'diagnóstico',
        'hermes pardini', 'hermespardini', 'hermes pardini', 'hermespardini',
        'richet', 'richet', 'richet', 'richet',
        'sabin', 'sabin', 'sabin', 'sabin',
        'exame', 'exame', 'exame', 'exame',
        'laboratorio', 'laboratório', 'laboratorio', 'laboratório'
    ];
    
    foreach ($laboratoriosConhecidos as $lab) {
        if (stripos($texto, $lab) !== false && empty($info['laboratorio'])) {
            // Extrair o nome completo do laboratório
            $padraoLab = '/(' . preg_quote($lab, '/') . '[^\n\r]*)/i';
            if (preg_match($padraoLab, $texto, $matches)) {
                $info['laboratorio'] = trim($matches[1]);
            }
        }
    }
    
    return $info;
}

function extrairTextoPDFAgressivo($arquivoTmp) {
    $metodos = [];
    
    // Método 1: PDFParser (já existe)
    try {
        $parser = new Smalot\PdfParser\Parser();
        $pdf = $parser->parseFile($arquivoTmp);
        $texto = $pdf->getText();
        if (!empty(trim($texto))) {
            return $texto;
        }
    } catch (Exception $e) {
        $metodos[] = "PDFParser falhou: " . $e->getMessage();
    }
    
    // Método 2: FPDI + FPDF
    try {
        $pdf = new \setasign\Fpdi\Fpdi();
        $pageCount = $pdf->setSourceFile($arquivoTmp);
        $texto = "";
        for ($i = 1; $i <= $pageCount; $i++) {
            $template = $pdf->importPage($i);
            $size = $pdf->getTemplateSize($template);
            $pdf->AddPage($size['orientation'], [$size['width'], $size['height']]);
            $pdf->useTemplate($template);
            // Tentar extrair texto da página
            $texto .= $pdf->Output('', 'S') . "\n";
        }
        if (!empty(trim($texto))) {
            return $texto;
        }
    } catch (Exception $e) {
        $metodos[] = "FPDI falhou: " . $e->getMessage();
    }
    
    // Método 3: Comando shell pdftotext (se disponível)
    try {
        if (function_exists('shell_exec')) {
            $comando = "pdftotext " . escapeshellarg($arquivoTmp) . " - 2>/dev/null";
            $texto = shell_exec($comando);
            if (!empty(trim($texto))) {
                return $texto;
            }
        }
    } catch (Exception $e) {
        $metodos[] = "pdftotext falhou: " . $e->getMessage();
    }
    
    // Método 4: Comando shell pdf2txt (se disponível)
    try {
        if (function_exists('shell_exec')) {
            $comando = "pdf2txt " . escapeshellarg($arquivoTmp) . " 2>/dev/null";
            $texto = shell_exec($comando);
            if (!empty(trim($texto))) {
                return $texto;
            }
        }
    } catch (Exception $e) {
        $metodos[] = "pdf2txt falhou: " . $e->getMessage();
    }
    
    // Método 5: Comando shell poppler-utils (se disponível)
    try {
        if (function_exists('shell_exec')) {
            $comando = "pdftotext -layout " . escapeshellarg($arquivoTmp) . " - 2>/dev/null";
            $texto = shell_exec($comando);
            if (!empty(trim($texto))) {
                return $texto;
            }
        }
    } catch (Exception $e) {
        $metodos[] = "poppler-utils falhou: " . $e->getMessage();
    }
    
    // Se todos falharam, retorna erro detalhado
    return "❌ Todos os métodos de extração falharam:\n" . implode("\n", $metodos);
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
  $tipo = $_POST["tipo"] ?? "";
  
  // Verificar reCAPTCHA para análise de sintomas e exames
  if ($tipo === "sintomas" || $tipo === "exame") {
    $token = $_POST["g-recaptcha-response"] ?? "";
    
    if (!$token) {
      echo json_encode(["resposta" => "❌ Erro: Token do reCAPTCHA ausente."]);
      exit;
    }
    
    if (!verificarRecaptcha($token, $secretKey)) {
      echo json_encode(["resposta" => "❌ Erro: Verificação do reCAPTCHA falhou. Tente novamente."]);
      exit;
    }
  }

  if ($tipo === "sintomas") {
    $sintomas = $_POST["sintomas"] ?? "";
    $duracao = $_POST["duracao"] ?? "";
    $intensidade = $_POST["intensidade"] ?? "";
    
    if (!$sintomas) {
      echo json_encode(["resposta" => "❌ Por favor, insira os sintomas."]);
      exit;
    }
    
    $prompt = <<<EOT
Você é um médico especialista com mais de 30 anos de experiência clínica, especializado em medicina interna, emergência médica, semiologia médica e diagnóstico diferencial. Você possui expertise em fisiopatologia, anatomia clínica, farmacologia clínica e medicina baseada em evidências. Analise os seguintes sintomas seguindo rigorosamente o PROTOCOLO MÉDICO ESTABELECIDO:

Sintomas: $sintomas
Duração: $duracao
Intensidade: $intensidade

PROTOCOLO DE ANÁLISE DE SINTOMAS - VERSÃO PROFISSIONAL:

1. ANÁLISE SEMIOLÓGICA COMPREENSIVA:

   A. Caracterização Primária dos Sintomas:
   - Localização anatômica precisa (região, lateralidade, profundidade)
   - Características qualitativas (tipo de dor, sensação, qualidade)
   - Intensidade quantificada (escala 1-10, impacto na vida diária)
   - Irradiação e extensão (trajeto, áreas afetadas)
   - Padrão temporal (início, evolução, periodicidade, duração)

   B. Cronologia e Evolução Temporal:
   - Início: súbito vs gradual, fatores desencadeantes
   - Evolução: progressiva, estável, flutuante, cíclica
   - Duração: aguda (<2 semanas), subaguda (2-6 semanas), crônica (>6 semanas)
   - Padrão: contínuo, intermitente, paroxístico, relacionado a atividades

   C. Fatores Modificadores:
   - Agravantes: posição, movimento, alimentação, temperatura, estresse
   - Atenuantes: repouso, medicação, posição, calor/frio
   - Fatores de risco: idade, sexo, comorbidades, medicamentos, ocupação

   D. Sintomas Associados e Constitucionais:
   - Sintomas sistêmicos: febre, calafrios, sudorese, perda de peso
   - Sintomas neurológicos: alteração de consciência, déficit motor/sensitivo
   - Sintomas cardiovasculares: palpitações, dispneia, edema, cianose
   - Sintomas gastrointestinais: náuseas, vômitos, alteração do apetite
   - Sintomas geniturinários: disúria, alteração do padrão urinário

2. DIAGNÓSTICO DIFERENCIAL ESTRUTURADO:

   A. Diagnósticos Mais Prováveis (Top 5-7):
   - Listar com probabilidade estimada (alta >70%, moderada 30-70%, baixa <30%)
   - Explicar fisiopatologia básica de cada condição
   - Mencionar fatores de risco específicos e epidemiologia
   - Correlacionar com características semiológicas apresentadas

   B. Diagnósticos de Exclusão (Condições Graves):
   - Condições que ameaçam a vida imediatamente
   - Condições que podem causar sequelas permanentes
   - Condições que requerem intervenção urgente (<24h)
   - Condições que podem progredir rapidamente

   C. Diagnósticos Secundários e Alternativos:
   - Condições menos prováveis mas importantes
   - Condições que podem mascarar outras doenças
   - Condições relacionadas a medicamentos, toxinas ou iatrogenia
   - Condições psicossomáticas ou funcionais

3. AVALIAÇÃO DE RISCO E URGÊNCIA COMPREENSIVA:

   A. Classificação de Urgência Detalhada:
   - 🟢 Baixo risco: Sintomas leves, sem sinais de alerta, evolução benigna esperada
   - 🟡 Risco moderado: Sintomas persistentes, moderados, necessitam avaliação médica
   - 🔴 Alto risco: Sinais de alerta presentes, sintomas graves, potencial de complicações
   - ⚫ Emergência: Sinais vitais comprometidos, risco imediato à vida, necessidade de intervenção urgente

   B. Sinais de Alerta Específicos (Red Flags):
   - Sinais vitais alterados: febre >39°C, taquicardia >100bpm, hipotensão <90/60mmHg
   - Sintomas neurológicos: alteração de consciência, déficit motor/sensitivo, convulsões
   - Sintomas cardiovasculares: dor torácica, dispneia, síncope, edema agudo
   - Sintomas abdominais agudos: dor intensa, distensão, sangramento, peritonismo
   - Sintomas sistêmicos: perda de peso >10%, sudorese noturna, fadiga extrema
   - Sintomas específicos por sistema: hemoptise, hematêmese, melena, hematuria

4. INVESTIGAÇÃO COMPLEMENTAR ESTRUTURADA:

   A. Exames Laboratoriais Específicos:
   - Hemograma completo com diferencial
   - Exames bioquímicos: glicemia, creatinina, eletrólitos, função hepática
   - Exames específicos por suspeita diagnóstica
   - Marcadores inflamatórios: PCR, VHS, procalcitonina
   - Exames toxicológicos quando indicado

   B. Exames de Imagem Indicados:
   - Radiografia simples quando apropriada
   - Ultrassonografia para avaliação de estruturas superficiais
   - Tomografia computadorizada para avaliação de estruturas profundas
   - Ressonância magnética para avaliação de tecidos moles
   - Exames contrastados quando necessário

   C. Exames Específicos por Especialidade:
   - Cardiologia: ECG, ecocardiograma, teste ergométrico
   - Pneumologia: espirometria, gasometria arterial
   - Gastroenterologia: endoscopia, colonoscopia, manometria
   - Neurologia: EEG, EMG, potencial evocado
   - Reumatologia: exames imunológicos, biópsia sinovial

5. CONDUTA MÉDICA ESTRUTURADA E DETALHADA:

   A. Medidas Imediatas e Autocuidado:
   - Medidas de conforto seguras e eficazes
   - Autocuidado apropriado para cada condição
   - Sinais que indicam necessidade de atendimento urgente
   - Medidas preventivas para evitar piora

   B. Seguimento Médico Estruturado:
   - Quando procurar atendimento médico (critérios específicos)
   - Frequência de retorno baseada na condição
   - Critérios para reavaliação e ajuste de conduta
   - Especialidades médicas recomendadas com justificativa

   C. Prevenção e Educação do Paciente:
   - Medidas preventivas específicas para cada condição
   - Mudanças no estilo de vida necessárias
   - Orientação sobre sinais de alerta e quando procurar ajuda
   - Educação sobre prognóstico e evolução esperada

6. RESUMO CLÍNICO PROFISSIONAL:
   - 🩺 Sintomas: [Descrição resumida e caracterização semiológica]
   - 📊 Classificação de Risco: [Baixo/Moderado/Alto/Emergência com justificativa]
   - ❗ Diagnósticos Principais: [Lista dos mais prováveis com probabilidade e fisiopatologia]
   - ⚠️ Sinais de Alerta: [Lista dos principais red flags com significado clínico]
   - 📌 Conduta Recomendada: [Ação imediata, seguimento e especialidade]
   - 🏥 Especialidade Sugerida: [Especialidade médica mais apropriada com justificativa]
   - 🔬 Investigação Complementar: [Exames específicos recomendados]

FORMATAÇÃO IMPORTANTE:
- Use títulos simples sem asteriscos (*) ou formatação markdown
- Exemplo correto: "Análise Semiológica Compreensiva"
- Exemplo incorreto: "**Análise Semiológica Compreensiva**" ou "*Análise Semiológica Compreensiva*"
- Use apenas texto limpo para títulos de seções

IMPORTANTE: 
- Use linguagem clara para o paciente, mas mantenha precisão técnica médica
- Sempre enfatize que esta é uma orientação educativa, não um diagnóstico definitivo
- Base suas recomendações em evidências médicas (PubMed, OMS, diretrizes médicas)
- Priorize a segurança do paciente e a identificação de condições graves
- NÃO use asteriscos (*) ou formatação markdown nos títulos das seções
- Use títulos limpos sem formatação especial
- Seja específico e detalhado em cada seção
- Inclua informações sobre fisiopatologia quando relevante
- Mencione possíveis complicações e prognóstico
- Forneça justificativas clínicas para cada recomendação

MEDICAMENTOS SUGERIDOS: No final da análise, inclua uma seção "MEDICAMENTOS SUGERIDOS" com:
- Para cada condição identificada, sugira 3 opções de medicamentos:
  1. MEDICAMENTO DE REFERÊNCIA (marca original) - R$ XX,XX
  2. MEDICAMENTO SIMILAR (marca similar) - R$ XX,XX  
  3. MEDICAMENTO GENÉRICO (genérico) - R$ XX,XX
- Use preços realistas do mercado brasileiro atual (2024-2025)
- Consulte preços da Farmácia Panvel (www.panvel.com.br) quando possível
- Inclua dosagem e forma farmacêutica quando relevante
- Organize por categoria terapêutica (ex: "Para Dor:", "Para Febre:", etc.)
- Use o formato exato: "MEDICAMENTOS SUGERIDOS:" seguido das categorias
- Para cada medicamento, use o formato: "1. Nome do Medicamento (tipo) - R$ XX,XX"
EOT;

    $respostaIA = chamarOpenAI($prompt);
    echo json_encode(["resposta" => $respostaIA]);

  } elseif ($tipo === "exame") {
    // Verificar se recebeu conteúdo OCR do front-end
    $conteudoOCR = $_POST["conteudo_ocr"] ?? "";
    $nomeArquivo = $_POST["nome_arquivo"] ?? "";
    
    if (!empty($conteudoOCR)) {
      // Usar conteúdo extraído pelo OCR no front-end
      $conteudoPDF = $conteudoOCR;
    } else {
      // Tentar extrair do arquivo enviado
      if (!isset($_FILES['arquivo']) || $_FILES['arquivo']['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(["resposta" => "❌ Erro ao fazer upload do arquivo."]);
        exit;
      }

      $arquivoTmp = $_FILES['arquivo']['tmp_name'];
      $nomeArquivo = $_FILES['arquivo']['name'];
      $ext = strtolower(pathinfo($nomeArquivo, PATHINFO_EXTENSION));

      if ($ext !== 'pdf') {
        echo json_encode(["resposta" => "❌ Apenas arquivos PDF são suportados no momento."]);
        exit;
      }

      // Extrair texto real do PDF usando método agressivo
      $conteudoPDF = extrairTextoPDFAgressivo($arquivoTmp);
    }
    
    if (strpos($conteudoPDF, '❌ Erro') === 0) {
      echo json_encode(["resposta" => $conteudoPDF]);
      exit;
    }
    
    if (empty(trim($conteudoPDF))) {
      echo json_encode(["resposta" => "❌ Não foi possível extrair texto do PDF. Verifique se o arquivo não está corrompido ou protegido."]);
      exit;
    }
    
    // Extrair informações do laboratório
    $infoLaboratorio = extrairInformacoesLaboratorio($conteudoPDF);
    
    // Criar string com informações do laboratório
    $infoLabStr = "";
    if (!empty($infoLaboratorio['laboratorio'])) {
        $infoLabStr .= "Laboratório: " . $infoLaboratorio['laboratorio'] . "\n";
    }
    if (!empty($infoLaboratorio['endereco'])) {
        $infoLabStr .= "Endereço: " . $infoLaboratorio['endereco'] . "\n";
    }
    if (!empty($infoLaboratorio['telefone'])) {
        $infoLabStr .= "Telefone: " . $infoLaboratorio['telefone'] . "\n";
    }
    if (!empty($infoLaboratorio['data_coleta'])) {
        $infoLabStr .= "Data da Coleta: " . $infoLaboratorio['data_coleta'] . "\n";
    }
    if (!empty($infoLaboratorio['data_liberacao'])) {
        $infoLabStr .= "Data da Liberação: " . $infoLaboratorio['data_liberacao'] . "\n";
    }
    if (!empty($infoLaboratorio['medico_responsavel'])) {
        $infoLabStr .= "Médico Responsável: " . $infoLaboratorio['medico_responsavel'];
        if (!empty($infoLaboratorio['crm'])) {
            $infoLabStr .= " (CRM: " . $infoLaboratorio['crm'] . ")";
        }
        $infoLabStr .= "\n";
    }
    
    // Limitar o tamanho do conteúdo para evitar problemas com a API
    if (strlen($conteudoPDF) > 8000) {
      $conteudoPDF = substr($conteudoPDF, 0, 8000) . "\n\n[Conteúdo truncado devido ao tamanho...]";
    }

    $prompt = <<<EOT
Você é um médico especialista com mais de 20 anos de experiência, especializado em interpretação de exames laboratoriais, de imagem e análise clínica. Analise o seguinte conteúdo extraído de um PDF seguindo rigorosamente o PROTOCOLO MÉDICO ESTABELECIDO:

INFORMAÇÕES DO LABORATÓRIO:
$infoLabStr

CONTEÚDO DO EXAME:
$conteudoPDF

PROTOCOLO DE ANÁLISE MÉDICA - VERSÃO PROFISSIONAL:

1. IDENTIFICAÇÃO E CLASSIFICAÇÃO DO EXAME:

   A. Classificação Primária:
   - Exame laboratorial: sangue, urina, fezes, secreções, liquor, outros fluidos
   - Exame de imagem: radiografia, tomografia, ressonância, ultrassonografia, mamografia, densitometria
   - Exame funcional: espirometria, ECG, EEG, EMG, teste ergométrico, MAPA, HOLTER
   - Exame anátomo-patológico: biópsia, citologia, imuno-histoquímica, autópsia
   - Exame genético/molecular: cariótipo, PCR, sequenciamento, painéis genéticos
   - Exame endoscópico: endoscopia digestiva, colonoscopia, broncoscopia, cistoscopia

   B. Contextualização Clínica:
   - Sintomas que motivaram o exame
   - Medicações em uso que podem interferir
   - Doenças crônicas e comorbidades
   - Histórico médico relevante
   - Exposições ocupacionais ou ambientais

2. ANÁLISE TÉCNICA COMPREENSIVA:

   A. Metodologia e Confiabilidade:
   - Técnica utilizada e princípios físicos/tecnológicos
   - Precisão e acurácia do método
   - Possíveis interferências e limitações
   - Controles de qualidade aplicados
   - Validação do equipamento e reagentes

   B. Valores e Interpretação Quantitativa:
   - Comparação com valores de referência específicos
   - Classificação: ✅ Normal / ⚠️ Limítrofe / ❌ Alterado / 🔴 Crítico
   - Grau de alteração: Leve / Moderado / Grave / Crítico
   - Significado clínico de cada alteração
   - Correlação com idade, sexo e condições específicas

   C. Análise Comparativa e Evolutiva:
   - Evolução temporal dos valores (se disponível)
   - Tendência: Melhora / Piora / Estabilidade / Flutuação
   - Mudanças significativas e sua relevância clínica
   - Padrões de progressão da doença

3. INTERPRETAÇÃO CLÍNICA PROFISSIONAL:

   A. Correlação Clínica Detalhada:
   - Compatibilidade com sintomas relatados
   - Explicação fisiopatológica das alterações
   - Associação com condições médicas conhecidas
   - Mecanismos de doença envolvidos

   B. Diagnóstico Diferencial Estruturado:
   - Condições que explicam os achados (Top 5-7)
   - Probabilidade de cada diagnóstico (alta >70%, moderada 30-70%, baixa <30%)
   - Condições que devem ser excluídas (diagnósticos de exclusão)
   - Condições menos prováveis mas importantes

   C. Implicações Prognósticas e de Risco:
   - Risco de complicações agudas e crônicas
   - Risco de progressão da doença
   - Necessidade de monitoramento e seguimento
   - Impacto na qualidade de vida

4. AVALIAÇÃO DE RISCO E URGÊNCIA COMPREENSIVA:

   A. Classificação de Urgência Detalhada:
   - 🟢 Baixo risco: Alterações leves, sem repercussão imediata, evolução benigna
   - 🟡 Risco moderado: Alterações que requerem atenção médica, monitoramento
   - 🔴 Alto risco: Alterações graves, potencial de complicações, necessidade de intervenção
   - ⚫ Emergência: Valores críticos, risco imediato à vida, necessidade de intervenção urgente

   B. Sinais de Alerta Específicos por Tipo de Exame:
   - Laboratorial: valores críticos, alterações agudas, padrões de falência orgânica
   - Imagem: massas suspeitas, fraturas instáveis, sangramentos, isquemias
   - Funcional: arritmias graves, obstruções, falência respiratória
   - Anátomo-patológico: neoplasias, inflamações graves, infecções

5. CONDUTA MÉDICA ESTRUTURADA E DETALHADA:

   A. Medidas Imediatas e Urgência:
   - Necessidade de atendimento urgente (critérios específicos)
   - Medicações que devem ser iniciadas/suspensas imediatamente
   - Restrições ou orientações específicas
   - Isolamento ou precauções quando necessário

   B. Investigação Complementar Estruturada:
   - Exames adicionais necessários com justificativa
   - Especialidades médicas recomendadas com indicação
   - Frequência de reavaliação baseada na condição
   - Critérios para ajuste de conduta

   C. Seguimento e Monitoramento Específico:
   - Intervalo para repetição do exame
   - Critérios para reavaliação e ajuste de conduta
   - Sinais que indicam piora e necessidade de intervenção
   - Protocolos de seguimento específicos

6. EDUCAÇÃO DO PACIENTE COMPREENSIVA:

   A. Explicação dos Resultados:
   - Linguagem clara sobre o que foi encontrado
   - Significado das alterações em termos compreensíveis
   - Prognóstico esperado e evolução natural
   - Impacto na vida diária e atividades

   B. Orientação sobre Tratamento e Prevenção:
   - Medicações prescritas e seus efeitos esperados
   - Mudanças no estilo de vida necessárias
   - Sinais de alerta para procurar atendimento
   - Medidas preventivas para evitar piora

7. RESUMO LAUDADO PROFISSIONAL:
   - 🏥 Laboratório: [Nome, localização e credenciamento]
   - 🧪 Exame: [Nome específico e metodologia utilizada]
   - 📈 Resultados Principais: [Valores mais relevantes com interpretação clínica]
   - ❗ Conclusão Clínica: [Interpretação médica dos achados com fisiopatologia]
   - ⚠️ Classificação de Risco: [Baixo/Moderado/Alto/Emergência com justificativa]
   - 📌 Conduta Recomendada: [Ação imediata, seguimento e especialidade]
   - 🏥 Especialidade Sugerida: [Especialidade médica mais apropriada com justificativa]
   - 🔬 Investigação Complementar: [Exames específicos recomendados com indicação]

FORMATAÇÃO IMPORTANTE:
- Use títulos simples sem asteriscos (*) ou formatação markdown
- Exemplo correto: "Interpretação Geral"
- Exemplo incorreto: "**Interpretação Geral**" ou "*Interpretação Geral*"
- Use apenas texto limpo para títulos de seções

IMPORTANTE: 
- NÃO inclua dados pessoais (nome, data, laboratório) no corpo da análise
- Use linguagem clara para o paciente, mas mantenha precisão técnica
- Sempre enfatize que esta é uma orientação educativa, não um diagnóstico definitivo
- Base suas recomendações em evidências médicas (PubMed, OMS, diretrizes médicas)
- NÃO use asteriscos (*) ou formatação markdown nos títulos das seções
- Use títulos limpos sem formatação especial

MEDICAMENTOS SUGERIDOS: No final da análise, inclua uma seção "MEDICAMENTOS SUGERIDOS" com:
- Para cada condição identificada, sugira 3 opções de medicamentos:
  1. MEDICAMENTO DE REFERÊNCIA (marca original) - R$ XX,XX
  2. MEDICAMENTO SIMILAR (marca similar) - R$ XX,XX  
  3. MEDICAMENTO GENÉRICO (genérico) - R$ XX,XX
- Use preços realistas do mercado brasileiro atual (2024-2025)
- Consulte preços da Farmácia Panvel (www.panvel.com.br) quando possível
- Inclua dosagem e forma farmacêutica quando relevante
- Organize por categoria terapêutica (ex: "Para Controle do Colesterol:", "Para Diabetes:", etc.)
- Use o formato exato: "MEDICAMENTOS SUGERIDOS:" seguido das categorias
- Para cada medicamento, use o formato: "1. Nome do Medicamento (tipo) - R$ XX,XX"
EOT;

    $respostaIA = chamarOpenAI($prompt);
    echo json_encode(["resposta" => $respostaIA]);
  } else {
    echo json_encode(["resposta" => "❌ Tipo de análise inválido."]);
    exit;
  }
} else {
  echo json_encode(["resposta" => "❌ Método não permitido."]);
  exit;
}

// Função para formatar medicamentos sugeridos pela IA
function formatarMedicamentos($texto) {
    $medicamentos = '';
    
    // Procurar pela seção de medicamentos no texto
    if (preg_match('/MEDICAMENTOS SUGERIDOS:(.*?)(?=\n\n|\Z)/s', $texto, $matches)) {
        $secaoMedicamentos = $matches[1];
        
        // Processar cada categoria de medicamento
        $categorias = explode("\n", trim($secaoMedicamentos));
        
        foreach ($categorias as $categoria) {
            if (trim($categoria) && !preg_match('/^\d+\./', $categoria)) {
                // É uma categoria (ex: "Para Controle do Colesterol:")
                $medicamentos .= '<h4 style="color: #2c3e50; margin: 15px 0 10px 0; font-size: 13px;">' . trim($categoria, ':') . '</h4>';
            } elseif (preg_match('/^\d+\.\s*(.*?)\s*-\s*R\$\s*([\d,]+)/', $categoria, $match)) {
                // É um medicamento com preço
                $tipo = '';
                $cor = '';
                $bgColor = '';
                
                if (strpos($match[1], 'REFERÊNCIA') !== false || strpos($match[1], 'original') !== false) {
                    $tipo = 'Referência';
                    $cor = '#155724';
                    $bgColor = '#e8f5e8';
                    $borderColor = '#28a745';
                } elseif (strpos($match[1], 'SIMILAR') !== false || strpos($match[1], 'similar') !== false) {
                    $tipo = 'Similar';
                    $cor = '#856404';
                    $bgColor = '#fff3cd';
                    $borderColor = '#ffc107';
                } elseif (strpos($match[1], 'GENÉRICO') !== false || strpos($match[1], 'genérico') !== false) {
                    $tipo = 'Genérico';
                    $cor = '#721c24';
                    $bgColor = '#f8d7da';
                    $borderColor = '#dc3545';
                }
                
                if ($tipo) {
                    $medicamento = preg_replace('/\(.*?\)/', '', $match[1]); // Remove parênteses
                    $medicamento = preg_replace('/REFERÊNCIA|SIMILAR|GENÉRICO|original|similar|genérico/i', '', $medicamento);
                    $medicamento = trim($medicamento);
                    
                    $medicamentos .= '<div style="background: ' . $bgColor . '; border-left: 4px solid ' . $borderColor . '; padding: 12px; margin-bottom: 10px; border-radius: 4px;">';
                    $medicamentos .= '<strong style="color: ' . $cor . ';">' . $tipo . ':</strong>';
                    $medicamentos .= '<span style="color: ' . $cor . ';"> ' . $medicamento . ' - R$ ' . $match[2] . '</span>';
                    $medicamentos .= '</div>';
                }
            }
        }
    }
    
    return $medicamentos;
}
