<?php
use Livewire\Component;
use App\Models\Subscription;
use App\Models\Config;
use Livewire\Attributes\Computed;

new class extends Component {
    public $clientId;
    public $subId;

    public function mount($clientId, $subId) {
        $this->clientId = $clientId;
        $this->subId = $subId;
    }

    #[Computed]
    public function subscription() {
        return Subscription::with('configs')->findOrFail($this->subId);
    }

    public function deleteConfig($id) {
        $config = Config::findOrFail($id);
        $config->delete();
    }
}; ?>

<div class="animate__animated animate__fadeIn">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-1">
                    <li class="breadcrumb-item"><a href="{{ route('subscriptions.index', $clientId) }}" wire:navigate>Подписки</a></li>
                    <li class="breadcrumb-item active">{{ $this->subscription->name }}</li>
                </ol>
            </nav>
            <h2 class="fw-bold m-0">Конфигурации серверов</h2>
        </div>
        <a href="{{ route('configs.create', [$clientId, $subId]) }}" wire:navigate class="btn btn-dark px-4 shadow-sm fw-bold">
            <i class="bi bi-plus-lg me-2"></i>Добавить конфиг
        </a>
    </div>

    <div class="row g-3">
        @forelse($this->subscription->configs as $config)
            <div class="col-12" wire:key="config-{{ $config->id }}">
                <div class="card border-0 shadow-sm rounded-4">
                    <div class="card-body d-flex justify-content-between align-items-center p-3">
                        <div class="d-flex align-items-center">
                            <div class="bg-dark rounded-3 p-2 me-3 text-white">
                                <i class="bi bi-hdd-network"></i>
                            </div>
                            <div>
                                <h6 class="fw-bold mb-0 text-dark">{{ $config->name }}</h6>
                                <small class="text-muted text-truncate d-inline-block" style="max-width: 250px;">{{ $config->link }}</small>
                            </div>
                        </div>
                        <div class="d-flex gap-2">
                            <a href="{{ route('configs.edit', [$clientId, $subId, $config->id]) }}" wire:navigate class="btn btn-light btn-sm text-primary">
                                <i class="bi bi-pencil-fill"></i>
                            </a>
                            <button wire:click="deleteConfig({{ $config->id }})" wire:confirm="Удалить сервер навсегда?" class="btn btn-light btn-sm text-danger">
                                <i class="bi bi-trash3-fill"></i>
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        @empty
            <div class="col-12 text-center py-5 bg-white rounded-4 border-dashed">
                <p class="text-muted m-0">Конфигурации еще не созданы</p>
            </div>
        @endforelse
    </div>
</div>
