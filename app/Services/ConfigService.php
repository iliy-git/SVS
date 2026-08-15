<?php

namespace App\Services;

use App\Models\Config;
use App\Models\Node;
use App\Models\Subscription;
use App\Models\Flag;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use Illuminate\Database\Eloquent\Collection;

/**
 * Service: ConfigService
 *
 * Логика управления конфигурациями серверов (VLESS/Shadowsocks и др.)
 */
class ConfigService
{
    /**
     * Правила валидации
     */
    protected function rules(?int $id = null): array
    {
        return [
            'name'          => 'required|min:2|max:255',
            'link'          => 'required|string',
            'flag_id'       => 'required|exists:flags,id',
            'traffic_limit' => 'nullable|integer|min:0',
            // Технические поля для Livewire
            'clientId'      => 'nullable',
            'subId'         => 'nullable',
            'configId'      => 'nullable',
            'is_modernized' => 'nullable|boolean',
            'node_id'       => 'nullable',
        ];
    }

    /**
     * Валидация данных
     */
    public function validate(array $data, ?int $id = null): array
    {
        return Validator::make($data, $this->rules($id))->validate();
    }

    /**
     * Поиск конфига
     */
    public function findById(int $id): Config
    {
        return Config::findOrFail($id);
    }

    /**
     * Получение всех флагов для выпадающих списков
     */
    public function getAllFlags(): Collection
    {
        return Flag::all();
    }

    /**
     * Загрузка подписки со списком конфигов (для Index)
     */
    public function getSubscriptionWithConfigs(int $subId): Subscription
    {
        return Subscription::with(['configs' => function($query) {
            $query->orderByDesc('is_active')
                ->orderByDesc('is_main');
        }, 'configs.flag'])->findOrFail($subId);
    }

    /**
     * Подготовка данных для формы редактирования (fill)
     */
    public function getFormData(int $id): array
    {
        $config = $this->findById($id);

        return [
            'configId'      => $config->id,
            'node_id'       => $config->node_id,
            'name'          => $config->name,
            'link'          => $config->link,
            'flag_id'       => $config->flag_id,
            'traffic_limit' => $config->traffic_limit ?? 0,
            'is_modernized' => (bool) $config->is_modernized,
        ];
    }

    /**
     * Создание конфига и привязка к подписке
     */
    public function createForSubscription(int $subId, array $data): Config
    {
        $config = Config::create([
            'name'          => $data['name'],
            'link'          => $data['link'],
            'flag_id'       => $data['flag_id'],
            'traffic_limit' => $data['traffic_limit'] ?? 0,
            'is_active'     => true,
            'is_modernized' => $data['is_modernized'] ?? 0,
        ]);

        Subscription::findOrFail($subId)->configs()->attach($config->id);

        return $config;
    }

    /**
     * Обновление конфига
     */
    public function updateConfig(int $id, array $data): Config
    {
        $config = $this->findById($id);

        $config->update([
            'name'          => $data['name'],
            'link'          => $data['link'],
            'flag_id'       => $data['flag_id'],
            'traffic_limit' => $data['traffic_limit'] ?? 0,
            'is_modernized' => $data['is_modernized'] ?? 0,
        ]);

        return $config;
    }

    /**
     * Переключение активности
     */
    public function toggleActive(int $id): bool
    {
        $config = $this->findById($id);
        return $config->update(['is_active' => !$config->is_active]);
    }

    /**
     * Установка основного конфига (только один может быть is_main)
     */
    public function setMain(int $subId, int $configId): void
    {
        $subscription = Subscription::with('configs')->findOrFail($subId);

        foreach ($subscription->configs as $config) {
            if ($config->id == $configId) {
                // Инвертируем текущий выбор
                $config->update(['is_main' => !$config->is_main]);
            } else {
                // Все остальные сбрасываем в false
                if ($config->is_main) {
                    $config->update(['is_main' => false]);
                }
            }
        }
    }

    /**
     * Удаление конфига
     */
    public function deleteConfig(int $id): ?bool
    {
        return $this->findById($id)->delete();
    }

    /**
     * Получение конфигов с удаленной ноды
     *
     * @param Node $node
     * @return array
     */
    public function fetchRemoteConfigs(Node $node): array
    {
        $response = Http::withHeaders(['X-API-KEY' => $node->api_key])
            ->timeout(5)
            ->withoutVerifying()
            ->get("https://{$node->ip}:11223/");

        if (!$response->ok()) {
            throw new \Exception("Ошибка сервера ноды: " . $response->status());
        }

        return $response->json();
    }

    /**
     * Создание конфига из удаленных данных и привязка к подписке
     *
     * @param int $subId
     * @param Node $node
     * @param string $link
     * @param array $allRemoteData
     * @return Config
     */
    public function importFromNode(int $subId, Node $node, string $link, array $allRemoteData): Config
    {
        $remoteItem = collect($allRemoteData)->firstWhere('link', $link);

        // Транзакция не обязательна, но для надежности при attach можно использовать
        return DB::transaction(function () use ($subId, $node, $link, $remoteItem) {
            $config = Config::create([
                'node_id'         => $node->id,
                'name'            => $remoteItem['email'] ?? 'Imported',
                'email'           => $remoteItem['email'] ?? null,
                'link'            => $link,
                'traffic_limit'   => $remoteItem['stats']['total'] ?? 0,
                'up'              => $remoteItem['stats']['up'] ?? 0,
                'down'            => $remoteItem['stats']['down'] ?? 0,
                'expiry_time'     => $remoteItem['stats']['expiry'] ?? null,
                'flag_id'         => $node->flag_id,
                'is_modernized' => false,
            ]);

            Subscription::findOrFail($subId)->configs()->attach($config->id);

            return $config;
        });
    }
}
