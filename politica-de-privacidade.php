<?php
// ReadMyLabs — Política de Privacidade.
// Página estática com layout Aurora. Vinculada do rodapé do index.php, do
// checkbox do cadastro e do painel da conta.
//
// ATENÇÃO ANTES DE PUBLICAR PARA O MUNDO:
//   Preencha os placeholders abaixo (CNPJ/CPF, nome do controlador, e-mail
//   de contato/DPO, cidade-foro). Enquanto algum placeholder começar com
//   "[PREENCHER", um banner amarelo aparece no topo da página avisando.
//   Esses valores ficam só aqui (não vão para o .env).

declare(strict_types=1);

require_once __DIR__ . '/loads_env.php';
loadEnv();
require_once __DIR__ . '/lib/analytics.php';

// ---- IDENTIFICAÇÃO DO CONTROLADOR (LGPD art. 41) ---------------------------
$RML_CTRL_NOME       = '[PREENCHER: nome ou razão social do responsável]';
$RML_CTRL_DOC        = '[PREENCHER: CPF ou CNPJ]';
$RML_CTRL_CIDADE     = '[PREENCHER: cidade/UF — foro p/ disputas]';
$RML_CTRL_CONTATO    = '[PREENCHER: e-mail principal de contato]';
$RML_CTRL_DPO_EMAIL  = '[PREENCHER: e-mail do encarregado/DPO]';

// ---- META ------------------------------------------------------------------
$RML_VERSAO_POLITICA = '1.0';
$RML_DATA_VIGENCIA   = '22/06/2026'; // dd/mm/aaaa

// Detecta placeholders ainda não preenchidos para mostrar o aviso de revisão.
$placeholdersPendentes = [];
foreach ([
    'Nome do controlador'      => $RML_CTRL_NOME,
    'Documento (CPF/CNPJ)'     => $RML_CTRL_DOC,
    'Cidade/UF'                => $RML_CTRL_CIDADE,
    'Contato principal'        => $RML_CTRL_CONTATO,
    'DPO/encarregado'          => $RML_CTRL_DPO_EMAIL,
] as $label => $val) {
    if (strpos($val, '[PREENCHER') === 0) $placeholdersPendentes[] = $label;
}

// Nav (mesmo padrão das outras páginas: "Entrar" vs "Minha conta / Sair").
require_once __DIR__ . '/auth/lib/sessao.php';
$u = sessaoAtual();
$linkHist = ($u && !empty($u['email_verificado']))
    ? '<a href="/historico.php" class="btn btn-ghost">Meus exames</a>' : '';
$navUser = $u
    ? $linkHist . '<a href="/conta.php" class="btn btn-ghost">' . htmlspecialchars($u['nome'] ?: $u['email']) . '</a><a href="/sair.php" class="btn btn-ghost">Sair</a>'
    : '<a href="/entrar.php" class="btn btn-ghost">Entrar</a><a href="/cadastro.php" class="btn btn-primary">Cadastrar</a>';

ob_start(); analyticsHead(); $gaHead = ob_get_clean();
?>
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="index,follow">
<?= $gaHead ?>
<title>Política de Privacidade · ReadMyLabs</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@500;600;700&family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
:root{--bg:#0b0b14;--bg-2:#13131f;--panel:rgba(255,255,255,.045);--panel-2:rgba(255,255,255,.07);--stroke:rgba(255,255,255,.10);--stroke-2:rgba(255,255,255,.16);--text:#f3f2fb;--muted:#a6a4c0;--faint:#6f6d8c;--indigo:#7c83ff;--violet:#b478ff;--cyan:#5fe3ff;--good:#52e2b0;--warn:#ffcf6b;--high:#ff7d8a;--ease:cubic-bezier(.2,.7,.2,1)}
*{box-sizing:border-box;margin:0;padding:0}
html{scroll-behavior:smooth}
body{font-family:"Plus Jakarta Sans",system-ui,sans-serif;background:var(--bg);color:var(--text);min-height:100vh;overflow-x:hidden}
.aurora-bg{position:fixed;inset:0;z-index:0;pointer-events:none;overflow:hidden}
.aurora-bg::before,.aurora-bg::after{content:"";position:absolute;border-radius:50%;filter:blur(110px);opacity:.45}
.aurora-bg::before{width:680px;height:680px;left:-200px;top:-220px;background:radial-gradient(circle at 30% 30%,#5a3cff,transparent 65%)}
.aurora-bg::after{width:580px;height:580px;right:-180px;top:120px;background:radial-gradient(circle at 60% 40%,#1fb6ff,transparent 62%)}
nav{position:sticky;top:0;z-index:60;backdrop-filter:blur(18px);background:rgba(7,7,13,.55);border-bottom:1px solid var(--stroke)}
.nav-in{max-width:1240px;margin:0 auto;padding:14px 28px;display:flex;align-items:center;gap:30px}
.logo{display:flex;align-items:center;gap:11px;font-family:"Space Grotesk";font-weight:700;font-size:18px;color:var(--text);text-decoration:none}
.logo-mark{width:32px;height:32px;border-radius:9px;background:linear-gradient(135deg,var(--indigo),var(--violet));display:grid;place-items:center;box-shadow:0 6px 20px rgba(124,131,255,.45)}
.logo-mark svg{width:17px;height:17px}
.nav-cta{margin-left:auto;display:flex;gap:10px;align-items:center}
.btn{font-family:inherit;font-weight:600;font-size:14.5px;border:none;cursor:pointer;border-radius:999px;padding:10px 18px;text-decoration:none;display:inline-flex;align-items:center;gap:8px;transition:transform .18s var(--ease),background .2s}
.btn-ghost{background:transparent;color:var(--text);border:1px solid var(--stroke-2)}
.btn-ghost:hover{background:var(--panel-2)}
.btn-primary{background:linear-gradient(120deg,var(--indigo),var(--violet));color:#fff;box-shadow:0 8px 24px rgba(124,131,255,.4)}
main{position:relative;z-index:2;padding:40px 24px 80px}
.wrap{max-width:780px;margin:0 auto}
.alerta-rev{background:rgba(255,207,107,.10);border:1px solid rgba(255,207,107,.32);color:#ffcf6b;padding:14px 16px;border-radius:13px;font-size:14px;margin-bottom:24px;line-height:1.55}
.alerta-rev strong{color:#fff}
h1{font-family:"Space Grotesk";font-size:34px;font-weight:700;letter-spacing:-.02em;margin-bottom:8px}
.meta{color:var(--faint);font-size:13.5px;margin-bottom:30px}
.indice{background:var(--panel);border:1px solid var(--stroke);border-radius:14px;padding:18px 22px;margin-bottom:28px}
.indice h3{font-family:"Space Grotesk";font-size:14px;font-weight:600;text-transform:uppercase;letter-spacing:.08em;color:var(--muted);margin-bottom:10px}
.indice ol{padding-left:18px;font-size:14px;color:var(--muted);line-height:1.85}
.indice a{color:var(--cyan);text-decoration:none}
.indice a:hover{text-decoration:underline}
section{margin-bottom:30px}
section h2{font-family:"Space Grotesk";font-size:20px;font-weight:700;color:var(--text);margin-bottom:10px;scroll-margin-top:84px}
section h2 .num{display:inline-block;width:30px;height:30px;border-radius:9px;background:linear-gradient(135deg,var(--indigo),var(--violet));color:#fff;text-align:center;line-height:30px;font-size:14px;margin-right:10px;vertical-align:middle}
section p,section li{font-size:14.8px;color:var(--text);line-height:1.7;margin-bottom:10px}
section p{color:#d5d3eb}
section ul,section ol{padding-left:22px;margin:8px 0 14px}
section li{color:#d5d3eb}
section li strong{color:var(--text)}
section .destaque{background:var(--panel);border:1px solid var(--stroke);border-radius:12px;padding:14px 16px;margin:12px 0;color:#d5d3eb;font-size:14.5px}
.tabela{width:100%;border-collapse:collapse;font-size:14px;margin:10px 0 16px;background:var(--panel);border:1px solid var(--stroke);border-radius:12px;overflow:hidden}
.tabela th,.tabela td{padding:11px 14px;text-align:left;border-bottom:1px solid var(--stroke);vertical-align:top;color:#d5d3eb}
.tabela th{background:rgba(255,255,255,.04);color:var(--text);font-weight:600;font-size:13px;text-transform:uppercase;letter-spacing:.04em}
.tabela tr:last-child td{border-bottom:none}
.foot-nav{margin-top:40px;text-align:center;font-size:14px;color:var(--muted)}
.foot-nav a{color:var(--cyan);text-decoration:none;margin:0 10px}
</style>
</head>
<body>
<div class="aurora-bg"></div>
<nav>
  <div class="nav-in">
    <a href="/" class="logo">
      <span class="logo-mark"><svg viewBox="0 0 24 24" fill="none"><path d="M4 6h16M4 12h10M4 18h13" stroke="#fff" stroke-width="2.2" stroke-linecap="round"/><circle cx="18.5" cy="13.5" r="3.2" stroke="#fff" stroke-width="2"/></svg></span>
      ReadMyLabs
    </a>
    <div class="nav-cta"><?= $navUser ?></div>
  </div>
</nav>

<main><div class="wrap">

<?php if ($placeholdersPendentes): ?>
<div class="alerta-rev">
  <strong>Revisão pendente antes de publicar.</strong>
  Esta política contém placeholders ainda não preenchidos:
  <em><?= htmlspecialchars(implode(', ', $placeholdersPendentes)) ?></em>.
  Edite as variáveis no topo de <code>politica-de-privacidade.php</code> e republique antes
  de comunicar a política como oficial. Este aviso some automaticamente quando todos os campos forem preenchidos.
</div>
<?php endif; ?>

<h1>Política de Privacidade</h1>
<div class="meta">
  Versão <?= htmlspecialchars($RML_VERSAO_POLITICA) ?> · Em vigor desde <?= htmlspecialchars($RML_DATA_VIGENCIA) ?> ·
  Lei Geral de Proteção de Dados (Lei nº 13.709/2018 — LGPD).
</div>

<div class="indice">
  <h3>Sumário</h3>
  <ol>
    <li><a href="#s1">Quem somos</a></li>
    <li><a href="#s2">O que esta política cobre</a></li>
    <li><a href="#s3">Quais dados coletamos</a></li>
    <li><a href="#s4">O que NÃO armazenamos</a></li>
    <li><a href="#s5">Por que tratamos seus dados (bases legais)</a></li>
    <li><a href="#s6">Com quem compartilhamos</a></li>
    <li><a href="#s7">Por quanto tempo guardamos</a></li>
    <li><a href="#s8">Como protegemos seus dados</a></li>
    <li><a href="#s9">Seus direitos (LGPD art. 18)</a></li>
    <li><a href="#s10">Cookies e analytics</a></li>
    <li><a href="#s11">Crianças e adolescentes</a></li>
    <li><a href="#s12">Aviso médico</a></li>
    <li><a href="#s13">Mudanças nesta política e contato</a></li>
  </ol>
</div>

<section id="s1">
  <h2><span class="num">1</span>Quem somos</h2>
  <p>
    O <strong>ReadMyLabs</strong> (<a href="https://readmylabs.com.br" style="color:var(--cyan);text-decoration:none">readmylabs.com.br</a>)
    é uma ferramenta educativa que ajuda pessoas leigas a entender exames laboratoriais e
    de imagem, e a obter orientação informativa sobre sintomas. Não somos clínica, não
    emitimos laudo e não substituímos atendimento médico.
  </p>
  <div class="destaque">
    <strong>Controlador dos dados</strong> (LGPD art. 5º VI):<br>
    <?= htmlspecialchars($RML_CTRL_NOME) ?> — <?= htmlspecialchars($RML_CTRL_DOC) ?> — <?= htmlspecialchars($RML_CTRL_CIDADE) ?>.<br>
    Contato: <?= htmlspecialchars($RML_CTRL_CONTATO) ?>.
  </div>
</section>

<section id="s2">
  <h2><span class="num">2</span>O que esta política cobre</h2>
  <p>Esta política descreve:</p>
  <ul>
    <li>quais dados pessoais o ReadMyLabs coleta quando você usa o site;</li>
    <li>como e por que tratamos esses dados;</li>
    <li>com quem podemos compartilhar (subprocessadores e parceiros);</li>
    <li>quanto tempo retemos cada dado;</li>
    <li>como você pode exercer os direitos garantidos pela LGPD.</li>
  </ul>
  <p>
    Ao usar o ReadMyLabs, você confirma que leu e concorda com esta política. Você não
    precisa criar conta para usar a ferramenta — neste caso, o tratamento de dados é
    significativamente menor (ver seção 3).
  </p>
</section>

<section id="s3">
  <h2><span class="num">3</span>Quais dados coletamos</h2>

  <p><strong>3.1. Uso anônimo (sem conta).</strong> Quando você usa o ReadMyLabs sem se cadastrar:</p>
  <ul>
    <li>O <strong>conteúdo do exame ou da descrição de sintomas</strong> é processado em tempo real para gerar a resposta e <strong>não é armazenado</strong> em nosso banco depois disso.</li>
    <li>Registramos apenas <strong>telemetria mínima</strong> (data/hora, tipo de análise, quantidade de tokens consumidos, contador de cache) e o <strong>hash SHA-256 do seu IP</strong> para controle de abuso (limite diário por IP). O IP em claro não é guardado.</li>
    <li>Para impedir uso automatizado, exigimos o reCAPTCHA do Google em cada análise (ver seção 6).</li>
  </ul>

  <p><strong>3.2. Conta de usuário.</strong> Quando você se cadastra, coletamos:</p>
  <ul>
    <li><strong>E-mail</strong> (login e comunicação) e, opcionalmente, <strong>nome</strong>.</li>
    <li><strong>Senha</strong>, armazenada apenas como <em>hash criptográfico</em> (bcrypt/argon2) — nem nós conseguimos lê-la em claro.</li>
    <li><strong>Hash do IP</strong> e do <em>user-agent</em> do dispositivo no momento do cadastro/sessão.</li>
    <li><strong>Status de verificação de e-mail</strong> e <strong>plano</strong> (free/avulsa/ilimitado).</li>
  </ul>

  <p><strong>3.3. Histórico de análises (apenas para conta logada e e-mail verificado).</strong>
    Quando você está logado(a) e com e-mail verificado, suas análises ficam salvas em
    histórico criptografado para você consultar depois. O conteúdo armazenado depende do tipo:</p>
  <table class="tabela">
    <tr><th>Tipo</th><th>O que é guardado</th><th>Criptografia?</th></tr>
    <tr><td>Exame laboratorial</td><td>Marcadores classificados, explicações, conclusão, nome do arquivo</td><td>Sim (AES-256-GCM)</td></tr>
    <tr><td>Sintomas</td><td>Descrição, duração, intensidade, resposta gerada</td><td>Sim (AES-256-GCM)</td></tr>
    <tr><td>"O que é este exame?"</td><td>Pergunta e resposta</td><td>Sim (AES-256-GCM)</td></tr>
    <tr><td>Exame de imagem (raio-X, ressonância etc.)</td><td><strong>Só o título do exame</strong>. Laudo, imagem e explicação <strong>não</strong> são salvos.</td><td>Não se aplica — não há conteúdo salvo</td></tr>
  </table>

  <p><strong>3.4. Telemetria de uso e segurança.</strong></p>
  <ul>
    <li>Contagem de uso diário por usuário (para aplicar a cota do plano).</li>
    <li>Tentativas de login bem-sucedidas e falhas (anti-<em>brute force</em>) com hash do IP e do e-mail normalizado.</li>
    <li>Tokens efêmeros de verificação de e-mail e redefinição de senha (válidos por curtos períodos).</li>
    <li>Logs do servidor com eventos de segurança (reCAPTCHA falho, limite atingido) — sem conteúdo do exame.</li>
  </ul>
</section>

<section id="s4">
  <h2><span class="num">4</span>O que NÃO armazenamos</h2>
  <ul>
    <li><strong>Arquivos PDF/imagem que você envia.</strong> Eles são processados em memória durante a análise e descartados ao final — nunca ficam no nosso disco. PDFs digitais nem chegam ao nosso servidor (o texto é extraído pelo seu próprio navegador via PDF.js).</li>
    <li><strong>Laudo radiológico nem a imagem do exame de imagem</strong>, nem a explicação que geramos para você. Apenas o título do exame fica registrado, sem nenhum conteúdo clínico — por uma decisão deliberada de proteção à sua privacidade.</li>
    <li><strong>Seu IP em claro.</strong> Só registramos o hash (SHA-256), que serve para limite anti-abuso, mas não permite identificá-lo individualmente.</li>
    <li><strong>Dados de cartão de crédito</strong>, porque não temos integração de pagamento direta hoje.</li>
  </ul>
</section>

<section id="s5">
  <h2><span class="num">5</span>Por que tratamos seus dados (bases legais)</h2>
  <p>Cada tratamento tem uma base legal específica (LGPD art. 7º e 11):</p>
  <table class="tabela">
    <tr><th>Finalidade</th><th>Base legal</th></tr>
    <tr><td>Operação da conta (cadastro, login, senha, e-mail de verificação)</td><td>Execução de contrato (art. 7º V)</td></tr>
    <tr><td><strong>Histórico de análises (dados de saúde)</strong></td><td><strong>Consentimento específico</strong> (art. 11 I) — manifestado no cadastro e revogável a qualquer momento (basta apagar suas análises ou sua conta)</td></tr>
    <tr><td>Prevenção de fraude e abuso (limite por IP, reCAPTCHA, throttling de login, logs)</td><td>Legítimo interesse (art. 7º IX)</td></tr>
    <tr><td>Análise estatística agregada (Google Analytics 4)</td><td>Consentimento via banner (art. 7º I)</td></tr>
    <tr><td>Cumprimento de obrigação legal ou ordem judicial</td><td>Obrigação legal (art. 7º II)</td></tr>
  </table>
</section>

<section id="s6">
  <h2><span class="num">6</span>Com quem compartilhamos</h2>
  <p>Não vendemos seus dados. Compartilhamos apenas com prestadores indispensáveis ao serviço, todos contratados sob acordos de processamento de dados:</p>
  <table class="tabela">
    <tr><th>Parceiro</th><th>Para quê</th><th>O que recebe</th></tr>
    <tr><td><strong>Anthropic</strong> (Claude API)</td><td>Gerar a interpretação da sua análise em linguagem leiga</td><td>Texto do exame ou descrição de sintomas, no momento da chamada. A Anthropic declara não usar inputs da API para treinar modelos.</td></tr>
    <tr><td><strong>Voyage AI</strong></td><td>Calcular embeddings vetoriais da base de definições de exames (RAG)</td><td>Só textos da base de referência, <strong>não</strong> dados do seu exame.</td></tr>
    <tr><td><strong>Resend</strong></td><td>Enviar e-mails transacionais (verificação, recuperação de senha)</td><td>Seu e-mail e o conteúdo da mensagem.</td></tr>
    <tr><td><strong>Google reCAPTCHA</strong></td><td>Verificar se você é humano</td><td>Sinais técnicos de navegação que o Google define em sua própria política.</td></tr>
    <tr><td><strong>Google Analytics 4</strong></td><td>Estatísticas agregadas de uso (com seu consentimento)</td><td>Identificador anônimo de sessão, páginas visitadas, eventos de interação. Sem dados de exame.</td></tr>
    <tr><td><strong>Hostinger</strong></td><td>Hospedagem do site, banco de dados e e-mail</td><td>Toda a infraestrutura roda na Hostinger. Os dados ficam armazenados nos servidores deles.</td></tr>
  </table>
  <p>Podemos compartilhar dados também para cumprir <strong>obrigação legal</strong> ou <strong>ordem judicial</strong>, sempre nos limites do estritamente necessário.</p>
</section>

<section id="s7">
  <h2><span class="num">7</span>Por quanto tempo guardamos</h2>
  <ul>
    <li><strong>Dados da conta</strong> (e-mail, senha hash, nome): enquanto a conta existir. Ao excluir a conta, são apagados em até 24h.</li>
    <li><strong>Histórico de análises</strong>: enquanto você não apagar. Você pode apagar item por item, todas de uma vez ("Apagar tudo") ou junto com a conta. Não temos prazo automático de expiração.</li>
    <li><strong>Sessões ativas</strong>: até 30 dias ou até você sair da conta.</li>
    <li><strong>Telemetria anônima e logs de segurança</strong>: até 90 dias.</li>
    <li><strong>Tokens efêmeros</strong> (verificação de e-mail, redefinição de senha): expiram em 24h–72h e são marcados como usados após o consumo.</li>
    <li><strong>Backups</strong>: podem manter cópias por até 30 dias adicionais por razões de continuidade do serviço.</li>
  </ul>
</section>

<section id="s8">
  <h2><span class="num">8</span>Como protegemos seus dados</h2>
  <p>Adotamos medidas técnicas e administrativas razoáveis para proteger seus dados (LGPD art. 46):</p>
  <ul>
    <li><strong>Criptografia em repouso</strong> do histórico clínico: AES-256-GCM (com verificação de integridade — alteração no banco é detectada e o conteúdo é rejeitado). A chave fica fora do banco de dados.</li>
    <li><strong>Criptografia em trânsito</strong>: HTTPS obrigatório em todo o site (TLS).</li>
    <li><strong>Senhas</strong> nunca armazenadas em claro: apenas como <em>hash</em> com função de derivação de senha (bcrypt/argon2).</li>
    <li><strong>Cookie de sessão</strong> HttpOnly, Secure e SameSite=Lax — não acessível por JavaScript.</li>
    <li><strong>Proteções contra CSRF</strong> em todas as ações sensíveis (formulários da área logada).</li>
    <li><strong>Limite de tentativas</strong> de login (anti-<em>brute force</em>) e reCAPTCHA em todas as análises.</li>
    <li>Restrições no servidor para bloquear acesso direto a arquivos de configuração e bibliotecas internas.</li>
  </ul>
  <p>Mesmo com essas medidas, nenhum sistema é 100% inviolável. Em caso de incidente de segurança que possa acarretar risco ou dano relevante aos titulares, comunicaremos os titulares afetados e a ANPD conforme art. 48 da LGPD.</p>
</section>

<section id="s9">
  <h2><span class="num">9</span>Seus direitos (LGPD art. 18)</h2>
  <p>Como titular, você pode, a qualquer momento:</p>
  <ul>
    <li><strong>Confirmar</strong> se tratamos seus dados e <strong>acessá-los</strong> — para os dados da conta, em <a href="/conta.php" style="color:var(--cyan)">Minha conta</a>; para o histórico, em <a href="/historico.php" style="color:var(--cyan)">Meus exames</a>.</li>
    <li><strong>Corrigir</strong> dados incompletos, inexatos ou desatualizados.</li>
    <li><strong>Eliminar</strong> dados tratados com base no consentimento: você pode apagar uma análise específica, todo o histórico, ou excluir a conta inteira (botão em "Minha conta"). A exclusão da conta apaga em cascata: histórico, sessões e tokens.</li>
    <li><strong>Solicitar a portabilidade</strong> dos dados a outro fornecedor (mediante pedido a <?= htmlspecialchars($RML_CTRL_DPO_EMAIL) ?>).</li>
    <li><strong>Revogar o consentimento</strong>: na prática, apagar o histórico ou a conta produz esse efeito.</li>
    <li><strong>Informações</strong> sobre o uso compartilhado de seus dados — descritas na seção 6 desta política.</li>
    <li><strong>Reclamar perante a ANPD</strong> (Autoridade Nacional de Proteção de Dados): <a href="https://www.gov.br/anpd" target="_blank" rel="noopener" style="color:var(--cyan)">gov.br/anpd</a>.</li>
  </ul>
  <p>Solicitações que não puderem ser atendidas pela própria interface devem ser feitas ao DPO (encarregado): <strong><?= htmlspecialchars($RML_CTRL_DPO_EMAIL) ?></strong>. Responderemos em até 15 dias úteis.</p>
</section>

<section id="s10">
  <h2><span class="num">10</span>Cookies e analytics</h2>
  <p>Usamos os seguintes cookies/identificadores:</p>
  <table class="tabela">
    <tr><th>Identificador</th><th>Para quê</th><th>Categoria</th></tr>
    <tr><td><code>rml_sess</code></td><td>Manter sua sessão de login</td><td>Estritamente necessário</td></tr>
    <tr><td><code>PHPSESSID</code></td><td>Suporte ao token anti-CSRF</td><td>Estritamente necessário</td></tr>
    <tr><td>Cookies do reCAPTCHA</td><td>Verificação anti-bot do Google</td><td>Estritamente necessário (segurança)</td></tr>
    <tr><td>Cookies do <strong>Google Analytics 4</strong> (medição com Consent Mode v2)</td><td>Estatísticas agregadas de uso (páginas vistas, conversões)</td><td>Mediante consentimento (banner)</td></tr>
  </table>
  <p>Cookies não-essenciais só são instalados depois que você aceita no banner de consentimento. Você pode revogar a qualquer momento limpando os cookies do site no seu navegador.</p>
</section>

<section id="s11">
  <h2><span class="num">11</span>Crianças e adolescentes</h2>
  <p>
    O ReadMyLabs é destinado a maiores de 18 anos. Não coletamos intencionalmente dados de
    crianças ou adolescentes. Caso identifiquemos uma conta criada por menor, ela será
    encerrada e os dados eliminados, conforme art. 14 da LGPD.
  </p>
</section>

<section id="s12">
  <h2><span class="num">12</span>Aviso médico</h2>
  <div class="destaque">
    O ReadMyLabs <strong>não é um dispositivo médico</strong>, <strong>não emite laudo</strong>
    e <strong>não substitui consulta com profissional de saúde</strong>. As explicações são
    geradas por inteligência artificial em linguagem leiga e têm caráter <strong>estritamente
    educativo</strong>. Em caso de dúvida ou sintoma preocupante, procure um médico. Em
    urgências, vá a um pronto-socorro ou ligue 192 (SAMU).
  </div>
</section>

<section id="s13">
  <h2><span class="num">13</span>Mudanças nesta política e contato</h2>
  <p>
    Podemos atualizar esta política para refletir mudanças no serviço, na lei ou em boas
    práticas. A versão e a data de vigência ficam no topo da página. Mudanças relevantes
    serão comunicadas por e-mail aos usuários cadastrados.
  </p>
  <p>
    <strong>Contato geral:</strong> <?= htmlspecialchars($RML_CTRL_CONTATO) ?><br>
    <strong>Encarregado (DPO):</strong> <?= htmlspecialchars($RML_CTRL_DPO_EMAIL) ?><br>
    <strong>Foro de eleição:</strong> <?= htmlspecialchars($RML_CTRL_CIDADE) ?>.
  </p>
</section>

<div class="foot-nav">
  <a href="/">Voltar ao app</a>
  <?php if ($u): ?> · <a href="/conta.php">Minha conta</a><?php endif; ?>
</div>

</div></main>

<?php echo analyticsEventoFn(); analyticsBanner(); ?>
</body>
</html>
