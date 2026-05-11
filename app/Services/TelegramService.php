<?php

namespace App\Services;

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
    public function updateSettings(string $token, string $chatId, bool $isPolling = false): void
    {
        $this->updateEnv([
            'TELEGRAM_BOT_TOKEN' => $token,
            'TELEGRAM_CHAT_ID'   => $chatId,
        ]);

        // Работаем с таблицей settings через модель Setting
        \App\Models\Setting::updateOrCreate(
            ['key' => 'tg_poll_enabled'],
            ['value' => $isPolling ? '1' : '0']
        );

        if ($isPolling) {
            Http::get("https://api.telegram.org/bot{$token}/deleteWebhook");
            $this->startPolling();
        } else {
            $this->stopPolling();
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

}
