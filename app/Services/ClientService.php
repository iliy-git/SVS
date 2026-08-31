<?php

namespace App\Services;

use App\Models\Client;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class ClientService
{
    /**
     * Получить список клиентов с фильтрацией и подсчетом подписок
     */
    public function getClientsForIndex(string $search = ''): Collection
    {
        return Client::withCount('subscriptions')
            ->with('subscriptions.configs')
            ->search($search)
            ->latest()
            ->get();
    }

    /**
     * Найти клиента по ID
     */
    public function findById(int $id): Client
    {
        return Client::findOrFail($id);
    }

    /**
     * Создать нового клиента
     */
    public function createClient(array $data): Client
    {
        $validated = $this->validate($data);

        return Client::create($validated);
    }

    /**
     * Обновить данные клиента
     */
    public function updateClient(int $id, array $data): bool
    {
        $validated = $this->validate($data, $id);
        $client = Client::findOrFail($id);

        return $client->update($validated);
    }

    /**
     * Удалить клиента (с транзакцией, если нужно удалять связанные данные)
     */
    public function deleteClient(int $id): void
    {
        DB::transaction(function () use ($id) {
            $client = Client::findOrFail($id);

            // Если нужно сначала отвязать или удалить подписки:
            // $client->subscriptions()->detach();

            $client->delete();
        });
    }
    /**
     * Правила валидации для клиента
     */
    protected function rules(int $id = null): array
    {
        return [
            'name'    => 'required|min:2|max:255',
            'phone'   => 'nullable|string|max:20',
            'address' => 'nullable|string|max:500',
            'additional_info' => 'nullable|string',
            'telegram_id' => 'nullable|string|max:255',
        ];
    }

    public function validate(array $data, int $id = null): array
    {
        return Validator::make($data, $this->rules($id))->validate();
    }

    /**
     * Найти клиента вместе с его подписками
     */
    public function findWithSubscriptions(int $id): Client
    {
        return Client::query()
            ->with('subscriptions')
            ->findOrFail($id);
    }
    /**
     * Найти клиента по ID конкретной подписки
     * * @param int $subscriptionId
     * @return Client
     */
    public function findBySubscriptionId(int $subscriptionId): Client
    {
        return Client::whereHas('subscriptions', function ($query) use ($subscriptionId) {
            $query->where('subscriptions.id', $subscriptionId);
        })
            ->with(['subscriptions' => function ($query) use ($subscriptionId) {
                // Подгружаем ТОЛЬКО ту подписку, которую искали, вместе с её конфигами
                $query->where('id', $subscriptionId)->with('configs');
            }])
            ->firstOrFail();
    }
}
