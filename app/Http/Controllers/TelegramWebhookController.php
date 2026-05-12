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

        $tgId = (string)$message['from']['id'];
        $botToken = env('TELEGRAM_BOT_TOKEN');
        $adminChatId = env('TELEGRAM_CHAT_ID');

        // --- 1. ЛОГИКА ОТВЕТА АДМИНА ПОЛЬЗОВАТЕЛЮ ---
        if ($tgId == $adminChatId && isset($message['reply_to_message'])) {
            $replyTo = $message['reply_to_message'];
            $replyText = $replyTo['text'] ?? $replyTo['caption'] ?? '';

            // Извлекаем ID пользователя из текста сообщения, на которое отвечаем
            if (preg_match('/🆔\s*(\d+)/u', $replyText, $matches)) {
                $targetUserId = $matches[1];

                // Определяем, что именно отправил админ (текст или медиа)
                $this->sendToUser($botToken, $targetUserId, $message);

                return; // Выходим, чтобы не дублировать в группу
            }
        }

        // --- 2. ИГНОРИРУЕМ ОБЫЧНЫЕ СООБЩЕНИЯ ОТ АДМИНА (не реплаи) ---
        if ($tgId == $adminChatId) return;

        // --- 3. ЛОГИКА ПРИЕМА СООБЩЕНИЯ ОТ ПОЛЬЗОВАТЕЛЯ ---
        $firstName = $message['from']['first_name'] ?? 'User';
        $user = TelegramUser::updateOrCreate(
            ['telegram_id' => $tgId],
            ['first_name' => $firstName, 'last_message_at' => now()]
        );

        // Пересылка админу с сохранением типа медиа
        $this->forwardToAdmin($botToken, $adminChatId, $message, $tgId, $firstName);
    }

    /**
     * Отправка ответа от админа конкретному пользователю
     */
    private function sendToUser($token, $userId, $message)
    {
        $text = $message['text'] ?? $message['caption'] ?? '';
        $method = 'sendMessage';
        $params = ['chat_id' => $userId];

        if (isset($message['photo'])) {
            $method = 'sendPhoto';
            $params['photo'] = end($message['photo'])['file_id'];
            $params['caption'] = $text;
        } elseif (isset($message['video'])) {
            $method = 'sendVideo';
            $params['video'] = $message['video']['file_id'];
            $params['caption'] = $text;
        } elseif (isset($message['voice'])) {
            $method = 'sendVoice';
            $params['voice'] = $message['voice']['file_id'];
            $params['caption'] = $text;
        } elseif (isset($message['document'])) {
            $method = 'sendDocument';
            $params['document'] = $message['document']['file_id'];
            $params['caption'] = $text;
        } else {
            $params['text'] = $text;
        }

        Http::post("https://api.telegram.org/bot{$token}/{$method}", $params);
    }

    /**
     * Пересылка сообщения пользователя в админ-чат
     */
    private function forwardToAdmin($token, $adminId, $message, $userId, $firstName)
    {
        $userLink = isset($message['from']['username']) ? "@" . $message['from']['username'] : $firstName;
        $caption = "📩 <b>Новое от:</b> {$userLink}\n🆔 {$userId}\n───\n";
        $text = $message['text'] ?? $message['caption'] ?? '';

        $method = 'sendMessage';
        $params = [
            'chat_id' => $adminId,
            'parse_mode' => 'HTML'
        ];

        if (isset($message['photo'])) {
            $method = 'sendPhoto';
            $params['photo'] = end($message['photo'])['file_id'];
            $params['caption'] = $caption . $text;
        } elseif (isset($message['video'])) {
            $method = 'sendVideo';
            $params['video'] = $message['video']['file_id'];
            $params['caption'] = $caption . $text;
        } else {
            $params['text'] = $caption . "📝 <b>Текст:</b>\n<i>{$text}</i>";
        }

        Http::post("https://api.telegram.org/bot{$token}/{$method}", $params);
    }
}
