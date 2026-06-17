<?php
// ReadMyLabs — painel do usuário (mínimo).
// Mostra plano, cota do dia, status de verificação, e dá acesso a sair/voltar.

require_once __DIR__ . '/auth/lib/sessao.php';
require_once __DIR__ . '/auth/lib/quota.php';
require_once __DIR__ . '/auth/lib/layout.php';
require_once __DIR__ . '/db.php';

$u = exigirLogin();

// Uso de hoje
$stmt = db()->prepare('SELECT contagem FROM usuario_uso_diario WHERE usuario_id = ? AND dia = CURDATE()');
$stmt->execute([$u['id']]);
$usoHoje = (int) ($stmt->fetchColumn() ?: 0);
$cotaDia = cotaDiariaDoPlano($u['plano']);

$banner = null;
if (!empty($_GET['confirmado'])) $banner = ['E-mail confirmado! Conta liberada.', 'ok'];
elseif (!empty($_GET['sredef'])) $banner = ['Senha redefinida com sucesso.', 'ok'];
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
</div>

<a href="/" class="btn btn-primary btn-block">Voltar para o app</a>
<div class="auth-foot"><a href="/sair.php">Sair da conta</a></div>
<?php if (!empty($_GET['confirmado'])): ?>
<script>window.addEventListener('load',function(){if(window.rml&&rml.event)rml.event('sign_up',{method:'email'});});</script>
<?php endif; ?>
<?php authRenderRodape();

