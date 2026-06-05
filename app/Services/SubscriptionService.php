<?php

namespace App\Services;

use App\Models\Subscription;
use App\Models\Client;
use Illuminate\Support\Facades\Validator;
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
}
