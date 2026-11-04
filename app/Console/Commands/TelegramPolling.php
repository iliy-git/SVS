<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use App\Http\Controllers\TelegramWebhookController;
use App\Models\Setting;
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
            Log::error("[TelegramPolling] TELEGRAM_BOT_TOKEN не найден в конфигурации.");
            return;
        }

        $this->info("🚀 Telegram Polling запущен...");
        $this->warn("Нажмите CTRL+C для остановки.");
        Log::info("[TelegramPolling] Сервис опроса Telegram успешно запущен.");

        $offset = 0;
        $controller = new TelegramWebhookController();
        $iteration = 0;

        while (true) {
            $iteration++;

            // 1. Проверяем, включен ли опрос в настройках панели
            try {
                $isEnabled = Setting::where('key', 'tg_poll_enabled')->value('value') === '1';
                if (!$isEnabled) {
                    sleep(3);
                    continue;
                }
            } catch (\Throwable $e) {
                // Если база кратковременно недоступна, ждем секунду
                sleep(1);
            }

            // 2. Сборка мусора и защита от утечек памяти (каждые 100 итераций)
            if ($iteration % 100 === 0) {
                gc_collect_cycles();

                // Если память превысила 64 МБ, завершаемся для чистого перезапуска супервизором
                if (memory_get_usage(true) > 64 * 1024 * 1024) {
                    Log::info("[TelegramPolling] Плановый перезапуск процесса для освобождения памяти (RAM > 64MB).");
                    break;
                }
            }

            try {
                // Long Polling: Telegram ждет 25 сек, таймаут HTTP клиента с запасом 35 сек
                $response = Http::timeout(35)->get("https://api.telegram.org/bot{$botToken}/getUpdates", [
                    'offset' => $offset,
                    'timeout' => 25,
                ]);

                if ($response->ok()) {
                    $updates = $response->json('result');

                    if (is_array($updates)) {
                        foreach ($updates as $update) {
                            if (!isset($update['update_id'])) {
                                continue;
                            }

                            // Сразу сдвигаем offset, чтобы битое сообщение не блокировало очередь
                            $offset = $update['update_id'] + 1;

                            $this->line("Получено обновление ID: " . $update['update_id']);

                            $request = new Request();
                            $request->replace($update);

                            try {
                                $controller->handle($request);
                            } catch (\Throwable $e) {
                                $this->error("Ошибка в обработчике: " . $e->getMessage());
                                Log::error("[TelegramPolling] Ошибка обработки обновления #{$update['update_id']}: " . $e->getMessage(), [
                                    'trace' => $e->getTraceAsString()
                                ]);
                            }
                        }
                    }
                } else {
                    $status = $response->status();
                    $body = $response->body();

                    $this->error("Ошибка API Telegram ({$status}): {$body}");
                    Log::warning("[TelegramPolling] Ответ Telegram API ({$status}): {$body}");

                    // Если конфликт (запущен второй процесс) или бан по частоте — делаем паузу
                    if ($status === 409 || $status === 429) {
                        sleep(5);
                    }
                }
            } catch (\Throwable $e) {
                $this->error("Ошибка соединения: " . $e->getMessage());
                Log::warning("[TelegramPolling] Ошибка сетевого соединения: " . $e->getMessage());
                sleep(3);
            }

            // Минимальная пауза между запросами
            usleep(300000); // 0.3 сек
        }
    }
}
