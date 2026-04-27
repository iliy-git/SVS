<?php

namespace App\Http\Controllers;

use App\Models\Subscription;
use Illuminate\Http\JsonResponse;
use App\Models\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SubscriptionController extends Controller
{
    public function show($token): JsonResponse
    {
        $sub = Subscription::with(['configs' => function($query) {
            $query->where('is_active', true);
        }, 'configs.flag', 'configs.node'])
            ->where('token', $token)
            ->firstOrFail();

        $deviceError = $this->checkDeviceBinding($sub);
        if ($deviceError) {
            return $deviceError;
        }

        // Обновляем статистику всех конфигов из панелей x-ui
        foreach ($sub->configs as $config) {
            $this->refreshConfigStats($config);
        }

        // Перезагружаем коллекцию после обновления данных в БД
        $sub->load(['configs' => function($query) {
            $query->where('is_active', true);
        }, 'configs.flag', 'configs.node']);

        $nodes = [];
        $allOutbounds = [];
        $mainTag = null;
        $serverNodes = [];

        // Переменные для финального заголовка Userinfo
        $totalUpBytes = 0;
        $totalDownBytes = 0;
        $totalLimitBytes = 0;

        // Находим главный конфиг, если он отмечен
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

                // --- ЛОГИКА ГЛАВНОГО КОНФИГА ---
                if ($mainConfig) {
                    // Если есть главный конфиг, данные для бара берем ТОЛЬКО из него
                    if ($conf->id === $mainConfig->id) {
                        $totalUpBytes = $confUp;
                        $totalDownBytes = $confDown;
                        $totalLimitBytes = $confLimit;
                    }
                } else {
                    // Если главный не выбран — суммируем трафик всех серверов
                    $totalUpBytes += $confUp;
                    $totalDownBytes += $confDown;
                    $totalLimitBytes += $confLimit;
                }

                // Форматирование для конкретной строки сервера в списке
                $usedTotal = $confUp + $confDown;
                $usedLabel = $usedTotal > 1024**3
                    ? number_format($usedTotal / 1024**3, 2) . "GB"
                    : number_format($usedTotal / 1024**2, 1) . "MB";

                $limitLabel = ($conf->traffic_limit > 0) ? "{$conf->traffic_limit}GB" : "∞";
                $flagEmoji = $conf->flag ? $conf->flag->emoji : "🚀";
                $configName = $conf->name ?: $conf->email;

                $displayName = "{$flagEmoji} {$configName} | {$usedLabel} / {$limitLabel}";
                $serverNodes[] = $this->generateFullConfig($displayName, [$outbound], $sub->name);
            }
        }

        if ($sub->with_balancer && count($allOutbounds) > 1) {
            $allTags = array_column($allOutbounds, 'tag');

            $selectorTags = [];
            $fallbackTag = null;

            if ($mainTag) {
                $selectorTags = array_values(array_diff($allTags, [$mainTag]));

                $fallbackTag = $mainTag;
            } else {
                $selectorTags = $allTags;
                $fallbackTag = null;
            }

            $nodes[] = $this->generateFullConfig(
                "🌐 Auto Balancer",
                $allOutbounds,
                $sub->name,
                $selectorTags,
                $fallbackTag
            );
        }

        $nodes = array_merge($nodes, $serverNodes);

        $safeSubName = str_replace(['"', "'", "\n", "\r"], '', $sub->name);
        $expireTimestamp = $sub->expires_at ? $sub->expires_at->timestamp : 0;

        // Формируем заголовок для прогресс-бара Happ
        $userInfo = "upload={$totalUpBytes}; download={$totalDownBytes}; total={$totalLimitBytes}; expire={$expireTimestamp}";

        return response()->json($nodes, 200, [
            'Content-Type' => 'text/plain; charset=utf-8',
            'X-Config-Name' => $safeSubName,
            'Profile-Title' => $safeSubName,
            'Subscription-Userinfo' => $userInfo,
            'Profile-Update-Interval' => '2',
        ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }
    public function getHappLink($token)
    {
        $sub = Subscription::where('token', $token)->first();
        $limit = $sub ? ($sub->install_limit ?? 1) : 1;

        $providerCode = env('HAPP_PROVIDER_CODE');
        $authKey = env('HAPP_AUTH_KEY');

        try {
            $installResponse = Http::timeout(5)->get('https://api.happ-proxy.com/api/add-install', [
                'provider_code' => $providerCode,
                'auth_key' => $authKey,
                'install_limit' => $limit
            ]);

            $url = route('subscription.raw', ['token' => $token]);

            if ($installResponse->ok() && $installResponse->json('rc') === 1) {
                $installCode = $installResponse->json('install_code');

                if ($sub) {
                    $sub->update(['happ_install_code' => $installCode]);
                }

                $url .= (str_contains($url, '?') ? '&' : '?') . "InstallID=" . $installCode;
            }

            $cryptoResponse = Http::timeout(5)->post('https://crypto.happ.su/api-v2.php', [
                'url' => $url
            ]);

            if ($cryptoResponse->ok()) {
                return $cryptoResponse->json('encrypted_link') ?? $url;
            }

        } catch (\Exception $e) {
            \Log::error("Happ API Error: " . $e->getMessage());
        }

        return route('subscription.raw', ['token' => $token]);
    }
    private function checkDeviceBinding(Subscription $sub): ?JsonResponse
    {
        // 1. Извлекаем реальный HWID из заголовков Happ
        $deviceId = request()->header('x-hwid');
        \Log::info($deviceId);
        // 2. Если заголовка нет (например, старая версия или прямой запрос),
        if (!$deviceId) {
            $deviceId = request()->query('InstallID');
        }

        if (!$deviceId) {
            $userAgent = request()->header('User-Agent', '');
            if (str_contains($userAgent, 'Happ/')) {
                $parts = explode('/', $userAgent);
                $idFromUa = end($parts);
                if ($idFromUa !== '17764321936841767581') {
                    $deviceId = $idFromUa;
                }
            }
        }

        if (!$deviceId) {
            return null;
        }

        if (empty($sub->device_id)) {
            $sub->update(['device_id' => $deviceId]);
            \Log::info("Устройство привязано: {$deviceId} для подписки #{$sub->id}");
            return null;
        }

        // Если устройство чужое
        if ($sub->device_id !== $deviceId) {
            \Log::warning("Блокировка: Подписка {$sub->id} привязана к {$sub->device_id}, пришел {$deviceId}");

            $message1 = "⚠️ ДОСТУП ОГРАНИЧЕН:";
            $message2 = "НЕОБХОДИМО ОБРАТИТЬСЯ В ТП";

            $dummyOutbound = [
                "protocol" => "vless",
                "settings" => [
                    "vnext" => [[
                        "address" => "127.0.0.1",
                        "port" => 443,
                        "users" => [["id" => "00000000-0000-0000-0000-000000000000", "encryption" => "none"]]
                    ]]
                ],
                "tag" => "error-proxy"
            ];

            $errorNodes = [
                $this->generateFullConfig($message1, [$dummyOutbound], $sub->name),
                $this->generateFullConfig($message2, [$dummyOutbound], $sub->name)
            ];

            return response()->json($errorNodes, 200, [
                'Content-Type' => 'text/plain; charset=utf-8',
                'X-Config-Name' => "ОШИБКА ДОСТУПА",
                'Profile-Title' => "LIMIT_EXCEEDED",
                'Subscription-Userinfo' => "upload=0; download=0; total=1; expire=1", // Красивая плашка "Истекло"
            ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        }

        return null;
    }
    private function refreshConfigStats(Config $config): void
    {
        if (!$config->is_active || !$config->node || !$config->email) {
            return;
        }

        try {
            $response = Http::withHeaders([
                'X-API-KEY' => $config->node->api_key
            ])
                ->timeout(2)
                ->withoutVerifying()
                ->get("https://{$config->node->ip}:11223/email", [
                    'email' => $config->email
                ]);

            if ($response->ok()) {
                $data = $response->json();

                $config->update([
                    'up'    => $data['up'] ?? $config->up,
                    'down'  => $data['down'] ?? $config->down,
                    // Если x-ui отдает total (лимит), сохраняем его
                    'traffic_limit' => isset($data['total']) && $data['total'] > 0
                        ? ($data['total'] / (1024**3))
                        : $config->traffic_limit,
                    'expiry_time'   => $data['expiry_time'] ?? $config->expiry_time,
                ]);
            }
        } catch (\Exception $e) {
            // В случае ошибки просто логируем или игнорируем, чтобы не прерывать выдачу подписки
            \Log::error("Failed to refresh stats for {$config->email} on node {$config->node->ip}: " . $e->getMessage());
        }
    }

    private function testingForOneDevice($sub)
    {
        // Happ обычно передает уникальный ID в X-Device-Id.
        // Если его нет, используем User-Agent, но обрезаем его, чтобы избежать проблем с версиями.
        $currentDeviceId = request()->header('X-Device-Id') ?? md5(request()->userAgent());

        // Если в базе пусто — привязываем
        if (is_null($sub->device_id)) {
            $sub->update(['device_id' => $currentDeviceId]);
            return null;
        }

        // Если ID не совпадает
        if ($sub->device_id !== $currentDeviceId) {
            // Логируем для отладки, чтобы ты видел в storage/logs/laravel.log что именно не совпало
            \Log::warning("Device mismatch for sub {$sub->token}. DB: {$sub->device_id}, Request: {$currentDeviceId}");

            return response()->json([
                ["remarks" => "⚠️ Ошибка: Доступ только с 1 устройства!"]
            ], 200, ['Content-Type' => 'text/plain; charset=utf-8']);
        }

        return null;
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

    private function generateFullConfig($remarks, $proxyOutbounds, $subName, $balancerTags = null, $fallbackTag = null)
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

        if ($balancerTags && count($balancerTags) >= 1) {

            $balancers[] = [
                "tag" => "balancer-main",
                "selector" => $balancerTags,
                "strategy" => [
                    "type" => "leastPing"
                ],
                "fallbackTag" => $fallbackTag
            ];

            $obsSelector = $fallbackTag
                ? array_unique(array_merge($balancerTags, [$fallbackTag]))
                : $balancerTags;

            $observatory = [
                "subjectSelector" => $obsSelector,
                "probeUrl" => "http://connectivitycheck.gstatic.com/generate_204",
                "probeInterval" => "20s",
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
