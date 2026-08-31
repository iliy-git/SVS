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
        $client->subscriptions->makeHidden(['token']);
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
}
