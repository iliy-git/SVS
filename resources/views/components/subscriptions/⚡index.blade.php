<?php
use Livewire\Component;
use App\Models\Client;
use App\Models\Subscription;
use Livewire\Attributes\Computed;

new class extends Component {
    public $clientId;

    public function mount($clientId) {
        $this->clientId = $clientId;
    }

    #[Computed]
    public function client() {
        return Client::with('subscriptions')->findOrFail($this->clientId);
    }

    public function deleteSubscription($id) {
        $sub = Subscription::findOrFail($id);
        $sub->delete();
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

                                    <div class="lw-dropdown-divider"></div>

                                    <button class="lw-dropdown-item text-danger"
                                            wire:click="deleteSubscription({{ $subscription->id }})"
                                            wire:confirm="Удалить подписку навсегда?">
                                        <i class="bi bi-trash me-2"></i> Удалить
                                    </button>
                                </div>
                            </div>
                        </div>

                        <h5 class="fw-bold text-dark mb-1">{{ $subscription->name }}</h5>
                        <p class="text-muted small mb-3">Личный токен доступа</p>

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
