<?php

use Livewire\Component;
use Livewire\Attributes\Computed;
use App\Models\SubscriptionTemplate;
use App\Models\TemplateInbound;
use App\Models\Node;
use App\Services\NodeService;
use Illuminate\Support\Str;

new class extends Component
{
    public int $templateId;
    public string $name = '';
    public string $slug = '';
    public string $description = '';
    public bool $is_active = true;

    // Список привязанных элементов
    public array $selectedInbounds = [];

    // Буферные поля
    public ?int $selectedNodeId = null;
    public ?int $selectedInboundId = null;
    public int $newTrafficLimitGb = 0;
    public bool $newIsTls = false;

    public array $availableInbounds = [];

    public function mount(int $templateId): void
    {
        $this->templateId = $templateId;
        $template = SubscriptionTemplate::with('inbounds.node')->findOrFail($templateId);

        $this->name = $template->name;
        $this->slug = $template->slug;
        $this->description = $template->description ?? '';
        $this->is_active = (bool)$template->is_active;

        // Предзагрузка существующих связей с трафиком и TLS
        foreach ($template->inbounds as $inboundRelation) {
            $this->selectedInbounds[] = [
                'node_id'          => $inboundRelation->node_id,
                'node_name'        => $inboundRelation->node->name ?? 'Node #' . $inboundRelation->node_id,
                'inbound_id'       => $inboundRelation->inbound_id,
                'title'            => 'Inbound #' . $inboundRelation->inbound_id,
                'traffic_limit_gb' => $inboundRelation->traffic_limit_gb ?? 0,
                'is_tls'           => (bool)($inboundRelation->is_tls ?? false),
                'priority'         => $inboundRelation->priority ?? 0,
            ];
        }
    }

    public function updatedName($value): void
    {
        if (empty($this->slug)) {
            $this->slug = Str::slug($value);
        }
    }

    public function updatedSelectedNodeId($nodeId, NodeService $nodeService): void
    {
        $this->selectedInboundId = null;
        $this->availableInbounds = [];

        if (!$nodeId) return;

        $node = Node::find($nodeId);
        if ($node) {
            $this->availableInbounds = $nodeService->getInbounds($node);
        }
    }

    public function addInbound(): void
    {
        $this->validate([
            'selectedNodeId'    => 'required|exists:nodes,id',
            'selectedInboundId' => 'required|integer',
            'newTrafficLimitGb' => 'required|integer|min:0',
        ], [
            'selectedNodeId.required'    => 'Выберите ноду.',
            'selectedInboundId.required' => 'Выберите инбаунд.',
        ]);

        foreach ($this->selectedInbounds as $item) {
            if ((int)$item['node_id'] === (int)$this->selectedNodeId && (int)$item['inbound_id'] === (int)$this->selectedInboundId) {
                $this->addError('selectedInboundId', 'Этот инбаунд уже добавлен.');
                return;
            }
        }

        $node = Node::find($this->selectedNodeId);

        $inboundData = collect($this->availableInbounds)->firstWhere('id', (int)$this->selectedInboundId);
        $title = !empty($inboundData['remark']) ? $inboundData['remark'] : ($inboundData['tag'] ?? 'inbound-' . $this->selectedInboundId);

        $this->selectedInbounds[] = [
            'node_id'          => (int)$this->selectedNodeId,
            'node_name'        => $node->name ?? 'Node #' . $this->selectedNodeId,
            'inbound_id'       => (int)$this->selectedInboundId,
            'title'            => $title,
            'traffic_limit_gb' => $this->newTrafficLimitGb,
            'is_tls'           => $this->newIsTls,
            'priority'         => count($this->selectedInbounds) * 10,
        ];

        // Сброс буферных полей
        $this->selectedNodeId = null;
        $this->selectedInboundId = null;
        $this->newTrafficLimitGb = 0;
        $this->newIsTls = false;
        $this->availableInbounds = [];
    }

    public function removeInbound(int $index): void
    {
        unset($this->selectedInbounds[$index]);
        $this->selectedInbounds = array_values($this->selectedInbounds);
    }

    #[Computed]
    public function nodes()
    {
        return Node::all();
    }

    public function save(): void
    {
        $this->validate([
            'name'             => 'required|string|max:255',
            'slug'             => 'required|string|max:100|unique:subscription_templates,slug,' . $this->templateId,
            'description'      => 'nullable|string',
            'selectedInbounds' => 'required|array|min:1',
        ], [
            'name.required'        => 'Введите название шаблона.',
            'slug.required'        => 'Укажите уникальный slug.',
            'slug.unique'          => 'Шаблон с таким slug уже существует.',
            'selectedInbounds.min' => 'Добавьте хотя бы один инбаунд в шаблон.',
        ]);

        $template = SubscriptionTemplate::findOrFail($this->templateId);
        $template->update([
            'name'        => $this->name,
            'slug'        => $this->slug,
            'description' => $this->description,
            'is_active'   => $this->is_active,
        ]);

        // Синхронизируем инбаунды
        $template->inbounds()->delete();

        foreach ($this->selectedInbounds as $item) {
            TemplateInbound::create([
                'template_id'      => $template->id,
                'node_id'          => $item['node_id'],
                'inbound_id'       => $item['inbound_id'],
                'traffic_limit_gb' => $item['traffic_limit_gb'] ?? 0,
                'is_tls'           => (bool)($item['is_tls'] ?? false),
                'priority'         => $item['priority'] ?? 0,
            ]);
        }

        session()->flash('success', 'Шаблон подписки успешно обновлен!');

        $this->redirect(route('subscription-templates.index'), navigate: true);
    }
}; ?>

<div class="animate__animated animate__fadeIn">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h2 class="fw-bold text-white m-0">Редактирование шаблона #{{ $templateId }}</h2>
            <p class="text-muted small mb-0">Изменение состава нод, лимитов и параметров TLS</p>
        </div>
        <a href="{{ route('subscription-templates.index') }}" wire:navigate class="btn btn-dark border-0 px-3 fw-bold">
            <i class="bi bi-arrow-left me-1"></i> Назад
        </a>
    </div>

    <form wire:submit="save">
        <div class="row g-4">
            <!-- Параметры шаблона -->
            <div class="col-lg-4">
                <div class="card border-0 shadow-sm rounded-4 text-white" style="background: #1a1d21;">
                    <div class="card-header border-bottom border-white border-opacity-10 bg-transparent fw-bold py-3">
                        <i class="bi bi-sliders me-2 text-primary"></i> Основные настройки
                    </div>
                    <div class="card-body p-4">
                        <div class="mb-3">
                            <label class="form-label small text-muted text-uppercase fw-bold">Название шаблона</label>
                            <input type="text" wire:model.live="name" class="form-control bg-dark text-white border-0 @error('name') is-invalid @enderror">
                            @error('name') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>

                        <div class="mb-3">
                            <label class="form-label small text-muted text-uppercase fw-bold">Slug (Уникальный код)</label>
                            <input type="text" wire:model="slug" class="form-control bg-dark text-white border-0 @error('slug') is-invalid @enderror">
                            @error('slug') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>

                        <div class="mb-3">
                            <label class="form-label small text-muted text-uppercase fw-bold">Описание</label>
                            <textarea wire:model="description" class="form-control bg-dark text-white border-0" rows="3"></textarea>
                        </div>

                        <div class="form-check form-switch mt-4">
                            <input class="form-check-input" type="checkbox" wire:model="is_active" id="isActiveSwitch">
                            <label class="form-check-label text-white fw-bold" for="isActiveSwitch">Активный шаблон</label>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Конструктор инбаундов -->
            <div class="col-lg-8">
                <div class="card border-0 shadow-sm rounded-4 text-white" style="background: #1a1d21;">
                    <div class="card-header border-bottom border-white border-opacity-10 bg-transparent fw-bold py-3 d-flex justify-content-between align-items-center">
                        <span><i class="bi bi-diagram-3 me-2 text-primary"></i> Привязанные инбаунды</span>
                        <span class="badge bg-primary rounded-pill px-3">{{ count($selectedInbounds) }}</span>
                    </div>
                    <div class="card-body p-4">

                        <!-- Панель добавления -->
                        <div class="p-3 mb-4 rounded-4" style="background: rgba(0,0,0,0.2); border: 1px solid rgba(255,255,255,0.05);">
                            <div class="row g-2 mb-2">
                                <div class="col-md-6">
                                    <label class="form-label small text-muted text-uppercase fw-bold">Выберите Ноду</label>
                                    <select wire:model.live="selectedNodeId" class="form-select bg-dark text-white border-0">
                                        <option value="">-- Нода --</option>
                                        @foreach($this->nodes as $node)
                                            <option value="{{ $node->id }}">{{ $node->name }} ({{ $node->ip }})</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label small text-muted text-uppercase fw-bold">Инбаунд (3x-ui)</label>
                                    <select wire:model="selectedInboundId" class="form-select bg-dark text-white border-0" @disabled(!$selectedNodeId)>
                                        <option value="">-- Выберите инбаунд --</option>
                                        @foreach($availableInbounds as $inbound)
                                            @php
                                                $id = $inbound['id'];
                                                $title = !empty($inbound['remark']) ? $inbound['remark'] : ($inbound['tag'] ?? 'inbound-' . $id);
                                                $protocol = strtoupper($inbound['protocol'] ?? 'PROXY');
                                                $port = $inbound['port'] ?? '';
                                            @endphp
                                            <option value="{{ $id }}">
                                                #{{ $id }} — {{ $title }} ({{ $protocol }}:{{ $port }})
                                            </option>
                                        @endforeach
                                    </select>
                                </div>
                            </div>

                            <div class="row g-2 align-items-end">
                                <div class="col-md-5">
                                    <label class="form-label small text-muted text-uppercase fw-bold">Трафик (ГБ)</label>
                                    <div class="input-group input-group-sm">
                                        <input type="number" min="0" wire:model="newTrafficLimitGb" class="form-control bg-dark text-white border-0" placeholder="0 = безлимит">
                                        <span class="input-group-text bg-dark text-muted border-0">GB</span>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="form-check form-switch pt-2">
                                        <input class="form-check-input" type="checkbox" wire:model="newIsTls" id="newTlsCheckEdit">
                                        <label class="form-check-label text-white fw-bold small" for="newTlsCheckEdit">
                                            <i class="bi bi-shield-lock text-warning me-1"></i> Включить TLS
                                        </label>
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <button type="button" wire:click="addInbound" class="btn btn-primary w-100 fw-bold btn-sm py-2">
                                        <i class="bi bi-plus-lg me-1"></i> Добавить
                                    </button>
                                </div>
                            </div>

                            <div wire:loading wire:target="selectedNodeId" class="text-info small mt-2">
                                <div class="spinner-border spinner-border-sm me-1" role="status"></div> Загрузка инбаундов с ноды...
                            </div>

                            @error('selectedNodeId') <div class="text-danger small mt-1">{{ $message }}</div> @enderror
                            @error('selectedInboundId') <div class="text-danger small mt-1">{{ $message }}</div> @enderror
                            @error('selectedInbounds') <div class="text-danger small mt-1">{{ $message }}</div> @enderror
                        </div>

                        <!-- Таблица элементов -->
                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0">
                                <thead style="background: rgba(0,0,0,0.2);">
                                <tr>
                                    <th class="ps-3 text-muted small text-uppercase fw-bold">Нода</th>
                                    <th class="text-muted small text-uppercase fw-bold">Инбаунд</th>
                                    <th class="text-muted small text-uppercase fw-bold" style="width: 110px;">Лимит (ГБ)</th>
                                    <th class="text-center text-muted small text-uppercase fw-bold" style="width: 60px;">TLS</th>
                                    <th class="text-muted small text-uppercase fw-bold" style="width: 80px;">Приоритет</th>
                                    <th class="text-end pe-3 text-muted small text-uppercase fw-bold" style="width: 40px;"></th>
                                </tr>
                                </thead>
                                <tbody class="border-0">
                                @forelse($selectedInbounds as $index => $item)
                                    <tr wire:key="sel-inb-{{ $index }}" class="border-bottom border-white border-opacity-5">
                                        <td class="ps-3">
                                            <i class="bi bi-server text-info me-2"></i>
                                            <span class="fw-bold text-white">{{ $item['node_name'] }}</span>
                                        </td>
                                        <td>
                                            <div class="fw-bold text-white">{{ $item['title'] ?? ('Inbound #' . $item['inbound_id']) }}</div>
                                            <code class="text-accent bg-dark px-2 py-1 rounded small">ID: {{ $item['inbound_id'] }}</code>
                                        </td>
                                        <td>
                                            <input type="number" min="0" wire:model="selectedInbounds.{{ $index }}.traffic_limit_gb" class="form-control form-control-sm bg-dark text-white border-0 text-center px-1" title="0 = Безлимит">
                                        </td>
                                        <td class="text-center">
                                            <div class="form-check form-switch d-inline-block m-0">
                                                <input class="form-check-input" type="checkbox" wire:model="selectedInbounds.{{ $index }}.is_tls">
                                            </div>
                                        </td>
                                        <td>
                                            <input type="number" wire:model="selectedInbounds.{{ $index }}.priority" class="form-control form-control-sm bg-dark text-white border-0 text-center">
                                        </td>
                                        <td class="text-end pe-3">
                                            <button type="button" wire:click="removeInbound({{ $index }})" class="btn btn-sm btn-dark border-0 rounded-2">
                                                <i class="bi bi-trash3 text-danger"></i>
                                            </button>
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="6" class="text-center text-muted py-5">
                                            <i class="bi bi-inbox fs-2 d-block mb-2 opacity-25"></i>
                                            В шаблоне пока нет привязанных инбаундов.
                                        </td>
                                    </tr>
                                @endforelse
                                </tbody>
                            </table>
                        </div>

                    </div>
                    <div class="card-footer border-top border-white border-opacity-10 bg-transparent text-end py-3">
                        <button type="submit" class="btn btn-primary px-4 fw-bold shadow-sm">
                            <i class="bi bi-check-circle-fill me-2"></i>Сохранить изменения
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </form>
</div>
