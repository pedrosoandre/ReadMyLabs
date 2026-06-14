# ReadMyLabs

App médico: o usuário envia PDF/foto de exame laboratorial (ou descreve sintomas) e recebe interpretação em linguagem leiga, gerada com economia agressiva de tokens da Anthropic.

**Domínio real: `readmylabs.com.br`** (sem "y" depois do "read" — a pasta local "readymylabs" engana).

## Princípios de arquitetura (ler primeiro — SEMPRE priorizar)
Arquitetura vem **antes** do código: definir camadas e fronteiras antes de escrever. Código organizado é requisito, não enfeite — nada de arquivo solto ou lógica espalhada.
1. **Modularidade.** Cada subsistema é autocontido, com fronteira clara (ex.: `rag/` tem `lib/` runtime, `ingest/` offline, `schema_rag.sql` e `README.md` próprios). Não espalhar responsabilidades.
2. **Pipeline de camadas únicas.** extração → classificação local → recuperação (RAG) → redação (IA). Cada camada faz uma coisa e não conhece o interior da seguinte.
3. **Dependência externa atrás de interface.** IA em `chamarClaude()`; embeddings em `voyage.php`; busca vetorial em `vetorBuscar()`. Trocar implementação não pode forçar refatoração do resto.
4. **Falha para desligado.** Feature nova degrada sem quebrar o núcleo (ex.: `ragAtivo()` sem `VOYAGE_API_KEY`).
5. **Runtime vs offline.** `*/lib/` deploya; `*/ingest/` é offline (roda 1x na máquina, não sobe).
6. **Economia de token é arquitetura** (seção abaixo). **LGPD por design**: nunca persistir dado sensível de paciente (histórico só com hash de IP).
7. **PHP puro, sem framework.** cURL + PDO; não introduzir dependências pesadas.

## Stack
- Frontend: `index.html` único (design "Aurora", dark, tudo inline — CSS+JS no mesmo arquivo). PDF.js extrai texto de PDFs digitais no navegador; PDFs escaneados e imagens são rasterizados e enviados como base64 para o backend (Claude Vision).
- Backend: PHP puro (sem framework) na Hostinger compartilhada. Entrada única: `analisar.php`.
- Banco: MySQL (`u854646013_examesip`). Schema em `sql/schema.sql`, seed com 46 marcadores em `sql/seed_marcadores.sql`.
- IA: API Anthropic direto via cURL (`lib/claude.php`). **Modelo por tarefa** (economia de custo, constantes no topo de `lib/claude.php`): OCR Vision → `claude-haiku-4-5` (`MODELO_OCR`); explicações/conclusão/"o que é o exame" (redação **aterrada**) → `claude-sonnet-4-6` (`MODELO_EXPLICACAO`); sintomas (raciocínio clínico **livre**, não aterrado) → `claude-sonnet-4-6` (`MODELO_SINTOMAS`). (Opus 4.8 deixou de ser usado; bump `MODELO_SINTOMAS` p/ Opus se quiser mais qualidade na triagem.)

## Arquitetura de economia de tokens (não quebrar!)
1. `lib/referencia.php` classifica os marcadores **localmente** contra a tabela `marcadores_referencia` — zero token.
2. Só marcadores **alterados** vão ao Claude, em **uma** chamada em lote.
3. `cache_explicacoes` (MySQL): explicação por (marcador, status, sexo, faixa etária) — hit = zero token.
4. **PDFs digitais**: texto extraído no navegador pelo PDF.js, não vai ao servidor — zero token.
5. **PDFs escaneados e imagens**: `extrairTexto()` rasteriza para JPEG (canvas, scale 2, qualidade 0.85) e envia `imagem_base64` (JSON array, máx 5 páginas) ao backend → `extrairTextoVision()` em `lib/claude.php` chama Claude Vision → texto retornado entra na pipeline normal de classificação. Tesseract.js foi removido.

## RAG de exames (`rag/`) — identificação/contextualização
Módulo autocontido para "o que é este exame?". **Feature-flagged**: sem `VOYAGE_API_KEY` fica desligado e o app roda igual. Ver `rag/README.md` (arquitetura + como popular).
- **Camada de _retrieval_ (recuperação) — o núcleo do RAG:** é a metade "busca" do RAG (a outra metade é a "geração"/redação do Claude). Dado um texto livre (pergunta do usuário, ou nome de exame que o regex não casou), ela *encontra* os pedaços mais relevantes da base **antes** de o Claude escrever — e não redige nada. Fluxo: (1) vetorizar a query (`gerarEmbedding`, Voyage) → (2) similaridade de cosseno na base (`vetorBuscar`, `rag/lib/vetor.php`) → (3) rerank opcional (`voyageRerank`) → (4) montar o contexto (`recuperarContexto`/`resolverMarcador`, `rag/lib/resolver.php`). A busca em si **não gasta token do Claude** (embedding é barato; cosseno é matemática local); o Claude só entra na **redação**, e **aterrado**: só pode usar o contexto recuperado, então não inventa valores/faixas. É a etapa `recuperação` da pipeline `extração → classificação local → recuperação (RAG) → redação`.
- **Não usa PDFs de pacientes** (LGPD). Base de *definições*: LOINC PT-BR (grátis) + manuais públicos (SUS-BH) + valores de ref. da pop. brasileira.
- **Embeddings: Voyage AI** (a Anthropic NÃO tem API de embeddings). `voyage-3-large`/`voyage-4-large`; reranker `rerank-2`. Cliente em `rag/lib/voyage.php`.
- **Vetores em MariaDB** (10.x não tem `VECTOR` nem aceita pgvector — isso é só PostgreSQL): embedding em JSON normalizado, **cosseno = produto escalar** em PHP (`rag/lib/vetor.php`), atrás de `vetorBuscar()`. pgvector é mais rápido (índice ANN) mas exige Postgres; quando escalar, trocar só o interior por Supabase/pgvector ou Qdrant. Manter a base **curada** (milhares, não 100k).
- **Integração:** `lib/referencia.php::expandirTermosComKb()` injeta sinônimos LOINC no regex (zero token, no-op se a tabela não existir); `analisar.php` modo `tipo=explicar` → `analisarExplicacao()` (RAG **aterrado** + Claude); `index.html` tem a seção "Entenda seu exame" atrás da flag JS `RAG_HABILITADO` (começa `false`/escondida — virar `true` após ingestão + chave Voyage).
- **Tabelas:** `rag/schema_rag.sql` (kb_fontes/kb_marcadores/kb_chunks/kb_vetores + `ALTER` em `exames.tipo`). Popular: schema → `00_seed` → (`01_seed_curado` ~90 exames sem download/Voyage **ou** `01_loinc_import` completo) → `02_manuais` → `03_embeddings` (CLI, offline).

## OCR via Claude Vision
- `chamarClaude()` em `lib/claude.php` aceita `string|array` no parâmetro `$prompt` (suporta blocos de conteúdo Vision).
- `extrairTextoVision(array $imagens): string` — recebe array `['data'=>base64,'mime'=>'image/jpeg'|'image/png']`, retorna texto formatado `"Marcador: valor unidade"` por linha para o regex de `classificarExame()`.
- Custo Vision: ~1.000–2.000 tokens/página, agora no `claude-haiku-4-5` (`MODELO_OCR`) — OCR é transcrição, não precisa do Opus (~5× mais barato). Compensa vs. Tesseract (30-60s OCR ruim que falhava nos regex).

## Exame de imagem (radiologia) — modo `imagem`, MESMO upload do exame
Sem card/campo próprios: usa o **mesmo upload** do exame. O backend detecta o tipo pela classificação local — documento **sem marcadores laboratoriais** (`classificarExame()` vazio) é roteado em `analisarExame()` para `responderImagem()`.
- **Híbrido:** explica o **laudo** (texto do radiologista) em linguagem leiga; OU descreve o **filme** (imagem do exame) de forma **estritamente NÃO-diagnóstica** (anatomia/tipo de exame; nunca afirmar achados/normalidade — "não há alterações" é tão proibido quanto "há lesão").
- `responderImagem()` (núcleo em `analisar.php`) usa `chamarClaudeVision()` (`lib/claude.php`, helper Vision reusado pelo OCR) + `MODELO_IMAGEM` (Sonnet). Saída JSON `{conteudo:laudo|filme, titulo_exame, resumo_leigo, tranquilizador[], merece_atencao[], perguntas_medico[], aviso}`. **Backstop de servidor:** no filme, zera `tranquilizador`/`merece_atencao` (defesa em profundidade).
- **Frontend:** o handler do exame ramifica por `d.tipo`; `renderImagem()`/`gerarPDFImagem()` renderizam no `#imgResult` do mesmo card (reusam `pdfSan`/`PDFC` do PDF de exame).
- **LGPD:** não persiste laudo/imagem nem a explicação derivada (grava só evento de uso, `resultado=''`).
- **Kill-switch:** `IMAGEM_HABILITADA=0` no `.env` do servidor desliga (volta ao erro "não reconhecemos marcadores"). A rota `tipo=imagem`/`analisarImagem()` ainda existe (wrapper `$_POST`), mas o front não a usa.
- **Banco:** `exames.tipo` é ENUM e inclui `'imagem'` (migração em `rag/schema_rag.sql`).
- A redação do filme é Vision-LLM, **não** radiologia validada: o disclaimer não-diagnóstico e o teste de segurança (filme/injection) são parte do design, **não opcionais** — é o que mantém o app como ferramenta educativa, não dispositivo médico.

## Proteções contra abuso (verificadas em produção)
- reCAPTCHA v2 checkbox obrigatório; o toggle `REQUIRE_RECAPTCHA=0` **não** desliga (PHP `'0' ?: '1'` → falha fechado; usar `off` em dev local).
- Rate limit: **1 análise/dia por IP** (`LIMITE_DIARIO=1` no `.env` do servidor; padrão do código é 3 se ausente). Arquivos em `limite_ip/`, hash sha256 do IP, independe do banco. Vale para TODOS os modos (exame, sintomas, explicar) — a checagem roda antes do roteamento. É a trava real anti-abuso.
- **Paywall + limite IP cooperam (sempre ligados em produção):** o paywall (front, `localStorage`) é a "vitrine" rápida; o limite de IP é a tranca real. Quando o servidor responde `limite_atingido`, o front abre o **mesmo modal de paywall** (`pwAbrir()`), então mesmo na aba anônima (que zera o `localStorage`) o usuário bate no limite de IP e vê a oferta — o furo da aba anônima fica fechado.
- **Porta de teste `?dev=TOKEN`:** abrir o site com `?dev=<DEV_BYPASS_TOKEN do .env>` faz o front pular o paywall e o `analisar.php` pular o limite de IP (só se o token bater; sem token/errado, tudo vale normalmente). Evita ligar/desligar flags. NUNCA divulgar o token. O token vive só no `.env` do servidor (não no HTML público).
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
- Arquivos deployados: `index.html`, `analisar.php`, `.htaccess`, `db.php`, `loads_env.php`, `lib/`, `rag/lib/` (runtime do RAG). `rag/ingest/` roda **no servidor** via SSH (o MySQL só aceita `localhost`); é CLI-only (`PHP_SAPI` guard → 403 no web), sobe só para rodar a ingestão.
- `vendor/` é symlink para `~/vendor` no servidor (não subir vendor).
- O `.env` do servidor é separado do local — nunca sobrescrever (tem as mesmas chaves + DB).
- phpMyAdmin: `https://auth-db1436.hstgr.io/` (credenciais do MySQL).
- **MySQL `wait_timeout=20`** (Hostinger): chamadas longas ao Claude (ex.: explicar muitos marcadores ~30s) deixam a conexão PDO ociosa e o servidor a derruba → erro 2006 "MySQL server has gone away" no INSERT seguinte → resposta vazia → "Unexpected end of JSON input" no front. `db.php` já faz `SET SESSION wait_timeout=600` ao conectar; `analisar.php` faz `@set_time_limit(120)`. Lembrar disso em qualquer operação longa + escrita no banco.
- Cuidado com `pkill -f` em comandos via plink: o padrão casa com a própria sessão SSH e a mata. Usar truque do colchete: `pgrep -f "padrao[x]"`.
- **Aspas no plink via PowerShell:** o PowerShell engole aspas (simples e duplas) antes de chegarem ao bash → comandos com `"`/`'` (ex.: SQL com `COUNT(*)` ou `LIKE 'x'`) viram erro de sintaxe e o bash recusa a linha inteira. Solução: comandos sem aspas (a senha `-pSENHA` sem aspas funciona) ou jogar o SQL num arquivo e `mysql ... < arquivo.sql`.
- **NUNCA** criar backup do `.env` dentro do `public_html` (ex.: `.env.bak_*`): o `.htaccess` bloqueia `.env`/`loads_env.php`, mas não os `.bak` → vazaria todas as chaves pela web. Backups do `.env` vão para `~/` (fora do web root).

## Testes (`tests/`, não deployar)
- `tests/smoke.sh [BASE_URL] [DEV_TOKEN]`: HTTP contra o site (sem custo de IA) — home 200/render, validação de entrada (405/400), gate do reCAPTCHA (exame **e** imagem), `.env`/`loads_env.php` 403, 404, CSP, sem vazamento de chaves. Os POSTs falham no captcha **antes** de incrementar, então não consomem o limite de IP; passe `DEV_TOKEN` p/ blindar contra o rate-limit.
- `tests/pipeline.php [BASE_APP] [--full]`: lógica no servidor via **CLI** (contorna o captcha chamando funções direto). Detector de auto-roteamento (lab=marcadores / laudo=0), classificação, ENUM `tipo`+imagem; `--full` faz 1 chamada ao Claude. Rodar no servidor: `pscp` p/ `~/` e `php ~/pipeline.php --full` (depois apagar).
- reCAPTCHA em produção impede E2E HTTP de uma análise completa — por isso a lógica fica no `pipeline.php` (CLI). Última execução: **19/19 PASS** (13 HTTP + 6 CLI).

## Git
- Repo: `https://github.com/pedrosoandre/ReadMyLabs` (branch `master`).
- `.env` está no `.gitignore` — `git add -u` é seguro, mas nunca forçar o add do `.env`.
- `limite_ip/`, `vendor/` também ignorados.
