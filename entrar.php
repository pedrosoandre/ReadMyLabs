<?php
// ReadMyLabs — login (e-mail + senha).

require_once __DIR__ . '/auth/lib/sessao.php';
require_once __DIR__ . '/auth/lib/usuarios.php';
require_once __DIR__ . '/auth/lib/csrf.php';
require_once __DIR__ . '/auth/lib/layout.php';
require_once __DIR__ . '/email/lib/email.php';

if (sessaoAtual()) { header('Location: /conta.php'); exit; }

$erro = null;
$smtpAtivo = emailHabilitado();

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!csrfValidar($_POST['csrf'] ?? null)) {
        $erro = 'Sessão expirada. Recarregue a página.';
    } else {
        $email = trim((string) ($_POST['email'] ?? ''));
        $senha = (string) ($_POST['senha'] ?? '');

        if ($email === '' || $senha === '') {
            $erro = 'Preencha e-mail e senha.';
        } elseif (loginThrottlado($email)) {
            $erro = 'Muitas tentativas. Aguarde alguns minutos e tente novamente.';
            error_log(json_encode(['evento' => 'login_throttle', 'email_norm' => normalizarEmail($email)]));
        } else {
            $u = validarLogin($email, $senha);
            if (!$u) {
                $erro = 'E-mail ou senha incorretos.';
            } else {
                iniciarSessaoUsuario((int) $u['id']);
                header('Location: /conta.php');
                exit;
            }
        }
    }
}

authRenderTopo('Entrar', $erro, 'erro');
$token = csrfTokenAtual();
?>
<h1>Entrar</h1>
<div class="sub">Acesse sua conta para continuar.</div>
<form method="post" autocomplete="on">
  <input type="hidden" name="csrf" value="<?= htmlspecialchars($token, ENT_QUOTES) ?>">
  <div class="field">
    <label for="email">E-mail</label>
    <input type="email" id="email" name="email" required maxlength="190" value="<?= htmlspecialchars($_POST['email'] ?? '', ENT_QUOTES) ?>" autocomplete="username">
  </div>
  <div class="field">
    <label for="senha">Senha</label>
    <input type="password" id="senha" name="senha" required autocomplete="current-password">
  </div>
  <button class="btn btn-primary btn-block" type="submit">Entrar</button>
</form>
<div class="auth-foot">
  <?php if ($smtpAtivo): ?><a href="/recuperar.php">Esqueci minha senha</a> · <?php endif; ?>
  <a href="/cadastro.php">Criar conta</a>
</div>
<?php authRenderRodape();
