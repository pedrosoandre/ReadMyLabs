# ReadMyLabs

App médico: o usuário envia PDF/foto de exame laboratorial (ou descreve sintomas) e recebe interpretação em linguagem leiga, gerada com economia agressiva de tokens da Anthropic.

**Domínio real: `readmylabs.com.br`** (sem "y" depois do "read" — a pasta local "readymylabs" engana).

## Stack
- Frontend: `index.html` único (design "Aurora", dark, tudo inline — CSS+JS no mesmo arquivo). PDF.js + Tesseract.js extraem o texto do exame **no navegador** (economiza token e upload).
- Backend: PHP puro (sem framework) na Hostinger compartilhada. Entrada única: `analisar.php`.
- Banco: MySQL (`u854646013_examesip`). Schema em `sql/schema.sql`, seed com 46 marcadores em `sql/seed_marcadores.sql`.
- IA: API Anthropic direto via cURL (`lib/claude.php`), modelo `claude-opus-4-8`.

## Arquitetura de economia de tokens (não quebrar!)
1. `lib/referencia.php` classifica os marcadores **localmente** contra a tabela `marcadores_referencia` — zero token.
2. Só marcadores **alterados** vão ao Claude, em **uma** chamada em lote.
3. `cache_explicacoes` (MySQL): explicação por (marcador, status, sexo, faixa etária) — hit = zero token.
4. Texto do exame extraído no navegador (PDF.js/Tesseract), não no servidor.

## Proteções contra abuso (verificadas em produção)
- reCAPTCHA v2 checkbox obrigatório; o toggle `REQUIRE_RECAPTCHA=0` **não** desliga (PHP `'0' ?: '1'` → falha fechado; usar `off` em dev local).
- Rate limit: 3 análises/dia por IP (arquivos em `limite_ip/`, hash sha256 do IP, independe do banco).
- Contador só incrementa **após** captcha válido; Claude só é chamado após captcha + limite + DB OK.
- `.htaccess` bloqueia acesso web a `.env` e `loads_env.php` (403) e seta CSP.
- **Prompt injection (sintomas):** campos `$sintomas/$duracao/$intensidade` envolvidos em tags XML no prompt; system prompt instrui o Claude a ignorar comandos nesses campos.
- **`_custo` (tokens/cache)** só aparece no response com `APP_DEBUG=true` no `.env` — nunca exposto em produção.
- `logRml()` ativo: loga captcha falho e rate limit atingido no error_log do servidor.

## Paywall (frontend)
- 1 análise gratuita por navegador (`localStorage: rml_free_usada`), marcada só após sucesso.
- Depois: modal `#paywall` com 2 planos (ancoragem): Avulsa R$ 14,90 (isca) vs **Ilimitado R$ 19,90/mês** (destaque "Melhor escolha").
- Botões abrem WhatsApp `wa.me/5551999009551` com mensagem pré-preenchida. Gateway de pagamento real: pendente.

## Deploy (SSH, não FTP — FTP recusa a senha)
- Credenciais em `.env` local (SSH_HOST/SSH_PORT/SSH_USER/SSH_PASS/SSH_HOSTKEY/SSH_DEST) e na memória do Claude.
- Ferramentas: `plink`/`pscp` (PuTTY, `C:\Program Files\PuTTY\`). Sempre `-batch -hostkey 'SHA256:5znhiRHvKXvXVOmrqV7woZ1aJRv89YAfyGRat/hGsqI' -P 65002`.
- Destino: `~/domains/readmylabs.com.br/public_html/` (o `~/public_html` é vazio, não usar).
- Arquivos deployados: `index.html`, `analisar.php`, `.htaccess`, `db.php`, `loads_env.php`, `lib/`.
- `vendor/` é symlink para `~/vendor` no servidor (não subir vendor).
- O `.env` do servidor é separado do local — nunca sobrescrever (tem as mesmas chaves + DB).
- phpMyAdmin: `https://auth-db1436.hstgr.io/` (credenciais do MySQL).
- Cuidado com `pkill -f` em comandos via plink: o padrão casa com a própria sessão SSH e a mata. Usar truque do colchete: `pgrep -f "padrao[x]"`.

## Git
- Repo: `https://github.com/pedrosoandre/ReadMyLabs` (branch `master`).
- `.env` está no `.gitignore` — `git add -u` é seguro, mas nunca forçar o add do `.env`.
- `limite_ip/`, `vendor/` também ignorados.
