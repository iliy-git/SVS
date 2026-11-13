<?php

use App\Models\SubscriptionTemplate;
use Livewire\Component;
use App\Services\ClientService;
use App\Services\SubscriptionService;
use Livewire\Attributes\Computed;
use App\Models\Subscription;

new class extends Component {
    public $clientId;

    public function mount($clientId)
    {
        $this->clientId = $clientId;
    }

    /**
     * Ожидается фронтендом как $this->client
     */
    #[Computed]
    public function client()
    {
        // Используем сервис для получения клиента с подписками
        return app(ClientService::class)->findWithSubscriptions($this->clientId);
    }

    #[Computed]
    public function templates()
    {
        return SubscriptionTemplate::where('is_active', true)->get();
    }

    /**
     * Ожидается фронтендом внутри цикла @forelse
     */
    public function getHappUrl($subscriptionId)
    {
        // Используем сервис для получения ссылки
        // Если ссылки нет в БД, сервис её сгенерирует и вернет
        return app(SubscriptionService::class)->getHappUrl($subscriptionId);
    }

    /**
     * Ожидается фронтендом при клике на "Удалить"
     */
    public function deleteSubscription($id)
    {
        app(SubscriptionService::class)->deleteSubscription($id);

        // Сбрасываем кеш Computed-свойства, чтобы фронт обновил список
        unset($this->client);
    }

    /**
     * Ожидается фронтендом при клике на "Сбросить устройство"
     */
    public function resetDevices($id)
    {
        app(SubscriptionService::class)->resetDevice($id);

        unset($this->client);

        $this->dispatch('notify', [
            'message' => 'Устройства сброшены',
            'type' => 'success'
        ]);
    }

    /**
     * Создание подписки по умолчанию (по первому активному шаблону)
     */
    public function createDefaultSubscription($installLimit = 1)
    {
        $defaultTemplate = SubscriptionTemplate::where('is_active', true)->first();

        if (!$defaultTemplate) {
            $this->dispatch('notify', [
                'message' => 'Нет активных шаблонов подписок!',
                'type' => 'danger'
            ]);
            return;
        }

        $this->createFromTemplate($defaultTemplate->id, $installLimit);
    }

    /**
     * Создание подписки на основе выбранного шаблона
     */
    public function createFromTemplate($templateId, $installLimit = 1)
    {
        $subscription = app(SubscriptionService::class)->createFromTemplate($this->clientId, $templateId, (int)$installLimit);

        unset($this->client);

        if ($subscription) {
            $this->dispatch('notify', [
                'message' => "Подписка «{$subscription->name}» успешно создана!",
                'type' => 'success'
            ]);
        } else {
            $this->dispatch('notify', [
                'message' => 'Ошибка при создании подписки по шаблону',
                'type' => 'danger'
            ]);
        }
    }

    /**
     * Ручная синхронизация подписки с шаблоном из интерфейса
     */
    public function syncSubscription($id)
    {
        $sub = \App\Models\Subscription::findOrFail($id);
        $changed = app(SubscriptionService::class)->syncWithTemplate($sub);

        unset($this->client);

        if ($changed) {
            $this->dispatch('notify', [
                'message' => "Подписка «{$sub->name}» успешно синхронизирована с шаблоном!",
                'type' => 'success'
            ]);
        } else {
            $this->dispatch('notify', [
                'message' => "Подписка «{$sub->name}» уже полностью соответствует актуальному шаблону",
                'type' => 'info'
            ]);
        }
    }

    public function extendSubscription($id, $days = 30)
    {
        $success = app(SubscriptionService::class)->extendSubscription($id, $days);

        unset($this->client);

        if ($success) {
            $this->dispatch('notify', [
                'message' => "Подписка продлена на {$days} дн.",
                'type' => 'success'
            ]);
        } else {
            $this->dispatch('notify', [
                'message' => 'Ошибка при продлении',
                'type' => 'danger'
            ]);
        }
    }
    public function attachAndSyncTemplate($subscriptionId, $templateId)
    {
        $sub = Subscription::findOrFail($subscriptionId);
        $changed = app(SubscriptionService::class)->attachAndSyncTemplate($sub, $templateId);

        unset($this->client);

        if ($changed) {
            $this->dispatch('notify', [
                'message' => "Подписка «{$sub->name}» успешно синхронизирована с шаблоном!",
                'type' => 'success'
            ]);
        } else {
            $this->dispatch('notify', [
                'message' => "Подписка «{$sub->name}» уже полностью соответствует актуальному шаблону",
                'type' => 'info'
            ]);
        }
    }
    public $showTariffModal = false;
    public $editingClientTariffId = null;
    public $formTariffId = '';
    public $formCustomPrice = '';
    public $formExpiresAt = '';
    public $formSelectedSubIds = [];

    #[Computed]
    public function availableTariffs()
    {
        return \App\Models\Tariff::where('is_active', true)->get();
    }

    public function openCreateTariffModal()
    {
        $this->editingClientTariffId = null;
        $this->formTariffId = $this->availableTariffs->first()?->id ?? '';
        $this->formCustomPrice = '';
        $maxExp = $this->client->subscriptions->max('expires_at') ?? now()->addDays(30);
        $this->formExpiresAt = $maxExp->format('Y-m-d');
        $unassigned = $this->client->subscriptions->whereNull('client_tariff_id');
        $toSelect = $unassigned->isNotEmpty() ? $unassigned : $this->client->subscriptions;
        $this->formSelectedSubIds = $toSelect->pluck('id')->map(fn($id) => (string)$id)->toArray();
        $this->showTariffModal = true;
    }

    public function openEditTariffModal($clientTariffId)
    {
        $ct = \App\Models\ClientTariff::with('subscriptions')->findOrFail($clientTariffId);
        $this->editingClientTariffId = $ct->id;
        $this->formTariffId = $ct->tariff_id;
        $this->formCustomPrice = $ct->custom_price ?? '';
        $this->formExpiresAt = $ct->expires_at ? $ct->expires_at->format('Y-m-d') : '';
        $this->formSelectedSubIds = $ct->subscriptions->pluck('id')->map(fn($id) => (string)$id)->toArray();
        $this->showTariffModal = true;
    }

    public function saveClientTariff()
    {
        $this->validate([
            'formTariffId'    => 'required|exists:tariffs,id',
            'formCustomPrice' => 'nullable|numeric|min:0',
            'formExpiresAt'   => 'nullable|date',
        ]);

        $tariff = \App\Models\Tariff::findOrFail($this->formTariffId);
        $expDate = $this->formExpiresAt ? \Carbon\Carbon::parse($this->formExpiresAt) : now()->addDays($tariff->duration_days ?: 30);
        $price = ($this->formCustomPrice !== '' && $this->formCustomPrice !== null) ? (float)$this->formCustomPrice : null;

        if ($this->editingClientTariffId) {
            $clientTariff = \App\Models\ClientTariff::findOrFail($this->editingClientTariffId);
            $clientTariff->update([
                'tariff_id'    => $this->formTariffId,
                'custom_price' => $price,
                'expires_at'   => $expDate,
            ]);

            \App\Models\Subscription::where('client_tariff_id', $clientTariff->id)
                ->whereNotIn('id', $this->formSelectedSubIds)
                ->update(['client_tariff_id' => null]);
        } else {
            $clientTariff = \App\Models\ClientTariff::create([
                'client_id'    => $this->clientId,
                'tariff_id'    => $this->formTariffId,
                'custom_price' => $price,
                'expires_at'   => $expDate,
            ]);
        }

        if (!empty($this->formSelectedSubIds)) {
            $subService = app(\App\Services\SubscriptionService::class);
            $selectedSubs = \App\Models\Subscription::whereIn('id', $this->formSelectedSubIds)->get();

            foreach ($selectedSubs as $sub) {
                $sub->update(['client_tariff_id' => $clientTariff->id]);
                $subService->setSubscriptionExpiry($sub, $expDate);
            }
        }

        $this->showTariffModal = false;
        unset($this->client);

        $this->dispatch('notify', [
            'message' => "Тариф «{$tariff->name}» успешно сохранен!",
            'type'    => 'success'
        ]);
    }

    public function deleteClientTariff($clientTariffId)
    {
        $ct = \App\Models\ClientTariff::findOrFail($clientTariffId);
        $ct->delete();
        unset($this->client);

        $this->dispatch('notify', [
            'message' => 'Тариф удален (подписки отвязаны)',
            'type'    => 'info'
        ]);
    }

    public function extendClientTariff($clientTariffId, $days = 30)
    {
        $ct = \App\Models\ClientTariff::with('subscriptions')->findOrFail($clientTariffId);
        $baseDate = ($ct->expires_at && $ct->expires_at->isFuture()) ? $ct->expires_at : now();
        $newExpiry = $baseDate->copy()->addDays($days);

        $ct->update(['expires_at' => $newExpiry]);

        $subService = app(\App\Services\SubscriptionService::class);
        foreach ($ct->subscriptions as $sub) {
            $subService->setSubscriptionExpiry($sub, $newExpiry);
        }

        unset($this->client);

        $this->dispatch('notify', [
            'message' => "Тариф, все входящие подписки и конфиги на нодах продлены на {$days} дн.!",
            'type'    => 'success'
        ]);
    }

    public $showTransferModal = false;
    public $transferSubId = null;
    public $transferTargetClientId = '';
    public $transferSearch = '';

    #[Computed]
    public function transferCandidates()
    {
        $query = \App\Models\Client::where('id', '!=', $this->clientId)
            ->withCount('subscriptions');

        if (!empty($this->transferSearch)) {
            $search = '%' . trim($this->transferSearch) . '%';
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', $search)
                  ->orWhere('telegram_id', 'like', $search)
                  ->orWhere('phone', 'like', $search);
            });
        }

        return $query->orderBy('name')->take(30)->get();
    }

    #[Computed]
    public function transferSubscriptionModel()
    {
        if (!$this->transferSubId) {
            return null;
        }
        return \App\Models\Subscription::with('clientTariff.tariff')->find($this->transferSubId);
    }

    public function openTransferModal($subscriptionId)
    {
        $this->transferSubId = $subscriptionId;
        $this->transferTargetClientId = '';
        $this->transferSearch = '';
        $this->showTransferModal = true;
    }

    public function transferSubscription()
    {
        $this->validate([
            'transferSubId'          => 'required|exists:subscriptions,id',
            'transferTargetClientId' => 'required|exists:clients,id|different:clientId',
        ], [
            'transferTargetClientId.required'  => 'Выберите нового владельца для подписки',
            'transferTargetClientId.different' => 'Нельзя передать подписку тому же клиенту',
        ]);

        $sub = \App\Models\Subscription::findOrFail($this->transferSubId);
        $targetClient = \App\Models\Client::findOrFail($this->transferTargetClientId);

        app(\App\Services\SubscriptionService::class)->transferSubscription(
            $this->transferSubId,
            (int) $this->transferTargetClientId
        );

        $this->showTransferModal = false;
        unset($this->client);
        unset($this->transferCandidates);
        unset($this->transferSubscriptionModel);

        $this->dispatch('notify', [
            'message' => "Подписка «{$sub->name}» успешно передана клиенту «{$targetClient->name}»!",
            'type'    => 'success'
        ]);
    }
}; ?>

<div class="animate__animated animate__fadeIn">
    <div x-data="{
            notifications: [],
            addNotification(detail) {
                // Корректно извлекаем данные для любой версии Livewire (v2/v3)
                let data = detail.message ? detail : (detail[0] ? detail[0] : detail);
                let id = Date.now();

                this.notifications.push({
                    id: id,
                    message: data.message || 'Операция выполнена',
                    type: data.type || 'success'
                });

                // Автоудаление через 3.5 секунды
                setTimeout(() => {
                    this.notifications = this.notifications.filter(n => n.id !== id);
                }, 3500);
            }
         }"
         @notify.window="addNotification($event.detail)"
         class="position-fixed bottom-0 end-0 p-3"
         style="z-index: 9999; max-width: 350px;">

        <template x-for="note in notifications" :key="note.id">
            <div x-transition:enter="transition ease-out duration-300 transform"
                 x-transition:enter-start="translate-y-3 opacity-0 scale-95"
                 x-transition:enter-end="translate-y-0 opacity-100 scale-100"
                 x-transition:leave="transition ease-in duration-200 transform opacity-0 scale-95"
                 class="card border-0 shadow-lg mb-2 overflow-hidden"
                 style="background: rgba(33, 37, 41, 0.95); backdrop-filter: blur(10px); border: 1px solid rgba(255,255,255,0.15) !important; border-radius: 12px;">
                <div class="card-body p-3 d-flex align-items-center gap-3">
                    <div class="rounded-circle p-2 d-flex align-items-center justify-content-center"
                         style="width: 32px; height: 32px;"
                         :class="note.type === 'success' ? 'bg-success bg-opacity-10 text-success' : 'bg-danger bg-opacity-10 text-danger'">
                        <i class="bi"
                           :class="note.type === 'success' ? 'bi-check-circle-fill' : 'bi-exclamation-circle-fill'"></i>
                    </div>
                    <div class="flex-grow-1 text-white small fw-medium" x-text="note.message"></div>
                    <button type="button" class="btn p-0 text-muted shadow-none border-0 original-scale"
                            @click="notifications = notifications.filter(n => n.id !== note.id)">
                        <i class="bi bi-x fs-5"></i>
                    </button>
                </div>
            </div>
        </template>
    </div>

    <style>
        .btn-press-animation {
            transition: transform 0.1s ease, background-color 0.2s ease;
        }

        .btn-press-animation:active {
            transform: scale(0.95) !important;
        }

        .custom-dark-dropdown {
            background: rgba(33, 37, 41, 0.95) !important;
            backdrop-filter: blur(8px);
            border: 1px solid rgba(255, 255, 255, 0.15) !important;
            border-radius: 12px !important;
            min-width: 210px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.5) !important;
        }

        .custom-dark-dropdown .dropdown-item {
            color: #dee2e6 !important;
            padding: 8px 14px;
            font-size: 0.9rem;
            transition: background 0.15s ease, color 0.15s ease;
            border-radius: 6px;
            margin: 2px 4px;
            width: auto;
            display: flex;
            align-items: center;
        }

        .custom-dark-dropdown .dropdown-item:hover {
            background: rgba(255, 255, 255, 0.1) !important;
            color: #fff !important;
        }

        .custom-dark-divider {
            border-top: 1px solid rgba(255, 255, 255, 0.1);
            margin: 6px 0;
        }

        .custom-url-copy input {
            border-top-left-radius: 8px !important;
            border-bottom-left-radius: 8px !important;
        }

        .custom-url-copy button, .custom-url-copy span {
            border-top-right-radius: 8px !important;
            border-bottom-right-radius: 8px !important;
        }
    </style>
    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-3">
        <div>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-1">
                    <li class="breadcrumb-item"><a href="{{ route('clients.index') }}" wire:navigate>Клиенты</a></li>
                    <li class="breadcrumb-item active">{{ $this->client->name }}</li>
                </ol>
            </nav>
            <h2 class="fw-bold m-0 text-white">Управление подписками</h2>
        </div>

        <div class="d-flex align-items-center gap-2">
            <!-- Кнопка 0: Сформировать тариф -->
            <button wire:click="openCreateTariffModal"
                    class="btn btn-warning fw-bold px-3 shadow-sm btn-press-animation text-dark">
                <i class="bi bi-box-seam me-1"></i> Сформировать тариф
            </button>

            <!-- Кнопка 1: Создать по умолчанию (быстрое действие) -->
            <button onclick="promptCreateDefault()"
                    wire:loading.attr="disabled"
                    class="btn btn-outline-success fw-bold px-3 shadow-sm btn-press-animation">
                <span wire:loading.remove wire:target="createDefaultSubscription">
                    <i class="bi bi-lightning-charge-fill me-1"></i> По умолчанию
                </span>
                <span wire:loading wire:target="createDefaultSubscription">
                    <span class="spinner-border spinner-border-sm me-1" role="status"></span> Создание...
                </span>
            </button>

            <!-- Кнопка 2: Создать по шаблону (выпадающее меню с шаблонами) -->
            <div class="dropdown" x-data="{ open: false }">
                <button @click="open = !open"
                        class="btn btn-outline-info fw-bold px-3 shadow-sm btn-press-animation dropdown-toggle">
                    <i class="bi bi-layers-fill me-1"></i> Выбрать шаблон
                </button>
                <div x-show="open"
                     @click.away="open = false"
                     class="dropdown-menu dropdown-menu-end show custom-dark-dropdown position-absolute mt-2"
                     style="display: none; right: 0; z-index: 1050;">
                    <div class="dropdown-header text-muted small text-uppercase px-3 py-2 fw-bold">Доступные шаблоны
                    </div>
                    @forelse($this->templates as $template)
                        <!-- ИЗМЕНЕНО: Вызываем JS-функцию -->
                        <button class="dropdown-item"
                                onclick="promptCreateTemplate({{ $template->id }}, '{{$template->name }}');"
                                @click="open = false">
                            <i class="bi bi-file-earmark-check me-2 text-info"></i> {{ $template->name }}
                        </button>
                    @empty
                        <div class="px-3 py-2 text-muted small">Нет активных шаблонов</div>
                    @endforelse
                </div>
            </div>
            <!-- Кнопка 3: Кастомная форма добавления -->
            <a href="{{ route('subscriptions.create', $clientId) }}" wire:navigate
               class="btn btn-primary px-3 shadow-sm fw-bold btn-press-animation">
                <i class="bi bi-plus-circle me-1"></i> Ручной тариф
            </a>
        </div>
    </div>

    {{-- ТАРИФНЫЕ ПАКЕТЫ КЛИЕНТА --}}
    @if($this->client->clientTariffs && $this->client->clientTariffs->count() > 0)
        <div class="mb-4">
            <h5 class="text-white fw-bold mb-3 d-flex align-items-center gap-2">
                <i class="bi bi-collection-fill text-warning"></i>
                Тарифные пакеты клиента
                <span class="badge bg-warning bg-opacity-10 text-warning border border-warning border-opacity-25 rounded-pill fs-6 fw-normal">
                    {{ $this->client->clientTariffs->count() }}
                </span>
            </h5>

            <div class="row g-3">
                @foreach($this->client->clientTariffs as $ct)
                    <div class="col-md-6 col-xl-4" wire:key="ct-{{ $ct->id }}">
                        <div class="card border-0 shadow-sm rounded-4 h-100" style="background: #1a1d21; border: 1px solid rgba(255,255,255,0.08) !important;">
                            <div class="card-body p-4 text-white d-flex flex-column justify-content-between">
                                <div>
                                    <div class="d-flex justify-content-between align-items-start mb-2">
                                        <h5 class="fw-bold mb-0 text-white">
                                            {{ $ct->tariff?->name ?? 'Тариф #' . $ct->id }}
                                        </h5>
                                        <span class="badge {{ $ct->is_custom_price ? 'bg-warning bg-opacity-25 text-warning' : 'bg-primary bg-opacity-25 text-primary' }} border-0 px-2 py-1 rounded-2">
                                            {{ (int)$ct->price }} ₽{{ $ct->is_custom_price ? ' (персон.)' : '' }}
                                        </span>
                                    </div>

                                    @if($ct->tariff?->description)
                                        <div class="text-muted small mb-2">{{ $ct->tariff->description }}</div>
                                    @endif

                                    <div class="d-flex align-items-center gap-2 small mb-3">
                                        <i class="bi bi-calendar-event text-secondary"></i>
                                        <span class="text-secondary">Срок:</span>
                                        <span class="fw-bold {{ ($ct->expires_at && $ct->expires_at->isPast()) ? 'text-danger' : 'text-success' }}">
                                            {{ $ct->expires_at ? $ct->expires_at->format('d.m.Y') : 'Бессрочно' }}
                                        </span>
                                        @if($ct->expires_at && $ct->expires_at->isFuture())
                                            <span class="text-muted small">({{ (int)now()->diffInDays($ct->expires_at, false) }} дн.)</span>
                                        @endif
                                    </div>

                                    <div class="border-top border-white border-opacity-5 pt-2 mb-3">
                                        <div class="small text-muted mb-1 text-uppercase fw-bold" style="font-size: 11px;">Входящие подписки ({{ $ct->subscriptions->count() }}):</div>
                                        <div class="d-flex flex-wrap gap-1">
                                            @forelse($ct->subscriptions as $s)
                                                <span class="badge bg-dark border border-white border-opacity-10 text-white rounded-pill px-2 py-1" style="font-size: 11px;">
                                                    <i class="bi bi-key-fill text-primary me-1"></i>{{ $s->name }} ({{ $s->install_limit }} устр.)
                                                </span>
                                            @empty
                                                <span class="text-muted small fst-italic">Подписки не прикреплены</span>
                                            @endforelse
                                        </div>
                                    </div>
                                </div>

                                <div class="d-flex justify-content-between align-items-center border-top border-white border-opacity-5 pt-3">
                                    <button wire:click="extendClientTariff({{ $ct->id }}, 30)"
                                            class="btn btn-sm btn-outline-success rounded-3 fw-bold"
                                            title="Продлить тариф и все подписки на 30 дней">
                                        <i class="bi bi-arrow-repeat me-1"></i> +30 дней
                                    </button>

                                    <div class="btn-group gap-1">
                                        <button wire:click="openEditTariffModal({{ $ct->id }})"
                                                class="btn btn-sm btn-dark border-0 rounded-2"
                                                title="Редактировать состав тарифа">
                                            <i class="bi bi-pencil-square text-primary"></i>
                                        </button>
                                        <button wire:click="deleteClientTariff({{ $ct->id }})"
                                                wire:confirm="Удалить этот тариф клиента? Подписки не будут удалены, только отвязаны."
                                                class="btn btn-sm btn-dark border-0 rounded-2"
                                                title="Удалить тариф">
                                            <i class="bi bi-trash3 text-danger"></i>
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    <div class="row g-3">
        @forelse($this->client->subscriptions as $subscription)
            <div class="col-md-6 col-lg-4" wire:key="sub-{{ $subscription->id }}">
                <div class="card border-0 shadow-sm h-100 rounded-4">
                    <div class="card-body p-4">
                        <div class="d-flex justify-content-between align-items-start mb-3">
                            <div class="bg-primary bg-opacity-10 p-2 rounded-3 text-primary">
                                <i class="bi bi-key-fill fs-4"></i>
                            </div>
                            <div class="lw-dropdown" x-data="{ open: false }">
                                <button @click="open = !open" class="btn btn-link text-muted p-1 shadow-none">
                                    <i class="bi bi-three-dots-vertical fs-5"></i>
                                </button>


                                <div x-show="open"
                                     @click.away="open = false"
                                     x-transition:enter="transition ease-out duration-150"
                                     x-transition:enter-start="opacity-0 transform scale-95 translate-y-[-10px]"
                                     x-transition:enter-end="opacity-100 transform scale-100 translate-y-0"
                                     x-transition:leave="transition ease-in duration-100"
                                     x-transition:leave-start="opacity-100 transform scale-100 translate-y-0"
                                     x-transition:leave-end="opacity-0 transform scale-95 translate-y-[-10px]"
                                     class="dropdown-menu dropdown-menu-end show custom-dark-dropdown position-absolute"
                                     style="display: none; right: 0; top: 100%; z-index: 1050;">

                                    <a class="dropdown-item"
                                       href="{{ route('subscriptions.edit', [$clientId, $subscription->id]) }}"
                                       wire:navigate>
                                        <i class="bi bi-pencil me-2 text-primary"></i> Изменить
                                    </a>

                                    <button class="dropdown-item text-success"
                                            wire:click="extendSubscription({{ $subscription->id }}, 30); open = false">
                                        <i class="bi bi-calendar-check me-2 text-success"></i> Продлить на 30 дней
                                    </button>

                                    <button class="dropdown-item text-info"
                                            @click="extendCustom({{ $subscription->id }}); open = false">
                                        <i class="bi bi-calendar-event me-2 text-info"></i> Свой срок...
                                    </button>

                                    @if($subscription->template_id)
                                        <button class="dropdown-item text-primary"
                                                wire:click="syncSubscription({{ $subscription->id }}); open = false">
                                            <i class="bi bi-arrow-repeat me-2 text-primary"></i> Синхронизировать с шаблоном
                                        </button>
                                    @else
                                        <div class="dropdown-header text-muted small text-uppercase px-3 py-1">Сменить/привязать шаблон</div>
                                        @foreach($this->templates as $tpl)
                                            <button class="dropdown-item text-info"
                                                    wire:click="attachAndSyncTemplate({{ $subscription->id }}, {{ $tpl->id }}); open = false"
                                                    wire:confirm="Все текущие конфиги подписки будут удалены и созданы заново по шаблону. Продолжить?">
                                                <i class="bi bi-link-45deg me-2 text-info"></i> {{ $tpl->name }}
                                            </button>
                                        @endforeach
                                    @endif

                                    <div class="custom-dark-divider"></div>

                                    <button class="dropdown-item text-danger"
                                            wire:click="deleteSubscription({{ $subscription->id }})"
                                            wire:confirm="Удалить подписку навсегда?">
                                        <i class="bi bi-trash me-2 text-danger"></i> Удалить
                                    </button>

                                    <button class="dropdown-item text-warning"
                                            wire:click="openTransferModal({{ $subscription->id }}); open = false">
                                        <i class="bi bi-arrow-left-right me-2 text-warning"></i> Передать клиенту
                                    </button>

                                    <button class="dropdown-item text-warning"
                                            wire:click="resetDevices({{ $subscription->id }})"
                                            wire:confirm="Обнулить привязку устройства для этой подписки?">
                                        <i class="bi bi-device-hdd-fill me-2 text-warning"></i> Сбросить устройство
                                    </button>
                                </div>
                            </div>
                        </div>

                        <div class="d-flex align-items-center gap-2 mb-1 flex-wrap">
                            <h5 class="fw-bold text-dark m-0">{{ $subscription->name }}</h5>
                            <span
                                class="badge bg-secondary bg-opacity-10 text-secondary border border-secondary-subtle rounded-pill text-info"
                                title="ID подписки">
                                #{{ $subscription->id }}
                            </span>
                            @if($subscription->template)
                                <span class="badge bg-primary bg-opacity-10 text-primary border border-primary-subtle rounded-pill"
                                      title="Создано по шаблону «{{ $subscription->template->name }}»">
                                    <i class="bi bi-file-earmark-check me-1"></i>{{ $subscription->template->name }}
                                </span>
                            @endif
                            @if($subscription->clientTariff)
                                <span class="badge bg-success bg-opacity-10 text-success border border-success-subtle rounded-pill"
                                      title="Входит в тариф «{{ $subscription->clientTariff->tariff?->name }}»">
                                    <i class="bi bi-box-seam me-1"></i>{{ $subscription->clientTariff->tariff?->name }}
                                </span>
                            @else
                                <span class="badge bg-secondary bg-opacity-10 text-secondary border border-secondary-subtle rounded-pill"
                                      title="Не привязана к тарифу">
                                    <i class="bi bi-dash-circle me-1"></i>Без тарифа
                                </span>
                            @endif
                        </div>
                        <p class="text-muted small mt-3 mb-1">Ссылка подписки пользователя</p>

                        <div class="input-group input-group-sm">
                            @php
                                $fullSubscriptionUrl = route('subscription.raw', ['token' => $subscription->token]);
                            @endphp
                            <div class="input-group input-group-sm custom-url-copy">
                                <input type="text"
                                       class="form-control bg-dark text-info border-secondary border-opacity-25 font-monospace"
                                       value="{{ $fullSubscriptionUrl }}"
                                       readonly>
                                <button class="btn btn-secondary border-0"
                                        onclick="copyToClipboard('{{ $fullSubscriptionUrl }}', this)"
                                        title="Копировать ссылку">
                                    <i class="bi bi-link-45deg"></i>
                                </button>
                            </div>
                        </div>
                        <p class="text-muted small mt-3 mb-1">Защищенная ссылка (Happ)</p>
                        <div class="input-group input-group-sm">
                            @php
                                $happUrl = $this->getHappUrl($subscription->id);
                            @endphp
                            <div class="input-group input-group-sm custom-url-copy">
                                <span class="input-group-text bg-dark border-secondary border-opacity-25 text-warning">
                                    <i class="bi bi-shield-lock-fill"></i>
                                </span>
                                <input type="text"
                                       class="form-control bg-dark text-info border-secondary border-opacity-25 font-monospace"
                                       value="{{ $happUrl }}"
                                       readonly>
                                <button class="btn btn-secondary border-0"
                                        onclick="copyToClipboard('{{ $happUrl }}', this)"
                                        title="Копировать Happ ссылку">
                                    <i class="bi bi-link-45deg"></i>
                                </button>
                            </div>
                        </div>
                        <p class="text-muted small mt-3 mb-1">Ссылка на подключение</p>
                        <div class="input-group input-group-sm">
                            @php
                                $fullSubscriptionUrl = route('subscription.page', ['token' => $subscription->token]);
                            @endphp
                            <div class="input-group input-group-sm custom-url-copy">
                                <input type="text"
                                       class="form-control bg-dark text-info border-secondary border-opacity-25 font-monospace"
                                       value="{{ $fullSubscriptionUrl }}"
                                       readonly>
                                <button class="btn btn-secondary border-0"
                                        onclick="copyToClipboard('{{ $fullSubscriptionUrl }}', this)"
                                        title="Копировать ссылку">
                                    <i class="bi bi-link-45deg"></i>
                                </button>
                            </div>
                        </div>
                        <a href="{{ route('configs.index', [$clientId, $subscription->id]) }}"
                           wire:navigate
                           class="btn btn-dark btn-sm w-100 mt-2 rounded-3">
                            <i class="bi bi-gear-fill me-2"></i> Настроить конфиги
                        </a>
                    </div>
                </div>
            </div>
        @empty
            <div class="col-12">
                <div class="text-center py-5 bg-dark rounded-4 border-dashed">
                    <i class="bi bi-shield-slash display-1 text-muted opacity-25"></i>
                    <p class="text-muted mt-3">У этого клиента пока нет активных подписок</p>
                </div>
            </div>
        @endforelse
    </div>
    <script>
        function extendCustom(subId) {
            let days = prompt("Введите количество дней для продления:", "1");
            if (days !== null && days !== "" && !isNaN(days)) {
            @this.call('extendSubscription', subId, parseInt(days))
                ;
            }
        }

        function copyToClipboard(text, btn) {
            navigator.clipboard.writeText(text).then(() => {
                const icon = btn.querySelector('i');
                const originalClass = icon.className;
                icon.className = 'bi bi-check2 text-success';
                setTimeout(() => {
                    icon.className = originalClass;
                }, 1500);
            });
        }
        function promptCreateDefault() {
            let limit = prompt("Укажите лимит устройств для этой подписки:", "1");
            if (limit !== null && limit !== "" && !isNaN(limit)) {
                @this.call('createDefaultSubscription', parseInt(limit));
            }
        }

        function promptCreateTemplate(templateId, templateName) {
            let limit = prompt(`Создание по шаблону «${templateName}».\nУкажите лимит устройств:`, "1");
            if (limit !== null && limit !== "" && !isNaN(limit)) {
                @this.call('createFromTemplate', templateId, parseInt(limit));
            }
        }
    </script>

    {{-- МОДАЛЬНОЕ ОКНО: СФОРМИРОВАТЬ ТАРИФ --}}
    @if($showTariffModal)
        <div class="modal fade show d-block" tabindex="-1" style="background: rgba(0, 0, 0, 0.7); backdrop-filter: blur(5px); z-index: 9999;">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content border-0 shadow-lg rounded-4 overflow-hidden" style="background: #1a1d21; border: 1px solid rgba(255,255,255,0.1) !important;">
                    <div class="modal-header border-0 p-4 pb-2">
                        <h5 class="modal-title fw-bold text-white">
                            <i class="bi bi-box-seam text-warning me-2"></i>
                            {{ $editingClientTariffId ? 'Редактировать тариф клиента' : 'Сформировать тариф для клиента' }}
                        </h5>
                        <button type="button" wire:click="$set('showTariffModal', false)" class="btn-close btn-close-white shadow-none"></button>
                    </div>

                    <form wire:submit.prevent="saveClientTariff">
                        <div class="modal-body p-4 text-white">
                            <div class="mb-3">
                                <label class="form-label small fw-bold text-secondary text-uppercase">Выберите тариф</label>
                                <select wire:model.live="formTariffId" class="form-select bg-dark border-0 text-white py-2 shadow-none custom-input">
                                    <option value="">-- Выберите тариф --</option>
                                    @foreach($this->availableTariffs as $t)
                                        <option value="{{ $t->id }}">
                                            {{ $t->name }} ({{ (int)$t->price }} ₽ / {{ $t->duration_days }} дн.) {{ !$t->is_public ? '[Скрытый]' : '' }}
                                        </option>
                                    @endforeach
                                </select>
                                @error('formTariffId') <small class="text-danger mt-1 d-block">{{ $message }}</small> @enderror
                            </div>

                            <div class="row mb-3">
                                <div class="col-6">
                                    <label class="form-label small fw-bold text-secondary text-uppercase">Персональная цена (₽)</label>
                                    <input type="number" step="0.01" min="0" wire:model="formCustomPrice"
                                           class="form-control bg-dark border-0 text-white py-2 shadow-none custom-input"
                                           placeholder="По тарифу">
                                    <div class="form-text text-muted" style="font-size: 11px;">Пусто = цена тарифа</div>
                                    @error('formCustomPrice') <small class="text-danger mt-1 d-block">{{ $message }}</small> @enderror
                                </div>
                                <div class="col-6">
                                    <label class="form-label small fw-bold text-secondary text-uppercase">Срок действия</label>
                                    <input type="date" wire:model="formExpiresAt"
                                           class="form-control bg-dark border-0 text-white py-2 shadow-none custom-input"
                                           style="color-scheme: dark;">
                                    @error('formExpiresAt') <small class="text-danger mt-1 d-block">{{ $message }}</small> @enderror
                                </div>
                            </div>

                            <div class="mb-2">
                                <label class="form-label small fw-bold text-secondary text-uppercase mb-2">
                                    Входящие подписки (отметьте нужные)
                                </label>
                                <div class="bg-dark rounded-3 p-3 border border-white border-opacity-5" style="max-height: 220px; overflow-y: auto;">
                                    @forelse($this->client->subscriptions as $sub)
                                        <div class="form-check mb-2 d-flex align-items-center justify-content-between" wire:key="f-sub-{{ $sub->id }}">
                                            <div>
                                                <input class="form-check-input" type="checkbox"
                                                       value="{{ $sub->id }}"
                                                       wire:model="formSelectedSubIds"
                                                       id="subCheck{{ $sub->id }}">
                                                <label class="form-check-label ms-2 text-white small" for="subCheck{{ $sub->id }}">
                                                    <strong>{{ $sub->name }}</strong>
                                                    <span class="text-muted">({{ $sub->install_limit }} устр.)</span>
                                                </label>
                                            </div>
                                            @if($sub->client_tariff_id && $sub->client_tariff_id != $editingClientTariffId)
                                                <span class="badge bg-secondary bg-opacity-25 text-muted" style="font-size: 10px;">В др. тарифе</span>
                                            @endif
                                        </div>
                                    @empty
                                        <div class="text-muted small">У клиента нет подписок для добавления</div>
                                    @endforelse
                                </div>
                            </div>
                        </div>

                        <div class="modal-footer border-0 p-4 pt-0 d-flex justify-content-between">
                            <button type="button" wire:click="$set('showTariffModal', false)" class="btn btn-outline-secondary px-4 border-0">
                                Отмена
                            </button>
                            <button type="submit" class="btn btn-warning px-4 fw-bold text-dark">
                                <i class="bi bi-check-lg me-1"></i> Сохранить тариф
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    @endif

    {{-- МОДАЛЬНОЕ ОКНО: ПЕРЕДАТЬ ПОДПИСКУ ДРУГОМУ КЛИЕНТУ --}}
    @if($showTransferModal && $this->transferSubscriptionModel)
        <div class="modal fade show d-block" tabindex="-1" style="background: rgba(0, 0, 0, 0.7); backdrop-filter: blur(5px); z-index: 9999;">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content border-0 shadow-lg rounded-4 overflow-hidden" style="background: #1a1d21; border: 1px solid rgba(255,255,255,0.1) !important;">
                    <div class="modal-header border-0 p-4 pb-2">
                        <h5 class="modal-title fw-bold text-white d-flex align-items-center gap-2">
                            <i class="bi bi-arrow-left-right text-warning"></i>
                            Передача подписки клиенту
                        </h5>
                        <button type="button" wire:click="$set('showTransferModal', false)" class="btn-close btn-close-white shadow-none"></button>
                    </div>

                    <form wire:submit.prevent="transferSubscription">
                        <div class="modal-body p-4 text-white">
                            <!-- Карточка передаваемой подписки -->
                            <div class="p-3 rounded-3 mb-3" style="background: rgba(255, 255, 255, 0.04); border: 1px solid rgba(255, 255, 255, 0.08);">
                                <div class="d-flex justify-content-between align-items-center mb-1">
                                    <span class="fw-bold text-white">{{ $this->transferSubscriptionModel->name }}</span>
                                    <span class="badge bg-primary bg-opacity-25 text-primary border-0">#{{ $this->transferSubscriptionModel->id }}</span>
                                </div>
                                <div class="small text-muted d-flex gap-3">
                                    <span><i class="bi bi-phone me-1"></i>{{ $this->transferSubscriptionModel->install_limit }} устр.</span>
                                    <span><i class="bi bi-calendar-event me-1"></i>{{ $this->transferSubscriptionModel->expires_at ? $this->transferSubscriptionModel->expires_at->format('d.m.Y') : 'Бессрочно' }}</span>
                                </div>
                                @if($this->transferSubscriptionModel->clientTariff)
                                    <div class="mt-2 text-warning small" style="font-size: 11px;">
                                        <i class="bi bi-exclamation-triangle-fill me-1"></i>
                                        Входит в тариф «{{ $this->transferSubscriptionModel->clientTariff->tariff?->name }}». При передаче подписка будет отвязана от этого тарифа.
                                    </div>
                                @endif
                            </div>

                            <!-- Поиск клиента -->
                            <div class="mb-3">
                                <label class="form-label small fw-bold text-secondary text-uppercase">Поиск нового владельца</label>
                                <div class="input-group">
                                    <span class="input-group-text bg-dark border-0 text-secondary">
                                        <i class="bi bi-search"></i>
                                    </span>
                                    <input type="text"
                                           wire:model.live.debounce.250ms="transferSearch"
                                           class="form-control bg-dark border-0 text-white py-2 shadow-none custom-input"
                                           placeholder="Имя, Telegram ID или телефон...">
                                </div>
                            </div>

                            <!-- Список кандидатов -->
                            <div class="mb-3">
                                <label class="form-label small fw-bold text-secondary text-uppercase">
                                    Выберите получателя ({{ $this->transferCandidates->count() }})
                                </label>
                                <div class="list-group custom-dark-list overflow-auto" style="max-height: 220px;">
                                    @forelse($this->transferCandidates as $candidate)
                                        <label class="list-group-item list-group-item-action bg-dark border-0 text-white d-flex justify-content-between align-items-center p-2 px-3 rounded-3 mb-1 cursor-pointer {{ $transferTargetClientId == $candidate->id ? 'border border-warning border-opacity-50 bg-secondary bg-opacity-25' : '' }}">
                                            <div class="d-flex align-items-center gap-2">
                                                <input class="form-check-input mt-0"
                                                       type="radio"
                                                       wire:model.live="transferTargetClientId"
                                                       value="{{ $candidate->id }}">
                                                <div>
                                                    <div class="fw-bold">{{ $candidate->name }}</div>
                                                    <div class="small text-muted" style="font-size: 11px;">
                                                        @if($candidate->telegram_id) TG: {{ $candidate->telegram_id }} @endif
                                                        @if($candidate->phone) • {{ $candidate->phone }} @endif
                                                    </div>
                                                </div>
                                            </div>
                                            <span class="badge bg-secondary bg-opacity-25 text-muted" style="font-size: 11px;">
                                                {{ $candidate->subscriptions_count }} подп.
                                            </span>
                                        </label>
                                    @empty
                                        <div class="text-muted small text-center py-3">
                                            Клиенты не найдены
                                        </div>
                                    @endforelse
                                </div>
                                @error('transferTargetClientId')
                                    <small class="text-danger mt-1 d-block">{{ $message }}</small>
                                @enderror
                            </div>
                        </div>

                        <div class="modal-footer border-0 p-4 pt-0 d-flex justify-content-between">
                            <button type="button" wire:click="$set('showTransferModal', false)" class="btn btn-outline-secondary px-4 border-0">
                                Отмена
                            </button>
                            <button type="submit"
                                    class="btn btn-warning px-4 fw-bold text-dark"
                                    @if(!$transferTargetClientId) disabled @endif>
                                <i class="bi bi-arrow-left-right me-1"></i> Передать подписку
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    @endif
</div>
