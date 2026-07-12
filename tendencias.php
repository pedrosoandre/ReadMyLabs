<?php
// ReadMyLabs — página "Evolução" (Onda 2: linha do tempo dos marcadores).
// Transforma exames isolados numa trajetória. O cálculo da tendência é LOCAL
// (zero token): descriptografa só o histórico do próprio usuário e monta séries.
// Exige sessão + e-mail verificado + crypto ativa (mesmo gate do histórico F4).

declare(strict_types=1);

require_once __DIR__ . '/loads_env.php';
loadEnv();

require_once __DIR__ . '/auth/lib/sessao.php';
require_once __DIR__ . '/auth/lib/layout.php';
require_once __DIR__ . '/auth/lib/exames_repo.php';
require_once __DIR__ . '/lib/crypto.php';

$u = exigirLogin();

// E-mail não verificado: sem evolução (mesma regra do histórico).
if (empty($u['email_verificado'])) {
    header('Location: /conta.php');
    exit;
}

$cryptoOK = criptoHabilitado();
$series   = $cryptoOK ? exameSerieMarcadores((int) $u['id']) : [];

authRenderTopo('Evolução');
?>
<style>
/* esta página precisa de mais largura que o auth-card padrão (dashboard) */
.auth-card{max-width:760px}
.tend-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-top:6px}
@media(max-width:640px){.tend-grid{grid-template-columns:1fr}}
.tend-card{background:var(--panel);border:1px solid var(--stroke);border-radius:14px;padding:15px 16px}
.tend-top{display:flex;justify-content:space-between;align-items:flex-start;gap:10px;margin-bottom:8px}
.tend-name{font-weight:600;font-size:14.5px;color:var(--text)}
.tend-meta{font-size:11.5px;color:var(--faint);margin-top:2px}
.tend-val{text-align:right;font-family:"Space Grotesk";font-weight:700;font-size:17px;white-space:nowrap}
.tend-badge{display:inline-block;font-size:10.5px;font-weight:600;padding:2px 8px;border-radius:999px;margin-top:3px}
.tend-delta{font-size:12.5px;font-weight:600;margin-top:2px}
.tend-spark{width:100%;height:64px;margin-top:6px}
</style>

<h1>Evolução</h1>
<div class="sub">Como seus marcadores se movem ao longo dos exames. Isto é informativo — quem interpreta a tendência é o seu médico.</div>

<?php if (!$cryptoOK): ?>
  <div class="auth-msg" style="border-color:rgba(255,207,107,.32);background:rgba(255,207,107,.10);color:#ffcf6b">
    A evolução está temporariamente indisponível. Tente novamente em alguns minutos.
  </div>
<?php elseif (!$series): ?>
  <div style="padding:22px 0;color:var(--muted);font-size:14.5px;line-height:1.6">
    Ainda não há dados suficientes para montar sua linha do tempo. Assim que você tiver
    <strong style="color:var(--text)">dois ou mais exames laboratoriais</strong> com o mesmo marcador,
    a evolução dele aparece aqui automaticamente.
  </div>
  <a href="/" class="btn btn-primary btn-block" style="margin-top:8px">Analisar um exame</a>
<?php else: ?>
  <div style="font-size:13px;color:var(--faint);margin:10px 0 4px"><?= count($series) ?> marcador(es) acompanhado(s)</div>
  <div class="tend-grid" id="tendGrid"></div>
<?php endif; ?>

<a href="/historico.php" class="btn btn-ghost btn-block" style="margin-top:18px">Meus exames</a>
<a href="/" class="btn btn-ghost btn-block" style="margin-top:8px">Voltar ao app</a>
<div class="auth-foot">
  <a href="/conta.php">Minha conta</a> ·
  <a href="/politica-de-privacidade.php">Política de Privacidade</a>
</div>

<script>
(function(){
  const SERIES = <?= json_encode($series, JSON_UNESCAPED_UNICODE) ?>;
  const grid = document.getElementById('tendGrid');
  if (!grid || !SERIES.length) return;

  function esc(s){ return String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])); }
  function corStatus(s){ return s==='critico'?'#ff7d8a':(s==='alto'||s==='baixo')?'#ffcf6b':'#52e2b0'; }
  function rotStatus(s){ return s==='critico'?'Requer atenção':s==='alto'?'Acima':s==='baixo'?'Abaixo':'Normal'; }
  function fmt(n){ const v=Number(n); return Number.isFinite(v)?v.toLocaleString('pt-BR',{maximumFractionDigits:2}):n; }
  function dataCurta(s){ try{ return new Date(String(s).replace(' ','T')).toLocaleDateString('pt-BR',{day:'2-digit',month:'2-digit',year:'2-digit'}); }catch(e){ return ''; } }

  // sparkline SVG com faixa de referência
  function spark(pts, refMin, refMax){
    const W=300, H=64, pad=8;
    const vals = pts.map(p=>p.valor);
    let lo = Math.min(...vals), hi = Math.max(...vals);
    if (refMin!=null) lo = Math.min(lo, refMin);
    if (refMax!=null) hi = Math.max(hi, refMax);
    if (hi===lo){ hi=lo+1; lo=lo-1; }
    const span = hi-lo;
    const x = i => pad + (W-2*pad) * (pts.length===1?0.5:(i/(pts.length-1)));
    const y = v => H-pad - (H-2*pad) * ((v-lo)/span);
    let band = '';
    if (refMin!=null || refMax!=null){
      const yTop = y(refMax!=null?refMax:hi);
      const yBot = y(refMin!=null?refMin:lo);
      band = `<rect x="0" y="${yTop.toFixed(1)}" width="${W}" height="${Math.max(1,(yBot-yTop)).toFixed(1)}" fill="rgba(82,226,176,.10)"/>`;
    }
    const line = pts.map((p,i)=>`${i?'L':'M'}${x(i).toFixed(1)},${y(p.valor).toFixed(1)}`).join(' ');
    const dots = pts.map((p,i)=>`<circle cx="${x(i).toFixed(1)}" cy="${y(p.valor).toFixed(1)}" r="3" fill="${corStatus(p.status)}"/>`).join('');
    return `<svg class="tend-spark" viewBox="0 0 ${W} ${H}" preserveAspectRatio="none" xmlns="http://www.w3.org/2000/svg">
      ${band}<path d="${line}" fill="none" stroke="#7c83ff" stroke-width="2" stroke-linejoin="round" stroke-linecap="round"/>${dots}</svg>`;
  }

  SERIES.forEach(s => {
    const pts = s.pontos;
    const first = pts[0].valor, last = pts[pts.length-1].valor;
    const ult = pts[pts.length-1];
    const delta = last - first;
    const arrow = Math.abs(delta) < 1e-9 ? '→' : (delta>0 ? '↑' : '↓');
    const pctTxt = (first!==0) ? ('  ('+(delta>0?'+':'')+(delta/Math.abs(first)*100).toFixed(0)+'%)') : '';
    const deltaCor = Math.abs(delta) < 1e-9 ? '#a6a4c0' : (delta>0 ? '#ffcf6b' : '#5fe3ff');
    const badgeCor = corStatus(ult.status);
    const el = document.createElement('div');
    el.className = 'tend-card';
    el.innerHTML =
      `<div class="tend-top">
         <div style="min-width:0">
           <div class="tend-name">${esc(s.nome)}</div>
           <div class="tend-meta">${pts.length} exames · ${dataCurta(pts[0].data)} → ${dataCurta(ult.data)}</div>
         </div>
         <div>
           <div class="tend-val">${fmt(last)} <span style="font-size:12px;color:var(--faint);font-weight:500">${esc(s.unidade||'')}</span></div>
           <span class="tend-badge" style="color:${badgeCor};background:${badgeCor}22">${rotStatus(ult.status)}</span>
         </div>
       </div>
       <div class="tend-delta" style="color:${deltaCor}">${arrow} de ${fmt(first)} para ${fmt(last)}${pctTxt}</div>
       ${spark(pts, s.ref_min, s.ref_max)}`;
    grid.appendChild(el);
  });
})();
</script>
<?php authRenderRodape();
