# Subsistema `email/`

Interface única para envio de e-mail transacional. Trocar de provider mexe apenas em `lib/email.php` — nem `auth/` nem `analisar.php` conhecem o provedor.

## Interface pública
```php
enviarEmail(string $paraEmail, string $paraNome, string $assunto, string $html, ?string $textoPlano = null): bool
emailHabilitado(): bool       // verifica se há provider+chave válidos
emailTemplateVerificacao(string $link, string $nome): array  // [html, txt]
emailTemplateRecuperacao(string $link): array                // [html, txt]
```

`enviarEmail()` **nunca lança** — falhas são logadas e a função retorna `false`. Callers decidem o fallback (em `auth/`, a UI mostra "se houver conta, enviamos um link", sem revelar existência).

## Providers suportados
Configurar via `.env`:
- `EMAIL_PROVIDER=resend` + `RESEND_API_KEY=re_...`
- `EMAIL_PROVIDER=sendgrid` + `SENDGRID_API_KEY=SG...`
- (vazio) → stub: loga a tentativa em `error_log`, retorna `false`. Útil em dev — basta abrir `error_log` para pegar os links de verificação. Em produção, `auth/` esconde o link "esqueci minha senha" enquanto `emailHabilitado()` for false.

Remetente:
- `EMAIL_FROM` (default `noreply@readmylabs.com.br`)
- `EMAIL_FROM_NOME` (default `ReadMyLabs`)

## Adicionar provider
Implementar `enviarEmailFoo(...)` no padrão dos existentes; despachar em `enviarEmail()` por `EMAIL_PROVIDER`. Nada mais muda.

## DNS para entregabilidade
SPF + DKIM no domínio são essenciais para não cair em spam — instruções específicas do provider (Resend/SendGrid documentam). Sem isso, o envio "funciona" mas vai para spam.
