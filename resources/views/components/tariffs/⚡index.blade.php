<?php

use Livewire\Component;
use App\Models\Tariff;
use Livewire\Attributes\{Computed, Session};

new class extends Component {
    public $search = '';

    #[Session]
    public $view = 'table';

    #[Computed]
    public function tariffs()
    {
        return Tariff::withCount('items')
            ->when($this->search, function($q) {
                $q->where('name', 'like', "%{$this->search}%")
                  ->orWhere('price', 'like', "%{$this->search}%");
            })
            ->latest()
            ->get();
    }

    public function setView($mode)
    {
        $this->view = $mode;
    }

    public function deleteTariff($id)
    {
        Tariff::findOrFail($id)->delete();
    }

    public function toggleActive($id)
    {
        $tariff = Tariff::findOrFail($id);
        $tariff->update(['is_active' => !$tariff->is_active]);
    }
}; ?>

<div class="animate__animated animate__fadeIn">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h2 class="fw-bold text-white m-0">Тарифы</h2>
            <p class="text-muted small mb-0">Всего тарифов: {{ $this->tariffs->count() }}</p>
        </div>

        <div class="d-flex gap-3 align-items-center">
            <div class="d-flex align-items-center bg-dark rounded-3 p-1"
                 style="border: 1px solid rgba(255,255,255,0.05); position: relative; width: 80px; height: 38px;"
                 x-data="{ currentView: @entangle('view') }">

                <div class="position-absolute bg-white rounded-2 shadow-sm"
                     style="top: 4px; bottom: 4px; transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1); z-index: 1;"
                     :style="currentView === 'table' ? 'left: 4px; width: 34px;' : 'left: 42px; width: 34px;'">
                </div>

                <button wire:click="setView('table')" class="btn btn-sm d-flex align-items-center justify-content-center border-0 p-0" style="width: 36px; height: 30px; position: relative; z-index: 2;" :class="currentView === 'table' ? 'text-dark' : 'text-muted'">
                    <i class="bi bi-list-ul fs-5"></i>
                </button>

                <button wire:click="setView('grid')" class="btn btn-sm d-flex align-items-center justify-content-center border-0 p-0" style="width: 36px; height: 30px; position: relative; z-index: 2;" :class="currentView === 'grid' ? 'text-dark' : 'text-muted'">
                    <i class="bi bi-grid-fill"></i>
                </button>
            </div>

            <a href="{{ route('tariffs.create') }}" wire:navigate class="btn btn-primary px-4 shadow-sm fw-bold">
                <i class="bi bi-plus-circle-fill me-2"></i>Добавить
            </a>
        </div>
    </div>

    <div class="card border-0 shadow-sm mb-4 rounded-4 bg-dark" style="border: 1px solid rgba(255,255,255,0.05) !important;">
        <div class="card-body p-2 d-flex align-items-center">
            <i class="bi bi-search m-2 text-muted"></i>
            <input type="text" wire:model.live.debounce.300ms="search" class="form-control border-0 shadow-none ps-3 bg-transparent text-white" placeholder="Поиск тарифа...">
            <div wire:loading wire:target="search" class="spinner-border spinner-border-sm text-primary me-3"></div>
        </div>
    </div>

    @if($view === 'table')
        <div class="card border-0 shadow-sm rounded-4 overflow-hidden" style="background: #1a1d21;">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead style="background: rgba(0,0,0,0.2);">
                    <tr>
                        <th class="ps-4 py-3 text-muted small text-uppercase fw-bold">Название</th>
                        <th class="text-muted small text-uppercase fw-bold">Стоимость</th>
                        <th class="text-muted small text-uppercase fw-bold">Срок</th>
                        <th class="text-muted small text-uppercase fw-bold">Наполнение</th>
                        <th class="text-muted small text-uppercase fw-bold">Статус</th>
                        <th class="text-end pe-4 text-muted small text-uppercase fw-bold">Действия</th>
                    </tr>
                    </thead>
                    <tbody class="border-0">
                    @foreach($this->tariffs as $tariff)
                        <tr wire:key="t-{{ $tariff->id }}" class="border-bottom border-white border-opacity-5">
                            <td class="ps-4">
                                <div class="fw-bold text-white fs-6">{{ $tariff->name }}</div>
                                @if($tariff->description)
                                    <div class="text-muted small text-truncate" style="max-width: 200px;">{{ $tariff->description }}</div>
                                @endif
                            </td>
                            <td><code class="text-accent bg-dark px-2 py-1 rounded fw-bold">{{ number_format($tariff->price, 0, '', ' ') }} ₽</code></td>
                            <td><span class="badge bg-secondary bg-opacity-25 text-white border border-secondary border-opacity-25">{{ $tariff->duration_days }} дней</span></td>
                            <td><span class="text-muted small"><i class="bi bi-layers me-1"></i>{{ $tariff->items_count }} шаблонов</span></td>
                            <td>
                                <button wire:click="toggleActive({{ $tariff->id }})" class="btn btn-sm p-0 border-0 shadow-none">
                                    <span class="{{ $tariff->is_active ? 'text-success-bright' : 'text-danger' }} small fw-bold d-flex align-items-center">
                                        <span class="me-2" style="width: 8px; height: 8px; background: {{ $tariff->is_active ? '#22c55e' : '#ef4444' }}; border-radius: 50%;"></span>
                                        {{ $tariff->is_active ? 'АКТИВЕН' : 'ОТКЛЮЧЕН' }}
                                    </span>
                                </button>
                            </td>
                            <td class="text-end pe-4">
                                <div class="btn-group gap-1">
                                    <a href="{{ route('tariffs.edit', $tariff->id) }}" wire:navigate class="btn btn-sm btn-dark border-0 rounded-2"><i class="bi bi-pencil-square text-primary"></i></a>
                                    <button wire:click="deleteTariff({{ $tariff->id }})" 
                                            wire:confirm="Вы уверены, что хотите удалить этот тариф?" 
                                            class="btn btn-sm btn-dark border-0 rounded-2">
                                        <i class="bi bi-trash3 text-danger"></i>
                                    </button>
                                </div>
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @else
        <div class="row g-4">
            @foreach($this->tariffs as $tariff)
                <div class="col-md-4 col-xl-3" wire:key="g-{{ $tariff->id }}">
                    <div class="card border-0 shadow-sm h-100 rounded-4 transition-all hover-up" style="background: #1a1d21; border: 1px solid rgba(255,255,255,0.05) !important;">
                        <div class="card-body p-4">
                            <div class="d-flex justify-content-between align-items-start mb-3">
                                <div class="badge {{ $tariff->is_active ? 'bg-success bg-opacity-10 text-success' : 'bg-danger bg-opacity-10 text-danger' }} border-0 rounded-2">
                                    {{ $tariff->is_active ? 'АКТИВЕН' : 'ОТКЛЮЧЕН' }}
                                </div>
                                <h4 class="fw-bold text-accent m-0">{{ number_format($tariff->price, 0, '', ' ') }} ₽</h4>
                            </div>
                            <h5 class="fw-bold text-white mb-1">{{ $tariff->name }}</h5>
                            <p class="text-muted small mb-3">{{ $tariff->duration_days }} дней • {{ $tariff->items_count }} шаблонов</p>
                            
                            <div class="d-flex gap-2 mt-4">
                                <a href="{{ route('tariffs.edit', $tariff->id) }}" wire:navigate class="btn btn-dark btn-sm flex-grow-1 py-2 fw-bold border-0" style="background: rgba(255,255,255,0.05);">ИЗМЕНИТЬ</a>
                                <button wire:click="deleteTariff({{ $tariff->id }})" wire:confirm="Удалить тариф?" class="btn btn-sm btn-dark border-0 rounded-2">
                                    <i class="bi bi-trash3 text-danger"></i>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            @endforeach
        </div>
    @endif
</div>

<style>
    .transition-all { transition: all 0.3s ease; }
    .hover-up:hover { transform: translateY(-5px); border-color: rgba(59, 130, 246, 0.4) !important; }
    .text-success-bright { color: #4ade80 !important; }
</style>