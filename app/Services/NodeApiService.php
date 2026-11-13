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
        $port = $node->port ?: 11223;
        $baseUrl = "https://{$node->ip}:{$port}";
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
     * Скачивание файла базы данных (.db) с ноды
     *
     * @param Node $node
     * @return array{filename: string, content: string, size: int}|null
     */
    public function downloadNodeBackup(Node $node): ?array
    {
        $port = $node->port ?: 11223;
        $baseUrl = "https://{$node->ip}:{$port}";
        $apiKey = $node->api_key ?? '';

        try {
            $response = Http::withHeaders([
                'X-API-KEY' => $apiKey,
            ])
                ->withoutVerifying()
                ->timeout(30)
                ->get("{$baseUrl}/backup");

            if ($response->successful()) {
                // Извлекаем оригинальное имя файла из заголовка Content-Disposition
                $disposition = $response->header('Content-Disposition');
                $filename = null;
                if ($disposition && preg_match('/filename="?([^";]+)"?/', $disposition, $matches)) {
                    $filename = trim($matches[1]);
                }

                if (!$filename) {
                    $cleanName = preg_replace('/[^a-zA-Z0-9_\-\.]/', '_', $node->name ?: $node->ip);
                    $filename = "apinode-backup-{$cleanName}-" . date('Ymd_His') . ".db";
                }

                $content = $response->body();

                return [
                    'filename' => $filename,
                    'content'  => $content,
                    'size'     => strlen($content),
                ];
            }

            Log::warning("Бэкап с ноды {$node->name} ({$node->ip}:{$port}) вернул HTTP {$response->status()}: " . $response->body());
        } catch (\Throwable $e) {
            Log::error("Ошибка скачивания бэкапа с ноды {$node->name} ({$node->ip}:{$port}): " . $e->getMessage());
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
     * Модернизация VLESS/xHTTP ссылки под TLS + CDN / Кастомную редакцию
     */
    public function modernizeLink(string $uri, bool $isTls, bool &$isModernized, ?array $overrides = null): string
    {
        $isModernized = false;

        $hasOverrides = !empty($overrides) && (
            !empty($overrides['enabled']) ||
            !empty($overrides['host']) ||
            !empty($overrides['port']) ||
            !empty($overrides['params'])
        );

        if ((!$isTls && !$hasOverrides) || empty($uri)) {
            return $uri;
        }

        $parsed = parse_url($uri);
        if (!$parsed || !isset($parsed['scheme'])) {
            return $uri;
        }

        $query = [];
        if (isset($parsed['query'])) {
            parse_str($parsed['query'], $query);
        }

        // Переопределение хоста подключения (например, CDN Edge или Cloudflare IP/домен)
        if (!empty($overrides['host'])) {
            $parsed['host'] = $overrides['host'];
        }

        // Переопределение порта
        if (!empty($overrides['port'])) {
            $parsed['port'] = (int)$overrides['port'];
        }

        // Переопределение любых произвольных query-параметров из массива params
        if (!empty($overrides['params']) && is_array($overrides['params'])) {
            foreach ($overrides['params'] as $key => $val) {
                if ($val === null || $val === '') {
                    unset($query[$key]);
                } else {
                    $query[$key] = is_array($val) ? json_encode($val, JSON_UNESCAPED_SLASHES) : $val;
                }
            }
        }

        // Поддержка стандартных плоских ключей
        foreach (['sni', 'path', 'type', 'security', 'fp', 'alpn', 'mode', 'extra'] as $stdKey) {
            if (isset($overrides[$stdKey]) && !isset($overrides['params'][$stdKey])) {
                $val = $overrides[$stdKey];
                $query[$stdKey] = is_array($val) ? json_encode($val, JSON_UNESCAPED_SLASHES) : $val;
            }
        }
        if (isset($overrides['header_host']) && !isset($overrides['params']['host'])) {
            $query['host'] = $overrides['header_host'];
        }

        // Если тип xhttp, обеспечиваем совместимость с CDN проксированием, если пользователь не переопределил их
        if (($query['type'] ?? '') === 'xhttp') {
            if (!isset($query['alpn']) && !isset($overrides['params']['alpn'])) {
                $query['alpn'] = $overrides['alpn'] ?? 'h3,h2,http/1.1';
            }
            if (!isset($query['fp']) && !isset($overrides['params']['fp'])) {
                $query['fp'] = $overrides['fp'] ?? 'randomized';
            }
            if (!isset($query['extra']) && !isset($overrides['params']['extra'])) {
                $query['extra'] = json_encode([
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
                ], JSON_UNESCAPED_SLASHES);
            }
        }

        // Признак TLS для VLESS
        if ($isTls && !isset($query['security']) && ($parsed['scheme'] ?? '') === 'vless') {
            $query['security'] = 'tls';
        }

        if (($parsed['scheme'] ?? '') === 'vless') {
            unset($query['spx'], $query['encryption']);
        }

        $isModernized = true;

        $user        = isset($parsed['user']) ? $parsed['user'] . '@' : '';
        $host        = $parsed['host'] ?? '';
        $portStr     = isset($parsed['port']) ? ':' . $parsed['port'] : '';
        $urlPath     = $parsed['path'] ?? '';
        $fragment    = isset($parsed['fragment']) ? '#' . $parsed['fragment'] : '';

        // Актуализируем имя во фрагменте (для клиентов v2rayNG, Sing-box и т.д.), если задан суффикс
        if (!empty($overrides['suffix'])) {
            $suffix = trim((string)$overrides['suffix']);
            $currentFrag = isset($parsed['fragment']) ? rawurldecode($parsed['fragment']) : '';
            if ($currentFrag !== '' && !str_contains($currentFrag, $suffix)) {
                $fragment = '#' . rawurlencode($currentFrag . ' ' . $suffix);
            } elseif ($currentFrag === '') {
                $fragment = '#' . rawurlencode($suffix);
            }
        }

        $queryString = !empty($query) ? '?' . http_build_query($query) : '';

        return "{$parsed['scheme']}://{$user}{$host}{$portStr}{$urlPath}{$queryString}{$fragment}";
    }
    /**
     * Продление клиента на ноде (3x-ui) или установка точной даты
     */
    public function extendClientOnNode(\App\Models\Config $config, int $days = 30, ?int $expiryTimeMs = null): bool
    {
        $ip = $config->node->ip ?? 'localhost';
        $baseUrl = "https://{$ip}:11223";
        $apiKey = $config->node->api_key ?? '';

        $payload = [
            'email' => $config->email,
            'days'  => $days,
        ];
        if ($expiryTimeMs !== null && $expiryTimeMs > 0) {
            $payload['expiry_time'] = $expiryTimeMs;
        }

        try {
            $response = Http::withHeaders([
                'X-API-KEY' => $apiKey
            ])
                ->withoutVerifying()
                ->timeout(5)
                ->post("{$baseUrl}/email/extend", $payload);

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
     * Установка точной даты окончания клиента на ноде (3x-ui)
     */
    public function setExpiryOnNode(\App\Models\Config $config, \Carbon\Carbon $expiresAt): bool
    {
        $expiryMs = (int) ($expiresAt->timestamp * 1000);
        $diffSeconds = $expiresAt->timestamp - now()->timestamp;
        $days = (int) max(1, round($diffSeconds / 86400));

        return $this->extendClientOnNode($config, $days, $expiryMs);
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