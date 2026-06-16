<?php
// ReadMyLabs — cadastro de usuário (e-mail + senha).
// Sem SMTP configurado: cria como verificado (modo dev). Com SMTP: manda link.

require_once __DIR__ . '/auth/lib/sessao.php';
require_once __DIR__ . '/auth/lib/usuarios.php';
require_once __DIR__ . '/auth/lib/csrf.php';
require_once __DIR__ . '/auth/lib/tokens.php';
require_once __DIR__ . '/auth/lib/layout.php';
require_once __DIR__ . '/email/lib/email.php';

// Já logado? Vai pra conta.
if (sessaoAtual()) { header('Location: /conta.php'); exit; }

$erro = null;
$ok   = null;

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!csrfValidar($_POST['csrf'] ?? null)) {
        $erro = 'Sessão expirada. Recarregue a página.';
    } else {
        $email   = trim((string) ($_POST['email'] ?? ''));
        $senha   = (string) ($_POST['senha'] ?? '');
        $nome    = trim((string) ($_POST['nome']  ?? ''));
        $termos  = !empty($_POST['termos']);
        $exigeVerif = emailHabilitado();

        try {
            $uid = criarUsuario($email, $senha, $nome ?: null, $exigeVerif, $termos);
            if ($exigeVerif) {
                $tok  = emitirTokenVerificacao($uid);
                $link = (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . '/confirmar.php?t=' . $tok;
                [$html, $txt] = emailTemplateVerificacao($link, $nome);
                enviarEmail($email, $nome, 'Confirme seu e-mail · ReadMyLabs', $html, $txt);
                $ok = 'Cadastro feito! Enviamos um link de confirmação para ' . htmlspecialchars($email) . '. Confira sua caixa de entrada (e o spam).';
            } else {
                // Sem SMTP — loga direto.
                iniciarSessaoUsuario($uid);
                header('Location: /conta.php');
                exit;
            }
        } catch (RuntimeException $e) {
            $erro = $e->getMessage();
        } catch (\Throwable $e) {
            error_log('cadastro.php: ' . $e->getMessage());
            $erro = 'Não foi possível concluir o cadastro. Tente novamente.';
        }
    }
}

authRenderTopo('Criar conta', $erro ?: $ok, $erro ? 'erro' : ($ok ? 'ok' : 'info'));
$token = csrfTokenAtual();
?>
<h1>Criar conta</h1>
<div class="sub">Sua leitura de exames, salva e organizada.</div>

<?php if (!$ok): ?>
<form method="post" autocomplete="off">
  <input type="hidden" name="csrf" value="<?= htmlspecialchars($token, ENT_QUOTES) ?>">
  <div class="field">
    <label for="nome">Nome (opcional)</label>
    <input type="text" id="nome" name="nome" maxlength="120" value="<?= htmlspecialchars($_POST['nome'] ?? '', ENT_QUOTES) ?>">
  </div>
  <div class="field">
    <label for="email">E-mail</label>
    <input type="email" id="email" name="email" required maxlength="190" value="<?= htmlspecialchars($_POST['email'] ?? '', ENT_QUOTES) ?>">
  </div>
  <div class="field">
    <label for="senha">Senha</label>
    <input type="password" id="senha" name="senha" required minlength="8">
    <div class="hint">Pelo menos 8 caracteres, com letras e números.</div>
  </div>
  <label class="check">
    <input type="checkbox" name="termos" value="1" required>
    <span>Concordo com a guarda dos meus dados de conta (e-mail, senha hash, data) conforme a Política de Privacidade. Análises de exame seguem sem persistência.</span>
  </label>
  <button class="btn btn-primary btn-block" type="submit">Criar conta</button>
</form>
<div class="auth-foot">Já tem conta? <a href="/entrar.php">Entrar</a></div>
<?php else: ?>
<div class="auth-foot"><a href="/entrar.php">Ir para o login</a></div>
<?php endif; ?>
<?php authRenderRodape();
