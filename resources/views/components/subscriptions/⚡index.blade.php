<?php

use App\Models\SubscriptionTemplate;
use Livewire\Component;
use App\Services\ClientService;
use App\Services\SubscriptionService;
use Livewire\Attributes\Computed;

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
    public function createDefaultSubscription()
    {
        $defaultTemplate = SubscriptionTemplate::where('is_active', true)->first();

        if (!$defaultTemplate) {
            $this->dispatch('notify', [
                'message' => 'Нет активных шаблонов подписок!',
                'type' => 'danger'
            ]);
            return;
        }

        $this->createFromTemplate($defaultTemplate->id);
    }

    /**
     * Создание подписки на основе выбранного шаблона
     */
    public function createFromTemplate($templateId)
    {
        $subscription = app(SubscriptionService::class)->createFromTemplate($this->clientId, $templateId);

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
            <!-- Кнопка 1: Создать по умолчанию (быстрое действие) -->
            <button wire:click="createDefaultSubscription"
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
                        <button class="dropdown-item"
                                wire:click="createFromTemplate({{ $template->id }}); open = false">
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

                                    <div class="custom-dark-divider"></div>

                                    <button class="dropdown-item text-danger"
                                            wire:click="deleteSubscription({{ $subscription->id }})"
                                            wire:confirm="Удалить подписку навсегда?">
                                        <i class="bi bi-trash me-2 text-danger"></i> Удалить
                                    </button>

                                    <button class="dropdown-item text-warning"
                                            wire:click="resetDevices({{ $subscription->id }})"
                                            wire:confirm="Обнулить привязку устройства для этой подписки?">
                                        <i class="bi bi-device-hdd-fill me-2 text-warning"></i> Сбросить устройство
                                    </button>
                                </div>
                            </div>
                        </div>

                        <div class="d-flex align-items-center gap-2 mb-1">
                            <h5 class="fw-bold text-dark m-0">{{ $subscription->name }}</h5>
                            <span
                                class="badge bg-secondary bg-opacity-10 text-secondary border border-secondary-subtle rounded-pill text-info"
                                title="ID подписки">
                                #{{ $subscription->id }}
                            </span>
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
    </script>
</div>
