<?php
// ReadMyLabs — painel do usuário (mínimo).
// Mostra plano, cota do dia, status de verificação, e dá acesso a sair/voltar.

require_once __DIR__ . '/auth/lib/sessao.php';
require_once __DIR__ . '/auth/lib/quota.php';
require_once __DIR__ . '/auth/lib/layout.php';
require_once __DIR__ . '/auth/lib/csrf.php';
require_once __DIR__ . '/db.php';

$u = exigirLogin();

// Histórico (F4) é opcional: só conta se o subsistema estiver no deploy.
$totalHist = 0;
$temHistorico = false;
if (is_file(__DIR__ . '/auth/lib/exames_repo.php')) {
    require_once __DIR__ . '/auth/lib/exames_repo.php';
    require_once __DIR__ . '/lib/crypto.php';
    if (criptoHabilitado()) {
        $temHistorico = true;
        $totalHist = exameContar((int) $u['id']);
    }
}

// Uso de hoje
$stmt = db()->prepare('SELECT contagem FROM usuario_uso_diario WHERE usuario_id = ? AND dia = CURDATE()');
$stmt->execute([$u['id']]);
$usoHoje = (int) ($stmt->fetchColumn() ?: 0);
$cotaDia = cotaDiariaDoPlano($u['plano']);

$banner = null;
if (!empty($_GET['confirmado'])) $banner = ['E-mail confirmado! Conta liberada.', 'ok'];
elseif (!empty($_GET['sredef'])) $banner = ['Senha redefinida com sucesso.', 'ok'];
elseif (!empty($_GET['erro']))   $banner = [
    ($_GET['erro'] === 'confirmacao') ? 'Digite seu e-mail exato para confirmar a exclusão.'
        : (($_GET['erro'] === 'csrf') ? 'Sessão expirada. Tente novamente.' : 'Não foi possível concluir. Tente novamente.'),
    'erro'
];
elseif (!$u['email_verificado'])  $banner = ['Seu e-mail ainda não foi confirmado. Verifique sua caixa de entrada.', 'info'];

authRenderTopo('Minha conta', $banner[0] ?? null, $banner[1] ?? 'info');
?>
<h1>Minha conta</h1>
<div class="sub">Olá, <?= htmlspecialchars($u['nome'] ?: explode('@', $u['email'])[0]) ?>.</div>

<div style="display:grid;gap:10px;margin:18px 0">
  <div style="display:flex;justify-content:space-between;font-size:14px;color:var(--muted)">
    <span>E-mail</span><span style="color:var(--text)"><?= htmlspecialchars($u['email']) ?></span>
  </div>
  <div style="display:flex;justify-content:space-between;font-size:14px;color:var(--muted)">
    <span>Plano</span><span style="color:var(--text);text-transform:capitalize"><?= htmlspecialchars($u['plano']) ?></span>
  </div>
  <div style="display:flex;justify-content:space-between;font-size:14px;color:var(--muted)">
    <span>Uso hoje</span>
    <span style="color:var(--text)"><?= $usoHoje ?><?= $cotaDia === null ? ' (ilimitado)' : ' / ' . $cotaDia ?></span>
  </div>
  <div style="display:flex;justify-content:space-between;font-size:14px;color:var(--muted)">
    <span>E-mail verificado</span>
    <span style="color:<?= $u['email_verificado'] ? 'var(--good)' : 'var(--warn)' ?>">
      <?= $u['email_verificado'] ? 'Sim' : 'Pendente' ?>
    </span>
  </div>
  <?php if ($temHistorico && $u['email_verificado']): ?>
  <div style="display:flex;justify-content:space-between;font-size:14px;color:var(--muted)">
    <span>Análises salvas</span>
    <span style="color:var(--text)"><?= $totalHist ?> · <a href="/historico.php" style="color:var(--cyan);text-decoration:none">ver histórico</a></span>
  </div>
  <?php endif; ?>
</div>

<a href="/" class="btn btn-primary btn-block">Voltar para o app</a>
<div class="auth-foot">
  <a href="/sair.php">Sair da conta</a> ·
  <a href="/politica-de-privacidade.php">Política de Privacidade</a>
</div>

<details style="margin-top:24px;padding:14px;border:1px solid rgba(255,125,138,.22);border-radius:13px;background:rgba(255,125,138,.04)">
  <summary style="cursor:pointer;color:#ff7d8a;font-weight:600;font-size:14px">Apagar minha conta</summary>
  <div style="font-size:13.5px;color:var(--muted);margin-top:10px;line-height:1.55">
    Esta ação apaga permanentemente sua conta, todas as suas análises salvas no histórico
    e todas as sessões abertas. <strong style="color:var(--text)">Não pode ser desfeita.</strong>
  </div>
  <form method="POST" action="/apagar-conta.php" style="margin-top:12px"
        onsubmit="return confirm('Tem certeza? Esta ação é permanente.');">
    <?= csrfCampoOculto() ?>
    <div class="field" style="margin-bottom:10px">
      <label>Para confirmar, digite seu e-mail (<?= htmlspecialchars($u['email']) ?>):</label>
      <input type="text" name="confirmar" required autocomplete="off" spellcheck="false">
    </div>
    <button type="submit" class="btn btn-block"
            style="background:#ff7d8a;color:#1a0a0d;font-weight:700">
      Apagar conta e todo o histórico
    </button>
  </form>
</details>
<?php if (!empty($_GET['confirmado'])): ?>
<script>window.addEventListener('load',function(){if(window.rml&&rml.event)rml.event('sign_up',{method:'email'});});</script>
<?php endif; ?>
<?php authRenderRodape();

