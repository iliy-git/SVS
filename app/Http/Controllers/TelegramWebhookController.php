<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\TelegramUser;
use Illuminate\Support\Facades\Http;

class TelegramWebhookController extends Controller
{
    public function handle(Request $request)
    {
        $message = $request->input('message');
        if (!$message) return;

        $tgId = $message['from']['id'];
        $botToken = env('TELEGRAM_BOT_TOKEN');
        $adminChatId = env('TELEGRAM_CHAT_ID');

        // 1. Логика ответа (Reply)
        if (isset($message['reply_to_message'])) {
            $replyTo = $message['reply_to_message'];
            $replyText = $replyTo['text'] ?? $replyTo['caption'] ?? '';

            if (preg_match('/🆔\s*(\d+)/u', $replyText, $matches)) {
                $targetUserId = $matches[1];

                // Вызываем универсальную отправку пользователю
                $this->sendTelegram($botToken, $targetUserId, $message);
                return;
            }
        }

        // 2. Если пишет админ не через Reply — игнорируем
        if ($tgId == $adminChatId) return;

        // 3. Логика получения сообщения от пользователя
        $firstName = $message['from']['first_name'] ?? 'User';
        $user = TelegramUser::updateOrCreate(
            ['telegram_id' => $tgId],
            ['first_name' => $firstName, 'last_message_at' => now()]
        );

        $text = $message['text'] ?? $message['caption'] ?? '';

        // -----------------------------------------------------
        // 🚀 НОВОЕ: Обработка команды /start
        // -----------------------------------------------------
        if ($text === '/start') {
            $welcomeText = "👋 <b>Привет!</b> <b>Комар</b> на связи.\nРасскажи, что стряслось? Опиши свою проблему и не забудь указать <b>номер подписки</b> — так мы сможем помочь гораздо быстрее!";

            // Отправляем красивое приветствие пользователю
            $this->sendTelegram($botToken, $tgId, ['text' => $welcomeText]);

            // Записываем в базу, что юзер нажал start (для истории), но НЕ пересылаем админу
            $user->messages()->create(['text' => '/start', 'is_from_bot' => false]);

            return; // Прерываем скрипт, чтобы не спамить админу
        }
        // -----------------------------------------------------


        $user->messages()->create(['text' => $text ?: '[Файл]', 'is_from_bot' => false]);

        $userLink = isset($message['from']['username'])
            ? "@" . $message['from']['username']
            : $firstName;

        $info = "📩 <b>Новое от:</b> {$userLink}\n🆔 {$tgId}";

        // Пересылаем сообщение админу (с сохранением картинки/документа)
        $this->sendTelegram($botToken, $adminChatId, $message, $info);
    }

    /**
     * Универсальный метод для отправки текста или медиафайлов
     */
    private function sendTelegram($token, $chatId, $message, $infoPrefix = '')
    {
        $text = $message['text'] ?? $message['caption'] ?? '';
        $caption = $infoPrefix ? $infoPrefix . "\n───\n" . $text : $text;

        $params = [
            'chat_id' => $chatId,
            'parse_mode' => 'HTML'
        ];

        if (isset($message['photo'])) {
            $method = 'sendPhoto';
            $params['photo'] = end($message['photo'])['file_id'];
            $params['caption'] = $caption;
        } elseif (isset($message['document'])) {
            $method = 'sendDocument';
            $params['document'] = $message['document']['file_id'];
            $params['caption'] = $caption;
        } elseif (isset($message['video'])) {
            $method = 'sendVideo';
            $params['video'] = $message['video']['file_id'];
            $params['caption'] = $caption;
        } elseif (isset($message['voice'])) {
            $method = 'sendVoice';
            $params['voice'] = $message['voice']['file_id'];
            $params['caption'] = $caption;
        } else {
            $method = 'sendMessage';
            $params['text'] = $caption;
        }

        return Http::post("https://api.telegram.org/bot{$token}/{$method}", $params);
    }
}
