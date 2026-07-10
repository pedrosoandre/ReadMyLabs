<?php
// ReadMyLabs — identidade de rede do cliente (rate-limit, whitelist, log).
// Interface única: quem precisa do IP do visitante chama daqui. Trocar a forma
// de resolver o IP (novo proxy/CDN) muda só este arquivo.
//
// Regras:
// - NUNCA confia em X-Forwarded-For (o cliente forja livremente).
// - Atrás da Cloudflare, o IP real do visitante vem em CF-Connecting-IP; só
//   confiamos nesse header quando REMOTE_ADDR pertence a uma faixa da Cloudflare
//   (senão um atacante batendo direto na origem o forjaria). Auto-detecta: sem
//   Cloudflare, o header é ignorado e vale o REMOTE_ADDR — falha-para-desligado.
// - Para rate-limit, IPv6 colapsa para o prefixo /64: um usuário costuma
//   controlar o bloco inteiro, então rotacionar os 64 bits finais daria "IPs
//   novos" de graça; a chave passa a ser o /64, não o endereço completo.

/** Faixas publicadas da Cloudflare (v4+v6). Fonte: cloudflare.com/ips
 *  (mudam muito raramente). */
function faixasCloudflare(): array {
    return [
        // IPv4
        '173.245.48.0/20', '103.21.244.0/22', '103.22.200.0/22', '103.31.4.0/22',
        '141.101.64.0/18', '108.162.192.0/18', '190.93.240.0/20', '188.114.96.0/20',
        '197.234.240.0/22', '198.41.128.0/17', '162.158.0.0/15', '104.16.0.0/13',
        '104.24.0.0/14', '172.64.0.0/13', '131.0.72.0/22',
        // IPv6
        '2400:cb00::/32', '2606:4700::/32', '2803:f800::/32', '2405:b500::/32',
        '2405:8100::/32', '2a06:98c0::/29', '2c0f:f248::/32',
    ];
}

/** $ip pertence ao CIDR $cidr? Compara os bits do prefixo (v4 e v6). */
function ipEmFaixa(string $ip, string $cidr): bool {
    $partes = explode('/', $cidr, 2);
    if (count($partes) !== 2) return false;
    $ipBin   = @inet_pton($ip);
    $redeBin = @inet_pton($partes[0]);
    if ($ipBin === false || $redeBin === false) return false;
    if (strlen($ipBin) !== strlen($redeBin)) return false; // famílias diferentes
    $bits          = (int) $partes[1];
    $bytesInteiros = intdiv($bits, 8);
    $restoBits     = $bits % 8;
    if ($bytesInteiros > 0
        && substr($ipBin, 0, $bytesInteiros) !== substr($redeBin, 0, $bytesInteiros)) {
        return false;
    }
    if ($restoBits > 0) {
        $mascara = ~(0xFF >> $restoBits) & 0xFF;
        if ((ord($ipBin[$bytesInteiros]) & $mascara) !== (ord($redeBin[$bytesInteiros]) & $mascara)) {
            return false;
        }
    }
    return true;
}

/** IP real do visitante (Cloudflare-aware, SEM colapsar /64). Use para match
 *  exato de whitelist. String vazia se não houver REMOTE_ADDR (ex.: CLI). */
function ipClienteReal(): string {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    if (!empty($_SERVER['HTTP_CF_CONNECTING_IP']) && filter_var($ip, FILTER_VALIDATE_IP)) {
        foreach (faixasCloudflare() as $cidr) {
            if (ipEmFaixa($ip, $cidr)) {
                $real = trim($_SERVER['HTTP_CF_CONNECTING_IP']);
                if (filter_var($real, FILTER_VALIDATE_IP)) $ip = $real;
                break;
            }
        }
    }
    return $ip;
}

/** Aplica a normalização de chave (IPv6 -> /64) a um IP qualquer. Usado pela
 *  chave de rate-limit e pelo aplicador de whitelist (para zerar o arquivo
 *  certo). '' -> 'cli' (preserva o hash do caminho CLI). */
function normalizarChaveIp(string $ip): string {
    if ($ip === '') return 'cli';
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
        $bin = @inet_pton($ip);
        if ($bin !== false && strlen($bin) === 16) {
            $prefixo = @inet_ntop(substr($bin, 0, 8) . str_repeat("\0", 8));
            if ($prefixo !== false) return $prefixo;
        }
    }
    return $ip;
}

/** Chave de rate-limit do cliente: IP real (Cloudflare-aware) com IPv6 em /64. */
function ipCliente(): string {
    return normalizarChaveIp(ipClienteReal());
}
