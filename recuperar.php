<?php
// ReadMyLabs — solicita reset de senha. Sem SMTP configurado, a página avisa
// que o recurso está temporariamente indisponível (falha-para-desligado).

require_once __DIR__ . '/auth/lib/sessao.php';
require_once __DIR__ . '/auth/lib/usuarios.php';
require_once __DIR__ . '/auth/lib/tokens.php';
require_once __DIR__ . '/auth/lib/csrf.php';
require_once __DIR__ . '/auth/lib/layout.php';
require_once __DIR__ . '/email/lib/email.php';

if (sessaoAtual()) { header('Location: /conta.php'); exit; }

if (!emailHabilitado()) {
    authRenderTopo('Recuperar senha', 'O envio de e-mails ainda não está configurado neste servidor. Tente novamente em breve, ou entre em contato pelo WhatsApp.', 'info');
    echo '<h1>Recuperar senha</h1><div class="sub">Recurso temporariamente indisponível.</div>';
    echo '<div class="auth-foot"><a href="/entrar.php">Voltar ao login</a></div>';
    authRenderRodape();
    exit;
}

$msg = null;

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!csrfValidar($_POST['csrf'] ?? null)) {
        $msg = ['Sessão expirada. Recarregue a página.', 'erro'];
    } else {
        $email = trim((string) ($_POST['email'] ?? ''));
        // Sempre responde a mesma coisa, exista ou não a conta (anti-enumeração).
        if ($email !== '') {
            $u = usuarioPorEmail($email);
            if ($u) {
                $tok  = emitirTokenRecuperacao((int) $u['id']);
                $link = (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . '/redefinir.php?t=' . $tok;
                [$html, $txt] = emailTemplateRecuperacao($link);
                enviarEmail($u['email'], (string) $u['nome'], 'Redefinir sua senha · ReadMyLabs', $html, $txt);
            }
        }
        $msg = ['Se houver uma conta com esse e-mail, enviamos um link para redefinir a senha. Verifique a caixa de entrada e o spam.', 'ok'];
    }
}

authRenderTopo('Recuperar senha', $msg[0] ?? null, $msg[1] ?? 'info');
$token = csrfTokenAtual();
?>
<h1>Recuperar senha</h1>
<div class="sub">Enviaremos um link para você criar uma nova senha.</div>
<?php if (!$msg || $msg[1] !== 'ok'): ?>
<form method="post" autocomplete="off">
  <input type="hidden" name="csrf" value="<?= htmlspecialchars($token, ENT_QUOTES) ?>">
  <div class="field">
    <label for="email">E-mail da conta</label>
    <input type="email" id="email" name="email" required maxlength="190">
  </div>
  <button class="btn btn-primary btn-block" type="submit">Enviar link</button>
</form>
<?php endif; ?>
<div class="auth-foot"><a href="/entrar.php">Voltar ao login</a></div>
<?php authRenderRodape();
