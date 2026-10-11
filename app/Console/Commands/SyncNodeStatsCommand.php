<?php

namespace App\Console\Commands;

use App\Models\Config;
use App\Models\Subscription;
use App\Services\NodeApiService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SyncNodeStatsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'nodes:sync-stats';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Синхронизация статистики трафика и сроков действия со всех нод в фоне';

    /**
     * Execute the console command.
     */
    public function handle(NodeApiService $nodeApi): int
    {
        $startTime = microtime(true);
        $this->info('Запуск синхронизации статистики нод...');

        // 1. Получаем все активные конфиги
        $activeConfigs = Config::with(['node'])
            ->where('is_active', true)
            ->whereNotNull('node_id')
            ->whereNotNull('email')
            ->get();

        if ($activeConfigs->isEmpty()) {
            $this->info('Нет активных конфигов для синхронизации.');
            return Command::SUCCESS;
        }

        // 2. Опрашиваем ноды (через пакетный GET /clients или параллельный пул)
        $nodeApi->refreshAllConfigsStats($activeConfigs);

        // 3. Актуализируем даты окончания шаблонных подписок
        $subscriptions = Subscription::whereNotNull('template_id')
            ->with(['configs' => function ($q) {
                $q->where('is_active', true)
                  ->whereNotNull('inbound_id');
            }, 'configs.node'])
            ->get();

        $updatedSubsCount = 0;
        foreach ($subscriptions as $sub) {
            $templateConfigs = $sub->configs->filter(function ($c) {
                return $c->inbound_id !== null
                    && $c->is_active
                    && $c->node
                    && $c->node->is_active
                    && (int)$c->expiry_time > 0;
            });

            if ($templateConfigs->isEmpty()) {
                continue;
            }

            $minExpirySeconds = (int)($templateConfigs->pluck('expiry_time')->min() / 1000);
            if ($minExpirySeconds > 0) {
                if (!$sub->expires_at || abs($sub->expires_at->timestamp - $minExpirySeconds) > 60) {
                    $sub->update([
                        'expires_at' => Carbon::createFromTimestamp($minExpirySeconds),
                    ]);
                    $updatedSubsCount++;
                }
            }
        }

        $duration = round(microtime(true) - $startTime, 2);
        $msg = "Синхронизировано {$activeConfigs->count()} конфигов, обновлено {$updatedSubsCount} подписок за {$duration} сек.";
        $this->info($msg);
        Log::info("[SyncNodeStats] {$msg}");

        return Command::SUCCESS;
    }
}
