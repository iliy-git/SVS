<?php

use Livewire\Component;
use Livewire\WithPagination;
use Livewire\Attributes\Computed;
use App\Models\SubscriptionTemplate;

new class extends Component
{
    use WithPagination;

    protected string $paginationTheme = 'bootstrap';

    public string $search = '';

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    #[Computed]
    public function templates()
    {
        return SubscriptionTemplate::query()
            ->withCount('inbounds')
            ->when($this->search, function ($query) {
                $query->where('name', 'like', '%' . $this->search . '%')
                    ->orWhere('slug', 'like', '%' . $this->search . '%')
                    ->orWhere('description', 'like', '%' . $this->search . '%');
            })
            ->latest()
            ->paginate(10);
    }

    public function toggleActive(int $templateId): void
    {
        $template = SubscriptionTemplate::find($templateId);
        if ($template) {
            $template->is_active = !$template->is_active;
            $template->save();

            session()->flash('success', "Статус шаблона \"{$template->name}\" изменен.");
        }
    }

    public function deleteTemplate(int $templateId): void
    {
        $template = SubscriptionTemplate::find($templateId);
        if ($template) {
            $name = $template->name;
            $template->delete();

            session()->flash('success', "Шаблон \"{$name}\" успешно удален.");
        }
    }
}; ?>

<div class="animate__animated animate__fadeIn">
    <!-- Шапка страницы -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h2 class="fw-bold text-white m-0">Шаблоны подписок</h2>
            <p class="text-muted small mb-0">Управление наборами нод и инбаундов для быстрой выдачи конфигов</p>
        </div>
        <a href="{{ route('subscription-templates.create') }}" wire:navigate class="btn btn-primary px-4 shadow-sm fw-bold">
            <i class="bi bi-plus-circle-fill me-2"></i>Создать шаблон
        </a>
    </div>

    <!-- Уведомления -->
    @if (session()->has('success'))
        <div class="alert alert-success alert-dismissible fade show bg-success bg-opacity-10 border-success text-success mb-4 rounded-4" role="alert">
            <i class="bi bi-check-circle me-2"></i> {{ session('success') }}
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    @endif

    <!-- Поиск -->
    <div class="card border-0 shadow-sm mb-4 rounded-4 bg-dark" style="border: 1px solid rgba(255,255,255,0.05) !important;">
        <div class="card-body p-2 d-flex align-items-center">
            <i class="bi bi-search m-2 text-muted"></i>
            <input type="text"
                   wire:model.live.debounce.300ms="search"
                   class="form-control border-0 shadow-none ps-3 bg-transparent text-white"
                   placeholder="Поиск по названию или slug...">
            <div wire:loading wire:target="search" class="spinner-border spinner-border-sm text-primary me-3"></div>
            @if($search)
                <button class="btn btn-link text-muted p-0 me-3 border-0 shadow-none" type="button" wire:click="$set('search', '')">
                    <i class="bi bi-x-lg"></i>
                </button>
            @endif
        </div>
    </div>

    <!-- Таблица шаблонов -->
    <div class="card border-0 shadow-sm rounded-4 overflow-hidden" style="background: #1a1d21;">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead style="background: rgba(0,0,0,0.2);">
                <tr>
                    <th class="ps-4 py-3 text-muted small text-uppercase fw-bold" style="width: 70px;">ID</th>
                    <th class="text-muted small text-uppercase fw-bold">Название</th>
                    <th class="text-muted small text-uppercase fw-bold">Slug</th>
                    <th class="text-muted small text-uppercase fw-bold">Инбаунды</th>
                    <th class="text-muted small text-uppercase fw-bold">Статус</th>
                    <th class="text-muted small text-uppercase fw-bold">Создан</th>
                    <th class="text-end pe-4 text-muted small text-uppercase fw-bold" style="width: 140px;">Действия</th>
                </tr>
                </thead>
                <tbody class="border-0">
                @forelse($this->templates as $template)
                    <tr wire:key="tmpl-{{ $template->id }}" class="border-bottom border-white border-opacity-5">
                        <td class="ps-4 text-muted fw-bold">#{{ $template->id }}</td>
                        <td>
                            <div class="fw-bold text-white fs-6">{{ $template->name }}</div>
                            @if($template->description)
                                <div class="text-muted small text-truncate" style="max-width: 250px;">
                                    {{ $template->description }}
                                </div>
                            @endif
                        </td>
                        <td>
                            <code class="text-info bg-dark px-2 py-1 rounded small border border-white border-opacity-10">
                                {{ $template->slug }}
                            </code>
                        </td>
                        <td>
                                <span class="badge bg-dark border border-secondary text-white px-2 py-1">
                                    <i class="bi bi-diagram-3 me-1 text-primary"></i>
                                    {{ $template->inbounds_count }}
                                </span>
                        </td>
                        <td>
                            <button type="button"
                                    wire:click="toggleActive({{ $template->id }})"
                                    class="btn btn-sm p-0 border-0">
                                @if($template->is_active)
                                    <span class="text-success-bright small fw-bold d-flex align-items-center">
                                            <span class="blink me-2" style="width: 8px; height: 8px; background: #22c55e; border-radius: 50%;"></span>
                                            АКТИВЕН
                                        </span>
                                @else
                                    <span class="text-danger small fw-bold d-flex align-items-center">
                                            <span class="me-2" style="width: 8px; height: 8px; background: #ef4444; border-radius: 50%;"></span>
                                            НЕАКТИВЕН
                                        </span>
                                @endif
                            </button>
                        </td>
                        <td class="text-muted small">
                            {{ $template->created_at->format('d.m.Y H:i') }}
                        </td>
                        <td class="text-end pe-4">
                            <div class="btn-group gap-1">
                                <a href="{{ route('subscription-templates.edit', $template->id) }}"
                                   wire:navigate
                                   class="btn btn-sm btn-dark border-0 rounded-2"
                                   title="Редактировать">
                                    <i class="bi bi-pencil-square text-primary"></i>
                                </a>
                                <button type="button"
                                        wire:click="deleteTemplate({{ $template->id }})"
                                        wire:confirm="Удалить шаблон &quot;{{ $template->name }}&quot;?"
                                        class="btn btn-sm btn-dark border-0 rounded-2"
                                        title="Удалить">
                                    <i class="bi bi-trash3 text-danger"></i>
                                </button>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="text-center text-muted py-5">
                            <i class="bi bi-layers fs-1 d-block mb-3 opacity-25"></i>
                            @if($search)
                                По запросу <strong>"{{ $search }}"</strong> ничего не найдено.
                            @else
                                Шаблоны подписок еще не созданы.
                            @endif
                        </td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </div>

        @if($this->templates->hasPages())
            <div class="card-footer border-0 p-3" style="background: rgba(0,0,0,0.1);">
                {{ $this->templates->links() }}
            </div>
        @endif
    </div>
</div>

<style>
    .blink { animation: blink-animation 2s infinite; }
    @keyframes blink-animation { 0% { opacity: 1; } 50% { opacity: 0.4; } 100% { opacity: 1; } }
    .text-success-bright { color: #22c55e; }
</style>
