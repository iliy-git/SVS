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
        $firstName = $message['from']['first_name'] ?? 'User';
        $botToken = env('TELEGRAM_BOT_TOKEN');
        $adminId = env('TELEGRAM_CHAT_ID');

        // 1. Сохраняем/обновляем пользователя
        $user = TelegramUser::updateOrCreate(
            ['telegram_id' => $tgId],
            ['first_name' => $firstName, 'last_message_at' => now()]
        );

        // 2. Определяем тип контента
        $mediaType = null;
        $fileId = null;
        $text = $message['text'] ?? $message['caption'] ?? ''; // Текст сообщения или подпись к фото

        if (isset($message['photo'])) {
            $mediaType = 'photo';
            $fileId = end($message['photo'])['file_id']; // Берем фото в лучшем качестве
        } elseif (isset($message['video'])) {
            $mediaType = 'video';
            $fileId = $message['video']['file_id'];
        } elseif (isset($message['document'])) {
            $mediaType = 'document';
            $fileId = $message['document']['file_id'];
        } elseif (isset($message['voice'])) {
            $mediaType = 'voice';
            $fileId = $message['voice']['file_id'];
        }

        // 3. Формируем текст для твоей локальной базы (на сайт)
        $dbText = $text;
        if ($mediaType) {
            $dbText = ($text ? "📝 {$text}\n" : "") . "📎 [Вложение: {$mediaType}]";
        }

        if (!empty($dbText)) {
            $user->messages()->create([
                'text' => $dbText,
                'is_from_bot' => false
            ]);
        }

        // 4. Пересылка админу (тебе)
//        if ($adminId && $tgId != $adminId) {
            $userLink = isset($message['from']['username'])
                ? "@" . $message['from']['username']
                : "<a href='tg://user?id={$tgId}'>{$firstName}</a>";

            $info = "📩 <b>Новое от:</b> {$userLink}\n🆔 <code>{$tgId}</code>";

            if ($mediaType) {
                $method = "send" . ucfirst($mediaType);
                Http::post("https://api.telegram.org/bot{$botToken}/{$method}", [
                    'chat_id' => $adminId,
                    $mediaType => $fileId,
                    'caption' => $info . ($text ? "\n───\n{$text}" : ""),
                    'parse_mode' => 'HTML'
                ]);
            } else {
                Http::post("https://api.telegram.org/bot{$botToken}/sendMessage", [
                    'chat_id' => $adminId,
                    'text' => $info . "\n───\n📝 <b>Текст:</b>\n<i>{$text}</i>",
                    'parse_mode' => 'HTML'
                ]);
            }
//        }
    }
}
