<?php

use App\Models\Flag;
use Livewire\Component;
use App\Models\Subscription;
use App\Models\Config;
use Livewire\Attributes\Computed;

new class extends Component {
    public $clientId;
    public $subId;
    public $flag;

    public function mount($clientId, $subId)
    {
        $this->clientId = $clientId;
        $this->subId = $subId;
    }

    #[Computed]
    public function subscription()
    {
        return Subscription::with('configs')->findOrFail($this->subId);
    }

    public function deleteConfig($id)
    {
        $config = Config::findOrFail($id);
        $config->delete();
    }
    #[Computed]
    public function getFlagByID($id)
    {
        return Flag::where('id', $id)->first();
    }

    public function setMainConfig($configId)
    {
        $config = Config::findOrFail($configId);

        if ($config->is_main) {
            $config->update(['is_main' => false]);
        } else {
            Config::where('subscription_id', $this->subId)->update(['is_main' => false]);

            $config->update(['is_main' => true]);
        }

        unset($this->subscription);
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
        <div class="d-flex gap-2">
            <a href="{{ route('configs.create', [$clientId, $subId]) }}" wire:navigate
               class="btn btn-dark px-4 shadow-sm fw-bold">
                <i class="bi bi-plus-lg me-2"></i>Добавить
            </a>
            <a href="{{ route('configs.add_from_node', [$clientId, $subId]) }}" wire:navigate
               class="btn btn-primary px-4 shadow-sm fw-bold border-0">
                <i class="bi bi-server me-2"></i>С нод
            </a>
        </div>
    </div>

    <div class="row g-3">
        @forelse($this->subscription->configs as $config)
            <div class="col-12" wire:key="config-{{ $config->id }}">
                <div class="card border-0 shadow-sm rounded-4 {{ $config->is_main ? 'border-start border-primary border-4' : '' }}">
                    <div class="card-body d-flex justify-content-between align-items-center p-3">
                        <div class="d-flex align-items-center">
                            <div class="bg-dark rounded-3 me-3 text-white d-flex align-items-center justify-content-center" style="width: 45px; height: 30px; overflow: hidden;">
                                @php $flagData = $this->getFlagByID($config->flag_id); @endphp
                                @if($flagData)
                                    <img src="https://purecatamphetamine.github.io/country-flag-icons/3x2/{{ strtoupper($flagData->code) }}.svg"
                                         alt="{{ $flagData->name }}"
                                         class="w-100">
                                @else
                                    <i class="bi bi-geo-alt"></i>
                                @endif
                            </div>
                            <div>
                                <h6 class="fw-bold mb-0 text-dark">
                                    {{ $config->name ?: $config->email }}
                                    @if($config->is_main)
                                    <span class="badge bg-primary-subtle text-primary ms-2" style="font-size: 10px;">MAIN</span>
                                    @endif
                                </h6>
                                <small class="text-muted text-truncate d-inline-block" style="max-width: 250px;">{{ $config->link }}</small>
                            </div>
                        </div>
                        <div class="d-flex gap-2 align-items-center">
                            <button wire:click="setMainConfig({{ $config->id }})"
                                    class="btn btn-sm {{ $config->is_main ? 'text-warning' : 'text-muted opacity-50' }}"
                                    title="Переключить основной статус">
                                <i class="bi {{ $config->is_main ? 'bi-star-fill' : 'bi-star' }} fs-5"></i>
                            </button>

                            <div class="vr mx-1"></div>

                            <a href="{{ route('configs.edit', [$clientId, $subId, $config->id]) }}" wire:navigate
                               class="btn btn-light btn-sm text-primary">
                                <i class="bi bi-pencil-fill"></i>
                            </a>
                            <button wire:click="deleteConfig({{ $config->id }})" wire:confirm="Удалить сервер навсегда?"
                                    class="btn btn-light btn-sm text-danger">
                                <i class="bi bi-trash3-fill"></i>
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        @empty
            <div class="col-12 text-center py-5 bg-light rounded-4 border border-dashed">
                <p class="text-muted m-0">Конфигурации еще не созданы</p>
            </div>
        @endforelse
    </div>
</div>
