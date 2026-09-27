<?php

namespace App\Services;

use App\Models\Subscription;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class HappApiService
{
    /**
     * Генерация защищенной ссылки (Happ) и обновление инсталляционного кода в БД
     */
    public function generateLink(Subscription $sub): string
    {
        $token = $sub->token;
        $limit = $sub->install_limit ?? 1;

        $providerCode = env('HAPP_PROVIDER_CODE');
        $authKey = env('HAPP_AUTH_KEY');

        // Базовая ссылка
        $url = route('subscription.raw', ['token' => $token]);

        try {
            // 1. Получаем код установки от Happ
            $installResponse = Http::timeout(5)->get('https://api.happ-proxy.com/api/add-install', [
                'provider_code' => $providerCode,
                'auth_key'      => $authKey,
                'install_limit' => $limit
            ]);

            if ($installResponse->ok() && $installResponse->json('rc') === 1) {
                $installCode = $installResponse->json('install_code');

                // Обновляем модель и создаем слот устройства
                $sub->update(['happ_install_code' => $installCode]);
                $sub->devices()->firstOrCreate(
                    ['happ_install_code' => $installCode],
                    ['device_id' => null]
                );

                // Добавляем InstallID к ссылке
                $url .= (str_contains($url, '?') ? '&' : '?') . "InstallID=" . $installCode;
            }

            // 2. Криптуем итоговую ссылку
            $cryptoResponse = Http::timeout(5)->post('https://crypto.happ.su/api-v2.php', [
                'url' => $url
            ]);

            if ($cryptoResponse->ok()) {
                return $cryptoResponse->json('encrypted_link') ?? $url;
            }

        } catch (\Exception $e) {
            Log::error("Happ API Error: " . $e->getMessage());
        }

        return $url;
    }
}