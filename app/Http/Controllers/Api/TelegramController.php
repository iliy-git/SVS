<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Client;
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
}
