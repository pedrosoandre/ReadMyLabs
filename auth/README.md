# Subsistema `auth/`

Autenticação por e-mail+senha para o ReadMyLabs. Autocontido, atrás de interface; o resto do app só fala com `sessaoAtual()` — não conhece o método de login.

## Princípio
- **Falha-para-desligado**: sem `auth/lib/sessao.php` no deploy, `analisar.php` segue o caminho anônimo (limite por IP). Auth é estritamente aditivo.
- **Modular**: `lib/` é runtime; `schema_auth.sql` é a única fonte de verdade do banco.
- Tudo PHP puro + PDO. Sem framework.

## Tabelas (`schema_auth.sql`)
- `usuarios` — `email`/`email_norm` (unique), `senha_hash`, `plano`, `email_verificado`, `aceitou_termos_em`.
- `sessoes` — token 64-hex → usuario; HttpOnly+Secure+SameSite=Lax; expira_em é a verdade.
- `verificacao_emails` — token único, 3 dias.
- `recuperacoes_senha` — token único, 30 minutos, invalida anteriores.
- `auth_tentativas` — log para throttling (5 falhas/15min por email OU IP).
- `usuario_uso_diario` — cota diária por conta (substitui limite-IP quando logado).

## Fluxo
1. **Cadastro** (`cadastro.php`): valida e-mail/senha + termos → `criarUsuario()` → (se SMTP ativo) e-mail com token de verificação; senão, marca verificado direto e loga.
2. **Login** (`entrar.php`): `loginThrottlado()` → `validarLogin()` → `iniciarSessaoUsuario()` seta cookie + linha em `sessoes`.
3. **Sessão** (`lib/sessao.php`): `sessaoAtual()` lê cookie, valida no banco, retorna user array ou null. Refresh `ultimo_uso` no máximo 1×/dia.
4. **Recuperação** (`recuperar.php` → `redefinir.php`): solicita por e-mail (sempre responde igual, anti-enumeração) → token → nova senha + invalida sessões antigas.
5. **Verificação** (`confirmar.php`): consome token, marca `email_verificado=1`, loga.
6. **Logout** (`sair.php`): apaga linha em `sessoes` + cookie.

## Integração com `analisar.php`
- **Logado + verificado**: usa `consumirCotaUsuario()` (cota do plano). Pula limite IP.
- **Logado + não verificado**: 403 com mensagem "confirme seu e-mail". Não consome nada.
- **Anônimo**: caminho original (`limite_ip/` + arquivo + flock).
- `DEV_BYPASS_TOKEN` continua válido para os dois caminhos.

## Aplicar o schema (uma vez por ambiente)
Servidor (via SSH):
```
mysql -u $DB_USER -p$DB_PASS $DB_NAME < auth/schema_auth.sql
```
Local (se rodar testes contra banco local): mesmo comando.

## Adicionar OAuth/Magic link (F2)
- Criar `auth/lib/oauth_google.php` e `auth/lib/magic.php` que terminam em `iniciarSessaoUsuario($uid)` — mesma sessão, mesmo cookie. Tabela nova `auth_provedores(usuario_id, provider, sub_id)` para vincular múltiplos métodos ao mesmo usuário.
- Nenhuma mudança em `sessaoAtual()` ou em `analisar.php`.
