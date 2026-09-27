<?php

namespace App\Services;

use App\Models\Subscription;

class XrayProfileBuilder
{
    /**
     * 1. КЛАССИЧЕСКИЙ ВАРИАНТ (УСПЕХ)
     */
    public function buildSuccessProfile(Subscription $sub): array
    {
        $nodes = [];
        $allOutbounds = [];
        $mainTag = null;
        $serverNodes = [];

        $totalUpBytes = 0;
        $totalDownBytes = 0;
        $totalLimitBytes = 0;

        $mainConfig = $sub->configs->firstWhere('is_main', true);

        foreach ($sub->configs as $i => $conf) {
            $tag = "proxy-" . ($i + 1);
            $outbound = $this->parseOutbound($conf->link, $tag);

            if ($outbound) {
                $allOutbounds[] = $outbound;
                if ($mainConfig && $conf->id === $mainConfig->id) {
                    $mainTag = $tag;
                }
                
                $confUp = (int)($conf->up ?? 0);
                $confDown = (int)($conf->down ?? 0);
                $confLimit = (int)(($conf->traffic_limit ?? 0) * 1024**3);

                if ($mainConfig) {
                    if ($conf->id === $mainConfig->id) {
                        $totalUpBytes = $confUp;
                        $totalDownBytes = $confDown;
                        $totalLimitBytes = $confLimit;
                    }
                } else {
                    $totalUpBytes += $confUp;
                    $totalDownBytes += $confDown;
                    $totalLimitBytes += $confLimit;
                }

                $usedTotal = $confUp + $confDown;
                $usedLabel = $usedTotal > 1024**3
                    ? number_format($usedTotal / 1024**3, 2) . "GB"
                    : number_format($usedTotal / 1024**2, 1) . "MB";

                $limitLabel = ($conf->traffic_limit > 0) ? "{$conf->traffic_limit}GB" : "∞";
                $flagEmoji = $conf->node?->flag?->emoji ?? $conf->flag?->emoji ?? "🚀";
                $configName = $conf->name ?: $conf->email;

                $displayName = "{$flagEmoji} {$configName} | {$usedLabel} / {$limitLabel}";
                $serverNodes[] = $this->generateFullConfig($displayName, [$outbound], $sub->name);
            }
        }

        if ($sub->with_balancer && count($allOutbounds) > 1) {
            $allTags = array_column($allOutbounds, 'tag');
            $selectorTags = $mainTag ? array_values(array_diff($allTags, [$mainTag])) : $allTags;
            $fallbackTag = $mainTag ?: null;

            $nodes[] = $this->generateFullConfig("🌐 Auto Balancer", $allOutbounds, $sub->name, $selectorTags, $fallbackTag);
        }

        $nodes = array_merge($nodes, $serverNodes);

        $infoText = trim("
📦 ВАША ПОДПИСКА №{$sub->id}
━━━━━━━━━━━━━━━━━
🆘 ВОЗНИКЛИ ПРОБЛЕМЫ ИЛИ ВОПРОСЫ?
💬 НАПИШИТЕ НАМ В TELEGRAM
🚀 МЫ ОПЕРАТИВНО ПОМОЖЕМ ВАМ!
");

        return [
            'nodes' => $nodes,
            'headers' => $this->buildHeaders($sub, $sub->name, $totalUpBytes, $totalDownBytes, $totalLimitBytes, $infoText)
        ];
    }

    /**
     * 2. ВАРИАНТ: ПРЕВЫШЕН ЛИМИТ УСТРОЙСТВ
     */
    public function buildLimitExceededProfile(Subscription $sub): array
    {
        $dummyOutbound = $this->getDummyOutbound("error-proxy");

        $nodes = [
            $this->generateFullConfig("⚠️ ДОСТУП ОГРАНИЧЕН:", [$dummyOutbound], $sub->name),
            $this->generateFullConfig("ПРЕВЫШЕН ЛИМИТ УСТРОЙСТВ ({$sub->install_limit})", [$dummyOutbound], $sub->name),
            $this->generateFullConfig("ОБРАТИТЕСЬ В ПОДДЕРЖКУ", [$dummyOutbound], $sub->name)
        ];

        $infoText = trim("
⚠️ ОШИБКА АВТОРИЗАЦИИ
━━━━━━━━━━━━━━━━━
Вы попытались подключить больше
устройств, чем позволяет тариф.
Лимит: {$sub->install_limit} шт.
");

        return [
            'nodes' => $nodes,
            'headers' => $this->buildHeaders($sub, "ЛИМИТ УСТРОЙСТВ", 0, 0, 1, $infoText)
        ];
    }

    /**
     * 3. ВАРИАНТ: ПОДПИСКА ИСТЕКЛА
     */
    public function buildExpiredProfile(Subscription $sub): array
    {
        $dummyOutbound = $this->getDummyOutbound("expired-proxy");

        $nodes = [
            $this->generateFullConfig("🛑 ПОДПИСКА ИСТЕКЛА", [$dummyOutbound], $sub->name),
            $this->generateFullConfig("ОПЛАТИТЕ ПРОДЛЕНИЕ", [$dummyOutbound], $sub->name)
        ];

        $infoText = trim("
🛑 ВАША ПОДПИСКА ИСТЕКЛА
━━━━━━━━━━━━━━━━━
Пожалуйста, перейдите в Telegram бота
и оплатите продление тарифа.
");

        // Устанавливаем expire = 1 (в прошлом), чтобы клиент сразу показал просрочку
        return [
            'nodes' => $nodes,
            'headers' => $this->buildHeaders($sub, "ПОДПИСКА ИСТЕКЛА", 0, 0, 1, $infoText, 1)
        ];
    }

    // ============================================================================
    // PRIVATE METHODS (Внутренняя логика сборки)
    // ============================================================================

    private function buildHeaders(Subscription $sub, string $title, int $upBytes, int $downBytes, int $limitBytes, string $infoText, int $forceExpire = null): array
    {
        $safeTitle = str_replace(['"', "'", "\n", "\r"], '', $title);
        $expireTimestamp = $forceExpire !== null ? $forceExpire : ($sub->expires_at ? $sub->expires_at->timestamp : 0);
        
        $userInfo = "upload={$upBytes}; download={$downBytes}; total={$limitBytes}; expire={$expireTimestamp}";

        return [
            'Content-Type'            => 'text/plain; charset=utf-8',
            'X-Config-Name'           => $safeTitle,
            'Profile-Title'           => $safeTitle,
            'Subscription-Userinfo'   => $userInfo,
            'Profile-Update-Interval' => '2',
            'Profile-WebPageUrl'      => 'https://t.me/+DyqjfDZNgYY4NjNi',
            'Support-Url'             => 'https://t.me/+DyqjfDZNgYY4NjNi',
            'announce'                => "base64:" . base64_encode($infoText),
        ];
    }

    private function getDummyOutbound(string $tag): array
    {
        return [
            "protocol" => "vless",
            "settings" => [
                "vnext" => [[
                    "address" => "127.0.0.1",
                    "port" => 443,
                    "users" => [["id" => "00000000-0000-0000-0000-000000000000", "encryption" => "none"]]
                ]]
            ],
            "tag" => $tag
        ];
    }

    private function parseOutbound($link, $tag)
    {
        $u = parse_url($link);
        if (!$u || !isset($u['scheme'])) return null;
        parse_str($u['query'] ?? '', $query);

        if ($u['scheme'] === 'vless') {
            $userData = ["encryption" => "none", "id" => $u['user'] ?? "", "level" => 8, "security" => "auto"];
            if (!empty($query['flow'])) $userData["flow"] = $query['flow'];

            $networkType = $query['type'] ?? 'tcp';
            if (!in_array($networkType, ['tcp', 'grpc', 'ws', 'xhttp'])) $networkType = 'tcp';

            $security = $query['security'] ?? 'none';
            if ($security === 'none' || empty($security)) $security = 'none';

            $streamSettings = ["network" => $networkType, "security" => $security];

            if ($security === 'reality') {
                $streamSettings["realitySettings"] = [
                    "allowInsecure" => false, "fingerprint" => $query['fp'] ?? "chrome",
                    "publicKey" => $query['pbk'] ?? "", "serverName" => $query['sni'] ?? "",
                    "shortId" => $query['sid'] ?? "", "show" => false, "spiderX" => "/"
                ];
            } elseif ($security === 'tls') {
                $tlsSettings = ["allowInsecure" => false, "serverName" => $query['sni'] ?? $query['host'] ?? ""];
                if (!empty($query['alpn'])) $tlsSettings["alpn"] = array_map('trim', explode(',', $query['alpn']));
                if (!empty($query['fp'])) $tlsSettings["fingerprint"] = $query['fp'];
                $streamSettings["tlsSettings"] = $tlsSettings;
            }

            if ($networkType === 'grpc') {
                $streamSettings["grpcSettings"] = ["authority" => $query['sni'] ?? "", "multiMode" => true, "serviceName" => $query['serviceName'] ?? ""];
            } elseif ($networkType === 'ws') {
                $streamSettings["wsSettings"] = ["headers" => ["Host" => !empty($query['host']) ? $query['host'] : ($query['sni'] ?? "")], "path" => !empty($query['path']) ? rawurldecode($query['path']) : "/"];
                if (empty($streamSettings["wsSettings"]["headers"]["Host"])) unset($streamSettings["wsSettings"]["headers"]);
            } elseif ($networkType === 'xhttp') {
                $xhttpSettings = ["path" => !empty($query['path']) ? rawurldecode($query['path']) : "/", "host" => !empty($query['host']) ? $query['host'] : ($query['sni'] ?? ""), "mode" => $query['mode'] ?? "auto"];
                if (!empty($query['extra'])) {
                    $extraDecoded = json_decode($query['extra'], true);
                    if (is_array($extraDecoded)) $xhttpSettings = array_merge($xhttpSettings, $extraDecoded);
                }
                $streamSettings["xhttpSettings"] = $xhttpSettings;
            } else {
                $streamSettings["tcpSettings"] = ["header" => ["type" => "none"]];
            }

            return [
                "mux" => ["concurrency" => -1, "enabled" => false, "xudpConcurrency" => 8, "xudpProxyUDP443" => ""],
                "protocol" => "vless",
                "settings" => ["vnext" => [["address" => $u['host'], "port" => (int)($u['port'] ?? 443), "users" => [$userData]]]],
                "streamSettings" => $streamSettings,
                "tag" => $tag
            ];
        }
        return null;
    }

    private function generateFullConfig($remarks, $proxyOutbounds, $subName, $balancerTags = null, $fallbackTag = null)
    {
        $outbounds = $proxyOutbounds;
        $outbounds[] = ["protocol" => "freedom", "settings" => ["domainStrategy" => "UseIP"], "tag" => "direct"];
        $outbounds[] = ["protocol" => "blackhole", "settings" => ["response" => ["type" => "http"]], "tag" => "block"];

        $domainsPath = storage_path('app/domains.txt');
        if (file_exists($domainsPath)) {
            $directDomains = file($domainsPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        } else {
            $directDomains = ["regexp:.*\\.ru$", "regexp:.*\\.su$", "domain:xn--b1aew.xn--p1ai"];
        }

        $rules = [
            ["type" => "field", "inboundTag" => ["metrics_in"], "outboundTag" => "metrics_out"],
            ["type" => "field", "protocol" => ["bittorrent"], "outboundTag" => "direct"],
            ["type" => "field", "geosite" => ["category-gov-ru", "category-ru", "vk", "yandex"], "domain" => $directDomains, "outboundTag" => "direct"],
            ["type" => "field", "ip" => ["8.8.8.8"], "port" => "53", "outboundTag" => "direct"]
        ];

        $balancers = [];
        $observatory = null;

        if ($balancerTags && count($balancerTags) >= 1) {
            $balancers[] = ["tag" => "balancer-main", "selector" => $balancerTags, "strategy" => ["type" => "leastPing"], "fallbackTag" => $fallbackTag];
            $observatory = ["subjectSelector" => $balancerTags, "probeUrl" => "http://connectivitycheck.gstatic.com/generate_204", "probeInterval" => "20s", "enableConcurrency" => true];
            array_unshift($rules, ["type" => "field", "network" => "tcp,udp", "balancerTag" => "balancer-main"]);
        } else {
            $currentProxyTag = $proxyOutbounds[0]['tag'] ?? 'proxy-1';
            array_push($rules, 
                ["type" => "field", "inboundTag" => ["socks"], "port" => "53", "outboundTag" => $currentProxyTag],
                ["type" => "field", "ip" => ["1.1.1.1"], "port" => "53", "outboundTag" => $currentProxyTag],
                ["type" => "field", "network" => "tcp,udp", "outboundTag" => $currentProxyTag]
            );
        }

        return [
            "dns" => ["hosts" => ["domain:googleapis.cn" => "googleapis.com"], "queryStrategy" => "UseIPv4", "servers" => ["1.1.1.1", ["address" => "1.1.1.1", "port" => 53], ["address" => "8.8.8.8", "port" => 53]]],
            "inbounds" => [
                ["listen" => "127.0.0.1", "port" => 10808, "protocol" => "socks", "settings" => ["auth" => "noauth", "udp" => true, "userLevel" => 8], "sniffing" => ["destOverride" => ["http", "tls", "quic"], "enabled" => true], "tag" => "socks"],
                ["listen" => "127.0.0.1", "port" => 10809, "protocol" => "http", "settings" => ["userLevel" => 8], "sniffing" => ["destOverride" => ["http", "tls", "quic"], "enabled" => true], "tag" => "http"],
                ["listen" => "127.0.0.1", "port" => 11111, "protocol" => "dokodemo-door", "settings" => ["address" => "127.0.0.1"], "tag" => "metrics_in"]
            ],
            "log" => ["loglevel" => "warning"],
            "metrics" => ["tag" => "metrics_out"],
            "outbounds" => $outbounds,
            "policy" => ["levels" => ["0" => ["statsUserDownlink" => true, "statsUserUplink" => true], "8" => ["connIdle" => 300, "downlinkOnly" => 1, "handshake" => 4, "uplinkOnly" => 1]], "system" => ["statsInboundDownlink" => true, "statsInboundUplink" => true, "statsOutboundDownlink" => true, "statsOutboundUplink" => true]],
            "remarks" => $remarks,
            "description" => $subName,
            "routing" => ["domainStrategy" => "IPIfNonMatch", "domainMatcher" => "hybrid", "rules" => $rules, "balancers" => $balancers],
            "stats" => (object)[],
            "observatory" => $observatory
        ];
    }
}