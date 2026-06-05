<?php
use Livewire\Component;
use App\Services\ClientService;
use App\Services\SubscriptionService;
use Livewire\Attributes\Computed;

new class extends Component {
    public $clientId;

    public function mount($clientId) {
        $this->clientId = $clientId;
    }

    /**
     * Ожидается фронтендом как $this->client
     */
    #[Computed]
    public function client() {
        // Используем сервис для получения клиента с подписками
        return app(ClientService::class)->findWithSubscriptions($this->clientId);
    }

    /**
     * Ожидается фронтендом внутри цикла @forelse
     */
    public function getHappUrl($subscriptionId) {
        // Используем сервис для получения ссылки
        // Если ссылки нет в БД, сервис её сгенерирует и вернет
        return app(SubscriptionService::class)->getHappUrl($subscriptionId);
    }

    /**
     * Ожидается фронтендом при клике на "Удалить"
     */
    public function deleteSubscription($id) {
        app(SubscriptionService::class)->deleteSubscription($id);

        // Сбрасываем кеш Computed-свойства, чтобы фронт обновил список
        unset($this->client);
    }

    /**
     * Ожидается фронтендом при клике на "Сбросить устройство"
     */
    public function resetDevices($id) {
        app(SubscriptionService::class)->resetDevice($id);

        unset($this->client);

        $this->dispatch('notify', [
            'message' => 'Устройства сброшены',
            'type' => 'success'
        ]);
    }

    public function extendSubscription($id, $days = 30) {
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
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-1">
                    <li class="breadcrumb-item"><a href="{{ route('clients.index') }}" wire:navigate>Клиенты</a></li>
                    <li class="breadcrumb-item active">{{ $this->client->name }}</li>
                </ol>
            </nav>
            <h2 class="fw-bold m-0">Управление подписками</h2>
        </div>
        <a href="{{ route('subscriptions.create', $clientId) }}" wire:navigate class="btn btn-primary px-4 shadow-sm fw-bold">
            <i class="bi bi-plus-circle me-2"></i>Добавить тариф
        </a>
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
                                     x-transition:enter="animate__animated animate__fadeIn animate__faster"
                                     class="lw-dropdown-menu"
                                     style="display: none;">

                                    <a class="lw-dropdown-item"
                                       href="{{ route('subscriptions.edit', [$clientId, $subscription->id]) }}"
                                       wire:navigate>
                                        <i class="bi bi-pencil me-2 text-primary"></i> Изменить
                                    </a>

                                    <button class="lw-dropdown-item text-success"
                                            wire:click="extendSubscription({{ $subscription->id }}, 30)">
                                        <i class="bi bi-calendar-check me-2"></i> Продлить на 30 дней
                                    </button>

                                    <button class="lw-dropdown-item text-info"
                                            onclick="extendCustom({{ $subscription->id }})">
                                        <i class="bi bi-calendar-event me-2"></i> Свой срок...
                                    </button>

                                    <script>
                                        function extendCustom(subId) {
                                            let days = prompt("Введите количество дней для продления:", "1");
                                            if (days !== null && days !== "" && !isNaN(days)) {
                                                // Вызываем метод Livewire через специальный синтаксис
                                            @this.call('extendSubscription', subId, parseInt(days));
                                            }
                                        }
                                    </script>

                                    <div class="lw-dropdown-divider"></div>

                                    <button class="lw-dropdown-item text-danger"
                                            wire:click="deleteSubscription({{ $subscription->id }})"
                                            wire:confirm="Удалить подписку навсегда?">
                                        <i class="bi bi-trash me-2"></i> Удалить
                                    </button>
                                    <button class="lw-dropdown-item text-warning"
                                            wire:click="resetDevices({{ $subscription->id }})"
                                            wire:confirm="Обнулить привязку устройства для этой подписки?">
                                        <i class="bi bi-device-hdd-fill me-2"></i> Сбросить устройство
                                    </button>
                                </div>
                            </div>
                        </div>

                        <div class="d-flex align-items-center gap-2 mb-1">
                            <h5 class="fw-bold text-dark m-0">{{ $subscription->name }}</h5>
                            <span class="badge bg-secondary bg-opacity-10 text-secondary border border-secondary-subtle rounded-pill text-info" title="ID подписки">
                                #{{ $subscription->id }}
                            </span>
                        </div>
                        <p class="text-muted small mt-3 mb-1">Личный токен доступа</p>

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
