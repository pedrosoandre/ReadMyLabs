<?php
// ReadMyLabs — interface única de envio de e-mail.
// Provider configurável via .env (EMAIL_PROVIDER). Sem chave/provider = stub
// que loga e retorna false (falha-para-desligado, sem quebrar fluxo de auth).
//
// Providers suportados nesta fase: 'resend' (https://resend.com), 'smtp_log' (default).
// Para trocar de provider, mudar APENAS este arquivo — chamadores usam enviarEmail().

require_once __DIR__ . '/../../loads_env.php';

function emailRemetente(): array {
    loadEnv();
    return [
        'from_email' => getenv('EMAIL_FROM')      ?: 'noreply@readmylabs.com.br',
        'from_nome'  => getenv('EMAIL_FROM_NOME') ?: 'ReadMyLabs',
    ];
}

function emailHabilitado(): bool {
    loadEnv();
    $prov = strtolower(getenv('EMAIL_PROVIDER') ?: '');
    if ($prov === 'resend')   return (bool) getenv('RESEND_API_KEY');
    if ($prov === 'sendgrid') return (bool) getenv('SENDGRID_API_KEY');
    return false;
}

/**
 * Envia um e-mail HTML. Retorna true/false.
 * Não joga exception — falhas são logadas. Chamadores tratam o false (UI de fallback).
 */
function enviarEmail(string $paraEmail, string $paraNome, string $assunto, string $html, ?string $textoPlano = null): bool {
    loadEnv();
    $prov = strtolower(getenv('EMAIL_PROVIDER') ?: '');
    $rem  = emailRemetente();

    if ($prov === 'resend') {
        return enviarEmailResend($paraEmail, $paraNome, $assunto, $html, $textoPlano, $rem);
    }
    if ($prov === 'sendgrid') {
        return enviarEmailSendGrid($paraEmail, $paraNome, $assunto, $html, $textoPlano, $rem);
    }
    // Stub: log e segue. Útil em dev — basta inspecionar error_log para pegar links.
    error_log(json_encode([
        'evento'   => 'email_stub',
        'para'     => $paraEmail,
        'assunto'  => $assunto,
        'preview'  => mb_substr(strip_tags($html), 0, 200),
    ], JSON_UNESCAPED_UNICODE));
    return false;
}

function enviarEmailResend(string $paraEmail, string $paraNome, string $assunto, string $html, ?string $textoPlano, array $rem): bool {
    $key = getenv('RESEND_API_KEY');
    if (!$key) { error_log('email: RESEND_API_KEY ausente'); return false; }

    $payload = [
        'from'    => $rem['from_nome'] . ' <' . $rem['from_email'] . '>',
        'to'      => [$paraNome !== '' ? ($paraNome . ' <' . $paraEmail . '>') : $paraEmail],
        'subject' => $assunto,
        'html'    => $html,
    ];
    if ($textoPlano !== null) $payload['text'] = $textoPlano;

    $ch = curl_init('https://api.resend.com/emails');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $key,
            'Content-Type: application/json',
        ],
        CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);
    $resp  = curl_exec($ch);
    $http  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err   = curl_error($ch);
    curl_close($ch);

    if ($err || $http < 200 || $http >= 300) {
        error_log('email Resend falhou: http=' . $http . ' err=' . $err . ' resp=' . substr((string) $resp, 0, 500));
        return false;
    }
    return true;
}

function enviarEmailSendGrid(string $paraEmail, string $paraNome, string $assunto, string $html, ?string $textoPlano, array $rem): bool {
    $key = getenv('SENDGRID_API_KEY');
    if (!$key) { error_log('email: SENDGRID_API_KEY ausente'); return false; }

    $payload = [
        'personalizations' => [[
            'to' => [['email' => $paraEmail, 'name' => $paraNome]],
        ]],
        'from'    => ['email' => $rem['from_email'], 'name' => $rem['from_nome']],
        'subject' => $assunto,
        'content' => [['type' => 'text/html', 'value' => $html]],
    ];
    if ($textoPlano !== null) {
        array_unshift($payload['content'], ['type' => 'text/plain', 'value' => $textoPlano]);
    }

    $ch = curl_init('https://api.sendgrid.com/v3/mail/send');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $key,
            'Content-Type: application/json',
        ],
        CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);
    $resp = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($err || $http < 200 || $http >= 300) {
        error_log('email SendGrid falhou: http=' . $http . ' err=' . $err . ' resp=' . substr((string) $resp, 0, 500));
        return false;
    }
    return true;
}

/** Templates curtos (HTML inline simples). */
function emailTemplateVerificacao(string $linkVerificar, string $nome): array {
    $saud = $nome !== '' ? htmlspecialchars($nome, ENT_QUOTES) : '';
    $html = <<<HTML
    <div style="font-family:Arial,Helvetica,sans-serif;background:#f5f7fb;padding:24px">
      <div style="max-width:540px;margin:0 auto;background:#fff;border-radius:12px;padding:28px;border:1px solid #e8ecf3">
        <h2 style="color:#1a2230;margin:0 0 8px">Confirme seu e-mail</h2>
        <p style="color:#3b4554;margin:8px 0 18px">Olá $saud, recebemos seu cadastro no ReadMyLabs. Para liberar seu acesso, confirme seu e-mail:</p>
        <p><a href="$linkVerificar" style="display:inline-block;background:#3b6bff;color:#fff;padding:12px 22px;border-radius:8px;text-decoration:none;font-weight:600">Confirmar e-mail</a></p>
        <p style="color:#6b7689;margin-top:18px;font-size:13px">Se não foi você, ignore este e-mail. O link expira em 3 dias.</p>
      </div>
    </div>
    HTML;
    $txt = "Confirme seu e-mail no ReadMyLabs:\n$linkVerificar\n\nSe não foi você, ignore.";
    return [$html, $txt];
}

function emailTemplateRecuperacao(string $linkRedefinir): array {
    $html = <<<HTML
    <div style="font-family:Arial,Helvetica,sans-serif;background:#f5f7fb;padding:24px">
      <div style="max-width:540px;margin:0 auto;background:#fff;border-radius:12px;padding:28px;border:1px solid #e8ecf3">
        <h2 style="color:#1a2230;margin:0 0 8px">Redefinir senha</h2>
        <p style="color:#3b4554;margin:8px 0 18px">Recebemos uma solicitação para redefinir a senha da sua conta no ReadMyLabs. Clique abaixo para criar uma nova senha:</p>
        <p><a href="$linkRedefinir" style="display:inline-block;background:#3b6bff;color:#fff;padding:12px 22px;border-radius:8px;text-decoration:none;font-weight:600">Redefinir senha</a></p>
        <p style="color:#6b7689;margin-top:18px;font-size:13px">Se você não pediu, ignore este e-mail. O link expira em 30 minutos.</p>
      </div>
    </div>
    HTML;
    $txt = "Redefinir senha no ReadMyLabs:\n$linkRedefinir\n\nExpira em 30 minutos. Ignore se não foi você.";
    return [$html, $txt];
}
