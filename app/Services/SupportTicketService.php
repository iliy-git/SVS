<?php

namespace App\Services;

use App\Models\Client;
use App\Models\Subscription;
use App\Models\SupportMessage;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class SupportTicketService
{
    public function __construct(
        protected TelegramService $telegramService
    ) {}

    /**
     * Создание нового сообщения в чате поддержки от клиента
     */
    public function sendMessageFromClient(Client $client, string $message, ?Subscription $fromSubscription = null): SupportMessage
    {
        $cleanMessage = trim($message);

        $msg = SupportMessage::create([
            'client_id' => $client->id,
            'sender'    => 'client',
            'message'   => $cleanMessage,
        ]);

        $this->notifyAdmin($msg, $client, $fromSubscription);

        return $msg;
    }

    /**
     * Отправка уведомления администратору в Telegram
     */
    protected function notifyAdmin(SupportMessage $msg, Client $client, ?Subscription $fromSubscription = null): void
    {
        $adminChatId = env('TELEGRAM_CHAT_ID');
        if (!$adminChatId) {
            Log::warning("[SupportTicketService] TELEGRAM_CHAT_ID не настроен.");
            return;
        }

        $client->loadMissing('subscriptions');
        $subsList = $client->subscriptions->map(function ($s) {
            $isExpired = $s->expires_at && $s->expires_at->isPast();
            return "#{$s->id} {$s->name}" . ($isExpired ? ' (истекла)' : '');
        })->implode(', ');

        $safeClientName = htmlspecialchars($client->name ?? 'Без имени', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $safeMessage = htmlspecialchars($msg->message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        $text = "💬 <b>Новое сообщение в чат поддержки!</b>\n";
        $text .= "👤 <b>Клиент:</b> {$safeClientName} (ID: #{$client->id})\n";
        if ($subsList) {
            $text .= "🔑 <b>Подписки:</b> {$subsList}\n";
        }
        if ($fromSubscription) {
            $text .= "📌 <b>Страница:</b> #{$fromSubscription->id} {$fromSubscription->name}\n";
        }
        $text .= "─────────────────────\n";
        $text .= "{$safeMessage}\n";
        $text .= "─────────────────────\n";
        $text .= "🆔 <code>client_{$client->id}</code>\n";
        $text .= "ℹ️ <i>Сделайте <b>Reply (Ответить)</b> на это сообщение, чтобы отправить ответ в чат на сайте.</i>";

        $this->telegramService->sendMessage((string)$adminChatId, $text);
    }

    /**
     * Обработка ответа администратора из Telegram через Reply
     * Ответ сохраняется строго в веб-чат на сайте (НЕ дублируется в Telegram клиенту)
     */
    public function handleAdminReply(int $clientId, string $replyText): bool
    {
        $cleanReply = trim($replyText);
        if ($cleanReply === '') {
            return false;
        }

        SupportMessage::create([
            'client_id' => $clientId,
            'sender'    => 'admin',
            'message'   => $cleanReply,
        ]);

        return true;
    }

    /**
     * Получить историю сообщений для сплошного чата
     */
    public function getChatMessages(int $clientId, int $limit = 50): Collection
    {
        return SupportMessage::where('client_id', $clientId)
            ->oldest()
            ->take($limit)
            ->get();
    }
}
