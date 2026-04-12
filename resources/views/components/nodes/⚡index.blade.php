<?php
use Livewire\Component;
use App\Models\Node;
use Livewire\Attributes\{Computed, Session};
use Illuminate\Support\Facades\Http;

new class extends Component {
    public $search = '';

    #[Session]
    public $view = 'table';

    #[Computed]
    public function nodes() {
        return Node::with('flag')
            ->where('name', 'like', "%{$this->search}%")
            ->orWhere('ip', 'like', "%{$this->search}%")
            ->latest()->get();
    }

    public function setView($mode) { $this->view = $mode; }

    public function deleteNode($id) {
        Node::destroy($id);
    }

    public function checkConnection($id) {
        $node = Node::find($id);
        try {
            $response = Http::withHeaders(['X-API-KEY' => $node->api_key])
                ->withoutVerifying()
                ->timeout(3)->get("https://{$node->ip}:{$node->port}/ping");

            $node->update([
                'is_active' => ($response->ok() && $response->json('status') === 'ok'),
                'last_seen' => now()
            ]);
        } catch (\Exception $e) {
            $node->update(['is_active' => false]);
        }
    }
}; ?>

<div class="animate__animated animate__fadeIn">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h2 class="fw-bold text-white m-0">Ноды (Серверы)</h2>
            <p class="text-muted small mb-0">Всего локаций: {{ $this->nodes->count() }}</p>
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

            <a href="{{ route('nodes.create') }}" wire:navigate class="btn btn-primary px-4 shadow-sm fw-bold">
                <i class="bi bi-plus-circle-fill me-2"></i>Добавить
            </a>
        </div>
    </div>

    <div class="card border-0 shadow-sm mb-4 rounded-4 bg-dark" style="border: 1px solid rgba(255,255,255,0.05) !important;">
        <div class="card-body p-2 d-flex align-items-center">
            <i class="bi bi-search m-2 text-muted"></i>
            <input type="text" wire:model.live.debounce.300ms="search" class="form-control border-0 shadow-none ps-3 bg-transparent text-white" placeholder="Поиск по названию или IP...">
            <div wire:loading wire:target="search" class="spinner-border spinner-border-sm text-primary me-3"></div>
        </div>
    </div>

    @if($view === 'table')
        <div class="card border-0 shadow-sm rounded-4 overflow-hidden" style="background: #1a1d21;">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead style="background: rgba(0,0,0,0.2);">
                    <tr>
                        <th class="ps-4 py-3 text-muted small text-uppercase fw-bold">Сервер</th>
                        <th class="text-muted small text-uppercase fw-bold">Адрес</th>
                        <th class="text-muted small text-uppercase fw-bold">Состояние</th>
                        <th class="text-muted small text-uppercase fw-bold">Активность</th>
                        <th class="text-end pe-4 text-muted small text-uppercase fw-bold">Действия</th>
                    </tr>
                    </thead>
                    <tbody class="border-0">
                    @foreach($this->nodes as $node)
                        <tr wire:key="t-{{ $node->id }}" class="border-bottom border-white border-opacity-5">
                            <td class="ps-4">
                                <div class="d-flex align-items-center">
                                    @if($node->flag)
                                        <img src="https://purecatamphetamine.github.io/country-flag-icons/3x2/{{ strtoupper($node->flag->code) }}.svg" class="me-3 rounded-1 shadow-sm" style="width: 24px;">
                                    @endif
                                    <div class="fw-bold text-white fs-6">{{ $node->name }}</div>
                                </div>
                            </td>
                            <td><code class="text-accent bg-dark px-2 py-1 rounded small">{{ $node->ip }}:{{ $node->port }}</code></td>
                            <td>
                                <span class="{{ $node->is_active ? 'text-success-bright' : 'text-danger' }} small fw-bold d-flex align-items-center">
                                    <span class="{{ $node->is_active ? 'blink' : '' }} me-2" style="width: 8px; height: 8px; background: {{ $node->is_active ? '#22c55e' : '#ef4444' }}; border-radius: 50%;"></span>
                                    {{ $node->is_active ? 'ONLINE' : 'OFFLINE' }}
                                </span>
                            </td>
                            <td class="text-muted small">{{ $node->last_seen ? $node->last_seen->diffForHumans() : '---' }}</td>
                            <td class="text-end pe-4">
                                <div class="btn-group gap-1">
                                    <button wire:click="checkConnection({{ $node->id }})" class="btn btn-sm btn-dark border-0 rounded-2"><i class="bi bi-arrow-repeat text-success"></i></button>
                                    <a href="{{ route('nodes.edit', $node->id) }}" wire:navigate class="btn btn-sm btn-dark border-0 rounded-2"><i class="bi bi-pencil-square text-primary"></i></a>
                                    <button wire:click="deleteNode({{ $node->id }})" wire:confirm="Удалить?" class="btn btn-sm btn-dark border-0 rounded-2"><i class="bi bi-trash3 text-danger"></i></button>
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
            @foreach($this->nodes as $node)
                <div class="col-md-4 col-xl-3" wire:key="g-{{ $node->id }}">
                    <div class="card border-0 shadow-sm h-100 rounded-4 transition-all hover-up" style="background: #1a1d21; border: 1px solid rgba(255,255,255,0.05) !important;">
                        <div class="card-body p-4">
                            <div class="d-flex justify-content-between align-items-start mb-3">
                                <div class="d-flex align-items-center justify-content-center"
                                     style="width: 32px; height: 24px;"> @if($node->flag)
                                        <img src="https://purecatamphetamine.github.io/country-flag-icons/3x2/{{ strtoupper($node->flag->code) }}.svg"
                                             class="rounded-1 w-100 h-100"
                                             style="object-fit: cover;" alt="{{ $node->flag->name }}">
                                    @else
                                        <i class="bi bi-server text-muted fs-5"></i>
                                    @endif
                                </div>

                                <button wire:click="checkConnection({{ $node->id }})"
                                        class="btn btn-link p-0 text-muted shadow-none">
                                    <i class="bi bi-arrow-clockwise fs-5"
                                       wire:loading.class="rotate-anim"
                                       wire:target="checkConnection({{ $node->id }})"></i>
                                </button>
                            </div>
                            <h5 class="fw-bold text-white mb-1">{{ $node->name }}</h5>
                            <p class="text-muted small mb-3"><code>{{ $node->ip }}</code></p>
                            <div class="d-flex align-items-center mb-4">
                                <span class="{{ $node->is_active ? 'text-success-bright' : 'text-danger' }} small fw-bold d-flex align-items-center">
                                    <span class="{{ $node->is_active ? 'blink' : '' }} me-2" style="width: 8px; height: 8px; background: {{ $node->is_active ? '#22c55e' : '#ef4444' }}; border-radius: 50%;"></span>
                                    {{ $node->is_active ? 'ONLINE' : 'OFFLINE' }}
                                </span>
                            </div>
                            <div class="d-flex gap-2">
                                <a href="{{ route('nodes.edit', $node->id) }}" wire:navigate class="btn btn-dark btn-sm flex-grow-1 py-2 fw-bold border-0" style="background: rgba(255,255,255,0.05);">ИЗМЕНИТЬ</a>
                                <button wire:click="deleteNode({{ $node->id }})" wire:confirm="Удалить?" class="btn btn-dark btn-sm px-3 border-0" style="background: rgba(220, 53, 69, 0.1);"><i class="bi bi-trash text-danger"></i></button>
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
    @keyframes rotate { from { transform: rotate(0deg); } to { transform: rotate(360deg); } }
    .rotate-anim { animation: rotate 1s linear infinite; display: inline-block; }
    .blink { animation: blink-animation 2s infinite; }
    @keyframes blink-animation { 0% { opacity: 1; } 50% { opacity: 0.4; } 100% { opacity: 1; } }
</style>
