<?php

namespace App\Http\Controllers;

use App\Models\Subscription;
use Illuminate\Http\JsonResponse;

class SubscriptionController extends Controller
{
    public function show($token): JsonResponse
    {
        $sub = Subscription::with('configs.flag')->where('token', $token)->firstOrFail();

        $nodes = [];
        $allOutbounds = [];
        $totalLimitBytes = 0;
        $serverNodes = [];

        foreach ($sub->configs as $i => $conf) {
            $tag = "proxy-" . ($i + 1);
            $outbound = $this->parseOutbound($conf->link, $tag);

            if ($outbound) {
                $allOutbounds[] = $outbound;

                $limitGb = $conf->traffic_limit ?? 0;
                $totalLimitBytes += ($limitGb * 1024 * 1024 * 1024);
                $limitLabel = ($limitGb > 0) ? " ({$limitGb}GB)" : " (∞)";

                $flagEmoji = $conf->flag ? $conf->flag->emoji : "🚀";
                $configName = $conf->name ?: "Server " . ($i + 1);
                $displayName = "{$flagEmoji} {$configName}{$limitLabel}";

                $serverNodes[] = $this->generateFullConfig($displayName, [$outbound], $sub->name);
            }
        }

        if ($sub->with_balancer && count($allOutbounds) > 1) {
            $balancerTags = array_column($allOutbounds, 'tag');
            $nodes[] = $this->generateFullConfig("🌐 Auto", $allOutbounds, $sub->name, $balancerTags);
        }

        $nodes = array_merge($nodes, $serverNodes);

        $safeSubName = str_replace(['"', "'", "\n", "\r"], '', $sub->name);
        $expireTimestamp = $sub->expires_at ? $sub->expires_at->timestamp : 0;

        $userInfo = "upload=0; download=0; total={$totalLimitBytes}; expire={$expireTimestamp}";

        return response()->json($nodes, 200, [
            'Content-Type' => 'text/plain; charset=utf-8',
            'Content-Disposition' => 'inline; filename="' . rawurlencode($safeSubName) . '"',
            'X-Config-Name' => $safeSubName,
            'Profile-Title' => $safeSubName,
            'Subscription-Userinfo' => $userInfo,
            'Profile-Update-Interval' => '2',
        ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }

    private function parseOutbound($link, $tag)
    {
        $u = parse_url($link);
        if (!$u || (!isset($u['scheme']) || $u['scheme'] !== 'vless')) return null;

        parse_str($u['query'] ?? '', $query);

        $userData = [
            "encryption" => "none",
            "id" => $u['user'],
            "level" => 8,
            "security" => "auto",
        ];


        if (!empty($query['flow'])) {
            $userData["flow"] = $query['flow'];
        }

        return [
            "mux" => ["concurrency" => -1, "enabled" => false, "xudpConcurrency" => 8, "xudpProxyUDP443" => ""],
            "protocol" => "vless",
            "settings" => [
                "vnext" => [[
                    "address" => $u['host'],
                    "port" => (int)($u['port'] ?? 443),
                    "users" => [$userData]
                ]]
            ],
            "streamSettings" => [
                "network" => "tcp",
                "realitySettings" => [
                    "allowInsecure" => false,
                    "fingerprint" => "chrome",
                    "publicKey" => $query['pbk'] ?? "",
                    "serverName" => $query['sni'] ?? "",
                    "shortId" => $query['sid'] ?? "",
                    "show" => false,
                    "spiderX" => "/"
                ],
                "security" => "reality",
                "tcpSettings" => ["header" => ["type" => "none"]]
            ],
            "tag" => $tag
        ];
    }

    private function generateFullConfig($remarks, $proxyOutbounds, $subName, $balancerTags = null)
    {
        $outbounds = $proxyOutbounds;

        $outbounds[] = [
            "protocol" => "freedom",
            "settings" => ["domainStrategy" => "UseIP"],
            "tag" => "direct"
        ];

        $outbounds[] = [
            "protocol" => "blackhole",
            "settings" => ["response" => ["type" => "http"]],
            "tag" => "block"
        ];

        $rules = [
            [
                "type" => "field",
                "inboundTag" => ["metrics_in"],
                "outboundTag" => "metrics_out"
            ],
            [
                "type" => "field",
                "ip" => ["8.8.8.8"],
                "port" => "53",
                "outboundTag" => "direct"
            ]
        ];

        $balancers = [];
        $observatory = null;

        if ($balancerTags && count($balancerTags) > 1) {

            $balancers[] = [
                "tag" => "balancer-main",
                "selector" => $balancerTags,
                "strategy" => [
                    "type" => "leastPing"
                ]
            ];

            $observatory = [
                "subjectSelector" => $balancerTags,
                "probeUrl" => "http://connectivitycheck.gstatic.com/generate_204",
                "probeInterval" => "10s",
                "enableConcurrency" => true
            ];

            array_unshift($rules, [
                "type" => "field",
                "network" => "tcp,udp",
                "balancerTag" => "balancer-main"
            ]);

        } else {
            $mainTag = $proxyOutbounds[0]['tag'];

            $rules[] = [
                "type" => "field",
                "inboundTag" => ["socks"],
                "port" => "53",
                "outboundTag" => $mainTag
            ];

            $rules[] = [
                "type" => "field",
                "ip" => ["1.1.1.1"],
                "port" => "53",
                "outboundTag" => $mainTag
            ];

            $rules[] = [
                "type" => "field",
                "network" => "tcp,udp",
                "outboundTag" => $mainTag
            ];
        }

        return [
            "dns" => [
                "hosts" => [
                    "domain:googleapis.cn" => "googleapis.com"
                ],
                "queryStrategy" => "UseIPv4",
                "servers" => [
                    "1.1.1.1",
                    ["address" => "1.1.1.1", "port" => 53],
                    ["address" => "8.8.8.8", "port" => 53]
                ]
            ],

            "inbounds" => [
                [
                    "listen" => "127.0.0.1",
                    "port" => 10808,
                    "protocol" => "socks",
                    "settings" => [
                        "auth" => "noauth",
                        "udp" => true,
                        "userLevel" => 8
                    ],
                    "sniffing" => [
                        "destOverride" => ["http", "tls", "quic"],
                        "enabled" => true
                    ],
                    "tag" => "socks"
                ],
                [
                    "listen" => "127.0.0.1",
                    "port" => 10809,
                    "protocol" => "http",
                    "settings" => ["userLevel" => 8],
                    "sniffing" => [
                        "destOverride" => ["http", "tls", "quic"],
                        "enabled" => true
                    ],
                    "tag" => "http"
                ],
                [
                    "listen" => "127.0.0.1",
                    "port" => 11111,
                    "protocol" => "dokodemo-door",
                    "settings" => ["address" => "127.0.0.1"],
                    "tag" => "metrics_in"
                ]
            ],

            "log" => [
                "loglevel" => "warning"
            ],

            "metrics" => [
                "tag" => "metrics_out"
            ],

            "outbounds" => $outbounds,

            "policy" => [
                "levels" => [
                    "0" => [
                        "statsUserDownlink" => true,
                        "statsUserUplink" => true
                    ],
                    "8" => [
                        "connIdle" => 300,
                        "downlinkOnly" => 1,
                        "handshake" => 4,
                        "uplinkOnly" => 1
                    ]
                ],
                "system" => [
                    "statsInboundDownlink" => true,
                    "statsInboundUplink" => true,
                    "statsOutboundDownlink" => true,
                    "statsOutboundUplink" => true
                ]
            ],

            "remarks" => $remarks,
            "description" => $subName,

            "routing" => [
                "domainStrategy" => "IPIfNonMatch",
                "rules" => $rules,
                "balancers" => $balancers
            ],

            "stats" => (object)[],

            "observatory" => $observatory
        ];
    }
}
