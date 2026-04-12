<?php
use Livewire\Component;
use App\Models\Client;
use Livewire\Attributes\{Computed};

new class extends Component {
    public $search = '';

    #[Session]
    public $view = 'table';

    #[Computed]
    public function clients() {
        return Client::withCount('subscriptions')
            ->where('name', 'like', "%{$this->search}%")
            ->latest()->get();
    }

    public function setView($mode) {
        $this->view = $mode;
    }

    public function deleteClient($id) {
        Client::destroy($id);
    }
}; ?>
<style>
    .hover-light:hover {
        color: rgba(255, 255, 255, 0.8) !important;
    }
</style>
<div class="animate__animated animate__fadeIn">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h2 class="fw-bold text-dark m-0">Клиенты</h2>
            <p class="text-muted small mb-0">Всего найдено: {{ $this->clients->count() }}</p>
        </div>

        <div class="d-flex gap-3 align-items-center">
            <div class="d-flex align-items-center bg-dark rounded-3 p-1"
                 style="border: 1px solid rgba(255,255,255,0.05); position: relative; width: fit-content;"
                 x-data="{ view: @entangle('view') }">

                <div class="position-absolute bg-white rounded-2 shadow-sm"
                     style="top: 4px; bottom: 4px; transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1); z-index: 1;"
                     :style="view === 'table' ? 'left: 4px; width: 36px;' : 'left: 40px; width: 36px;'">
                </div>

                <button wire:click="setView('table')"
                        class="btn btn-sm d-flex align-items-center justify-content-center border-0 p-0"
                        style="width: 36px; height: 28px; position: relative; z-index: 2;"
                        :class="view === 'table' ? 'text-dark' : 'text-muted hover-light'">
                    <i class="bi bi-list-ul fs-5"></i>
                </button>

                <button wire:click="setView('grid')"
                        class="btn btn-sm d-flex align-items-center justify-content-center border-0 p-0"
                        style="width: 36px; height: 28px; position: relative; z-index: 2;"
                        :class="view === 'grid' ? 'text-dark' : 'text-muted hover-light'">
                    <i class="bi bi-grid-fill"></i>
                </button>
            </div>

            <a href="{{ route('clients.create') }}" wire:navigate class="btn btn-primary px-4 shadow-sm fw-bold">
                <i class="bi bi-person-plus-fill me-2"></i>Добавить
            </a>
        </div>
    </div>

    <div class="card border-0 shadow-sm mb-4 rounded-4">
        <div class="card-body p-2 d-flex align-items-center">
            <i class="bi bi-search m-2 text-muted"></i>
            <input type="text" wire:model.live="search"
                   class="form-control border-0 shadow-none ps-2"
                   placeholder="Поиск по имени или телефону...">
        </div>
    </div>

    @if($view === 'table')
        <div class="card border-0 shadow-sm rounded-4 overflow-hidden">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="bg-light">
                    <tr>
                        <th class="ps-4 py-3 text-muted small text-uppercase">Имя</th>
                        <th class="text-muted small text-uppercase">Телефон</th>
                        <th class="text-muted small text-uppercase">Подписки</th>
                        <th class="text-muted small text-uppercase">Адрес</th>
                        <th class="text-end pe-4 text-muted small text-uppercase">Действия</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach($this->clients as $client)
                        <tr wire:key="table-{{ $client->id }}">
                            <td class="ps-4 fw-bold text-dark">{{ $client->name }}</td>
                            <td class="text-muted">{{ $client->phone ?? '—' }}</td>
                            <td>
                                    <span class="badge bg-primary bg-opacity-10 text-primary rounded-pill px-3">
                                        {{ $client->subscriptions_count }}
                                    </span>
                            </td>
                            <td class="text-muted small text-truncate" style="max-width: 200px;">{{ $client->address ?? '—' }}</td>
                            <td class="text-end pe-4">
                                <div class="btn-group">
                                    <a href="{{ route('subscriptions.index', $client->id) }}" wire:navigate class="btn btn-sm btn-light border-0" title="Подписки"><i class="bi bi-card-checklist text-success"></i></a>
                                    <a href="{{ route('clients.edit', $client->id) }}" wire:navigate class="btn btn-sm btn-light border-0"><i class="bi bi-pencil text-primary"></i></a>
                                    <button wire:click="deleteClient({{ $client->id }})" wire:confirm="Удалить?" class="btn btn-sm btn-light border-0"><i class="bi bi-trash text-danger"></i></button>
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
            @foreach($this->clients as $client)
                <div class="col-md-6 col-lg-4 col-xl-3" wire:key="grid-{{ $client->id }}">
                    <div class="card border-0 shadow-sm h-100 rounded-4 card-client-hover">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-start mb-3">
                                <div class="bg-primary bg-opacity-10 p-2 rounded-3">
                                    <i class="bi bi-person text-primary fs-4"></i>
                                </div>
                                <span class="badge bg-primary bg-opacity-10 text-primary rounded-pill">
                                    {{ $client->subscriptions_count }} под.
                                </span>
                            </div>

                            <h5 class="fw-bold text-dark mb-1">{{ $client->name }}</h5>
                            <p class="text-muted small mb-3"><i class="bi bi-telephone me-2"></i>{{ $client->phone ?? '—' }}</p>
                            <p class="text-muted x-small mb-0 text-truncate mb-2">
                                <i class="bi bi-geo-alt me-1"></i>{{ $client->address ?? 'Нет адреса' }}
                            </p>

                            <div class="d-flex gap-2">
                                <a href="{{ route('subscriptions.index', $client->id) }}" wire:navigate class="btn btn-light btn-sm flex-grow-1 fw-bold text-success border-0">
                                    <i class="bi bi-card-checklist me-1"></i>Подписки
                                </a>
                                <a href="{{ route('clients.edit', $client->id) }}" wire:navigate class="btn btn-light btn-sm px-3 border-0"><i class="bi bi-pencil text-primary"></i></a>
                                <button wire:click="deleteClient({{ $client->id }})" wire:confirm="Удалить?" class="btn btn-light btn-sm px-3 border-0"><i class="bi bi-trash text-danger"></i></button>
                            </div>
                        </div>
                    </div>
                </div>
            @endforeach
        </div>
    @endif

</div>
