<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Tariff;
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
}
