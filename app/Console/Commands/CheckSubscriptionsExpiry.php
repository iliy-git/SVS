<?php

namespace App\Console\Commands;

use App\Models\Setting;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use App\Models\Subscription;
use App\Services\TelegramService;

class CheckSubscriptionsExpiry extends Command
{
    protected $signature = 'subscriptions:check-expiry';
    protected $description = 'Фоновый демон мониторинга истекающих подписок (за 24 часа)';

    public function handle(TelegramService $tgService)
    {
        $this->info("🚀 Демон проверки подписок запущен...");

        while (true) {
            // Проверяем, включено ли уведомление в настройках (по аналогии с нодами)
            $isEnabled = Setting::where('key', 'notify_subs_status')->value('value') === '1';

            if ($isEnabled) {
                try {
                    // Используем Eloquent: берем подписки на ближайшие 24 часа ВМЕСТЕ с клиентами
                    $expiringSubscriptions = Subscription::with('clients')
                        ->where('expires_at', '>', now())
                        ->where('expires_at', '<=', now()->addDay())
                        ->get();

                    $adminChatId = env('TELEGRAM_CHAT_ID');

                    foreach ($expiringSubscriptions as $sub) {
                        // Уникальный ключ кэша (чтобы не спамить каждый час об одной подписке)
                        $cacheKey = "notify_expiry_{$sub->id}";

                        if (!Cache::has($cacheKey)) {
                            // Так как связь belongsToMany, берем первого привязанного клиента
                            $client = $sub->clients->first();
                            $clientName = $client ? $client->name : 'Без владельца';

                            $message = "⚠️ <b>Внимание! Истекает подписка</b>\n\n";
                            $message .= "👤 <b>Клиент:</b> {$clientName}\n";
                            $message .= "📦 <b>Устройство:</b> {$sub->name} (ID: #{$sub->id})\n";
                            $message .= "📅 <b>Заканчивается:</b> <code>{$sub->expires_at->format('d.m.Y H:i')}</code>\n\n";
                            $message .= "⏳ <i>Осталось менее 24 часов!</i>";

                            if ($tgService->sendMessage($adminChatId, $message)) {
                                // Запоминаем в кэше на 25 часов, что уже уведомили
                                Cache::put($cacheKey, true, now()->addHours(25));
                                $this->info("Отправлено уведомление по подписке #{$sub->id}");
                            }
                        }
                    }

                } catch (\Exception $e) {
                    Log::error("Ошибка в мониторинге подписок: " . $e->getMessage());
                }
            }

            // Засыпаем ровно на 1 час (3600 секунд)
            sleep(60);
        }
    }
}
