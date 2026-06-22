<?php
// One-off: adiciona/atualiza IP_WHITELIST no .env do servidor sem sobrescrever
// outras chaves, e remove o arquivo de rate-limit do IP (libera acesso imediato).
// CLI-only. APAGAR do servidor depois de rodar.

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); }

$ip = $argv[1] ?? '';
if ($ip === '' || !filter_var($ip, FILTER_VALIDATE_IP)) {
    fwrite(STDERR, "uso: php config_ip_whitelist.php <IP>\n");
    exit(1);
}

// Resolve caminho do .env (mesma raiz do public_html — relativo ao arquivo).
$root   = __DIR__;
$envArq = $root . '/.env';
if (!is_file($envArq)) {
    fwrite(STDERR, ".env não encontrado em $envArq\n");
    exit(1);
}

// Lê o .env atual SEM sobrescrever. Procura linha IP_WHITELIST=...
$envRaw   = file_get_contents($envArq);
$linhas   = preg_split('/\R/', $envRaw);
$achou    = false;
$linhasNovas = [];
foreach ($linhas as $l) {
    if (preg_match('/^\s*IP_WHITELIST\s*=\s*(.*)$/', $l, $m)) {
        $achou = true;
        $atual = trim($m[1]);
        $ips   = array_values(array_filter(array_map('trim', explode(',', $atual))));
        if (!in_array($ip, $ips, true)) $ips[] = $ip;
        $linhasNovas[] = 'IP_WHITELIST=' . implode(',', $ips);
        echo "[UPD ] IP_WHITELIST: " . implode(',', $ips) . "\n";
    } else {
        $linhasNovas[] = $l;
    }
}
if (!$achou) {
    $linhasNovas[] = 'IP_WHITELIST=' . $ip;
    echo "[ADD ] IP_WHITELIST=$ip\n";
}

// Backup do .env (FORA do public_html — segurança LGPD; não cria .bak na web).
$bk = dirname($root) . '/.env.bk_' . date('Ymd_His');
if (file_put_contents($bk, $envRaw) === false) {
    fwrite(STDERR, "não consegui criar backup em $bk — abortando\n");
    exit(1);
}
echo "[BAK ] $bk\n";

if (file_put_contents($envArq, implode("\n", $linhasNovas)) === false) {
    fwrite(STDERR, "não consegui escrever $envArq\n");
    exit(1);
}
echo "[OK  ] .env atualizado\n";

// Remove o arquivo de rate-limit do IP (libera acesso imediato hoje).
$hash  = hash('sha256', $ip);
$arqRL = $root . '/limite_ip/' . $hash . '.txt';
if (is_file($arqRL)) {
    if (unlink($arqRL)) echo "[RM  ] rate-limit do IP zerado ($arqRL)\n";
    else                echo "[ERR ] não consegui remover $arqRL\n";
} else {
    echo "[INFO] rate-limit do IP não existia (já estava limpo)\n";
}

echo "\nPronto. Faça uma análise para validar.\n";
