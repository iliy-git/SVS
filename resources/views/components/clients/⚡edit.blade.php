<?php
use Livewire\Component;
use App\Models\Client;
use Livewire\Attributes\Layout;

new  class extends Component {
    public $clientId, $name, $phone, $address, $additional_info;

    public function mount($clientId) {
        $client = Client::findOrFail($clientId);
        $this->clientId = $client->id;
        $this->name = $client->name;
        $this->phone = $client->phone;
        $this->address = $client->address;
        $this->additional_info = $client->additional_info;
    }

    public function save() {
        $this->validate(['name' => 'required']);
        Client::find($this->clientId)->update([
            'name' => $this->name,
            'phone' => $this->phone,
            'address' => $this->address,
            'additional_info' => $this->additional_info,
        ]);
        return $this->redirectRoute('clients.index', navigate: true);
    }
}; ?>

<div class="row justify-content-center animate__animated animate__fadeIn">
    <div class="col-md-8">
        <div class="card border-0 shadow-sm">
            <div class="card-body p-4">
                <h4 class="fw-bold mb-4 border-bottom pb-2">Правка клиента: {{ $name }}</h4>
                <form wire:submit.prevent="save">
                    <div class="row g-3">
                        <div class="col-12">
                            <label class="form-label small fw-bold">ФИО</label>
                            <input type="text" wire:model="name" class="form-control bg-light border-0">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-bold">Телефон</label>
                            <input type="text" wire:model="phone" class="form-control bg-light border-0">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-bold">Адрес</label>
                            <input type="text" wire:model="address" class="form-control bg-light border-0">
                        </div>
                        <div class="col-12">
                            <label class="form-label small fw-bold">Заметки</label>
                            <textarea wire:model="additional_info" class="form-control bg-light border-0" rows="3"></textarea>
                        </div>
                        <div class="col-12 d-flex justify-content-between align-items-center pt-2">
                            <a href="{{ route('clients.index') }}" wire:navigate class="btn btn-outline-secondary px-4 py-2 fw-bold rounded-3 shadow-sm border-0 bg-white bg-opacity-5">
                                <i class="bi bi-arrow-left me-2"></i>ОТМЕНА
                            </a>

                            <button type="submit" class="btn btn-primary px-5 py-2 fw-bold rounded-3 shadow-sm">
                                <i class="bi bi-check-lg me-2"></i>ОбНОВИТЬ
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
