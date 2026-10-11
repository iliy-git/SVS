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
            // Если статистика конфига обновлялась менее 120 сек назад,
            // данные свежие — пропускаем опрос ноды, чтобы отдать подписку мгновенно
            if ($c->updated_at && $c->updated_at->diffInSeconds(now()) < 120) {
                return false;
            }
            return true;
        });

        if ($activeConfigs->isEmpty()) {
            return;
        }

        $configsByNode = $activeConfigs->groupBy('node_id');
        $fallbackConfigs = collect();

        foreach ($configsByNode as $nodeId => $nodeConfigs) {
            $node = $nodeConfigs->first()->node;
            if (!$node) {
                continue;
            }

            $nodeClients = $this->fetchNodeClients($node);

            if ($nodeClients !== null) {
                $clientsByEmail = collect($nodeClients)->keyBy('email');

                foreach ($nodeConfigs as $config) {
                    $clientData = $clientsByEmail->get($config->email);
                    if ($clientData) {
                        $updateData = [
                            'up'            => $clientData['up'] ?? $config->up,
                            'down'          => $clientData['down'] ?? $config->down,
                            'traffic_limit' => isset($clientData['total']) && $clientData['total'] > 0
                                ? ($clientData['total'] / (1024**3))
                                : $config->traffic_limit,
                            'expiry_time'   => $clientData['expiry_time'] ?? $config->expiry_time,
                        ];

                        if (!empty($clientData['link']) && !$config->is_modernized) {
                            $updateData['link'] = $clientData['link'];
                        }

                        $config->update($updateData);
                    }
                }
            } else {
                $fallbackConfigs = $fallbackConfigs->merge($nodeConfigs);
            }
        }

        if ($fallbackConfigs->isEmpty()) {
            return;
        }

        try {
            // Фолбэк: параллельный опрос поштучно для нод без поддержки /clients
            $responses = Http::pool(function (Pool $pool) use ($fallbackConfigs) {
                foreach ($fallbackConfigs as $config) {
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

            foreach ($fallbackConfigs as $config) {
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

                    $config->update($updateData);
                }
            }
        } catch (\Exception $e) {
            Log::error("Failed in fallback refreshAllConfigsStats: " . $e->getMessage());
        }
    }

    /**
     * Получение всех клиентов с ноды за один запрос (GET /clients)
     */
    public function fetchNodeClients(Node $node): ?array
    {
        $baseUrl = "https://{$node->ip}:11223";
        try {
            $response = Http::withHeaders([
                'X-API-KEY' => $node->api_key
            ])
                ->withoutVerifying()
                ->timeout(5)
                ->get("{$baseUrl}/clients");

            if ($response->successful() && is_array($response->json())) {
                return $response->json();
            }
        } catch (\Throwable $e) {
            Log::warning("Не удалось получить пакетный список клиентов с ноды {$baseUrl}: " . $e->getMessage());
        }

        return null;
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

    /**
     * Удаление клиента на ноде (3x-ui)
     */
    public function deleteClientOnNode(\App\Models\Config $config): bool
    {
        if (!$config->node || empty($config->email)) {
            return false;
        }

        $ip = $config->node->ip ?? 'localhost';
        $baseUrl = "https://{$ip}:11223";
        $apiKey = $config->node->api_key ?? '';

        try {
            $response = Http::withHeaders([
                'X-API-KEY'    => $apiKey,
                'Content-Type' => 'application/json',
            ])
                ->withoutVerifying()
                ->timeout(3.5)
                ->post("{$baseUrl}/client/delete", [
                    'email'      => $config->email,
                    'inbound_id' => (int)($config->inbound_id ?? 0),
                ]);

            if ($response->successful()) {
                Log::info("Клиент {$config->email} успешно удален на ноде {$baseUrl}");
                return true;
            }

            Log::warning("Ответ ноды {$baseUrl} при удалении клиента {$config->email}: " . $response->body());
        } catch (\Exception $e) {
            // Если эндпоинт на ноде еще не реализован или нода офлайн - не роняем удаление в панели, а логируем
            Log::info("Запрос удаления клиента на ноде {$baseUrl} пропущен или недоступен: " . $e->getMessage());
        }

        return false;
    }
}