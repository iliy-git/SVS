<?php

namespace App\Http\Controllers;

use App\Models\Subscription;
use Illuminate\Http\JsonResponse;
use App\Models\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use App\Services\XrayProfileBuilder;
use App\Services\NodeApiService;

class SubscriptionController extends Controller
{
    public function show(string $token, XrayProfileBuilder $profileBuilder, NodeApiService $nodeApi): JsonResponse
    {
        $sub = Subscription::where('token', $token)->firstOrFail();

        // 1. JIT Синхронизация структуры шаблона (добавление/удаление серверов при смене шаблона)
        app(\App\Services\SubscriptionService::class)->syncWithTemplate($sub);
        
        $sub->load(['configs' => function($query) {
            $query->where('is_active', true);
        }, 'configs.flag', 'configs.node.flag']);

        // 2. Актуализация даты окончания в памяти/БД (без внешних сетевых вызовов)
        $this->syncExpiryDate($sub);

        // 2. СЦЕНАРИЙ А: Подписка истекла?
        if ($sub->expires_at && $sub->expires_at->isPast()) {
            $profile = $profileBuilder->buildExpiredProfile($sub);
            return response()->json($profile['nodes'], 200, $profile['headers'], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        }

        // 3. СЦЕНАРИЙ Б: Превышен лимит устройств?
        if ($this->isDeviceBlocked($sub)) {
            $profile = $profileBuilder->buildLimitExceededProfile($sub);
            return response()->json($profile['nodes'], 200, $profile['headers'], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        }

        // 4. СЦЕНАРИЙ В: Классическая выдача
        $profile = $profileBuilder->buildSuccessProfile($sub);
        
        return response()->json($profile['nodes'], 200, $profile['headers'], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Синхронизация даты истечения
     */
    private function syncExpiryDate(Subscription $sub): void
    {
        // 1. Ручные подписки без шаблона НЕ ТРОГАЕМ: 
        // их срок задан админом вручную и не должен ломаться из-за общих нод
        if (empty($sub->template_id)) {
            return;
        }
        // 2. Для шаблонных подписок берем ТОЛЬКО конфиги этого шаблона (inbound_id != null)
        // и только с живых, активных нод
        $templateConfigs = $sub->configs->filter(function ($c) {
            return $c->inbound_id !== null
                && $c->is_active 
                && $c->node 
                && $c->node->is_active 
                && (int)$c->expiry_time > 0;
        });
        if ($templateConfigs->isEmpty()) {
            return;
        }
        // 3. Берем минимальную дату среди живых нод шаблона (самый ранний срок среди персональных конфигов)
        $minExpirySeconds = (int)($templateConfigs->pluck('expiry_time')->min() / 1000);
        if ($minExpirySeconds > 0) {
            // Обновляем, только если расхождение больше минуты (защита от лишних запросов в БД)
            if (!$sub->expires_at || abs($sub->expires_at->timestamp - $minExpirySeconds) > 60) {
                $sub->update([
                    'expires_at' => \Carbon\Carbon::createFromTimestamp($minExpirySeconds)
                ]);
            }
        }
    }

    /**
     * Проверяет, заблокировано ли устройство
     * Возвращает TRUE, если доступ ЗАПРЕЩЕН
     */
    private function isDeviceBlocked(Subscription $sub): bool
    {
        $deviceId = request()->header('x-hwid') ?? request()->query('InstallID');
        
        if (!$deviceId) {
            $userAgent = request()->header('User-Agent', '');
            if (str_contains($userAgent, 'Happ/')) {
                $parts = explode('/', $userAgent);
                $idFromUa = end($parts);

                if ($idFromUa !== '17764321936841767581') {
                    $deviceId = $idFromUa;
                }
            }
        }

        if (!$deviceId) return false;

        $installCode = request()->query('InstallID');

        $deviceExists = $sub->devices()->where(function ($q) use ($deviceId, $installCode) {
            $q->where('device_id', $deviceId);
            if ($installCode) $q->orWhere('happ_install_code', $installCode);
        })->exists();

        if ($deviceExists) return false;

        $limit = $sub->install_limit ?? 1;

        if ($sub->devices()->count() < $limit) {
            $sub->devices()->create([
                'device_id' => $deviceId,
                'happ_install_code' => $installCode,
            ]);

            if (empty($sub->device_id)) {
                $sub->update(['device_id' => $deviceId]);
            }
            return false;
        }

        Log::warning("Блокировка: Подписка {$sub->id} лимит установок ({$limit}), пришел {$deviceId}");
        return true;
    }


}