<?php

namespace App\Console\Commands;

use App\Models\Node;
use App\Models\Setting;
use App\Services\NodeApiService;
use App\Services\TelegramService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class BackupNodesCommand extends Command
{
    /**
     * Сигнатура консольной команды
     *
     * @var string
     */
    protected $signature = 'nodes:backup 
                            {--force : Выполнить бэкап принудительно (игнорируя расписание и статус ползунка)} 
                            {--daemon : Запуск в режиме фонового демона}';

    /**
     * Описание команды
     *
     * @var string
     */
    protected $description = 'Ежедневный сбор бэкапов баз данных со всех активных нод и отправка в Telegram';

    /**
     * Выполнение команды
     */
    public function handle(NodeApiService $nodeApi, TelegramService $tgService): int
    {
        if ($this->option('daemon')) {
            return $this->runDaemon($nodeApi, $tgService);
        }

        $isForce = (bool) $this->option('force');
        $isEnabled = Setting::where('key', 'backup_nodes_daily')->value('value') === '1';

        if (!$isEnabled && !$isForce) {
            $this->info('Ежедневный бэкап нод выключен в настройках Telegram.');
            return Command::SUCCESS;
        }

        $today = now()->toDateString();
        $lastDate = Setting::where('key', 'last_nodes_backup_date')->value('value');

        if ($lastDate === $today && !$isForce) {
            $this->info("Бэкап за сегодня ({$today}) уже был отправлен. Используйте --force для принудительного повтора.");
            return Command::SUCCESS;
        }

        return $this->runBackup($nodeApi, $tgService, $isForce);
    }

    /**
     * Режим фонового демона (если запускается автономно)
     */
    protected function runDaemon(NodeApiService $nodeApi, TelegramService $tgService): int
    {
        $this->info('🚀 Демон ежедневного бэкапа нод запущен...');

        while (true) {
            $isEnabled = Setting::where('key', 'backup_nodes_daily')->value('value') === '1';
            if (!$isEnabled) {
                $this->warn('Бэкап нод отключен в настройках. Завершение демона.');
                break;
            }

            $today = now()->toDateString();
            $lastDate = Setting::where('key', 'last_nodes_backup_date')->value('value');
            $currentHour = (int) now()->format('H');

            // Запуск в 04:00 ночи (или в течение дня, если сегодня еще ни разу не запускался)
            if ($lastDate !== $today && $currentHour >= 4) {
                $this->info("Время для ежедневного бэкапа ({$today} {$currentHour}:00). Запуск...");
                $this->runBackup($nodeApi, $tgService, false);
            }

            sleep(60);
        }

        return Command::SUCCESS;
    }

    /**
     * Основная процедура сбора бэкапов и отправки в Telegram
     */
    public function runBackup(NodeApiService $nodeApi, TelegramService $tgService, bool $isForce = false): int
    {
        $chatId = env('TELEGRAM_CHAT_ID');
        $token = env('TELEGRAM_BOT_TOKEN');

        if (!$chatId || !$token) {
            $this->error('TELEGRAM_CHAT_ID или TELEGRAM_BOT_TOKEN не заданы в .env!');
            Log::error('[BackupNodes] Ошибка: не настроены параметры Telegram в .env');
            return Command::FAILURE;
        }

        $nodes = Node::where('is_active', true)->get();
        if ($nodes->isEmpty()) {
            $nodes = Node::all();
        }

        if ($nodes->isEmpty()) {
            $this->warn('В базе нет нод для создания бэкапа.');
            $tgService->sendMessage($chatId, "⚠️ <b>Бэкап нод:</b> В системе нет ни одного добавленного сервера.");
            return Command::SUCCESS;
        }

        $this->info("Начало выгрузки бэкапов для {$nodes->count()} серверов...");
        $nowStr = now()->format('d.m.Y H:i:s');
        $successCount = 0;
        $failCount = 0;

        foreach ($nodes as $node) {
            $this->line("Обработка ноды: {$node->name} ({$node->ip})...");

            $backup = $nodeApi->downloadNodeBackup($node);

            if ($backup && !empty($backup['content'])) {
                $sizeKb = round($backup['size'] / 1024, 1);
                $sizeStr = $sizeKb > 1024 ? round($sizeKb / 1024, 2) . ' МБ' : "{$sizeKb} КБ";

                $caption = "💾 <b>Бэкап ноды:</b> {$node->name}\n"
                    . "🌐 <b>IP:</b> <code>{$node->ip}</code>\n"
                    . "📊 <b>Размер:</b> {$sizeStr}\n"
                    . "📅 <b>Дата:</b> {$nowStr}";

                $sent = $tgService->sendDocument(
                    $chatId,
                    $backup['content'],
                    $backup['filename'],
                    $caption
                );

                if ($sent) {
                    $successCount++;
                    $this->info("✅ {$node->name}: успешно отправлен в Telegram ({$sizeStr})");
                } else {
                    $failCount++;
                    $this->error("❌ {$node->name}: ошибка отправки файла через Telegram API");
                }
            } else {
                $failCount++;
                $errorMsg = "❌ <b>Ошибка бэкапа ноды:</b> {$node->name}\n"
                    . "🌐 <b>IP:</b> <code>{$node->ip}</code>\n"
                    . "⚠️ Не удалось получить файл бэкапа (проверьте доступность ноды и обновление apiNode).";

                $tgService->sendMessage($chatId, $errorMsg);
                $this->error("❌ {$node->name}: не удалось получить бэкап");
            }
        }

        // Фиксируем факт успешного выполнения за сегодня
        Setting::updateOrCreate(['key' => 'last_nodes_backup_date'], ['value' => now()->toDateString()]);
        Setting::updateOrCreate(['key' => 'last_nodes_backup_at'], ['value' => now()->toDateTimeString()]);

        // Отправляем итоговый отчет
        $summary = "📊 <b>Итог ежедневного бэкапа нод</b>\n\n"
            . "✅ Успешно сохранено: <b>{$successCount}</b>\n"
            . ($failCount > 0 ? "❌ Ошибок: <b>{$failCount}</b>\n" : "")
            . "📅 Дата: <code>{$nowStr}</code>";

        $tgService->sendMessage($chatId, $summary);

        $msg = "Бэкап нод завершен. Успешно: {$successCount}, с ошибками: {$failCount}";
        $this->info($msg);
        Log::info("[BackupNodes] {$msg}");

        return Command::SUCCESS;
    }
}

