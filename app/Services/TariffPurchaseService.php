<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Client;
use App\Models\Subscription;
use App\Models\SubscriptionTemplate;
use App\Models\Tariff;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Service: TariffPurchaseService
 *
 * Обрабатывает покупку тарифа со стороны Telegram-бота на панели (LWproject):
 *  1. Находит или создаёт клиента по telegram_id.
 *  2. Создаёт подписки согласно тарифу (TariffItem → SubscriptionTemplate).
 *     Либо, если передан fallback-тариф (напр. «1_sub», «2_sub»), создаёт подписки по активному шаблону.
 *  3. Возвращает созданные подписки и данные клиента.
 */
class TariffPurchaseService
{
    public function __construct(
        protected SubscriptionService $subscriptionService,
    ) {}

    /**
     * Основной метод: покупка тарифа пользователем Telegram.
     *
     * @param  int|string $telegramId  Telegram ID пользователя.
     * @param  int|string $tariffId    ID тарифа (числовой из таблицы tariffs или строковый «1_sub»...).
     * @param  int        $subsCount   Количество подписок (если пришло из запроса бота).
     * @param  int|null   $days        Срок действия в днях (если не указано, берётся из тарифа или 30).
     * @param  float|null $price       Стоимость покупки (USDT / RUB).
     * @return array{
     *     success: bool,
     *     client: Client|null,
     *     subscriptions: Subscription[],
     *     message?: string,
     *     errors: string[],
     * }
     */
    public function purchase(
        int|string $telegramId,
        int|string $tariffId,
        int        $subsCount = 1,
        ?int       $days = null,
        ?float     $price = null,
    ): array {
        $client = $this->resolveClient($telegramId);

        /** @var Tariff|null $tariff */
        $tariff = is_numeric($tariffId)
            ? Tariff::with(['items.template'])->find((int) $tariffId)
            : Tariff::with(['items.template'])->where('id', $tariffId)->first();

        if ($tariff && $tariff->is_active && $tariff->items->isNotEmpty()) {
            return $this->purchaseByDatabaseTariff($client, $tariff, $days);
        }

        // Если тариф в БД не найден или не содержит элементов — используем активный шаблон как fallback
        return $this->purchaseByTemplateFallback(
            client:    $client,
            subsCount: max(1, $subsCount),
            days:      $days ?: ($tariff?->duration_days ?: 30),
            tariffId:  (string) $tariffId,
        );
    }

    /**
     * Создание подписок на основе тарифа из БД с настроенными TariffItem.
     */
    protected function purchaseByDatabaseTariff(Client $client, Tariff $tariff, ?int $days): array
    {
        $periodDays    = $days ?: ($tariff->duration_days ?: 30);
        $subscriptions = [];
        $errors        = [];

        DB::transaction(function () use ($client, $tariff, $periodDays, &$subscriptions, &$errors): void {
            foreach ($tariff->items as $item) {
                $template = $item->template;

                if (!$template || !$template->is_active) {
                    $errors[] = "Шаблон #{$item->template_id} не найден или неактивен, пропускаем.";
                    Log::warning('[TariffPurchase] Пропуск неактивного/отсутствующего шаблона', [
                        'tariff_id'   => $tariff->id,
                        'template_id' => $item->template_id,
                    ]);
                    continue;
                }

                try {
                    $subscription = $this->subscriptionService->createFromTemplate(
                        clientId:     $client->id,
                        templateId:   $template->id,
                        installLimit: $item->device_limit ?? 1,
                        days:         $periodDays,
                    );

                    if (!$subscription) {
                        $errors[] = "Не удалось создать подписку по шаблону «{$template->name}».";
                        continue;
                    }

                    if (!empty($item->custom_name)) {
                        $subscription->name = $item->custom_name;
                        $subscription->save();
                    }

                    $subscription->loadMissing(['configs', 'devices']);
                    $subscriptions[] = $subscription;

                    Log::info('[TariffPurchase] Подписка создана из тарифа', [
                        'subscription_id' => $subscription->id,
                        'template_id'     => $template->id,
                        'client_id'       => $client->id,
                        'tariff_id'       => $tariff->id,
                    ]);
                } catch (\Throwable $e) {
                    $errors[] = "Ошибка при создании подписки: {$e->getMessage()}";
                    Log::error('[TariffPurchase] Ошибка создания подписки', [
                        'tariff_id'   => $tariff->id,
                        'template_id' => $template->id,
                        'client_id'   => $client->id,
                        'error'       => $e->getMessage(),
                    ]);
                }
            }
        });

        if (empty($subscriptions)) {
            return $this->failure(
                !empty($errors) ? implode('; ', $errors) : 'Не удалось создать подписки для тарифа.',
                $client,
                $errors
            );
        }

        return [
            'success'       => true,
            'client'        => $client,
            'subscriptions' => $subscriptions,
            'errors'        => $errors,
        ];
    }

    /**
     * Создание подписок по первому активному шаблону (fallback сценарий).
     */
    protected function purchaseByTemplateFallback(Client $client, int $subsCount, int $days, string $tariffId): array
    {
        $template = SubscriptionTemplate::where('is_active', true)->first();

        if (!$template) {
            return $this->failure('На панели не найдено ни одного активного шаблона подписки.', $client);
        }

        $subscriptions = [];
        $errors        = [];

        DB::transaction(function () use ($client, $template, $subsCount, $days, &$subscriptions, &$errors): void {
            for ($i = 0; $i < $subsCount; $i++) {
                try {
                    $subscription = $this->subscriptionService->createFromTemplate(
                        clientId:     $client->id,
                        templateId:   $template->id,
                        installLimit: 1,
                        days:         $days,
                    );

                    if ($subscription) {
                        $subscription->loadMissing(['configs', 'devices']);
                        $subscriptions[] = $subscription;
                    } else {
                        $errors[] = "Не удалось создать подписку #{$i} по шаблону «{$template->name}».";
                    }
                } catch (\Throwable $e) {
                    $errors[] = "Ошибка создания подписки #{$i}: {$e->getMessage()}";
                    Log::error('[TariffPurchase] Fallback ошибка создания подписки', [
                        'template_id' => $template->id,
                        'iteration'   => $i,
                        'error'       => $e->getMessage(),
                    ]);
                }
            }
        });

        if (empty($subscriptions)) {
            return $this->failure(
                !empty($errors) ? implode('; ', $errors) : 'Не удалось создать подписки.',
                $client,
                $errors
            );
        }

        return [
            'success'       => true,
            'client'        => $client,
            'subscriptions' => $subscriptions,
            'errors'        => $errors,
        ];
    }

    /**
     * Находит или создаёт клиента по telegram_id.
     */
    protected function resolveClient(int|string $telegramId): Client
    {
        return Client::firstOrCreate(
            ['telegram_id' => (string) $telegramId],
            [
                'name'            => (string) $telegramId,
                'additional_info' => 'Зарегистрирован через Telegram Bot',
            ]
        );
    }

    /**
     * Стандартный ответ об ошибке.
     */
    protected function failure(string $message, ?Client $client = null, array $errors = []): array
    {
        Log::error('[TariffPurchase] ' . $message);

        return [
            'success'       => false,
            'message'       => $message,
            'client'        => $client,
            'subscriptions' => [],
            'errors'        => !empty($errors) ? $errors : [$message],
        ];
    }
}
