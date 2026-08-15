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

        // === НОВАЯ ЛОГИКА: СИНХРОНИЗАЦИЯ ДАТЫ ОКОНЧАНИЯ ===

        // 1. Собираем все даты окончания больше 0 (исключаем безлимитные)
        $validExpiries = $sub->configs
            ->pluck('expiry_time')
            ->filter(fn($time) => (int)$time > 0);

        if ($validExpiries->isNotEmpty()) {
            // 2. Берем минимальное значение (ближайшую дату)
            $minExpiryMs = $validExpiries->min();

            // 3. Конвертируем миллисекунды X-UI в секунды для Carbon
            $minExpirySeconds = (int)($minExpiryMs / 1000);

            // 4. Обновляем подписку только если дата реально изменилась
            // (чтобы не дергать UPDATE при каждом запросе)
            if (!$sub->expires_at || $sub->expires_at->timestamp !== $minExpirySeconds) {
                $sub->update([
                    'expires_at' => \Carbon\Carbon::createFromTimestamp($minExpirySeconds)
                ]);
            }
        } else {
            // Если у всех конфигов expiry_time = 0 (полный безлимит по времени)
            // сбрасываем дату окончания подписки, если она была установлена
            if ($sub->expires_at !== null) {
                $sub->update(['expires_at' => null]);
            }
        }
//        $sub->refresh();

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
        Log::info( $expireTimestamp);
        $userInfo = "upload={$totalUpBytes}; download={$totalDownBytes}; total={$totalLimitBytes}; expire={$expireTimestamp}";

        // 1. Номер подписки прямо в главном названии, чтобы он всегда был перед глазами
        $profileTitle = "{$safeSubName}";

        // 2. Формируем подробный текст с переносами строк (\n)
        // Этот текст будет доступен в информационном блоке заголовка
        $infoText = trim("
📦 ВАША ПОДПИСКА №{$sub->id}
━━━━━━━━━━━━━━━━━
🆘 ВОЗНИКЛИ ПРОБЛЕМЫ ИЛИ ВОПРОСЫ?
💬 НАПИШИТЕ НАМ В TELEGRAM
🚀 МЫ ОПЕРАТИВНО ПОМОЖЕМ ВАМ!
");

// Кодируем в Base64 без лишних пробелов
        $encodedAnnounce = "base64:" . base64_encode($infoText);

        return response()->json($nodes, 200, [
            'Content-Type' => 'text/plain; charset=utf-8',

            // Название подписки (в самом верху интерфейса)
            'X-Config-Name' => $profileTitle,
            'Profile-Title' => $profileTitle,

            // Статистика трафика
            'Subscription-Userinfo' => $userInfo,
            'Profile-Update-Interval' => '2',

            // Кнопка-ссылка на Telegram (самолетик или глобус в клиенте)
            'Profile-WebPageUrl' => 'https://t.me/+DyqjfDZNgYY4NjNi',
            'Support-Url' => 'https://t.me/+DyqjfDZNgYY4NjNi',

            // Тот самый текст с номером и пояснением (обязательно кодируем)
            'announce' => $encodedAnnounce,

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
                'Subscription-Userinfo' => "upload=0; download=0; total=1; expire=1",
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
                ->timeout(3) // Рекомендуется поднять до 3 секунд, так как скрипт теперь делает чуть больше работы в БД
                ->withoutVerifying()
                ->get("https://{$config->node->ip}:11223/email", [
                    'email' => $config->email
                ]);

            if ($response->ok()) {
                $data = $response->json();

                $updateData = [
                    'up'    => $data['up'] ?? $config->up,
                    'down'  => $data['down'] ?? $config->down,
                    'traffic_limit' => isset($data['total']) && $data['total'] > 0
                        ? ($data['total'] / (1024**3))
                        : $config->traffic_limit,
                    'expiry_time'   => $data['expiry_time'] ?? $config->expiry_time,
                ];

                // Проверяем, пришел ли линк и не пустой ли он, чтобы не затереть БД ошибкой
                if (!empty($data['link']) && !$config->is_modernized) {
                    $updateData['link'] = $data['link'];
                }
                \Log::info($updateData);

                // Дополнительно: обновляем статус активности из панели (описано ниже)
                if (isset($data['is_active'])) {
                    $updateData['is_active'] = (bool)$data['is_active'];
                }

                $config->update($updateData);
            }
        } catch (\Exception $e) {
            \Log::error("Failed to refresh stats and link for {$config->email} on node {$config->node->ip}: " . $e->getMessage());
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
        // Проверяем, удалось ли распарсить ссылку и есть ли вообще протокол (scheme)
        if (!$u || !isset($u['scheme'])) {
            return null;
        }

        parse_str($u['query'] ?? '', $query);

        // ==========================================
        // Логика VLESS
        // ==========================================
        if ($u['scheme'] === 'vless') {
            $userData = [
                "encryption" => "none",
                "id" => $u['user'] ?? "",
                "level" => 8,
                "security" => "auto",
            ];

            if (!empty($query['flow'])) {
                $userData["flow"] = $query['flow'];
            }

            // Определяем тип транспорта (tcp, grpc, ws или xhttp)
            $networkType = $query['type'] ?? 'tcp';
            if (!in_array($networkType, ['tcp', 'grpc', 'ws', 'xhttp'])) {
                $networkType = 'tcp'; // фолбек на дефолт
            }

            // Определяем безопасность (reality, tls или none)
            $security = $query['security'] ?? 'none';
            if ($security === 'none' || empty($security)) {
                $security = 'none';
            }

            $streamSettings = [
                "network" => $networkType,
                "security" => $security,
            ];

            // Настройки Reality
            if ($security === 'reality') {
                $streamSettings["realitySettings"] = [
                    "allowInsecure" => false,
                    "fingerprint" => $query['fp'] ?? "chrome",
                    "publicKey" => $query['pbk'] ?? "",
                    "serverName" => $query['sni'] ?? "",
                    "shortId" => $query['sid'] ?? "",
                    "show" => false,
                    "spiderX" => "/"
                ];
            }
            // Настройки TLS
            elseif ($security === 'tls') {
                $tlsSettings = [
                    "allowInsecure" => false,
                    "serverName" => $query['sni'] ?? $query['host'] ?? ""
                ];

                if (!empty($query['alpn'])) {
                    // Корректно разбиваем ALPN из строки (может быть через запятую или с пробелами)
                    $tlsSettings["alpn"] = array_map('trim', explode(',', $query['alpn']));
                }

                if (!empty($query['fp'])) {
                    $tlsSettings["fingerprint"] = $query['fp'];
                }

                $streamSettings["tlsSettings"] = $tlsSettings;
            }

            // Транспортные настройки
            if ($networkType === 'grpc') {
                $streamSettings["grpcSettings"] = [
                    "authority" => $query['sni'] ?? "",
                    "multiMode" => true,
                    "serviceName" => $query['serviceName'] ?? ""
                ];
            } elseif ($networkType === 'ws') {
                $streamSettings["wsSettings"] = [
                    "headers" => [
                        "Host" => !empty($query['host']) ? $query['host'] : ($query['sni'] ?? "")
                    ],
                    "path" => !empty($query['path']) ? rawurldecode($query['path']) : "/"
                ];

                if (empty($streamSettings["wsSettings"]["headers"]["Host"])) {
                    unset($streamSettings["wsSettings"]["headers"]);
                }
            } elseif ($networkType === 'xhttp') {
                $xhttpSettings = [
                    "path" => !empty($query['path']) ? rawurldecode($query['path']) : "/",
                    "host" => !empty($query['host']) ? $query['host'] : ($query['sni'] ?? ""),
                    "mode" => $query['mode'] ?? "auto",
                ];

                // Разбор JSON-параметра extra (содержит packet-up, padding, методы и т.д.)
                if (!empty($query['extra'])) {
                    $extraDecoded = json_decode($query['extra'], true);
                    if (is_array($extraDecoded)) {
                        // Объединяем параметры из extra в xhttpSettings
                        $xhttpSettings = array_merge($xhttpSettings, $extraDecoded);
                    }
                }

                $streamSettings["xhttpSettings"] = $xhttpSettings;
            } else {
                $streamSettings["tcpSettings"] = ["header" => ["type" => "none"]];
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
                "streamSettings" => $streamSettings,
                "tag" => $tag
            ];
        }

        // Если протокол не поддерживается
        return null;
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
        $directDomains = [
            "regexp:.*\\.ru$",
            "regexp:.*\\.su$",
            "regexp:.*\\.by$",
            "regexp:.*\\.xn--p1ai$",
            "regexp:^(.+\\.)?mail\\.ru$",
            "regexp:^(.+\\.)?yandex\\.(aero|az|by|cloud|co\\.il|com(\\.am|\\.ge|\\.ru|\\.tr|\\.ua)?|de|ee|eu|fi|fr|jobs|kg|kz|lt|lv|md|net|org|pl|ru|st|sx|tj|tm|tr|ua|uz)$",
            "regexp:^(.+\\.)?vk\\.(com|ru)$",
            "regexp:^(.+\\.)?ok(cdn)?\\.ru$",
            "regexp:^(.+\\.)?2gis\\.(ae|am|az|by|com(\\.cy)?|cz|ge|kg|kz|ru|tj|ua|uz)$",
            "regexp:^(.+\\.)?vtb\\.ru$",
            "regexp:^(.+\\.)?rzd\\.ru$",
            "regexp:^(.+\\.)?gosuslugi\\.ru$",
            "regexp:^(.+\\.)?ozon(e)?\\.ru$",
            "regexp:^(.+\\.)?wb\\.ru$",
            "regexp:^(.+\\.)?wildberries\\.ru$",
            "regexp:^(.+\\.)?kinopoisk\\.ru$",
            "regexp:^(.+\\.)?alfa.*\\.ru$",
            "regexp:^(.+\\.)?t(2|ele2)\\.ru$",
            "regexp:^(.+\\.)?lemanapro\\.ru$",
            "regexp:^(.+\\.)?rutube\\.ru$",
            "regexp:^(.+\\.)?mts.*\\.ru$",
            "regexp:^(.+\\.)?dzen.*\\.ru$",
            "regexp:^(.+\\.)?x5.*\\.ru$",
            "regexp:^(.+\\.)?beeline\\.ru$",
            "regexp:^(.+\\.)?megafon\\.ru$",
            "regexp:^alfa(-?[a-z]+)?\\.(ru|com|biz|st)$",
            "regexp:^api\\.(a\\.mts|avito|expf|max|mindbox)\\.ru$",
            "regexp:^vtb(-?[a-z0-9]+)?\\.(ru|com|in|digital|site|bank\\.in|fut\\.ru|corp\\.ru|promo)$",
            "regexp:^[0-9]+\\.mc\\.yandex\\.ru$",
            "regexp:^[0-9]{2}\\.img\\.avito\\.st$",
            "regexp:^sun[0-9]+(-[0-9]+)?\\.userapi\\.com$",
            "regexp:^sso-app[0-9]+\\.vtb\\.ru$",
            "domain:2gis.ae", "domain:2gis.am", "domain:2gis.az", "domain:2gis.by", "domain:2gis.com", "domain:2gis.com.cy", "domain:2gis.cz", "domain:2gis.ge", "domain:2gis.kg", "domain:2gis.kz", "domain:2gis.ru", "domain:2gis.tj", "domain:2gis.ua", "domain:2gis.uz",
            "domain:avito.ru", "domain:avito.st", "domain:ok.ru", "domain:okcdn.ru", "domain:mycdn.me", "full:st-ok-pts.cdn-vk.ru", "full:st-ok.cdn-vk.ru",
            "domain:ozon.by", "domain:ozon.com", "domain:ozon.com.by", "domain:ozon.com.kz", "domain:ozon.kz", "domain:ozon.ru", "domain:ozon.tm", "domain:ozone.ru", "domain:ozonru.me", "domain:ozonusercontent.com",
            "domain:geobasket.ru", "domain:paywb.com", "domain:rwb.ru", "domain:wb-basket.ru", "domain:wb.ru", "domain:wbbasket.ru", "domain:wbpay.ru", "domain:wibes.ru", "domain:wildberries.ru",
            "domain:stbid.ru", "domain:1c-bitrix.ru", "domain:1c.ru", "domain:1cfresh.com", "domain:1cloud.ru", "domain:1internet.tv", "domain:300.ya.ru", "domain:47news.ru", "domain:4meeting.me", "domain:5ka.ru", "domain:5post.market", "domain:742231.ms.ok.ru", "domain:8ofpsm7zqu.a.trbcdn.net", "domain:a.auth-nsdi.ru", "domain:a.res-nsdi.ru", "domain:abr.ru", "domain:aclub.ru", "domain:adriver.ru", "domain:gov.ru", "domain:aeroflot.ru", "domain:alformacap.com", "domain:alformacapital.com", "domain:ams2-cdn.2gis.com", "domain:auth-nsdi.ru", "domain:auto.ru", "domain:av.ru", "domain:b.auth-nsdi.ru", "domain:b.res-nsdi.ru", "domain:baltbank.ru", "domain:banka-ui.dev", "domain:banki.ru", "domain:bankline.ru", "domain:flocktory.com", "domain:beta-bank.com", "domain:bitrix24.ru", "domain:boosty.to", "domain:max.ru", "domain:bronevik.com", "domain:mail.ru", "domain:cdn-tinkoff.ru", "domain:oversecure.org", "domain:uxfeedback.ru", "domain:serverstats.ru", "domain:cdnsix.vk-com.ru", "domain:speedstream.ru", "domain:chizhik.club", "domain:citydrive.ru", "domain:clstorage.net", "domain:mts.ru", "domain:rzd.ru", "domain:yadro.ru", "domain:credistory.ru", "domain:csat.ru", "domain:cscampus.ru", "domain:d-assets.2gis.ru", "domain:d5de4k0ri8jba7ucdbt6.apigw.yandexcloud.net", "domain:dbo-dengi.online", "domain:dellin.ru", "domain:disk.2gis.com", "domain:dixy.ru", "domain:dnevnik.ru", "domain:dns-shop.ru", "domain:dodopizza.ru", "domain:dom.ru", "domain:domclick.ru", "domain:donationalerts.com", "domain:drweb.ru", "domain:e5.ru", "domain:edadeal.io", "domain:edadeal.ru", "domain:vk.com", "domain:targetads.io", "domain:fastvps.ru", "domain:fb-cdn.premier.one", "domain:finuslugi.ru", "domain:fivepost.ru", "domain:fix-price.com", "domain:gazeta.ru", "domain:gazprombank.tech", "domain:gazprompay.ru", "domain:get4click.ru", "domain:gorodpay.ru", "domain:gpb.ru", "domain:gpbmobile.ru", "domain:gpmdi.ru", "domain:hh.ru", "domain:idx5.ru", "domain:imgsmail.ru", "domain:investalfabank.com", "domain:iz.ru", "domain:jivo.ru", "domain:jivochat.com", "domain:jivosite.com", "domain:jsons.injector.3ebra.net", "domain:jua5fcnytm.a.trbcdn.net", "domain:jx5.ru", "domain:kaspersky.com", "domain:kaspersky.ru", "domain:kazanexpress.ru", "domain:kommersant.ru", "domain:kp.ru", "domain:krasyar.ru", "domain:krd.ru", "domain:kuper.ru", "domain:lenta.com", "domain:lenta.ru", "domain:lmru.tech", "domain:magnit-ru.injector.3ebra.net", "domain:magnit.ru", "domain:tinkoff.ru", "domain:megafon.ru", "domain:megamarket.ru", "domain:megamarket.tech", "domain:memealerts.com", "domain:alfabank.ru", "domain:mirpayonline.ru", "domain:miya-news.online", "domain:mm.ru", "domain:mnogolososya.ru", "domain:moex.com", "domain:mtscdn.ru", "domain:mtsdengi.ru", "domain:myapelsin.ru", "domain:mymts.ru", "domain:netmonet.co", "domain:nspk.ru", "domain:okko.sport", "domain:okko.tv", "domain:okolo.app", "domain:oneme.ru", "domain:perekrestok.ru", "domain:rutubelist.ru", "domain:pochta.ru", "domain:userapi.com", "domain:psbank.ru", "domain:psblog.ru", "domain:adhigh.net", "domain:qms.ru", "domain:rambler.ru", "domain:skcrtxr.com", "domain:rbc.ru", "domain:receive-sentry.lmru.tech", "domain:res-nsdi.ru", "domain:rostelecom.ru", "domain:rshb.ru", "domain:rt.ru", "domain:rtbcdn.ru", "domain:russiacalling.com", "domain:rustore.ru", "domain:rzd-bonus.ru", "domain:s3.yandex.net", "domain:sbermarket.ru", "domain:sbermegamarket.ru", "domain:sbermobile.ru", "domain:sbpgpb.ru", "domain:servicepipe.ru", "domain:sistema-capital.com", "domain:spvb.ru", "domain:statad.ru", "domain:beeline.ru", "domain:stats.vk-portal.net", "domain:svoy.academy", "domain:tamtam.chat", "domain:taximaxim.ru", "domain:taxsee.com", "domain:tbank-online.com", "domain:timeweb.cloud", "domain:timeweb.com", "domain:tips.tips", "domain:tns-counter.ru", "domain:tnt-online.ru", "domain:tochka-tech.com", "domain:tochka.com", "domain:top-fwz1.mail.ru", "domain:topdelivery.ru", "domain:yastatic.net", "domain:trbcdn.net", "domain:tsx.x5static.net", "domain:tu-tu.ru", "domain:tutu.ru", "domain:usedesk.ru", "domain:vgtrk.ru", "domain:victoria-group.ru", "domain:volnamobile.ru", "domain:wcm.weborama-tech.ru", "domain:web-static.mindbox.ru", "domain:whoosh.bike", "domain:cbonds.ru", "domain:windsurf.com", "domain:wink.ru", "domain:ws-api.oneme.ru", "domain:vulpine.email", "domain:cikrf.ru", "domain:magnit.com", "domain:x5.ru", "domain:x5.tech", "domain:yota.ru", "domain:youla-web-static.mrgcdn.ru", "domain:youla.io", "domain:youla.ru", "domain:zentotem.net", "domain:xn----7sb7akeedqd.xn--p1ai", "domain:xn--80aacoonefzg3am8b1fsb.xn--p1ai", "domain:xn--90ab2c.xn--p1ai", "domain:xn--90aifd0aza.site", "domain:xn--b1aew.xn--p1ai"
        ];

        $rules = [
            ["type" => "field", "inboundTag" => ["metrics_in"], "outboundTag" => "metrics_out"],
            ["type" => "field", "protocol" => ["bittorrent"], "outboundTag" => "direct"],
            [
                "type" => "field",
                "geosite" => ["category-gov-ru", "category-ru", "vk", "yandex"],
                "domain" => $directDomains,
                "outboundTag" => "direct"
            ],
            ["type" => "field", "ip" => ["8.8.8.8"], "port" => "53", "outboundTag" => "direct"]
        ];

        $balancers = [];
        $observatory = null;

        if ($balancerTags && count($balancerTags) >= 1) {
            // Блок балансировщика оставляем как был
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
                "subjectSelector" => $balancerTags,
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
            // Динамически берем tag текущего обрабатываемого прокси
            $currentProxyTag = $proxyOutbounds[0]['tag'] ?? 'proxy-1';

            $rules[] = [
                "type" => "field",
                "inboundTag" => ["socks"],
                "port" => "53",
                "outboundTag" => $currentProxyTag
            ];

            $rules[] = [
                "type" => "field",
                "ip" => ["1.1.1.1"],
                "port" => "53",
                "outboundTag" => $currentProxyTag
            ];

            $rules[] = [
                "type" => "field",
                "network" => "tcp,udp",
                "outboundTag" => $currentProxyTag
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
                "domainMatcher" => "hybrid",
                "rules" => $rules,
                "balancers" => $balancers
            ],

            "stats" => (object)[],

            "observatory" => $observatory
        ];
    }
}
