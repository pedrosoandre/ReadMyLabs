<?php
// ReadMyLabs — define nova senha via token de recuperação.

require_once __DIR__ . '/auth/lib/sessao.php';
require_once __DIR__ . '/auth/lib/usuarios.php';
require_once __DIR__ . '/auth/lib/tokens.php';
require_once __DIR__ . '/auth/lib/csrf.php';
require_once __DIR__ . '/auth/lib/layout.php';

$tok = (string) ($_GET['t'] ?? $_POST['t'] ?? '');
$uid = resolverTokenRecuperacao($tok);

if (!$uid) {
    authRenderTopo('Redefinir senha', 'Link inválido ou expirado. Solicite um novo.', 'erro');
    echo '<h1>Redefinir senha</h1>';
    echo '<div class="auth-foot"><a href="/recuperar.php">Solicitar novo link</a></div>';
    authRenderRodape();
    exit;
}

$erro = null;

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!csrfValidar($_POST['csrf'] ?? null)) {
        $erro = 'Sessão expirada. Recarregue a página.';
    } else {
        $s1 = (string) ($_POST['senha']  ?? '');
        $s2 = (string) ($_POST['senha2'] ?? '');
        if ($s1 !== $s2) {
            $erro = 'As senhas não coincidem.';
        } else {
            try {
                setSenhaUsuario($uid, $s1);
                marcarTokenRecuperacaoUsado($tok);
                iniciarSessaoUsuario($uid);
                header('Location: /conta.php?sredef=1');
                exit;
            } catch (RuntimeException $e) {
                $erro = $e->getMessage();
            }
        }
    }
}

authRenderTopo('Redefinir senha', $erro, 'erro');
$ctok = csrfTokenAtual();
?>
<h1>Nova senha</h1>
<div class="sub">Defina uma senha forte para a sua conta.</div>
<form method="post" autocomplete="off">
  <input type="hidden" name="csrf" value="<?= htmlspecialchars($ctok, ENT_QUOTES) ?>">
  <input type="hidden" name="t" value="<?= htmlspecialchars($tok, ENT_QUOTES) ?>">
  <div class="field">
    <label for="senha">Nova senha</label>
    <input type="password" id="senha" name="senha" required minlength="8" autocomplete="new-password">
    <div class="hint">Pelo menos 8 caracteres, com letras e números.</div>
  </div>
  <div class="field">
    <label for="senha2">Confirme a senha</label>
    <input type="password" id="senha2" name="senha2" required minlength="8" autocomplete="new-password">
  </div>
  <button class="btn btn-primary btn-block" type="submit">Salvar senha</button>
</form>
<?php authRenderRodape();
