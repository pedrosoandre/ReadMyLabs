<?php
// ReadMyLabs — página "Meus exames" (histórico do usuário).
// Lista metadados (decriptação só ao abrir um item, via fetch -> historico-ver.php).
// Exige sessão + e-mail verificado + crypto ativa.

declare(strict_types=1);

require_once __DIR__ . '/loads_env.php';
loadEnv();

require_once __DIR__ . '/auth/lib/sessao.php';
require_once __DIR__ . '/auth/lib/csrf.php';
require_once __DIR__ . '/auth/lib/layout.php';
require_once __DIR__ . '/auth/lib/exames_repo.php';
require_once __DIR__ . '/lib/crypto.php';

$u = exigirLogin();

// E-mail não verificado: não dá pra entrar (histórico só de quem confirmou e-mail).
if (empty($u['email_verificado'])) {
    header('Location: /conta.php');
    exit;
}

// Histórico desativado (sem chave de crypto): mostra mensagem clara.
$cryptoOK = criptoHabilitado();

$lista = $cryptoOK ? exameListar((int) $u['id'], 100) : [];
$csrf  = csrfTokenAtual();

authRenderTopo('Meus exames');
?>
<h1>Meus exames</h1>
<div class="sub">Suas análises ficam aqui — protegidas com criptografia em repouso. Só você (logado) consegue abrir.</div>

<?php if (!$cryptoOK): ?>
  <div class="auth-msg" style="border-color:rgba(255,207,107,.32);background:rgba(255,207,107,.10);color:#ffcf6b">
    O histórico está temporariamente indisponível. Tente novamente em alguns minutos.
  </div>
<?php elseif (!$lista): ?>
  <div style="padding:24px 0;color:var(--muted);font-size:14.5px">
    Você ainda não tem análises salvas. Faça uma análise no app — ela aparece aqui automaticamente.
  </div>
  <a href="/" class="btn btn-primary btn-block" style="margin-top:8px">Ir para o app</a>
<?php else: ?>
  <div style="display:flex;justify-content:space-between;align-items:center;margin:6px 0 12px">
    <span style="font-size:13px;color:var(--faint)"><?= count($lista) ?> análise(s)</span>
    <button id="btnApagarTudo" class="btn btn-ghost" style="font-size:13px;padding:7px 12px">Apagar tudo</button>
  </div>

  <ul id="histLista" style="list-style:none;display:grid;gap:8px;padding:0;margin:0">
    <?php foreach ($lista as $row):
      $id = (int) $row['id'];
      $tp = $row['tipo'];
      $tit = $row['titulo'] ?: ucfirst($tp);
      $rp = $row['resumo_publico'] ?: '';
      $dt = date('d/m/Y H:i', strtotime($row['criado_em']));
      $badge = ['exame' => 'Laboratorial', 'sintomas' => 'Sintomas', 'explicar' => 'Explicar exame', 'imagem' => 'Imagem'][$tp] ?? $tp;
    ?>
      <li data-id="<?= $id ?>"
          style="background:var(--panel);border:1px solid var(--stroke);border-radius:13px;padding:13px 15px;display:flex;flex-direction:column;gap:6px">
        <div style="display:flex;justify-content:space-between;gap:10px;align-items:flex-start">
          <div style="min-width:0;flex:1">
            <div style="font-weight:600;font-size:14.5px;color:var(--text);white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?= htmlspecialchars($tit) ?></div>
            <div style="font-size:12.5px;color:var(--muted);margin-top:2px"><?= htmlspecialchars($rp) ?></div>
            <div style="font-size:12px;color:var(--faint);margin-top:3px"><?= $badge ?> · <?= $dt ?></div>
          </div>
          <div style="display:flex;gap:6px;flex-shrink:0">
            <button class="btn btn-ghost btn-abrir" style="font-size:13px;padding:7px 13px">Abrir</button>
            <button class="btn btn-ghost btn-apagar" style="font-size:13px;padding:7px 12px;border-color:rgba(255,125,138,.32);color:#ff7d8a">Apagar</button>
          </div>
        </div>
      </li>
    <?php endforeach; ?>
  </ul>
<?php endif; ?>

<a href="/" class="btn btn-ghost btn-block" style="margin-top:18px">Voltar ao app</a>
<div class="auth-foot"><a href="/conta.php">Minha conta</a></div>

<!-- Modal de visualização -->
<div id="histModal" style="display:none;position:fixed;inset:0;z-index:100;background:rgba(0,0,0,.7);backdrop-filter:blur(6px);align-items:center;justify-content:center;padding:24px">
  <div style="background:var(--bg-2);border:1px solid var(--stroke-2);border-radius:18px;max-width:720px;width:100%;max-height:90vh;overflow:auto;padding:26px">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px">
      <h2 id="modTitulo" style="font-family:'Space Grotesk';font-size:20px;font-weight:700"></h2>
      <button id="modFechar" class="btn btn-ghost" style="padding:6px 14px">Fechar</button>
    </div>
    <div id="modCorpo" style="font-size:14.5px;line-height:1.65;color:var(--text)"></div>
  </div>
</div>

<script>
(function(){
  const csrf = <?= json_encode($csrf) ?>;
  const modal = document.getElementById('histModal');
  const modTit = document.getElementById('modTitulo');
  const modCor = document.getElementById('modCorpo');
  document.getElementById('modFechar').onclick = () => { modal.style.display = 'none'; };
  modal.addEventListener('click', e => { if (e.target === modal) modal.style.display = 'none'; });

  // -- helpers de render --------
  function esc(s){ return String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])); }
  function corStatus(s){ return s==='normal'?'#52e2b0':s==='alto'||s==='baixo'?'#ffcf6b':s==='critico'?'#ff7d8a':'#a6a4c0'; }

  function renderExame(a){
    const r = a.resultado || {};
    const mks = r.marcadores || a.marcadores || [];
    let html = '';
    if (r.conclusao && r.conclusao.texto) {
      html += `<div style="background:rgba(255,255,255,.04);border:1px solid var(--stroke);border-radius:12px;padding:14px;margin-bottom:14px">
        <div style="font-weight:600;margin-bottom:6px">Síntese</div>
        <div style="color:var(--muted)">${esc(r.conclusao.texto)}</div></div>`;
    }
    if (mks.length){
      html += '<div style="display:grid;gap:6px">';
      mks.forEach(m => {
        html += `<div style="border:1px solid var(--stroke);border-radius:10px;padding:10px 12px">
          <div style="display:flex;justify-content:space-between;font-size:14px">
            <span style="font-weight:600">${esc(m.nome)}</span>
            <span style="color:${corStatus(m.status)}">${esc(m.valor ?? '')} ${esc(m.unidade ?? '')} <span style="opacity:.8;font-size:12.5px">(${esc(m.status)})</span></span>
          </div>
          ${m.explicacao ? `<div style="font-size:13px;color:var(--muted);margin-top:4px">${esc(m.explicacao)}</div>` : ''}
        </div>`;
      });
      html += '</div>';
    }
    if (r.nota) html += `<div style="margin-top:14px;font-size:12.5px;color:var(--faint)">${esc(r.nota)}</div>`;
    return html || '<div style="color:var(--muted)">Sem conteúdo legível.</div>';
  }

  function renderSintomas(a){
    const r = a.resultado || {};
    const m = a.marcadores || {};
    let html = '';
    if (m.sintomas) {
      html += `<div style="background:rgba(255,255,255,.04);border:1px solid var(--stroke);border-radius:12px;padding:12px 14px;margin-bottom:14px">
        <div style="font-weight:600;margin-bottom:4px">O que você descreveu</div>
        <div style="color:var(--muted);font-size:14px">${esc(m.sintomas)}</div>
        ${m.duracao ? `<div style="font-size:13px;color:var(--faint);margin-top:6px">Duração: ${esc(m.duracao)}</div>`:''}
        ${m.intensidade ? `<div style="font-size:13px;color:var(--faint)">Intensidade: ${esc(m.intensidade)}</div>`:''}
      </div>`;
    }
    if (r.resposta){
      html += `<div style="white-space:pre-wrap;color:var(--text)">${esc(r.resposta)}</div>`;
    }
    if (r.nota) html += `<div style="margin-top:14px;font-size:12.5px;color:var(--faint)">${esc(r.nota)}</div>`;
    return html;
  }

  function renderExplicar(a){
    const r = a.resultado || {};
    const m = a.marcadores || {};
    let html = '';
    if (m.pergunta){
      html += `<div style="color:var(--faint);font-size:13px;margin-bottom:8px">Pergunta: "${esc(m.pergunta)}"</div>`;
    }
    if (r.resposta){
      html += `<div style="color:var(--text);line-height:1.65">${esc(r.resposta)}</div>`;
    }
    if (r.fontes && r.fontes.length){
      html += '<div style="margin-top:12px;font-size:12.5px;color:var(--muted)">Fontes: ' + r.fontes.map(esc).join(', ') + '</div>';
    }
    return html;
  }

  function renderImagem(a){
    return `<div style="background:rgba(255,207,107,.08);border:1px solid rgba(255,207,107,.32);border-radius:12px;padding:14px;color:#ffcf6b">
      Por proteção à sua privacidade, exames de imagem (laudo e filme) não têm conteúdo salvo no histórico. Esta entrada serve só de registro.
    </div>`;
  }

  function renderAnalise(a){
    if (a.tipo === 'exame')    return renderExame(a);
    if (a.tipo === 'sintomas') return renderSintomas(a);
    if (a.tipo === 'explicar') return renderExplicar(a);
    if (a.tipo === 'imagem')   return renderImagem(a);
    return '<div style="color:var(--muted)">Tipo desconhecido.</div>';
  }

  // -- ações --------
  async function abrir(id){
    modTit.textContent = 'Carregando...';
    modCor.innerHTML = '<div style="color:var(--muted)">Decriptando análise...</div>';
    modal.style.display = 'flex';
    try{
      const fd = new FormData(); fd.append('csrf', csrf); fd.append('id', id);
      const r = await fetch('/historico-ver.php', { method:'POST', body: fd, credentials:'same-origin' });
      const j = await r.json();
      if (!j.ok) { modTit.textContent = 'Erro'; modCor.innerHTML = '<div style="color:#ff7d8a">'+esc(j.erro || 'Falha ao abrir.')+'</div>'; return; }
      const a = j.analise;
      const dt = a.criado_em ? ' · ' + new Date(a.criado_em.replace(' ','T')).toLocaleString('pt-BR') : '';
      modTit.textContent = (a.titulo || 'Análise') ;
      modCor.innerHTML = `<div style="font-size:12.5px;color:var(--faint);margin-bottom:12px">${a.tipo}${dt}</div>` + renderAnalise(a);
    }catch(e){
      modTit.textContent = 'Erro';
      modCor.innerHTML = '<div style="color:#ff7d8a">Falha de rede.</div>';
    }
  }

  async function apagar(id, li){
    if (!confirm('Apagar esta análise? Esta ação não pode ser desfeita.')) return;
    const fd = new FormData(); fd.append('csrf', csrf); fd.append('id', id);
    const r = await fetch('/historico-apagar.php', { method:'POST', body: fd, credentials:'same-origin' });
    const j = await r.json();
    if (j.ok) { li.remove(); } else { alert(j.erro || 'Falha ao apagar.'); }
  }

  async function apagarTudo(){
    if (!confirm('Apagar TODAS as suas análises? Esta ação não pode ser desfeita.')) return;
    const fd = new FormData(); fd.append('csrf', csrf); fd.append('tudo', '1');
    const r = await fetch('/historico-apagar.php', { method:'POST', body: fd, credentials:'same-origin' });
    const j = await r.json();
    if (j.ok) location.reload(); else alert(j.erro || 'Falha ao apagar.');
  }

  document.querySelectorAll('#histLista li').forEach(li => {
    const id = li.dataset.id;
    li.querySelector('.btn-abrir').onclick = () => abrir(id);
    li.querySelector('.btn-apagar').onclick = () => apagar(id, li);
  });
  const btnTudo = document.getElementById('btnApagarTudo');
  if (btnTudo) btnTudo.onclick = apagarTudo;
})();
</script>
<?php authRenderRodape();
