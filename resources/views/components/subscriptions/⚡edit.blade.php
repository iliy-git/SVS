<?php
use Livewire\Component;
use App\Models\Subscription;

new class extends Component {
    public $clientId;
    public $subId;
    public $name;
    public $token;
    public $with_balancer;
    public $expires_at;

    public function mount($clientId, $subId) {
        $this->clientId = $clientId;
        $subscription = Subscription::findOrFail($subId);

        $this->subId = $subscription->id;
        $this->name = $subscription->name;
        $this->token = $subscription->token;
        $this->with_balancer = (bool)$subscription->with_balancer;

        $this->expires_at = $subscription->expires_at ? $subscription->expires_at->format('Y-m-d') : '';
    }

    public function save() {
        $this->validate([
            'name' => 'required|min:3',
            'token' => 'required|unique:subscriptions,token,' . $this->subId,
            'with_balancer' => 'boolean',

        ]);

        Subscription::find($this->subId)->update([
            'name' => $this->name,
            'token' => $this->token,
            'with_balancer' => $this->with_balancer,
            'expires_at' => $this->expires_at ?: null,
        ]);

        return $this->redirectRoute('subscriptions.index', ['clientId' => $this->clientId], navigate: true);
    }
}; ?>

<div class="row justify-content-center animate__animated animate__fadeIn">
    <div class="col-md-6">
        <div class="card border-0 shadow-lg rounded-4 overflow-hidden" style="background: #1a1d21;">
            <div class="card-body p-4 text-white">
                <div class="d-flex align-items-center mb-4">
                    <a href="{{ route('subscriptions.index', $clientId) }}" wire:navigate class="btn btn-dark rounded-circle me-3 shadow-sm" style="background: #2a2e34; border: none;">
                        <i class="bi bi-arrow-left text-white"></i>
                    </a>
                    <h4 class="fw-bold m-0">Редактирование тарифа</h4>
                </div>

                <form wire:submit.prevent="save">
                    <div class="mb-3">
                        <label class="form-label small fw-bold text-secondary text-uppercase">Название</label>
                        <input type="text" wire:model="name" class="form-control bg-dark border-0 text-white py-2 shadow-none custom-input" placeholder="Название тарифа">
                        @error('name') <small class="text-danger mt-1 d-block">{{ $message }}</small> @enderror
                    </div>

                    <div class="mb-4">
                        <label class="form-label small fw-bold text-secondary text-uppercase">Токен (Ключ)</label>
                        <input type="text" wire:model="token" class="form-control bg-dark border-0 text-white py-2 shadow-none font-monospace custom-input">
                        @error('token') <small class="text-danger mt-1 d-block">{{ $message }}</small> @enderror
                    </div>

                    <div class="balancer-card mb-4 p-3 d-flex align-items-center justify-content-between {{ $with_balancer ? 'active' : '' }}">
                        <div class="d-flex align-items-center">
                            <div class="icon-box me-3 d-flex align-items-center justify-content-center">
                                <i class="bi bi-shuffle fs-4 {{ $with_balancer ? 'text-primary' : 'text-secondary' }}"></i>
                            </div>
                            <div>
                                <div class="fw-bold small text-uppercase mb-0">Балансировщик (Auto)</div>
                                <div class="text-secondary" style="font-size: 11px;">Автовыбор лучшего сервера в JSON</div>
                            </div>
                        </div>
                        <div class="form-check m-0">
                            <input class="form-check-input custom-checkbox" type="checkbox" wire:model.live="with_balancer">
                        </div>
                    </div>
                    <div class="mb-4">
                        <label class="form-label small fw-bold text-secondary text-uppercase">Срок действия</label>
                        <div class="input-group">
                        <span class="input-group-text bg-dark border-0 text-secondary" style="border-radius: 10px 0 0 10px;">
                            <i class="bi bi-calendar-event"></i>
                        </span>
                            <input type="date" wire:model="expires_at"
                                   class="form-control bg-dark border-0 text-white py-2 shadow-none custom-input"
                                   style="border-radius: 0 10px 10px 0; color-scheme: dark;">
                        </div>
                        <div class="form-text text-muted" style="font-size: 11px;">
                            Оставьте пустым, если подписка <strong>вечная</strong>.
                        </div>
                    </div>

                    <div class="d-flex justify-content-between align-items-center pt-2">
                        <a href="{{ route('subscriptions.index', $clientId) }}" wire:navigate class="btn btn-link text-secondary text-decoration-none fw-bold p-0">
                            <i class="bi bi-x-lg me-1"></i>ОТМЕНА
                        </a>

                        <button type="submit" class="btn btn-primary px-5 py-2 fw-bold rounded-3 shadow-sm border-0">
                            <i class="bi bi-arrow-repeat me-2"></i>ОБНОВИТЬ
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<style>
    .balancer-card {
        background: rgba(255, 255, 255, 0.03);
        border: 1px solid rgba(255, 255, 255, 0.05);
        border-radius: 14px;
        transition: all 0.2s ease;
    }
    .balancer-card.active {
        background: rgba(13, 110, 253, 0.08);
        border-color: rgba(13, 110, 253, 0.3);
    }

    .custom-checkbox {
        width: 1.5rem !important;
        height: 1.5rem !important;
        background-color: rgba(255, 255, 255, 0.1) !important;
        border: 1px solid rgba(255, 255, 255, 0.2) !important;
        cursor: pointer;
        transition: all 0.2s ease;
    }

    .custom-checkbox:checked {
        background-color: #0d6efd !important;
        border-color: #0d6efd !important;
        box-shadow: 0 0 10px rgba(13, 110, 253, 0.3);
    }

    .custom-checkbox:focus {
        box-shadow: none !important;
        outline: none !important;
    }

    .icon-box {
        width: 45px;
        height: 45px;
        background: rgba(0, 0, 0, 0.2);
        border-radius: 10px;
    }
</style>
