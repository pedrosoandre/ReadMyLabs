<?php
// ReadMyLabs — layout compartilhado das páginas de autenticação.
// Mantém a identidade visual do index.html (Aurora, dark, mesma família tipográfica).
// Sem dependência de framework — HTML inline.

require_once __DIR__ . '/sessao.php';
require_once __DIR__ . '/../../lib/analytics.php';

function authRenderTopo(string $titulo, ?string $msg = null, string $msgTipo = 'erro'): void {
    $u   = sessaoAtual();
    $msgHtml = '';
    if ($msg) {
        $cor = $msgTipo === 'ok'   ? '#52e2b0'
             : ($msgTipo === 'info' ? '#5fe3ff' : '#ff7d8a');
        $bg  = $msgTipo === 'ok'   ? 'rgba(82,226,176,.12)'
             : ($msgTipo === 'info' ? 'rgba(95,227,255,.10)' : 'rgba(255,125,138,.10)');
        $bd  = $msgTipo === 'ok'   ? 'rgba(82,226,176,.32)'
             : ($msgTipo === 'info' ? 'rgba(95,227,255,.32)' : 'rgba(255,125,138,.32)');
        $msgHtml = '<div class="auth-msg" style="border-color:' . $bd . ';background:' . $bg . ';color:' . $cor . '">' . htmlspecialchars($msg) . '</div>';
    }
    $linkHist = ($u && !empty($u['email_verificado']))
        ? '<a href="/historico.php" class="btn btn-ghost">Meus exames</a>'
        : '';
    $navUser = $u
        ? $linkHist . '<a href="/conta.php" class="btn btn-ghost">' . htmlspecialchars($u['nome'] ?: $u['email']) . '</a><a href="/sair.php" class="btn btn-ghost">Sair</a>'
        : '<a href="/entrar.php" class="btn btn-ghost">Entrar</a><a href="/cadastro.php" class="btn btn-primary">Cadastrar</a>';

    $t = htmlspecialchars($titulo);
    ob_start(); analyticsHead(); $gaHead = ob_get_clean();
    echo <<<HTML
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex">
$gaHead
<title>$t · ReadMyLabs</title>
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
.btn-primary:hover{transform:translateY(-1px)}
.btn-block{width:100%;justify-content:center;padding:13px 22px;font-size:15px}
main{position:relative;z-index:2;padding:60px 24px 80px}
.auth-card{max-width:440px;margin:0 auto;background:var(--panel);border:1px solid var(--stroke);border-radius:20px;padding:32px 30px;backdrop-filter:blur(20px);box-shadow:0 40px 90px -40px rgba(0,0,0,.8)}
.auth-card h1{font-family:"Space Grotesk";font-size:26px;font-weight:700;letter-spacing:-.02em;margin-bottom:6px}
.auth-card .sub{font-size:14.5px;color:var(--muted);margin-bottom:22px}
.auth-msg{padding:11px 14px;border-radius:10px;border:1px solid;font-size:14px;margin-bottom:18px;line-height:1.45}
.field{margin-bottom:14px}
.field label{display:block;font-size:13px;color:var(--muted);margin-bottom:6px;font-weight:500}
.field input,.field textarea{width:100%;padding:11px 14px;border-radius:11px;background:rgba(255,255,255,.04);border:1px solid var(--stroke);color:var(--text);font-size:15px;font-family:inherit;transition:border .2s,background .2s}
.field input:focus,.field textarea:focus{outline:none;border-color:var(--indigo);background:rgba(124,131,255,.07)}
.field .hint{font-size:12.5px;color:var(--faint);margin-top:5px}
.check{display:flex;gap:9px;align-items:flex-start;font-size:13.5px;color:var(--muted);line-height:1.5;margin:14px 0}
.check input{margin-top:3px;accent-color:var(--indigo)}
.auth-foot{margin-top:16px;text-align:center;font-size:14px;color:var(--muted)}
.auth-foot a{color:var(--cyan);text-decoration:none}
.auth-foot a:hover{text-decoration:underline}
.divider{display:flex;align-items:center;gap:12px;font-size:12px;color:var(--faint);margin:18px 0;text-transform:uppercase;letter-spacing:.1em}
.divider::before,.divider::after{content:"";flex:1;height:1px;background:var(--stroke)}
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
    <div class="nav-cta">$navUser</div>
  </div>
</nav>
<main>
  <div class="auth-card">
    $msgHtml
HTML;
}

function authRenderRodape(): void {
    ob_start(); echo analyticsEventoFn(); analyticsBanner(); $gaTail = ob_get_clean();
    echo <<<HTML
  </div>
</main>
$gaTail
</body>
</html>
HTML;
}
