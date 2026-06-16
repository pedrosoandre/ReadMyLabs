#!/usr/bin/env bash
# Smoke/segurança do ReadMyLabs — superfície pública, SEM custo de token.
# Uso: bash tests/smoke.sh [BASE_URL] [DEV_TOKEN]
#   BASE_URL  default https://readmylabs.com.br
#   DEV_TOKEN (opcional) DEV_BYPASS_TOKEN do .env do servidor — evita esbarrar no limite de IP.
set -u
BASE="${1:-https://readmylabs.com.br}"
DEV="${2:-}"
EP="$BASE/analisar.php"
DEVQ=""; [ -n "$DEV" ] && DEVQ="--data-urlencode dev=$DEV"
pass=0; fail=0
chk(){ if [ "$2" = "$3" ]; then echo "PASS  $1 ($3)"; pass=$((pass+1)); else echo "FAIL  $1 (esperado $2, obtido $3)"; fail=$((fail+1)); fi; }
has(){ if printf '%s' "$3" | grep -q "$2"; then echo "PASS  $1"; pass=$((pass+1)); else echo "FAIL  $1 (nao achei: $2)"; fail=$((fail+1)); fi; }
nhas(){ if printf '%s' "$3" | grep -Eq "$2"; then echo "FAIL  $1 (achei: $2)"; fail=$((fail+1)); else echo "PASS  $1"; pass=$((pass+1)); fi; }
code(){ curl -s -o /dev/null -w '%{http_code}' "$@"; }

echo "== ReadMyLabs smoke :: $BASE =="

# Home
chk "GET / -> 200" 200 "$(code "$BASE/")"
HOME="$(curl -s "$BASE/")"
has  "Home renderiza (ReadMyLabs)" "ReadMyLabs" "$HOME"
has  "Home tem upload card (#dz)" 'id="dz"' "$HOME"
nhas "Home NAO vaza chaves/senhas" 'sk-ant-|-----BEGIN|RECAPTCHA_SECRET=[A-Za-z0-9]' "$HOME"

# Endpoint: método + validação de entrada
chk "GET /analisar.php -> 405" 405 "$(code "$EP")"
chk "POST sem tipo -> 400"     400 "$(code -X POST $DEVQ "$EP")"
chk "POST tipo inválido -> 400" 400 "$(code -X POST $DEVQ --data-urlencode 'tipo=xxx' "$EP")"

# Gate do reCAPTCHA ativo (exame e imagem passam no whitelist, travam no captcha)
EXR="$(curl -s -X POST $DEVQ --data-urlencode 'tipo=exame' --data-urlencode 'conteudo_ocr=Glicose: 99 mg/dL' "$EP")"
has "Captcha exige token (tipo=exame)" "reCAPTCHA" "$EXR"
IMR="$(curl -s -X POST $DEVQ --data-urlencode 'tipo=imagem' --data-urlencode 'conteudo_ocr=laudo' "$EP")"
has "tipo=imagem no whitelist (trava no captcha, nao 'inválido')" "reCAPTCHA" "$IMR"

# Segurança de arquivos
chk "GET /.env -> 403"          403 "$(code "$BASE/.env")"
chk "GET /loads_env.php -> 403" 403 "$(code "$BASE/loads_env.php")"
chk "GET inexistente -> 404"    404 "$(code "$BASE/zzz-nao-existe-$RANDOM")"
chk "GET /auth/lib/sessao.php -> 403"   403 "$(code "$BASE/auth/lib/sessao.php")"
chk "GET /auth/schema_auth.sql -> 403"  403 "$(code "$BASE/auth/schema_auth.sql")"
chk "GET /email/lib/email.php -> 403"   403 "$(code "$BASE/email/lib/email.php")"

# Auth — páginas de superfície
chk "GET /entrar.php -> 200"    200 "$(code "$BASE/entrar.php")"
chk "GET /cadastro.php -> 200"  200 "$(code "$BASE/cadastro.php")"
chk "GET /recuperar.php -> 200" 200 "$(code "$BASE/recuperar.php")"
chk "GET /auth-status.php -> 200" 200 "$(code "$BASE/auth-status.php")"
AS="$(curl -s "$BASE/auth-status.php")"
has "auth-status retorna logged_in:false sem cookie" 'logged_in":false' "$AS"

# Headers de segurança
HDR="$(curl -s -D - -o /dev/null "$BASE/")"
has "CSP presente" "Content-Security-Policy" "$HDR"

echo "== resultado: $pass PASS / $fail FAIL =="
[ "$fail" -eq 0 ]
