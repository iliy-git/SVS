<?php
use Livewire\Component;
use App\Models\Subscription;
use App\Models\Client;

new class extends Component {
    public $clientId;
    public $name = '';
    public $token = '';
    public $with_balancer = true;
    public $expires_at;

    public function mount($clientId) {
        $this->clientId = $clientId;
        $this->generateToken();
    }

    public function generateToken() {
        $this->token = bin2hex(random_bytes(16));
    }

    public function save() {
        $this->validate([
            'name' => 'required|min:3',
            'token' => 'required|unique:subscriptions,token',
            'with_balancer' => 'boolean',
        ]);

        $subscription = Subscription::create([
            'name' => $this->name,
            'token' => $this->token,
            'with_balancer' => $this->with_balancer,
            'expires_at' => $this->expires_at ?: null,
        ]);

        $client = Client::findOrFail($this->clientId);
        $client->subscriptions()->attach($subscription->id);

        return $this->redirectRoute('subscriptions.index', ['clientId' => $this->clientId], navigate: true);
    }
}; ?>

<div class="row justify-content-center animate__animated animate__fadeIn">
    <div class="col-md-6">
        <div class="card border-0 shadow-sm rounded-4">
            <div class="card-body p-4">
                <div class="d-flex align-items-center mb-4">
                    <a href="{{ route('subscriptions.index', $clientId) }}" wire:navigate class="btn btn-dark rounded-circle me-3">
                        <i class="bi bi-arrow-left"></i>
                    </a>
                    <h4 class="fw-bold m-0">Новый тариф</h4>
                </div>

                <form wire:submit.prevent="save">
                    <div class="mb-3">
                        <label class="form-label small fw-bold text-muted text-uppercase">Название подписки</label>
                        <input type="text" wire:model="name" class="form-control bg-light border-0 py-2 shadow-none" placeholder="Например: VIP Germany">
                        @error('name') <small class="text-danger">{{ $message }}</small> @enderror
                    </div>

                    <div class="mb-4">
                        <label class="form-label small fw-bold text-muted text-uppercase">Ключ доступа</label>
                        <div class="input-group">
                            <input type="text" wire:model="token" readonly class="form-control bg-white border-0 py-2 font-monospace text-primary">
                            <button type="button" wire:click="generateToken" class="btn btn-white border shadow-none">
                                <i class="bi bi-arrow-clockwise"></i>
                            </button>
                        </div>
                    </div>

                    <div class="mb-4 p-3 rounded-3 bg-light d-flex align-items-center justify-content-between">
                        <div>
                            <div class="fw-bold small text-uppercase">Балансировщик (Auto)</div>
                            <div class="text-muted small">Добавить автоматический выбор сервера</div>
                        </div>
                        <div class="form-check form-switch m-0">
                            <input class="form-check-input" type="checkbox" wire:model="with_balancer" style="width: 3em; height: 1.5em; cursor: pointer;">
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

                    <div class="col-12 d-flex justify-content-between align-items-center pt-2">
                        <a href="{{ route('subscriptions.index', $clientId) }}" wire:navigate class="btn btn-outline-secondary px-4 py-2 fw-bold rounded-3 shadow-sm border-0 bg-white bg-opacity-5">
                            <i class="bi bi-arrow-left me-2"></i>ОТМЕНА
                        </a>

                        <button type="submit" class="btn btn-primary px-5 py-2 fw-bold rounded-3 shadow-sm">
                            <i class="bi bi-check-lg me-2"></i>СОХРАНИТЬ
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
