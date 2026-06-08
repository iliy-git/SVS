<?php

namespace App\Services;

use App\Models\Setting;
use App\Models\TelegramUser;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

class TelegramService
{
    protected string $baseUrl = "https://api.telegram.org/bot";

    /**
     * Сохранение настроек и обновление Webhook
     */
    public function updateSettings(string $token, string $chatId, bool $isPolling = false, bool $notifyNodes = false, bool $notifySubs = false): void
    {
        // 1. Сначала обновляем файл .env на диске
        $this->updateEnv([
            'TELEGRAM_BOT_TOKEN' => $token,
            'TELEGRAM_CHAT_ID'   => $chatId,
        ]);

        Setting::updateOrCreate(['key' => 'tg_poll_enabled'], ['value' => $isPolling ? '1' : '0']);
        Setting::updateOrCreate(['key' => 'notify_nodes_status'], ['value' => $notifyNodes ? '1' : '0']);
        Setting::updateOrCreate(['key' => 'notify_subs_status'], ['value' => $notifySubs ? '1' : '0']);

        // --- Управление Polling ---
        $this->stopPolling(); // ВСЕГДА сначала тушим старый процесс!
        if ($isPolling) {
            Http::get("https://api.telegram.org/bot{$token}/deleteWebhook");
            $this->startPolling(); // Запустится уже с новым .env
        }

        // --- Управление Мониторингом нод ---
        $this->stopNodesMonitoring(); // ВСЕГДА сначала тушим старый процесс!
        if ($notifyNodes) {
            $this->startNodesMonitoring(); // Запустится уже с новым .env
        }

        // --- Управление окончанием подписок
        $this->stopSubscriptionsMonitoring(); // ВСЕГДА сначала тушим старый процесс!
        if ($notifySubs) {
            $this->startSubscriptionsMonitoring(); // Запустится уже с новым .env
        }
    }

    /**
     * Отправка сообщения (текст или фото с подписью)
     */
    public function sendMessage(string $chatId, string $text, $image = null): bool
    {
        $token = env('TELEGRAM_BOT_TOKEN');
        if (!$token) return false;

        $endpoint = $image ? "/sendPhoto" : "/sendMessage";

        $request = $image
            ? Http::attach('photo', file_get_contents($image->getRealPath()), $image->getClientOriginalName())
            : Http::asJson();

        $params = [
            'chat_id' => $chatId,
            'parse_mode' => 'HTML'
        ];

        if ($image) {
            $params['caption'] = $text;
        } else {
            $params['text'] = $text;
        }

        $response = $request->post("{$this->baseUrl}{$token}{$endpoint}", $params);

        return $response->ok();
    }

    /**
     * Сохранение сообщения в локальную БД
     */
    public function storeMessage(TelegramUser $user, string $text, bool $isFromBot = true, bool $isImage = false)
    {
        return $user->messages()->create([
            'text' => $isImage ? "🖼 Фото: " . $text : $text,
            'is_from_bot' => $isFromBot,
        ]);
    }

    /**
     * Работа с .env (инкапсулируем логику)
     */
    protected function updateEnv(array $data): void
    {
        $path = base_path('.env');
        if (!File::exists($path)) return;

        $content = File::get($path);
        foreach ($data as $key => $value) {
            $content = preg_match("/^{$key}=.*/m", $content)
                ? preg_replace("/^{$key}=.*/m", "{$key}={$value}", $content)
                : $content . "\n{$key}={$value}";
        }
        File::put($path, $content);
    }

    /**
    * Запуск слушателя сообщений
    */
    public function startPolling(): void
    {
        $basePath = base_path();
        $artisan = base_path('artisan');

        $command = "cd $basePath && nohup php artisan tg:poll > /dev/null 2>&1 &";

        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            pclose(popen("start /B php artisan tg:poll", "r"));
        } else {
            exec($command);
        }
    }

    /**
     * Остановка слушателя сообщений
     */
    public function stopPolling(): void
    {
        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            exec('taskkill /F /IM php.exe /T');
        } else {
            exec('pkill -f "tg:poll"');
        }

        \Illuminate\Support\Facades\Cache::forget('tg_poll_lock');
    }
    /**
     * Запуск демона мониторинга нод
     */
    public function startNodesMonitoring(): void
    {
        $basePath = base_path();
        $command = "cd $basePath && nohup php artisan nodes:check > /dev/null 2>&1 &";

        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            pclose(popen("start /B php artisan nodes:check", "r"));
        } else {
            exec($command);
        }
    }

    /**
     * Остановка демона мониторинга нод
     */
    public function stopNodesMonitoring(): void
    {
        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            // На винде мягко убить отдельный php-скрипт сложно, тушится всё
            exec('taskkill /F /IM php.exe /T');
        } else {
            // На Linux точечно убиваем процесс демона нод
            exec('pkill -f "nodes:check"');
        }
    }
    /**
     * Отправка уведомлений если нода не отвечает
     */
    public function sendSystemAlert(string $message): bool
    {
        // Проверяем, включен ли ползунок в настройках
        $isEnabled = \App\Models\Setting::where('key', 'notify_nodes_status')->value('value');
        if ($isEnabled !== '1') {
            return false;
        }

        $token = env('TELEGRAM_BOT_TOKEN');
        $adminChatId = env('TELEGRAM_CHAT_ID');

        if (!$token || !$adminChatId) {
            return false;
        }

        // Отправляем запрос в Telegram
        $response = \Illuminate\Support\Facades\Http::post("{$this->baseUrl}{$token}/sendMessage", [
            'chat_id' => $adminChatId,
            'text' => "🖥 <b>МОНИТОРИНГ СЕРВЕРОВ</b>\n\n" . $message,
            'parse_mode' => 'HTML'
        ]);

        return $response->ok();
    }
    /**
     * Запуск демона мониторинга подписок
     */
    public function startSubscriptionsMonitoring(): void
    {
        $basePath = base_path();
        $command = "cd $basePath && nohup php artisan subscriptions:check-expiry > /dev/null 2>&1 &";

        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            pclose(popen("start /B php artisan subscriptions:check-expiry", "r"));
        } else {
            exec($command);
        }
    }

    /**
     * Остановка демона
     */
    public function stopSubscriptionsMonitoring(): void
    {
        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            exec('taskkill /F /IM php.exe /T');
        } else {
            exec('pkill -f "subscriptions:check-expiry"');
        }
    }
}
