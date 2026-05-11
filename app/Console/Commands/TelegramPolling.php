<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use App\Http\Controllers\TelegramWebhookController;
use Illuminate\Http\Request;

class TelegramPolling extends Command
{
    protected $signature = 'tg:poll';
    protected $description = 'Запуск Telegram бота в режиме опроса (Polling)';

    public function handle()
    {

        $botToken = config('services.telegram.token') ?? env('TELEGRAM_BOT_TOKEN');

        if (!$botToken) {
            $this->error("TELEGRAM_BOT_TOKEN не найден в .env файле!");
            return;
        }

        $this->info("🚀 Telegram Polling запущен...");
        $this->warn("Нажмите CTRL+C для остановки.");

        $offset = 0;
        $controller = new TelegramWebhookController();

        while (true) {
            try {
                // Используем Long Polling (timeout 30 секунд)
                $response = Http::timeout(35)->get("https://api.telegram.org/bot{$botToken}/getUpdates", [
                    'offset' => $offset,
                    'timeout' => 30,
                ]);

                if ($response->ok()) {
                    $updates = $response->json('result');

                    foreach ($updates as $update) {
                        $this->line("Получено обновление ID: " . $update['update_id']);

                        $request = new Request();
                        $request->replace($update);

                        // Передаем запрос в контроллер
                        try {
                            $controller->handle($request);
                        } catch (\Exception $e) {
                            $this->error("Ошибка в контроллере: " . $e->getMessage());
                        }

                        $offset = $update['update_id'] + 1;
                    }
                } else {
                    $this->error("Ошибка API Telegram: " . $response->body());
                }
            } catch (\Exception $e) {
                $this->error("Ошибка соединения: " . $e->getMessage());
                sleep(5);
            }

            // Минимальная задержка между итерациями
            usleep(500000); // 0.5 сек
        }
    }
}
