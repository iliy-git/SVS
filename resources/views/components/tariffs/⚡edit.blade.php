<?php

use Livewire\Component;
use App\Models\Tariff;
use App\Models\SubscriptionTemplate;
use Illuminate\Support\Facades\DB;

new class extends Component {
    public $tariffId;
    public $name, $description, $price, $duration_days, $is_active;
    
    public $items = [];
    public $availableTemplates = [];

    public function mount($tariffId)
    {
        $tariff = Tariff::with('items')->findOrFail($tariffId);
        
        $this->tariffId = $tariff->id;
        $this->name = $tariff->name;
        $this->description = $tariff->description;
        $this->price = $tariff->price;
        $this->duration_days = $tariff->duration_days;
        $this->is_active = $tariff->is_active;

        foreach ($tariff->items as $item) {
            $this->items[] = [
                'template_id' => $item->template_id,
                'device_limit' => $item->device_limit,
                'custom_name' => $item->custom_name,
            ];
        }

        $this->availableTemplates = SubscriptionTemplate::where('is_active', true)->get();
        
        if (empty($this->items)) {
            $this->addItem();
        }
    }

    public function addItem()
    {
        $this->items[] = ['template_id' => '', 'device_limit' => 1, 'custom_name' => ''];
    }

    public function removeItem($index)
    {
        unset($this->items[$index]);
        $this->items = array_values($this->items);
    }

    public function save()
    {
        $this->validate([
            'name' => 'required|string|max:255',
            'price' => 'required|numeric|min:0',
            'duration_days' => 'required|integer|min:1',
            'items' => 'required|array|min:1',
            'items.*.template_id' => 'required|exists:subscription_templates,id',
            'items.*.device_limit' => 'required|integer|min:1',
        ]);

        DB::transaction(function () {
            $tariff = Tariff::findOrFail($this->tariffId);
            
            $tariff->update([
                'name' => $this->name,
                'description' => $this->description,
                'price' => $this->price,
                'duration_days' => $this->duration_days,
                'is_active' => $this->is_active,
            ]);

            // Простейший способ синхронизации: удаляем старые, пишем новые
            $tariff->items()->delete();

            foreach ($this->items as $item) {
                $tariff->items()->create([
                    'template_id' => $item['template_id'],
                    'device_limit' => $item['device_limit'],
                    'custom_name' => $item['custom_name'] ?: null,
                ]);
            }
        });

        return $this->redirectRoute('tariffs.index', navigate: true);
    }
}; ?>

<div class="row justify-content-center animate__animated animate__fadeIn">
    <div class="col-md-10 col-lg-8">
        <div class="card border-0 shadow-lg rounded-4 overflow-hidden" style="background: #1a1d21;">
            <div class="card-header bg-primary bg-opacity-10 border-0 p-4">
                <h4 class="fw-bold text-white m-0">Редактирование Тарифа</h4>
            </div>
            
            <div class="card-body p-4 text-white">
                <form wire:submit.prevent="save">
                    <!-- ОСНОВНАЯ ИНФОРМАЦИЯ -->
                    <div class="row g-4 mb-5">
                        <div class="col-md-8">
                            <label class="form-label small fw-bold text-secondary text-uppercase">Название тарифа</label>
                            <input type="text" wire:model="name" class="form-control bg-dark border-0 text-white py-2 shadow-none border-focus border border-white border-opacity-5">
                            @error('name') <span class="text-danger small">{{ $message }}</span> @enderror
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small fw-bold text-secondary text-uppercase">Статус</label>
                            <div class="form-check form-switch mt-2">
                                <input class="form-check-input shadow-none" type="checkbox" wire:model="is_active" id="isActiveEdit" style="transform: scale(1.3); margin-left: -2em;">
                                <label class="form-check-label ms-2 mt-1" for="isActiveEdit">Активен</label>
                            </div>
                        </div>
                        
                        <div class="col-12">
                            <label class="form-label small fw-bold text-secondary text-uppercase">Описание (для бота)</label>
                            <textarea wire:model="description" class="form-control bg-dark border-0 text-white py-2 shadow-none border-focus border border-white border-opacity-5" rows="2"></textarea>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label small fw-bold text-secondary text-uppercase">Цена (₽)</label>
                            <input type="number" wire:model="price" class="form-control bg-dark border-0 text-white py-2 shadow-none border-focus border border-white border-opacity-5">
                            @error('price') <span class="text-danger small">{{ $message }}</span> @enderror
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-bold text-secondary text-uppercase">Срок (Дней)</label>
                            <input type="number" wire:model="duration_days" class="form-control bg-dark border-0 text-white py-2 shadow-none border-focus border border-white border-opacity-5">
                            @error('duration_days') <span class="text-danger small">{{ $message }}</span> @enderror
                        </div>
                    </div>

                    <!-- НАПОЛНЕНИЕ ТАРИФА -->
                    <div class="mb-4">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <label class="form-label small fw-bold text-secondary text-uppercase m-0">Наполнение тарифа (Ссылки)</label>
                            <button type="button" wire:click="addItem" class="btn btn-sm btn-outline-primary border-opacity-25 rounded-3 fw-bold">
                                <i class="bi bi-plus-lg"></i> Добавить ссылку
                            </button>
                        </div>

                        @error('items') <div class="text-danger small mb-3">Добавьте хотя бы один шаблон в тариф</div> @enderror

                        @foreach($items as $index => $item)
                            <div class="card bg-dark border border-white border-opacity-5 rounded-3 mb-3 p-3 position-relative transition-all hover-up">
                                @if(count($items) > 1)
                                    <button type="button" wire:click="removeItem({{ $index }})" class="btn btn-sm btn-danger position-absolute" style="top: -10px; right: -10px; border-radius: 50%; width: 28px; height: 28px; padding: 0;">
                                        <i class="bi bi-x-lg" style="font-size: 12px;"></i>
                                    </button>
                                @endif

                                <div class="row g-3">
                                    <div class="col-md-6">
                                        <label class="small text-muted mb-1">Какой шаблон выдать?</label>
                                        <select wire:model="items.{{ $index }}.template_id" class="form-select bg-transparent border-white border-opacity-10 text-white shadow-none border-focus">
                                            <option value="" class="bg-dark text-muted">-- Выберите шаблон --</option>
                                            @foreach($availableTemplates as $tpl)
                                                <option value="{{ $tpl->id }}" class="bg-dark text-white">{{ $tpl->name }}</option>
                                            @endforeach
                                        </select>
                                        @error("items.{$index}.template_id") <span class="text-danger small">Обязательно</span> @enderror
                                    </div>
                                    <div class="col-md-2">
                                        <label class="small text-muted mb-1">Устройств</label>
                                        <input type="number" wire:model="items.{{ $index }}.device_limit" class="form-control bg-transparent border-white border-opacity-10 text-white shadow-none border-focus">
                                        @error("items.{$index}.device_limit") <span class="text-danger small">Ошибка</span> @enderror
                                    </div>
                                    <div class="col-md-4">
                                        <label class="small text-muted mb-1">Пометка к имени</label>
                                        <input type="text" wire:model="items.{{ $index }}.custom_name" class="form-control bg-transparent border-white border-opacity-10 text-white shadow-none border-focus" placeholder="Напр: (ТВ)">
                                    </div>
                                </div>
                            </div>
                        @endforeach
                    </div>

                    <div class="d-flex justify-content-between align-items-center mt-5 pt-3 border-top border-secondary border-opacity-10">
                        <a href="{{ route('tariffs.index') }}" wire:navigate class="btn btn-link text-secondary text-decoration-none fw-bold p-0 transition-all hover-light">
                            <i class="bi bi-arrow-left me-2"></i>ОТМЕНА
                        </a>
                        <button type="submit" class="btn btn-primary px-5 py-2 fw-bold rounded-3 shadow-sm">
                            <i class="bi bi-cloud-arrow-up-fill me-2"></i>ОБНОВИТЬ
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<style>
    .border-focus:focus { border-color: rgba(13, 110, 253, 0.5) !important; box-shadow: 0 0 0 0.25rem rgba(13, 110, 253, 0.1) !important; }
    .transition-all { transition: all 0.2s ease; }
    .hover-up:hover { transform: translateY(-3px); border-color: rgba(255, 255, 255, 0.15) !important; }
    .hover-light:hover { color: white !important; }
</style>