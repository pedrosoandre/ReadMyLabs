<?php
// ReadMyLabs — interface única para Google Analytics 4 / Google Ads (gtag).
// Falha-para-desligado: sem GA_MEASUREMENT_ID no .env, renderiza string vazia.
//
// Padrão: Consent Mode v2 — gtag carrega sempre, mas com tudo "denied".
// O banner LGPD (analyticsBanner) é quem grava `rml_consent` e atualiza para
// "granted". Sem aceite, o GA recebe só pings cookieless (LGPD-compliant).

require_once __DIR__ . '/../loads_env.php';

function gaId(): string {
    loadEnv();
    return (string) (getenv('GA_MEASUREMENT_ID') ?: '');
}

function gtagAdsId(): string {
    loadEnv();
    return (string) (getenv('GADS_CONVERSION_ID') ?: '');
}

/** Renderiza o snippet do gtag (carrega async). Coloque dentro do <head>. */
function analyticsHead(): void {
    $id = gaId();
    if ($id === '') return;
    $safeId  = htmlspecialchars($id, ENT_QUOTES);
    $adsId   = gtagAdsId();
    $safeAds = $adsId !== '' ? htmlspecialchars($adsId, ENT_QUOTES) : '';
    $extraConfig = $safeAds !== '' ? "gtag('config', '$safeAds');" : '';
    echo <<<HTML
<!-- Google tag (gtag.js) -->
<script async src="https://www.googletagmanager.com/gtag/js?id=$safeId"></script>
<script>
window.dataLayer = window.dataLayer || [];
function gtag(){dataLayer.push(arguments);}
gtag('consent','default',{ad_storage:'denied',ad_user_data:'denied',ad_personalization:'denied',analytics_storage:'denied',wait_for_update:500});
try{
  var m=document.cookie.match(/(?:^|;\\s*)rml_consent=([^;]+)/);
  if(m && m[1]==='all'){gtag('consent','update',{ad_storage:'granted',ad_user_data:'granted',ad_personalization:'granted',analytics_storage:'granted'});}
  else if(m && m[1]==='analytics'){gtag('consent','update',{analytics_storage:'granted'});}
}catch(e){}
gtag('js', new Date());
gtag('config', '$safeId', { anonymize_ip: true });
$extraConfig
</script>

HTML;
}

/** Banner LGPD (aceitar/recusar). Coloque antes do </body>. */
function analyticsBanner(): void {
    if (gaId() === '') return;
    echo <<<HTML
<style>
.rml-cc{position:fixed;left:16px;right:16px;bottom:16px;z-index:9999;background:rgba(13,13,22,.94);backdrop-filter:blur(14px);border:1px solid rgba(255,255,255,.14);border-radius:14px;padding:14px 18px;display:none;color:#f3f2fb;font-family:"Plus Jakarta Sans",system-ui,sans-serif;max-width:780px;margin:0 auto;box-shadow:0 20px 50px -20px rgba(0,0,0,.7)}
.rml-cc.show{display:flex;gap:14px;align-items:center;flex-wrap:wrap;animation:rml-cc-in .4s ease}
@keyframes rml-cc-in{from{opacity:0;transform:translateY(20px)}to{opacity:1;transform:translateY(0)}}
.rml-cc p{flex:1;min-width:240px;font-size:13.5px;color:#cac8e2;line-height:1.5;margin:0}
.rml-cc a{color:#5fe3ff;text-decoration:none}
.rml-cc a:hover{text-decoration:underline}
.rml-cc-btns{display:flex;gap:8px}
.rml-cc-btns button{font-family:inherit;font-weight:600;font-size:13px;border:none;cursor:pointer;border-radius:999px;padding:9px 16px;transition:transform .15s ease}
.rml-cc-btns button:hover{transform:translateY(-1px)}
.rml-cc-aceitar{background:linear-gradient(120deg,#7c83ff,#b478ff);color:#fff;box-shadow:0 6px 18px rgba(124,131,255,.4)}
.rml-cc-recusar{background:transparent;color:#f3f2fb;border:1px solid rgba(255,255,255,.2)}
</style>
<div class="rml-cc" id="rmlCc" role="dialog" aria-label="Aviso de cookies">
  <p>Usamos cookies para entender como o site é usado (Google Analytics). Sem aceitar, registramos só métricas agregadas sem identificação. Você pode mudar a qualquer momento.</p>
  <div class="rml-cc-btns">
    <button class="rml-cc-recusar" type="button" id="rmlCcRecusar">Recusar</button>
    <button class="rml-cc-aceitar" type="button" id="rmlCcAceitar">Aceitar</button>
  </div>
</div>
<script>
(function(){
  var el=document.getElementById('rmlCc');
  if(!el) return;
  function getC(){var m=document.cookie.match(/(?:^|;\\s*)rml_consent=([^;]+)/);return m?m[1]:'';}
  function setC(v){var d=new Date();d.setFullYear(d.getFullYear()+1);var sec=(location.protocol==='https:'?'; Secure':'');document.cookie='rml_consent='+v+'; expires='+d.toUTCString()+'; path=/; SameSite=Lax'+sec;}
  if(!getC()) el.classList.add('show');
  document.getElementById('rmlCcAceitar').onclick=function(){
    setC('all'); el.classList.remove('show');
    if(window.gtag){gtag('consent','update',{ad_storage:'granted',ad_user_data:'granted',ad_personalization:'granted',analytics_storage:'granted'});}
  };
  document.getElementById('rmlCcRecusar').onclick=function(){
    setC('none'); el.classList.remove('show');
  };
})();
</script>

HTML;
}

/** Helper JS para disparar eventos: rml.event('sign_up', {method:'email'}); */
function analyticsEventoFn(): string {
    if (gaId() === '') return '';
    return "<script>window.rml=window.rml||{};rml.event=function(n,p){try{if(window.gtag)gtag('event',n,p||{});}catch(e){}};</script>\n";
}
