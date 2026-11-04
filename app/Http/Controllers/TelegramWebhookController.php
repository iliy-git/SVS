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
        if (!$message || !is_array($message)) return;

        // Безопасное получение ID отправителя (защита от сообщений каналов/системных апдейтов без from)
        $tgId = $message['from']['id'] ?? null;
        if (!$tgId) return;

        $botToken = env('TELEGRAM_BOT_TOKEN');
        $adminChatId = env('TELEGRAM_CHAT_ID');

        // 1. Логика ответа (Reply)
        if (isset($message['reply_to_message'])) {
            $replyTo = $message['reply_to_message'];
            $replyText = $replyTo['text'] ?? $replyTo['caption'] ?? '';

            if (preg_match('/🆔\s*(\d+)/u', $replyText, $matches)) {
                $targetUserId = $matches[1];

                // Вызываем отправку пользователю
                $this->sendTelegram($botToken, $targetUserId, $message);
                return;
            }
        }

        // 2. Если пишет админ не через Reply — игнорируем
        if ($adminChatId && (string)$tgId === (string)$adminChatId) return;

        // 3. Логика получения сообщения от пользователя
        $firstName = $message['from']['first_name'] ?? 'User';
        $user = TelegramUser::updateOrCreate(
            ['telegram_id' => $tgId],
            ['first_name' => $firstName, 'last_message_at' => now()]
        );

        $text = $message['text'] ?? $message['caption'] ?? '';

        // -----------------------------------------------------
        // 🚀 Обработка команды /start
        // -----------------------------------------------------
        if ($text === '/start') {
            $welcomeText = "👋 <b>Привет!</b> <b>Комар</b> на связи.\nРасскажи, что стряслось? Опиши свою проблему и не забудь указать <b>номер подписки</b> — так мы сможем помочь гораздо быстрее!";

            // Отправляем красивое приветствие пользователю
            $this->sendTelegram($botToken, $tgId, ['text' => $welcomeText], '', true);

            // Записываем в базу, что юзер нажал start (для истории), но НЕ пересылаем админу
            $user->messages()->create(['text' => '/start', 'is_from_bot' => false]);

            return;
        }
        // -----------------------------------------------------

        $user->messages()->create(['text' => $text ?: '[Файл]', 'is_from_bot' => false]);

        $rawUsername = $message['from']['username'] ?? null;
        $userLink = $rawUsername
            ? "@" . htmlspecialchars($rawUsername, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
            : htmlspecialchars($firstName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        $info = "📩 <b>Новое от:</b> {$userLink}\n🆔 {$tgId}";

        // Пересылаем сообщение админу (с безопасным экранированием текста)
        if ($adminChatId) {
            $this->sendTelegram($botToken, $adminChatId, $message, $info, true);
        }
    }

    /**
     * Универсальный метод для отправки текста или медиафайлов
     */
    private function sendTelegram($token, $chatId, $message, $infoPrefix = '', $useHtml = false)
    {
        if (!$token || !$chatId) return null;

        $text = $message['text'] ?? $message['caption'] ?? '';

        if ($useHtml) {
            $safeText = htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $caption = $infoPrefix ? $infoPrefix . "\n───\n" . $safeText : $safeText;
        } else {
            $caption = $infoPrefix ? $infoPrefix . "\n───\n" . $text : $text;
        }

        $params = [
            'chat_id' => $chatId,
        ];

        if ($useHtml) {
            $params['parse_mode'] = 'HTML';
        }

        if (isset($message['photo']) && is_array($message['photo'])) {
            $method = 'sendPhoto';
            $params['photo'] = end($message['photo'])['file_id'];
            if ($caption !== '') $params['caption'] = $caption;
        } elseif (isset($message['document'])) {
            $method = 'sendDocument';
            $params['document'] = $message['document']['file_id'];
            if ($caption !== '') $params['caption'] = $caption;
        } elseif (isset($message['video'])) {
            $method = 'sendVideo';
            $params['video'] = $message['video']['file_id'];
            if ($caption !== '') $params['caption'] = $caption;
        } elseif (isset($message['voice'])) {
            $method = 'sendVoice';
            $params['voice'] = $message['voice']['file_id'];
            if ($caption !== '') $params['caption'] = $caption;
        } else {
            $method = 'sendMessage';
            $params['text'] = $caption !== '' ? $caption : ($infoPrefix ?: '📩 [Вложение]');
        }

        try {
            return Http::timeout(10)->post("https://api.telegram.org/bot{$token}/{$method}", $params);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error("Ошибка отправки в Telegram ({$method}): " . $e->getMessage());
            return null;
        }
    }
}
