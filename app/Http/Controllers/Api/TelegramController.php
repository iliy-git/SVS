<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Subscription;
use App\Models\Tariff;
use App\Services\ClientService;
use App\Services\SubscriptionService;
use App\Services\TariffPurchaseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TelegramController extends Controller
{
    public function getSubscriptions(int $telegramId): JsonResponse
    {
        // Ищем клиента и сразу загружаем его подписки (и при необходимости конфиги)
        $client = Client::where('telegram_id', $telegramId)
            ->with(['subscriptions' => function ($query) {
                // Если нужно підгрузить конфиги каждой подписки:
                // $query->with('configs');
            }])
            ->first();

        if (!$client) {
            return response()->json([
                'success' => false,
                'message' => 'Клиент с таким Telegram ID не найден'
            ], 404);
        }
//        $client->subscriptions->makeHidden(['token']);
        return response()->json([
            'success' => true,
            'data' => [
                'client_id' => $client->id,
                'name' => $client->name,
                'telegram_id' => $client->telegram_id,
                'subscriptions' => $client->subscriptions
            ]
        ], 200);
    }

    /**
     * Создание нового клиента через API (с проверкой на дубликаты)
     */
    public function storeClient(Request $request, ClientService $service): JsonResponse
    {
        $data = $request->only(['name', 'phone', 'address', 'additional_info', 'telegram_id']);

        if (!empty($data['telegram_id'])) {
            $existingClient = \App\Models\Client::where('telegram_id', $data['telegram_id'])->first();
            
            if ($existingClient) {
                return response()->json([
                    'success' => true,
                    'status' => 'already_exists',
                    'client' => $existingClient
                ], 200);
            }
        }

        if (!empty($data['phone'])) {
            $existingClientByPhone = \App\Models\Client::where('phone', $data['phone'])->first();
            
            if ($existingClientByPhone) {
                return response()->json([
                    'success' => true,
                    'status' => 'already_exists',
                    'client' => $existingClientByPhone
                ], 200);
            }
        }

        try {
            $data['name'] = $data['name'] ?? 'Клиент из Telegram';
            
            $newClient = $service->createClient($data);

            return response()->json([
                'success' => true,
                'status' => 'created',
                'client' => $newClient
            ], 201);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка валидации',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Внутренняя ошибка сервера: ' . $e->getMessage()
            ], 500);
        }
    }
    /**
     * Возвращает список активных тарифов для Telegram бота.
     * Формат ответа подогнан под требования бота (fallback формат).
     */
    public function getTariffs(): JsonResponse
    {
        // Достаем активные тарифы, жадно грузим items, чтобы посчитать устройства/подписки
        $tariffs = Tariff::with('items')->where('is_active', true)->get();

        $formattedTariffs = $tariffs->map(function ($tariff) {
            // Считаем общее количество "подписок" или "устройств" в тарифе,
            // складывая device_limit у каждого элемента тарифа
            $totalDevices = $tariff->items->sum('device_limit');
            
            // Если в тарифе нет элементов (шаблонов), ставим 1 по умолчанию
            $totalDevices = $totalDevices > 0 ? $totalDevices : 1;

            return [
                'id' => $tariff->id, // Бот будет передавать этот ID при покупке
                'name' => $tariff->name,
                'subscriptions_count' => $totalDevices, // Для совместимости с ботом
                'price_rub' => (int) $tariff->price, // Цена в рублях
                
                // Переводим рубли в USDT (примерно, либо можно добавить поле price_usdt в БД)
                'price_usdt' => round($tariff->price / 100, 2), 
                
                'period_days' => $tariff->duration_days,
                
                // Бейдж берем из описания или генерируем
                'badge' => $tariff->description ?? '⚡ Стандарт', 
            ];
        });

        return response()->json([
            'success' => true,
            'data' => $formattedTariffs
        ]);
    }

    /**
     * Покупка тарифа через API бота и создание подписок
     */
    public function purchaseTariff(Request $request, TariffPurchaseService $tariffPurchaseService): JsonResponse
    {
        $validated = $request->validate([
            'telegram_id'         => 'required',
            'tariff_id'           => 'required',
            'subscriptions_count' => 'nullable|integer',
            'days'                => 'nullable|integer',
            'price'               => 'nullable|numeric',
        ]);

        $result = $tariffPurchaseService->purchase(
            telegramId: (string) $validated['telegram_id'],
            tariffId:   $validated['tariff_id'],
            subsCount:  (int) ($validated['subscriptions_count'] ?? 1),
            days:       isset($validated['days']) ? (int) $validated['days'] : null,
            price:      isset($validated['price']) ? (float) $validated['price'] : null,
        );

        if (!$result['success']) {
            return response()->json([
                'success' => false,
                'message' => $result['message'] ?? 'Не удалось создать подписки по тарифу',
                'errors'  => $result['errors'] ?? [],
            ], 422);
        }

        return response()->json([
            'success' => true,
            'data'    => [
                'client_id'     => $result['client']->id,
                'name'          => $result['client']->name,
                'telegram_id'   => $result['client']->telegram_id,
                'subscriptions' => $result['subscriptions'],
            ],
        ], 200);
    }
    /**
     * Продление подписки 
     */
    public function extend(Request $request, int $id, SubscriptionService $subscriptionService): JsonResponse
    {
        $validated = $request->validate([
            'telegram_id' => 'required',
            'days'        => 'nullable|integer',
        ]);

        $telegramId = (string) $validated['telegram_id'];
        $days = (int) ($validated['days'] ?? 30);

        // 1. Проверяем, что клиент существует и подписка принадлежит именно ему
        $client = Client::where('telegram_id', $telegramId)->first();
        if (!$client || !$client->subscriptions()->where('subscriptions.id', $id)->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'Подписка не найдена или не принадлежит данному клиенту'
            ], 403);
        }

        // 2. Вызываем SubscriptionService для продления
        $success = $subscriptionService->extendSubscription($id, $days);

        if ($success) {
            $subscription = Subscription::find($id);

            return response()->json([
                'success' => true,
                'message' => "Подписка успешно продлена на {$days} дн.",
                'data' => [
                    'subscription_id' => $subscription->id,
                    'expires_at'      => $subscription->expires_at?->toIso8601String(),
                ]
            ], 200);
        }

        return response()->json([
            'success' => false,
            'message' => 'Не удалось продлить конфиги на нодах'
        ], 500);
    }
}
