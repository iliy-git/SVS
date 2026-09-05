<?php

namespace App\Services;

use App\Models\Subscription;
use App\Models\Client;
use App\Models\SubscriptionTemplate;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use App\Http\Controllers\SubscriptionController;

/**
 * Service: SubscriptionService
 *
 * Бизнес-логика управления подписками.
 */
class SubscriptionService
{
    /**
     * Конфигурация правил валидации
     *
     * @param int|null $id ID для исключения при проверке unique
     * @return array
     */
    protected function rules(?int $id = null): array
    {
        return [
            'name'          => 'required|min:3|max:255',
            'token'         => [
                'required',
                'string',
                Rule::unique('subscriptions', 'token')->ignore($id),
            ],
            'with_balancer' => 'boolean',
            'expires_at'    => 'nullable|date',
            'clientId'      => 'nullable',
            'subId'         => 'nullable',
        ];
    }

    /**
     * Валидация входных данных
     *
     * @param array $data Данные из формы/компонента
     * @param int|null $id
     * @return array
     */
    public function validate(array $data, ?int $id = null): array
    {
        return Validator::make($data, $this->rules($id))->validate();
    }

    /**
     * Поиск записи в БД
     *
     * @param int $id
     * @return Subscription
     */
    public function findById(int $id): Subscription
    {
        return Subscription::findOrFail($id);
    }

    /**
     * Создание подписки для конкретного клиента
     *
     * @param int $clientId
     * @param array $data
     * @return Subscription
     */
    public function createForClient(int $clientId, array $data): Subscription
    {
        $subscription = Subscription::create([
            'name'          => $data['name'],
            'token'         => $data['token'],
            'with_balancer' => $data['with_balancer'] ?? true,
            'expires_at'    => $data['expires_at'] ?: null,
        ]);

        $happUrl = (new SubscriptionController())->getHappLink($subscription->token);
        $subscription->update(['happ_url' => $happUrl]);

        $client = Client::findOrFail($clientId);
        $client->subscriptions()->attach($subscription->id);

        return $subscription;
    }

    /**
     * Обновление существующей подписки
     *
     * @param int $id
     * @param array $data
     * @return Subscription
     */
    public function updateSubscription(int $id, array $data): Subscription
    {
        $subscription = $this->findById($id);
        $oldToken = $subscription->token;

        $subscription->update([
            'name'          => $data['name'],
            'token'         => $data['token'],
            'with_balancer' => $data['with_balancer'] ?? true,
            'expires_at'    => $data['expires_at'] ?: null,
        ]);

        // Пересоздаем ссылку, если токен изменился
        if ($oldToken !== $data['token']) {
            $happUrl = (new SubscriptionController())->getHappLink($subscription->token);
            $subscription->update(['happ_url' => $happUrl]);
        }

        return $subscription;
    }

    /**
     * Формирование данных для заполнения формы редактирования
     *
     * @param int $id
     * @return array
     */
    public function getFormData(int $id): array
    {
        $sub = $this->findById($id);

        return [
            'subId'         => $sub->id,
            'name'          => $sub->name,
            'token'         => $sub->token,
            'with_balancer' => (bool)$sub->with_balancer,
            'expires_at'    => $sub->expires_at ? $sub->expires_at->format('Y-m-d') : '',
        ];
    }

    /**
     * Получение или генерация ссылки подписки
     *
     * @param int $id
     * @return string
     */
    public function getHappUrl(int $id): string
    {
        $sub = $this->findById($id);

        if (!empty($sub->happ_url)) {
            return $sub->happ_url;
        }

        $happUrl = (new SubscriptionController())->getHappLink($sub->token);
        $sub->update(['happ_url' => $happUrl]);

        return $happUrl;
    }
    /**
     * Удаление подписки
     *
     * @param int $id
     * @return bool|null
     */
    public function deleteSubscription(int $id): ?bool
    {
        $subscription = $this->findById($id);


        return $subscription->delete();
    }

    /**
     * Сброс привязки устройства (device_id)
     *
     * @param int $id
     * @return bool
     */
    public function resetDevice(int $id): bool
    {
        $subscription = $this->findById($id);

        return $subscription->update([
            'device_id' => null
        ]);
    }

    /**
     * Продление всех активных конфигов подписки
     *
     * @param int $id
     * @param int $days Количество дней для продления
     * @return bool
     */
    public function extendSubscription(int $id, int $days = 30): bool
    {
        $subscription = Subscription::with(['configs.node'])->findOrFail($id);

        if ($subscription->configs->isEmpty()) {
            return false;
        }

        $nowMs = now()->timestamp * 1000;
        $updatedConfigs = 0;

        foreach ($subscription->configs as $config) {
            if (!$config->node || empty($config->email)) continue;

            $currentExpiry = (int)$config->expiry_time;
            $baseTime = ($currentExpiry > $nowMs) ? $currentExpiry : $nowMs;

            // Динамический расчет времени: $days * 24 часа * 60 мин * 60 сек * 1000 мс
            $newExpiryMs = $baseTime + ($days * 24 * 60 * 60 * 1000);

            try {
                $response = \Illuminate\Support\Facades\Http::withHeaders([
                    'X-API-KEY' => $config->node->api_key
                ])
                    ->withoutVerifying()
                    ->timeout(5)
                    ->post("https://{$config->node->ip}:11223/email/extend", [
                        'email' => $config->email,
                        'days'  => $days // Передаем количество дней в наш API
                    ]);

                if ($response->successful()) {
                    $config->update(['expiry_time' => $newExpiryMs, 'is_active' => true]);
                    $updatedConfigs++;
                }
            } catch (\Exception $e) {
                \Log::error("Сбой ноды {$config->node->ip}: " . $e->getMessage());
            }
        }

        if ($updatedConfigs > 0) {
            $subscription->update(['expires_at' => \Carbon\Carbon::now()->addDays($days)]);
            return true;
        }
        return false;
    }
    /**
     * Создание подписки по шаблону с созданием конфигов на нодах
     */
    public function createFromTemplate(int $clientId, int $templateId): ?Subscription
    {
        $template = SubscriptionTemplate::with('inbounds.node')->find($templateId);

        if (!$template) {
            return null;
        }

        return DB::transaction(function () use ($clientId, $template) {
            $subscriptionToken = Str::random(32);
            $expiresAt = now()->addDays(30);

            // 1. Создаем подписку
            $subscription = Subscription::create([
                'name'          => $template->name,
                'token'         => $subscriptionToken,
                'expires_at'    => $expiresAt,
                'with_balancer' => 1,
                'install_limit' => 1,
                'is_active'     => true,
            ]);

            // 2. Привязываем подписку к клиенту
            DB::table('client_subscription')->insert([
                'client_id'       => $clientId,
                'subscription_id' => $subscription->id,
            ]);

            // 3. Создаем конфиги для каждого инбаунда из шаблона
            foreach ($template->inbounds as $templateInbound) {
                $node = $templateInbound->node;

                if (!$node) {
                    continue;
                }

                $randomEmail = 'usr_' . Str::lower(Str::random(10)) . '@generated.local';
                $configName  = ($node->name ?? 'Node ' . $node->id) . ' (' . ($templateInbound->remark ?? 'Inbound #' . $templateInbound->inbound_id) . ')';

                // Вызываем метод с передачей лимита трафика из связи
                $nodeResult = $this->createClientOnNode($node, [
                    'inbound_id' => $templateInbound->inbound_id,
                    'email'      => $randomEmail,
                    'totalGB'    => (int)($templateInbound->traffic_limit_gb ?? 0), // Лимит из template_inbounds
                    'days'       => 30,
                ]);

                $rawLink = $nodeResult['link'] ?? '';
                $isModernized = false;

                // Если в связи указан TLS — модернизируем ссылку
                $finalLink = $this->modernizeLink($rawLink, (bool)($templateInbound->is_tls ?? false), $isModernized);

                // Создаем конфиг
                $config = $subscription->configs()->create([
                    'node_id'       => $node->id,
                    'inbound_id'    => $templateInbound->inbound_id,
                    'name'          => $configName,
                    'email'         => $randomEmail,
                    'link'          => $finalLink,
                    'is_modernized' => $isModernized,
                    'priority'      => $templateInbound->priority ?? 0,
                    'is_active'     => true,
                    'flag_id'       => $node->flag->id ?? null,
                ]);

                $subscription->configs()->syncWithoutDetaching([$config->id]);
            }

            return $subscription;
        });
    }

    /**
     * Модернизация VLESS/xHTTP ссылки под TLS + CDN
     */
    private function modernizeLink(string $uri, bool $isTls, bool &$isModernized): string
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

        // 1. Настройки CDN и хостов
        $cdnHost    = 'pt0pegkjoi.cdn.twcstorage.ru';
        $headerHost = 'cdn.komap.pw';

        // 2. Основной адрес и порт
        $parsed['host'] = $cdnHost;
        $parsed['port'] = 443;

        // 3. Хардкодим полный набор параметров xHTTP + TLS
        $query['type']     = 'xhttp';
        $query['security'] = 'tls';
        $query['sni']      = $cdnHost;
        $query['fp']       = 'randomized';
        $query['alpn']     = 'h3,h2,http/1.1';
        $query['host']     = $headerHost;
        $query['path']     = '/assets/vendor.js';
        $query['mode']     = 'packet-up';

        // 4. Запекаем тот самый 'extra' JSON
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

        // Чистим мусор от 3x-ui
        unset($query['spx'], $query['encryption']);

        $isModernized = true;

        // 5. Собираем итоговую ссылку
        $user     = isset($parsed['user']) ? $parsed['user'] . '@' : '';
        $host     = $parsed['host'];
        $port     = ':' . $parsed['port'];
        $path     = $parsed['path'] ?? '';
        $fragment = '#xHTTP-PacketUp';

        $queryString = '?' . http_build_query($query);

        return "vless://{$user}{$host}{$port}{$path}{$queryString}{$fragment}";
    }

    /**
     * Создание клиента на ноде через API
     */
    private function createClientOnNode($node, array $params): array
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
                ->timeout(10)
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

            sleep(2);

            return $this->fetchLinkByEmail($baseUrl, $apiKey, $params['email']);

        } catch (\Exception $e) {
            Log::error("Исключение при запросе к ноде {$baseUrl} (Email: {$params['email']}): " . $e->getMessage());
        }

        return ['link' => ''];
    }

    /**
     * Запрос ссылки с ноды по email
     */
    private function fetchLinkByEmail(string $baseUrl, string $apiKey, string $email): array
    {
        try {
            $response = Http::withHeaders([
                'X-API-KEY' => $apiKey,
            ])
                ->withoutVerifying()
                ->timeout(5)
                ->get("{$baseUrl}/email", [
                    'email' => $email,
                ]);

            if ($response->successful()) {
                $data = $response->json();
                return [
                    'link' => $data['link'] ?? '',
                ];
            } else {
                Log::error("Ошибка GET /email для {$email} на ноде {$baseUrl}: " . $response->body());
            }
        } catch (\Exception $e) {
            Log::error("Исключение при GET /email для {$email} на ноде {$baseUrl}: " . $e->getMessage());
        }

        return ['link' => ''];
    }
}
