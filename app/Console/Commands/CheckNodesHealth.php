<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Node;
use App\Services\NodeService;
use App\Services\TelegramService;

class CheckNodesHealth extends Command
{
    protected $signature = 'nodes:check';
    protected $description = 'Фоновый демон мониторинга нод';

    public function handle(NodeService $nodeService, TelegramService $tgService)
    {
        $this->info("🚀 Демон мониторинга нод успешно запущен...");

        while (true) {
            $isEnabled = \App\Models\Setting::where('key', 'notify_nodes_status')->value('value') === '1';
            if (!$isEnabled) {
                $this->warn("Мониторинг отключен в настройках. Завершение процесса.");
                break;
            }

            $nodes = Node::all();

            foreach ($nodes as $node) {
                // Оборачиваем каждую ноду отдельно
                try {
                    $wasActive = (bool) $node->is_active;
                    $isActive = $nodeService->checkHealth($node->id);

                    if ($wasActive !== $isActive) {
                        $statusIcon = $isActive ? "✅" : "❌";
                        $statusText = $isActive ? "ВОССТАНОВЛЕН" : "НЕДОСТУПЕН";

                        $message = "{$statusIcon} <b>Сервер:</b> {$node->name}\n";
                        $message .= "<b>IP:</b> <code>{$node->ip}</code>\n";
                        $message .= "<b>Статус:</b> {$statusText}";

                        $tgService->sendSystemAlert($message);
                    }

                    $node->update([
                        'is_active' => $isActive,
                        'last_seen' => now()
                    ]);

                } catch (\Exception $e) {
                    // Если нода легла с критической ошибкой, помечаем её как неактивную
                    $node->update([
                        'is_active' => false,
                        'last_seen' => now()
                    ]);

                    // Записываем ошибку в стандартный лог Laravel (storage/logs/laravel.log)
                    \Illuminate\Support\Facades\Log::error("Ошибка мониторинга ноды ID {$node->id}: " . $e->getMessage());
                }
            }

            sleep(60);
        }
    }
}

