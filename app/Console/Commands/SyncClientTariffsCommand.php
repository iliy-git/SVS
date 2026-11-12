<?php

namespace App\Console\Commands;

use App\Models\Client;
use App\Models\ClientTariff;
use App\Models\Tariff;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SyncClientTariffsCommand extends Command
{
    protected $signature = 'clients:sync-tariffs {--dry-run : Только показать результат без записи в БД} {--reset : Полностью отвязать все подписки и удалить ClientTariff}';
    protected $description = 'Автоматически сформировать ClientTariff для клиентов с непривязанными подписками';

    public function handle()
    {
        if ($this->option('reset')) {
            $countSubs = \App\Models\Subscription::whereNotNull('client_tariff_id')->count();
            $countTariffs = ClientTariff::count();

            \App\Models\Subscription::query()->update(['client_tariff_id' => null]);
            ClientTariff::query()->delete();

            $this->info("🔄 Сброшено: отвязано {$countSubs} подписок, удалено {$countTariffs} тарифов клиентов.");
            return 0;
        }

        $isDryRun = (bool) $this->option('dry-run');
        $tariffs = Tariff::with('items')->where('is_active', true)->get();
        $clients = Client::with('subscriptions')->get();

        $this->info($isDryRun ? '🔍 Режим симуляции (Dry-run)' : '🚀 Запуск синхронизации тарифов...');

        $syncedCount = 0;

        foreach ($clients as $client) {
            $subs = $client->subscriptions->whereNull('client_tariff_id');

            if ($subs->isEmpty()) {
                continue;
            }

            $totalDevices = (int) $subs->sum('install_limit');
            if ($totalDevices < 1) {
                $totalDevices = $subs->count();
            }

            // Подбираем тариф:
            // 1. По сумме устройств в элементах тарифа
            // 2. Иначе по количеству подписок
            // 3. Иначе первый попавшийся
            $matchedTariff = $tariffs->first(function ($t) use ($totalDevices) {
                return (int) $t->items->sum('device_limit') === $totalDevices;
            }) ?? $tariffs->first(function ($t) use ($subs) {
                return (int) $t->items->sum('device_limit') === $subs->count();
            }) ?? $tariffs->where('is_active', true)->sortByDesc(fn($t) => $t->items->sum('device_limit'))->first()
               ?? $tariffs->first();

            if (!$matchedTariff) {
                $this->warn("Для клиента #{$client->id} '{$client->name}' не найден ни один тариф в базе.");
                continue;
            }

            $maxExpiresAt = $subs->max('expires_at') ?? now()->addDays(30);

            $this->line(sprintf(
                'Клиент #%d %-25s | Подписок: %d (устройств: %d) ──> Тариф #%d «%s» (%s ₽) | Срок: %s',
                $client->id,
                "'{$client->name}'",
                $subs->count(),
                $totalDevices,
                $matchedTariff->id,
                $matchedTariff->name,
                (int) $matchedTariff->price,
                $maxExpiresAt->format('d.m.Y')
            ));

            if (!$isDryRun) {
                DB::transaction(function () use ($client, $matchedTariff, $maxExpiresAt, $subs) {
                    $clientTariff = ClientTariff::create([
                        'client_id'    => $client->id,
                        'tariff_id'    => $matchedTariff->id,
                        'custom_price' => null,
                        'expires_at'   => $maxExpiresAt,
                    ]);

                    foreach ($subs as $sub) {
                        $sub->update(['client_tariff_id' => $clientTariff->id]);
                    }
                });
            }

            $syncedCount++;
        }

        if ($isDryRun) {
            $this->warn("Готово. Найдено для объединения клиентов: {$syncedCount}. Запустите без флага --dry-run для применения.");
        } else {
            $this->info("✅ Успешно сформированы тарифы для клиентов: {$syncedCount}!");
        }

        return 0;
    }
}
