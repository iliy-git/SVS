<?php

use App\Models\Flag;
use Illuminate\Support\Facades\DB;
use Livewire\Component;
use App\Models\Subscription;
use App\Models\Config;

new class extends Component {
    public $clientId;
    public $subId;
    public $flag;
    public $subscription;

    public function mount($clientId, $subId)
    {
        $this->clientId = $clientId;
        $this->subId = $subId;

        $this->loadSubscription();
    }
    public function loadSubscription()
    {
        $this->subscription = Subscription::with(['configs' => function($query) {
            $query->orderByDesc('is_active')
            ->orderByDesc('is_main');
        }, 'configs.flag'])
            ->findOrFail($this->subId);
    }

    public function deleteConfig($id)
    {
        $config = Config::findOrFail($id);
        $config->delete();
        $this->loadSubscription();
    }

    public function getFlagByID($id)
    {
        return Flag::where('id', $id)->first();
    }

    public function setMainConfig($configId)
    {
        foreach ($this->subscription->configs as $config) {

            if ($config->id == $configId) {
                $newValue = !$config->is_main;
                $config->update(['is_main' => $newValue]);
            } else {
                if ($config->is_main) {
                    $config->update(['is_main' => false]);
                }
            }
        }

        $this->loadSubscription();
    }
    public function toggleActive($configId)
    {
        $config = Config::findOrFail($configId);
        $config->update(['is_active' => !$config->is_active]);

        $this->loadSubscription();
    }
}; ?>

<div class="animate__animated animate__fadeIn">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-1">
                    <li class="breadcrumb-item"><a href="{{ route('subscriptions.index', $clientId) }}" wire:navigate>Подписки</a>
                    </li>
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
            <div class="col-12" wire:key="config-{{ $config->id }}-{{ $config->is_active }}-{{ $config->is_main }}">
                {{-- Добавляем opacity-75 и grayscale для неактивных --}}
                <div class="card border-0 shadow-sm rounded-4 {{ $config->is_main ? 'border-start border-primary border-4' : '' }} {{ !$config->is_active ? 'opacity-75 bg-light' : '' }}"
                     style="{{ !$config->is_active ? 'filter: grayscale(0.8);' : '' }} transition: all 0.3s ease;">

                    <div class="card-body d-flex justify-content-between align-items-center p-3">
                        <div class="d-flex align-items-center">
                            {{-- Флаг --}}
                            <div class="bg-dark rounded-3 me-3 text-white d-flex align-items-center justify-content-center"
                                 style="width: 45px; height: 30px; overflow: hidden;">
                                @if($config->flag)
                                    <img src="https://purecatamphetamine.github.io/country-flag-icons/3x2/{{ strtoupper($config->flag->code) }}.svg" class="w-100">
                                @else
                                    <i class="bi bi-geo-alt"></i>
                                @endif
                            </div>

                            <div>
                                <h6 class="fw-bold mb-0 {{ $config->is_active ? 'text-dark' : 'text-muted' }}">
                                    {{ $config->name ?: $config->email }}
                                    @if($config->is_main)
                                        <span class="badge bg-primary-subtle text-primary ms-2" style="font-size: 10px;">MAIN</span>
                                    @endif
                                    @if(!$config->is_active)
                                        <span class="badge bg-secondary-subtle text-secondary ms-2" style="font-size: 10px;">OFF</span>
                                    @endif
                                </h6>
                                <small class="text-muted text-truncate d-inline-block" style="max-width: 250px;">{{ $config->link }}</small>
                            </div>
                        </div>

                        <div class="d-flex gap-2 align-items-center">
                            {{-- ГАЛОЧКА (is_active) --}}
                            <button wire:click="toggleActive({{ $config->id }})"
                                    class="btn btn-sm p-0 border-0 bg-transparent me-1"
                                    title="{{ $config->is_active ? 'Деактивировать' : 'Активировать' }}">
                                <i class="bi {{ $config->is_active ? 'bi-check-circle-fill text-success' : 'bi-circle text-muted opacity-50' }} fs-5"></i>
                            </button>

                            {{-- ЗВЕЗДА (is_main) --}}
                            <button wire:click="setMainConfig({{ $config->id }})"
                                    class="btn btn-sm p-0 border-0 bg-transparent"
                                    {{ !$config->is_active ? 'disabled' : '' }}
                                    title="Сделать основным">
                                <i class="bi {{ $config->is_main ? 'bi-star-fill text-warning' : 'bi-star text-muted opacity-25' }} fs-5"></i>
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
