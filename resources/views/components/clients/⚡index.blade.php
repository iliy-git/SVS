<?php
use Livewire\Component;
use App\Models\Client;
use Livewire\Attributes\{Computed};

new class extends Component {
    public $search = '';

    #[Computed]
    public function clients() {
        return Client::withCount('subscriptions')
            ->where('name', 'like', "%{$this->search}%")
            ->latest()->get();
    }

    public function deleteClient($id) {
        Client::destroy($id);
    }
}; ?>

<div class="animate__animated animate__fadeIn">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2 class="fw-bold text-dark m-0">Клиенты</h2>
        <a href="{{ route('clients.create') }}" wire:navigate class="btn btn-primary px-4 shadow-sm">
            <i class="bi bi-person-plus-fill me-2"></i>Добавить
        </a>
    </div>

    <div class="card border-0 shadow-sm mb-4">
        <div class="card-body p-2">
            <input type="text" wire:model.live="search" class="form-control border-0 shadow-none" placeholder="Поиск по имени или телефону...">
        </div>
    </div>

    <div class="card border-0 shadow-sm rounded-4 overflow-hidden">
        <table class="table table-hover align-middle mb-0">
            <thead class="bg-light">
            <tr>
                <th class="ps-4">Имя</th>
                <th>Телефон</th>
                <th>Подписки</th>
                <th>Адрес</th>
                <th class="text-end pe-4">Действия</th>
            </tr>
            </thead>
            <tbody>
            @foreach($this->clients as $client)
                <tr wire:key="client-{{ $client->id }}">
                    <td class="ps-4 fw-bold">{{ $client->name }}</td>
                    <td class="text-muted small">{{ $client->phone ?? '—' }}</td>
                    <td><span class="badge bg-light text-primary">{{ $client->subscriptions_count }}</span></td>
                    <td class="text-muted small">{{ $client->address ?? '—' }}</td>
                    <td class="text-end pe-4">
                        <a href="{{ route('subscriptions.index', $client->id) }}"
                           wire:navigate
                           class="btn btn-white btn-sm border"
                           title="Управление подписками">
                            <i class="bi bi-card-checklist text-success"></i>
                        </a>
                        <a href="{{ route('clients.edit', $client->id) }}" wire:navigate class="btn btn-sm btn-light text-primary"><i class="bi bi-pencil"></i></a>
                        <button wire:click="deleteClient({{ $client->id }})" wire:confirm="Удалить?" class="btn btn-sm btn-light text-danger"><i class="bi bi-trash"></i></button>
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
</div>
