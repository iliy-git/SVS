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
    protected NodeApiService $nodeApi;
    protected HappApiService $happApi;

    // Внедряем NodeApiService через конструктор
    public function __construct(NodeApiService $nodeApi, HappApiService $happApi)
    {
        $this->nodeApi = $nodeApi;
        $this->happApi = $happApi;
    }
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
            'install_limit' => 'required|integer|min:1|max:100',
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
            'install_limit' => $data['install_limit'] ?? 1,
        ]);

        $happUrl = $this->happApi->generateLink($subscription);
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
            'install_limit' => $data['install_limit'] ?? 1,
        ]);

        // Пересоздаем ссылку, если токен изменился
        if ($oldToken !== $data['token']) {
            $happUrl = $this->happApi->generateLink($subscription);
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
            'install_limit' => (int)($sub->install_limit ?? 1),
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

        $happUrl = $this->happApi->generateLink($sub);
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
        $subscription->devices()->delete();

        return $subscription->update([
            'device_id' => null,
            'happ_install_code' => null,
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

            // === ВЫЗЫВАЕМ ВНЕШНИЙ СЕРВИС ===
            $isExtended = $this->nodeApi->extendClientOnNode($config, $days);

            if ($isExtended) {
                $config->update(['expiry_time' => $newExpiryMs, 'is_active' => true]);
                $updatedConfigs++;
            }
        }

        if ($updatedConfigs > 0) {
            $subscription->update(['expires_at' => \Carbon\Carbon::now()->addDays($days)]);
            return true;
        }
        return false;
    }
    /**
     * Формирует читаемое название конфигурации.
     */
    protected function resolveConfigName($node, $templateInbound): string
    {
        $baseName = $node->name ?? ('Node ' . $node->id);
        $isBypass = !empty($templateInbound->is_main) || !empty($templateInbound->is_tls);

        return $isBypass ? "{$baseName} (Обход БС)" : $baseName;
    }

    /**
     * Создание подписки по шаблону с созданием конфигов на нодах.
     */
    public function createFromTemplate(int $clientId, int $templateId, int $installLimit = 1): ?Subscription
    {
        $template = SubscriptionTemplate::with(['inbounds.node.flag'])->find($templateId);

        if (!$template) {
            return null;
        }

        return DB::transaction(function () use ($clientId, $template, $installLimit) {
            // 1. Создаем подписку
            $subscription = Subscription::create([
                'template_id'   => $template->id,
                'name'          => $template->name,
                'token'         => Str::random(32),
                'expires_at'    => now()->addDays(30),
                'with_balancer' => 1,
                'install_limit' => $installLimit,
                'is_active'     => true,
            ]);

            // 2. Привязываем подписку к клиенту
            DB::table('client_subscription')->insert([
                'client_id'       => $clientId,
                'subscription_id' => $subscription->id,
            ]);

            // 3. Создаем конфиги для каждого инбаунда шаблона
            foreach ($template->inbounds as $templateInbound) {
                $node = $templateInbound->node;
                if (!$node) {
                    continue;
                }

                $randomEmail = 'usr_' . Str::lower(Str::random(10)) . '@generated.local';
                $configName  = $this->resolveConfigName($node, $templateInbound);
                $isMain      = !empty($templateInbound->is_tls) || !empty($templateInbound->is_main);

                $nodeResult = $this->nodeApi->createClientOnNode($node, [
                        'inbound_id' => $templateInbound->inbound_id,
                        'email'      => $randomEmail,
                        'totalGB'    => (int)($templateInbound->traffic_limit_gb ?? 0),
                        'days'       => 30,
                ]);

                $rawLink      = $nodeResult['link'] ?? '';
                $isModernized = false;
                $finalLink = $this->nodeApi->modernizeLink($rawLink, (bool)($templateInbound->is_tls ?? false), $isModernized);

                $config = $subscription->configs()->create([
                    'node_id'       => $node->id,
                    'inbound_id'    => $templateInbound->inbound_id,
                    'name'          => $configName,
                    'email'         => $randomEmail,
                    'link'          => $finalLink,
                    'is_main'       => $isMain,
                    'is_modernized' => $isModernized,
                    'priority'      => $templateInbound->priority ?? 0,
                    'traffic_limit' => $templateInbound->traffic_limit_gb ?? 0,
                    'is_active'     => true,
                    'flag_id'       => $node->flag->id ?? null,
                ]);

                $subscription->configs()->syncWithoutDetaching([$config->id]);
            }

            return $subscription;
        });
    }
    /**
     * Привязываеи шаблон к подписке полностью удаляет сущесвующие конфиги
     * и генерирует их заново по выбранному шаблону
     */
    public function attachAndSyncTemplate(Subscription $subscription, int $templateId): bool
    {
        $template = SubscriptionTemplate::with(['inbounds.node.flag'])->find($templateId);
        if (!$template || !$template->is_active) {
            return false;
        }
        return DB::transaction(function() use ($subscription, $templateId) {
            $subscription->update([
                'template_id' => $templateId,
                'is_active' => true,
            ]);

            $subscription->loadMissing(['configs.node']);
            foreach ($subscription->configs as $config) {
                $subscription->configs()->detach($config->id);
                $config->delete();
            }

            $subscription->unsetRelation('configs');

            return $this->syncWithTemplate($subscription);

        });
    }


    /**
     * Just-in-Time (On-Demand) синхронизация подписки с актуальным состоянием шаблона.
     * Вызывается при обращении клиента за подпиской.
     */
    public function syncWithTemplate(Subscription $subscription): bool
    {
        if (empty($subscription->template_id)) {
            return false;
        }

        $template = SubscriptionTemplate::with(['inbounds.node.flag'])->find($subscription->template_id);
        if (!$template || !$template->is_active) {
            return false;
        }

        $subscription->loadMissing(['configs.node', 'configs.flag']);
        $existingConfigs  = $subscription->configs;
        $templateInbounds = $template->inbounds;

        // 1. Поиск инбаундов для добавления
        $inboundsToAdd = $templateInbounds->filter(function ($ti) use ($existingConfigs) {
            return !$existingConfigs->contains(function ($c) use ($ti) {
                return (int)$c->node_id === (int)$ti->node_id && (int)$c->inbound_id === (int)$ti->inbound_id;
            });
        });

        // 2. Поиск конфигов для удаления (уже отсутствуют в шаблоне)
        $configsToRemove = $existingConfigs->filter(function ($c) use ($templateInbounds) {
            if ($c->inbound_id === null) {
                return false;
            }

            return !$templateInbounds->contains(function ($ti) use ($c) {
                return (int)$ti->node_id === (int)$c->node_id && (int)$ti->inbound_id === (int)$c->inbound_id;
            });
        });

        // 3. Поиск конфигов с изменившимися параметрами
        $configsToUpdate = [];
        foreach ($templateInbounds as $ti) {
            $matchingConfig = $existingConfigs->first(function ($c) use ($ti) {
                return (int)$c->node_id === (int)$ti->node_id && (int)$c->inbound_id === (int)$ti->inbound_id;
            });

            if ($matchingConfig) {
                $expectedName        = $this->resolveConfigName($ti->node, $ti);
                $expectedIsMain      = !empty($ti->is_tls) || !empty($ti->is_main);

                $needsNameUpdate     = $matchingConfig->name !== $expectedName;
                $needsMainUpdate     = (bool)$matchingConfig->is_main !== $expectedIsMain;
                $needsTlsUpdate      = (bool)$matchingConfig->is_modernized !== (bool)$ti->is_tls;
                $needsLimitUpdate    = (int)($matchingConfig->traffic_limit ?? 0) !== (int)($ti->traffic_limit_gb ?? 0);
                $needsPriorityUpdate = (int)($matchingConfig->priority ?? 0) !== (int)($ti->priority ?? 0);

                if ($needsNameUpdate || $needsMainUpdate || $needsTlsUpdate || $needsLimitUpdate || $needsPriorityUpdate) {
                    $configsToUpdate[] = [
                        'config'        => $matchingConfig,
                        'inbound'       => $ti,
                        'expected_name' => $expectedName,
                        'expected_main' => $expectedIsMain,
                        'needs_tls'     => $needsTlsUpdate,
                    ];
                }
            }
        }

        // Быстрый выход, если расхождений нет
        if ($inboundsToAdd->isEmpty() && $configsToRemove->isEmpty() && empty($configsToUpdate)) {
            return false;
        }

        $hasChanges = false;

        // Применяем удаление
        foreach ($configsToRemove as $c) {
            $subscription->configs()->detach($c->id);
            $c->delete();
            $hasChanges = true;
        }

        // Применяем обновление
        foreach ($configsToUpdate as $item) {
            /** @var \App\Models\Config $config */
            $config  = $item['config'];
            /** @var \App\Models\TemplateInbound $inbound */
            $inbound = $item['inbound'];

            $updateData = [
                'name'          => $item['expected_name'],
                'is_main'       => $item['expected_main'],
                'priority'      => $inbound->priority ?? 0,
                'traffic_limit' => $inbound->traffic_limit_gb ?? 0,
            ];

            if ($item['needs_tls']) {
                $rawLink = '';
                if ($config->node && !empty($config->email)) {
                    $baseUrl  = "https://{$config->node->ip}:11223";
                    $apiKey   = $config->node->api_key ?? '';
                    $linkData = $this->nodeApi->fetchLinkByEmail($baseUrl, $apiKey, $config->email);
                    $rawLink  = $linkData['link'] ?? '';
                }

                if (empty($rawLink)) {
                    $rawLink = $config->link;
                }

                $isModernized = false;
                $updateData['link'] = $this->nodeApi->modernizeLink($rawLink, (bool)$inbound->is_tls, $isModernized);
                $updateData['is_modernized'] = $isModernized;
            }

            $config->update($updateData);
            $hasChanges = true;
        }

        // Применяем добавление новых
        foreach ($inboundsToAdd as $templateInbound) {
            $node = $templateInbound->node;
            if (!$node) {
                continue;
            }

            try {
                $randomEmail = 'usr_' . Str::lower(Str::random(10)) . '@generated.local';
                $configName  = $this->resolveConfigName($node, $templateInbound);
                $isMain      = !empty($templateInbound->is_tls) || !empty($templateInbound->is_main);

                $nodeResult = $this->nodeApi->createClientOnNode($node, [
                    'inbound_id' => $templateInbound->inbound_id,
                    'email'      => $randomEmail,
                    'totalGB'    => (int)($templateInbound->traffic_limit_gb ?? 0),
                    'days'       => 30,
                ]);

                $rawLink = $nodeResult['link'] ?? '';
                if (empty($rawLink)) {
                    Log::warning("Не удалось получить ссылку для ноды #{$node->id} при JIT-синхронизации подписки #{$subscription->id}");
                    continue;
                }

                $isModernized = false;

                $finalLink    = $this->nodeApi->modernizeLink($rawLink, (bool)($templateInbound->is_tls ?? false), $isModernized);

                $config = $subscription->configs()->create([
                    'node_id'       => $node->id,
                    'inbound_id'    => $templateInbound->inbound_id,
                    'name'          => $configName,
                    'email'         => $randomEmail,
                    'link'          => $finalLink,
                    'is_main'       => $isMain,
                    'is_modernized' => $isModernized,
                    'priority'      => $templateInbound->priority ?? 0,
                    'traffic_limit' => $templateInbound->traffic_limit_gb ?? 0,
                    'is_active'     => true,
                    'flag_id'       => $node->flag->id ?? null,
                ]);

                $subscription->configs()->syncWithoutDetaching([$config->id]);
                $hasChanges = true;
            } catch (\Exception $e) {
                Log::error("Ошибка при JIT-синхронизации ноды #{$node->id} для подписки #{$subscription->id}: " . $e->getMessage());
            }
        }

        return $hasChanges;
    }
}
