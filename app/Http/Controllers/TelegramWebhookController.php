<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\TelegramUser;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TelegramWebhookController extends Controller
{
    public function handle(Request $request)
    {
        $message = $request->input('message');
        if (!$message) return;

        $tgId = $message['from']['id'];
        $botToken = env('TELEGRAM_BOT_TOKEN');
        $adminChatId = env('TELEGRAM_CHAT_ID');

        if (isset($message['reply_to_message'])) {
            $replyTo = $message['reply_to_message'];
            $replyText = $replyTo['text'] ?? $replyTo['caption'] ?? '';

            if (preg_match('/🆔\s*(\d+)/u', $replyText, $matches)) {
                $targetUserId = $matches[1];
                $answerText = $message['text'] ?? '';

                if (!empty($answerText)) {
                    // Отправляем ваш ответ лично пользователю
                    Http::post("https://api.telegram.org/bot{$botToken}/sendMessage", [
                        'chat_id' => $targetUserId,
                        'text' => $answerText,
                    ]);

                    return;
                }
            }
        }

        if ($tgId == $adminChatId) return;


        $firstName = $message['from']['first_name'] ?? 'User';
        $user = TelegramUser::updateOrCreate(
            ['telegram_id' => $tgId],
            ['first_name' => $firstName, 'last_message_at' => now()]
        );

        $text = $message['text'] ?? $message['caption'] ?? '';
        $user->messages()->create(['text' => $text, 'is_from_bot' => false]);

        $userLink = isset($message['from']['username'])
            ? "@" . $message['from']['username']
            : $firstName;

        $info = "📩 <b>Новое от:</b> {$userLink}\n🆔 {$tgId}";

        Http::post("https://api.telegram.org/bot{$botToken}/sendMessage", [
            'chat_id' => $adminChatId,
            'text' => $info . "\n───\n📝 <b>Текст:</b>\n<i>{$text}</i>",
            'parse_mode' => 'HTML'
        ]);
    }
}
