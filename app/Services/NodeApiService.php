<?php

namespace App\Services;

use App\Models\Config;
use App\Models\Node;
use Illuminate\Http\Client\Pool;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class NodeApiService
{
    /**
     * Параллельное обновление статистики активных конфигов через Http::pool.
     * Запросы ко всем нодам выполняются одновременно, а не по очереди.
     */
    public function refreshAllConfigsStats($configs): void
    {
        $activeConfigs = $configs->filter(function (Config $c) {
            if (!$c->is_active || !$c->node || !$c->email) {
                return false;
            }
            // Если конфиг создан только что (менее 15 сек назад в JIT-синхронизации),
            // его данные уже свежие — пропускаем, чтобы сэкономить сетевое время
            if ($c->created_at && $c->created_at->diffInSeconds(now()) < 15) {
                return false;
            }
            return true;
        });

        if ($activeConfigs->isEmpty()) {
            return;
        }

        try {
            // Параллельный опрос всех нод с жестким таймаутом 2.5 сек
            $responses = Http::pool(function (Pool $pool) use ($activeConfigs) {
                foreach ($activeConfigs as $config) {
                    $pool->as((string)$config->id)
                        ->withHeaders([
                            'X-API-KEY' => $config->node->api_key
                        ])
                        ->timeout(2.5)
                        ->withoutVerifying()
                        ->get("https://{$config->node->ip}:11223/email", [
                            'email' => $config->email
                        ]);
                }
            });

            foreach ($activeConfigs as $config) {
                $response = $responses[$config->id] ?? null;
                
                if ($response && $response instanceof \Illuminate\Http\Client\Response && $response->ok()) {
                    $data = $response->json();

                    $updateData = [
                        'up'    => $data['up'] ?? $config->up,
                        'down'  => $data['down'] ?? $config->down,
                        'traffic_limit' => isset($data['total']) && $data['total'] > 0
                            ? ($data['total'] / (1024**3))
                            : $config->traffic_limit,
                        'expiry_time'   => $data['expiry_time'] ?? $config->expiry_time,
                    ];

                    if (!empty($data['link']) && !$config->is_modernized) {
                        $updateData['link'] = $data['link'];
                    }

                    // if (isset($data['is_active'])) {
                    //     $updateData['is_active'] = (bool)$data['is_active'];
                    // }

                    $config->update($updateData);
                }
            }
        } catch (\Exception $e) {
            Log::error("Failed in refreshAllConfigsStats: " . $e->getMessage());
        }
    }

    /**
     * Создание клиента на ноде через API (3x-ui)
     */
    public function createClientOnNode(Node $node, array $params): array
    {
        $ip = $node->ip ?? 'localhost';
        $baseUrl = "https://{$ip}:11223";
        $apiKey = $node->api_key ?? '';

        try {
            $addResponse = Http::withHeaders([
                'X-API-KEY'    => $apiKey,
                'Content-Type' => 'application/json',
            ])
                ->withoutVerifying()
                ->timeout(3.5)
                ->post("{$baseUrl}/client/add", [
                    'inbound_id' => (int)$params['inbound_id'],
                    'email'      => $params['email'],
                    'totalGB'    => (int)($params['totalGB'] ?? 0),
                    'days'       => (int)($params['days'] ?? 30),
                ]);

            if (!$addResponse->successful()) {
                Log::error("Ошибка POST /client/add на ноде {$baseUrl} (Email: {$params['email']}): " . $addResponse->body());
                return ['link' => ''];
            }

            $addData = $addResponse->json();
            if (!empty($addData['link'])) {
                return ['link' => $addData['link']];
            }

            usleep(250000);

            return $this->fetchLinkByEmail($baseUrl, $apiKey, $params['email']);

        } catch (\Exception $e) {
            Log::error("Исключение при запросе к ноде {$baseUrl} (Email: {$params['email']}): " . $e->getMessage());
        }

        return ['link' => ''];
    }

    /**
     * Запрос ссылки с ноды по email
     */
    public function fetchLinkByEmail(string $baseUrl, string $apiKey, string $email): array
    {
        try {
            $response = Http::withHeaders(['X-API-KEY' => $apiKey])
                ->withoutVerifying()
                ->timeout(2.5)
                ->get("{$baseUrl}/email", ['email' => $email]);

            if ($response->successful()) {
                $data = $response->json();
                return ['link' => $data['link'] ?? ''];
            } else {
                Log::error("Ошибка GET /email для {$email} на ноде {$baseUrl}: " . $response->body());
            }
        } catch (\Exception $e) {
            Log::error("Исключение при GET /email для {$email} на ноде {$baseUrl}: " . $e->getMessage());
        }

        return ['link' => ''];
    }

    /**
     * Модернизация VLESS/xHTTP ссылки под TLS + CDN
     */
    public function modernizeLink(string $uri, bool $isTls, bool &$isModernized): string
    {
        $isModernized = false;

        if (!$isTls || empty($uri)) {
            return $uri;
        }

        $parsed = parse_url($uri);
        if (!$parsed || !isset($parsed['scheme']) || $parsed['scheme'] !== 'vless') {
            return $uri;
        }

        $query = [];
        if (isset($parsed['query'])) {
            parse_str($parsed['query'], $query);
        }

        $cdnHost    = 'pt0pegkjoi.cdn.twcstorage.ru';
        $headerHost = 'cdn.komap.pw';

        $parsed['host'] = $cdnHost;
        $parsed['port'] = 443;

        $query['type']     = 'xhttp';
        $query['security'] = 'tls';
        $query['sni']      = $cdnHost;
        $query['fp']       = 'randomized';
        $query['alpn']     = 'h3,h2,http/1.1';
        $query['host']     = $headerHost;
        $query['path']     = '/assets/vendor.js';
        $query['mode']     = 'packet-up';

        $extraData = [
            'noGRPCHeader'       => true,
            'seqKey'             => '_seq',
            'seqPlacement'       => 'query',
            'sessionIDKey'       => '_sid',
            'sessionIDPlacement' => 'query',
            'sessionKey'         => '_sid',
            'sessionPlacement'   => 'query',
            'uplinkHTTPMethod'   => 'POST',
            'xPaddingBytes'      => '100-300',
            'xPaddingKey'        => '_dc',
            'xPaddingMethod'     => 'tokenish',
            'xPaddingObfsMode'   => true,
            'xPaddingPlacement'  => 'query',
        ];

        $query['extra'] = json_encode($extraData, JSON_UNESCAPED_SLASHES);

        unset($query['spx'], $query['encryption']);

        $isModernized = true;

        $user     = isset($parsed['user']) ? $parsed['user'] . '@' : '';
        $host     = $parsed['host'];
        $port     = ':' . $parsed['port'];
        $path     = $parsed['path'] ?? '';
        $fragment = isset($parsed['fragment']) ? '#' . $parsed['fragment'] : '#Modernized-Proxy';
        $queryString = '?' . http_build_query($query);

        return "vless://{$user}{$host}{$port}{$path}{$queryString}{$fragment}";
    }
    /**
     * Продление клиента на ноде (3x-ui)
     */
    public function extendClientOnNode(\App\Models\Config $config, int $days): bool
    {
        $ip = $config->node->ip ?? 'localhost';
        $baseUrl = "https://{$ip}:11223";
        $apiKey = $config->node->api_key ?? '';

        try {
            $response = Http::withHeaders([
                'X-API-KEY' => $apiKey
            ])
                ->withoutVerifying()
                ->timeout(5)
                ->post("{$baseUrl}/email/extend", [
                    'email' => $config->email,
                    'days'  => $days
                ]);

            if ($response->successful()) {
                return true;
            }

            Log::error("Ошибка POST /email/extend на ноде {$baseUrl} (Email: {$config->email}): " . $response->body());
        } catch (\Exception $e) {
            Log::error("Сбой ноды {$baseUrl} при продлении: " . $e->getMessage());
        }

        return false;
    }
}