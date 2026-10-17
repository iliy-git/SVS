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
    public string $name = '';
    public string $slug = '';
    public string $description = '';
    public bool $is_active = true;

    // Выбранные инбаунды
    public array $selectedInbounds = [];

    // Буферные поля добавления инбаунда
    public ?int $selectedNodeId = null;
    public ?int $selectedInboundId = null;
    public int $newTrafficLimitGb = 0;
    public bool $newIsTls = false;

    // Редактирование произвольной редакции ссылки для строки таблицы
    public ?int $editingOverrideIndex = null;
    public array $editingOverrides = [];

    public array $availableInbounds = [];

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

        $node = Node::with('flag')->find($this->selectedNodeId);
        $inboundData = collect($this->availableInbounds)->firstWhere('id', (int)$this->selectedInboundId);
        $title = !empty($inboundData['remark']) ? $inboundData['remark'] : ($inboundData['tag'] ?? 'inbound-' . $this->selectedInboundId);

        $this->selectedInbounds[] = [
            'node_id'          => (int)$this->selectedNodeId,
            'node_name'        => $node->name ?? 'Node #' . $this->selectedNodeId,
            'flag_code'        => $node->flag ? strtoupper($node->flag->code) : null,
            'inbound_id'       => (int)$this->selectedInboundId,
            'title'            => $title,
            'traffic_limit_gb' => $this->newTrafficLimitGb,
            'is_tls'           => $this->newIsTls,
            'link_overrides'   => null,
            'priority'         => count($this->selectedInbounds) * 10,
        ];

        $this->selectedNodeId = null;
        $this->selectedInboundId = null;
        $this->newTrafficLimitGb = 0;
        $this->newIsTls = false;
        $this->availableInbounds = [];
    }

    public function openOverrideModal(int $index): void
    {
        $this->editingOverrideIndex = $index;
        $ov = $this->selectedInbounds[$index]['link_overrides'] ?? [];

        $paramsList = [];
        $existingKeys = [];

        if (!empty($ov['params']) && is_array($ov['params'])) {
            foreach ($ov['params'] as $k => $v) {
                $paramsList[] = [
                    'uid'   => (string)Str::uuid(),
                    'key'   => (string)$k,
                    'value' => is_array($v) ? json_encode($v) : (string)$v,
                ];
                $existingKeys[strtolower((string)$k)] = true;
            }
        }

        // Обратная совместимость с существующим плоским форматом записей в БД
        if (isset($ov['header_host']) && !isset($existingKeys['host'])) {
            $paramsList[] = [
                'uid'   => (string)Str::uuid(),
                'key'   => 'host',
                'value' => (string)$ov['header_host'],
            ];
            $existingKeys['host'] = true;
        }

        foreach (['sni', 'path', 'type', 'security', 'fp', 'alpn', 'mode', 'extra'] as $stdKey) {
            if (isset($ov[$stdKey]) && !isset($existingKeys[$stdKey])) {
                $val = $ov[$stdKey];
                $paramsList[] = [
                    'uid'   => (string)Str::uuid(),
                    'key'   => $stdKey,
                    'value' => is_array($val) ? json_encode($val) : (string)$val,
                ];
                $existingKeys[$stdKey] = true;
            }
        }

        foreach ($ov as $k => $v) {
            $kStr = (string)$k;
            if (!in_array($kStr, ['enabled', 'host', 'port', 'suffix', 'params', 'header_host', 'sni', 'path', 'type', 'security', 'fp', 'alpn', 'mode', 'extra']) && !isset($existingKeys[strtolower($kStr)])) {
                $paramsList[] = [
                    'uid'   => (string)Str::uuid(),
                    'key'   => $kStr,
                    'value' => is_array($v) ? json_encode($v) : (string)$v,
                ];
                $existingKeys[strtolower($kStr)] = true;
            }
        }

        $this->editingOverrides = [
            'enabled' => !empty($ov['enabled']) || (!empty($ov) && !isset($ov['enabled'])),
            'host'    => $ov['host'] ?? '',
            'port'    => $ov['port'] ?? '',
            'suffix'  => $ov['suffix'] ?? '',
            'params'  => $paramsList,
        ];
    }

    public function addOverrideParam(string $key = '', string $value = ''): void
    {
        // Не дублируем ключи, если такой уже добавлен
        if ($key !== '') {
            foreach ($this->editingOverrides['params'] as $idx => $p) {
                if (strtolower($p['key']) === strtolower($key)) {
                    if ($value !== '' && empty($this->editingOverrides['params'][$idx]['value'])) {
                        $this->editingOverrides['params'][$idx]['value'] = $value;
                    }
                    return;
                }
            }
        }

        $this->editingOverrides['params'][] = [
            'uid'   => (string)Str::uuid(),
            'key'   => $key,
            'value' => $value,
        ];
    }

    public function removeOverrideParam(int $pIndex): void
    {
        unset($this->editingOverrides['params'][$pIndex]);
        $this->editingOverrides['params'] = array_values($this->editingOverrides['params']);
    }

    public function saveOverrides(): void
    {
        if ($this->editingOverrideIndex === null || !isset($this->selectedInbounds[$this->editingOverrideIndex])) {
            return;
        }

        $host   = trim($this->editingOverrides['host'] ?? '');
        $port   = trim((string)($this->editingOverrides['port'] ?? ''));
        $suffix = trim($this->editingOverrides['suffix'] ?? '');

        $portInt = null;
        if ($port !== '' && is_numeric($port)) {
            $portInt = max(1, min(65535, (int)$port));
        }

        $params = [];
        foreach ($this->editingOverrides['params'] ?? [] as $p) {
            $k = trim($p['key'] ?? '');
            if ($k !== '') {
                $params[$k] = $p['value'] ?? '';
            }
        }

        $hasData = ($host !== '' || $portInt !== null || $suffix !== '' || !empty($params));

        if (!empty($this->editingOverrides['enabled']) && $hasData) {
            $this->selectedInbounds[$this->editingOverrideIndex]['link_overrides'] = [
                'enabled' => true,
                'host'    => $host,
                'port'    => $portInt,
                'suffix'  => $suffix,
                'params'  => $params,
            ];
            $this->selectedInbounds[$this->editingOverrideIndex]['is_tls'] = true;
        } else {
            $this->selectedInbounds[$this->editingOverrideIndex]['link_overrides'] = null;
        }

        $this->editingOverrideIndex = null;
    }

    public function closeOverrideModal(): void
    {
        $this->editingOverrideIndex = null;
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
            'slug'             => 'required|string|max:100|unique:subscription_templates,slug',
            'description'      => 'nullable|string',
            'selectedInbounds' => 'required|array|min:1',
        ], [
            'name.required'        => 'Введите название шаблона.',
            'slug.required'        => 'Укажите уникальный slug.',
            'slug.unique'          => 'Шаблон с таким slug уже существует.',
            'selectedInbounds.min' => 'Добавьте хотя бы один инбаунд в шаблон.',
        ]);

        $template = SubscriptionTemplate::create([
            'name'        => $this->name,
            'slug'        => $this->slug,
            'description' => $this->description,
            'is_active'   => $this->is_active,
        ]);

        foreach ($this->selectedInbounds as $item) {
            TemplateInbound::create([
                'template_id'      => $template->id,
                'node_id'          => $item['node_id'],
                'inbound_id'       => $item['inbound_id'],
                'traffic_limit_gb' => $item['traffic_limit_gb'] ?? 0,
                'is_tls'           => (bool)($item['is_tls'] ?? false),
                'link_overrides'   => !empty($item['link_overrides']) ? $item['link_overrides'] : null,
                'priority'         => $item['priority'] ?? 0,
            ]);
        }

        session()->flash('success', "Шаблон \"{$this->name}\" успешно создан!");

        $this->redirect(route('subscription-templates.index'), navigate: true);
    }
}; ?>

<div class="animate__animated animate__fadeIn">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h2 class="fw-bold text-white m-0">Создание шаблона подписки</h2>
            <p class="text-muted small mb-0">Формирование набора нод, инбаундов и кастомных редакций ссылок</p>
        </div>
        <a href="{{ route('subscription-templates.index') }}" wire:navigate class="btn btn-dark border-0 px-3 fw-bold">
            <i class="bi bi-arrow-left me-1"></i> Назад
        </a>
    </div>

    <form wire:submit="save">
        <div class="row g-4">
            <!-- Левая колонка -->
            <div class="col-lg-4">
                <div class="card border-0 shadow-sm rounded-4 text-white" style="background: #1a1d21;">
                    <div class="card-header border-bottom border-white border-opacity-10 bg-transparent fw-bold py-3">
                        <i class="bi bi-sliders me-2 text-primary"></i> Основные настройки
                    </div>
                    <div class="card-body p-4">
                        <div class="mb-3">
                            <label class="form-label small text-muted text-uppercase fw-bold">Название шаблона</label>
                            <input type="text" wire:model.live="name" class="form-control bg-dark text-white border-0 @error('name') is-invalid @enderror" placeholder="Например: VIP Premium">
                            @error('name') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>

                        <div class="mb-3">
                            <label class="form-label small text-muted text-uppercase fw-bold">Slug (Уникальный код)</label>
                            <input type="text" wire:model="slug" class="form-control bg-dark text-white border-0 @error('slug') is-invalid @enderror" placeholder="vip-premium">
                            @error('slug') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>

                        <div class="mb-3">
                            <label class="form-label small text-muted text-uppercase fw-bold">Описание</label>
                            <textarea wire:model="description" class="form-control bg-dark text-white border-0" rows="4" placeholder="Дополнительное примечание..."></textarea>
                        </div>

                        <div class="tls-toggle-card p-3 mt-4 d-flex align-items-center justify-content-between {{ $is_active ? 'active-green' : '' }}"
                             wire:click="$toggle('is_active')" style="cursor:pointer;">
                            <div class="d-flex align-items-center">
                                <i class="bi bi-lightning-charge-fill me-2 fs-5 {{ $is_active ? 'text-success' : 'text-secondary' }}"></i>
                                <div>
                                    <div class="fw-bold small text-uppercase {{ $is_active ? 'text-white' : 'text-secondary' }}">Активный шаблон</div>
                                    <div class="text-secondary" style="font-size: 11px;">Выбран по умолчанию при создании подписки</div>
                                </div>
                            </div>
                            <div class="form-check m-0" onclick="event.stopPropagation()">
                                <input class="form-check-input custom-checkbox custom-checkbox-green" type="checkbox"
                                       wire:model.live="is_active" id="isActiveSwitch">
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Правая колонка -->
            <div class="col-lg-8">
                <div class="card border-0 shadow-lg rounded-4 text-white" style="background: #1a1d21;">
                    <div class="card-header border-bottom border-white border-opacity-10 bg-transparent fw-bold py-3 d-flex justify-content-between align-items-center">
                        <span><i class="bi bi-diagram-3 me-2 text-primary"></i> Привязанные инбаунды</span>
                        <span class="badge bg-primary rounded-pill px-3">{{ count($selectedInbounds) }}</span>
                    </div>
                    <div class="card-body p-4">

                        <!-- Панель добавления нового инбаунда -->
                        <div class="p-3 mb-4 rounded-4" style="background: rgba(0,0,0,0.25); border: 1px solid rgba(255,255,255,0.06);">
                            <div class="row g-2 mb-2">
                                <div class="col-md-6">
                                    <label class="form-label small text-muted text-uppercase fw-bold">Нода</label>
                                    <select wire:model.live="selectedNodeId" class="form-select bg-dark text-white border-0">
                                        <option value="">-- Выберите ноду --</option>
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
                                    <label class="form-label small text-muted text-uppercase fw-bold">Лимит трафика (ГБ)</label>
                                    <div class="input-group input-group-sm">
                                        <input type="number" min="0" wire:model="newTrafficLimitGb" class="form-control bg-dark text-white border-0" placeholder="0 = безлимит">
                                        <span class="input-group-text bg-dark text-muted border-0">GB</span>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label small text-muted text-uppercase fw-bold">TLS Режим</label>
                                    <div class="tls-toggle-card p-2 d-flex align-items-center justify-content-between {{ $newIsTls ? 'active' : '' }}"
                                         wire:click="$toggle('newIsTls')" style="cursor:pointer;">
                                        <div class="d-flex align-items-center">
                                            <i class="bi bi-shield-lock-fill me-2 {{ $newIsTls ? 'text-warning' : 'text-secondary' }}"></i>
                                            <span class="small fw-bold {{ $newIsTls ? 'text-white' : 'text-secondary' }}">TLS</span>
                                        </div>
                                        <div class="form-check m-0" onclick="event.stopPropagation()">
                                            <input class="form-check-input custom-checkbox" type="checkbox"
                                                   wire:model.live="newIsTls" id="newTlsCheck">
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <button type="button" wire:click="addInbound" class="btn btn-primary w-100 fw-bold btn-sm py-2 shadow-sm">
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

                        <!-- Таблица добавленных инбаундов -->
                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0">
                                <thead style="background: rgba(0,0,0,0.2);">
                                <tr>
                                    <th class="ps-3 text-muted small text-uppercase fw-bold">Нода</th>
                                    <th class="text-muted small text-uppercase fw-bold">Инбаунд</th>
                                    <th class="text-muted small text-uppercase fw-bold" style="width: 100px;">Лимит (ГБ)</th>
                                    <th class="text-center text-muted small text-uppercase fw-bold" style="width: 170px;">Режим ссылки</th>
                                    <th class="text-muted small text-uppercase fw-bold" style="width: 70px;">Приор.</th>
                                    <th class="text-end pe-3 text-muted small text-uppercase fw-bold" style="width: 40px;"></th>
                                </tr>
                                </thead>
                                <tbody class="border-0">
                                @forelse($selectedInbounds as $index => $item)
                                    <tr wire:key="sel-inb-{{ $index }}" class="border-bottom border-white border-opacity-5">
                                        <td class="ps-3">
                                            <div class="d-flex align-items-center">
                                                @if(!empty($item['flag_code']))
                                                    <img src="https://purecatamphetamine.github.io/country-flag-icons/3x2/{{ $item['flag_code'] }}.svg"
                                                         class="rounded-1 me-2" style="width:28px;height:19px;object-fit:cover;">
                                                @else
                                                    <i class="bi bi-server text-info me-2"></i>
                                                @endif
                                                <span class="fw-bold text-white">{{ $item['node_name'] }}</span>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="fw-bold text-white">{{ $item['title'] }}</div>
                                            <code class="text-accent bg-dark px-2 py-1 rounded small">ID: {{ $item['inbound_id'] }}</code>
                                        </td>
                                        <td>
                                            <input type="number" min="0" wire:model="selectedInbounds.{{ $index }}.traffic_limit_gb" class="form-control form-control-sm bg-dark text-white border-0 text-center px-1" title="0 = Безлимит">
                                        </td>
                                        <td class="text-center">
                                            @if(!empty($item['link_overrides']))
                                                <button type="button" wire:click="openOverrideModal({{ $index }})"
                                                        class="btn btn-sm btn-outline-primary d-inline-flex align-items-center gap-1 px-2 py-1 rounded-pill shadow-sm"
                                                        title="Редакция активна. Нажмите для изменения">
                                                    <i class="bi bi-sliders"></i>
                                                    <span style="font-size: 11px;">
                                                        {{ !empty($item['link_overrides']['host']) ? Str::limit($item['link_overrides']['host'], 14) : 'Редакция' }}
                                                    </span>
                                                </button>
                                            @elseif(!empty($item['is_tls']))
                                                <div class="d-inline-flex align-items-center gap-1">
                                                    <span class="badge bg-warning bg-opacity-15 text-warning border border-warning border-opacity-25 py-1 px-2 rounded-pill"
                                                          wire:click="$set('selectedInbounds.{{ $index }}.is_tls', false)" style="cursor: pointer;" title="Нажмите, чтобы выключить TLS">
                                                        <i class="bi bi-shield-lock-fill me-1"></i>TLS
                                                    </span>
                                                    <button type="button" wire:click="openOverrideModal({{ $index }})" class="btn btn-sm btn-dark text-muted p-1 rounded-2" title="Настроить редакцию">
                                                        <i class="bi bi-sliders text-primary"></i>
                                                    </button>
                                                </div>
                                            @else
                                                <div class="d-inline-flex align-items-center gap-1">
                                                    <span class="badge bg-secondary bg-opacity-15 text-light border border-secondary border-opacity-25 py-1 px-2 rounded-pill"
                                                          wire:click="$set('selectedInbounds.{{ $index }}.is_tls', true)" style="cursor: pointer;" title="Нажмите, чтобы включить TLS">
                                                        Прямой
                                                    </span>
                                                    <button type="button" wire:click="openOverrideModal({{ $index }})" class="btn btn-sm btn-dark text-muted p-1 rounded-2" title="Настроить редакцию">
                                                        <i class="bi bi-sliders text-secondary"></i>
                                                    </button>
                                                </div>
                                            @endif
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
                                            В шаблон пока не добавлено ни одного инбаунда.
                                        </td>
                                    </tr>
                                @endforelse
                                </tbody>
                            </table>
                        </div>

                    </div>
                    <div class="card-footer border-top border-white border-opacity-10 bg-transparent text-end py-3">
                        <button type="submit" class="btn btn-primary px-4 fw-bold shadow-sm">
                            <i class="bi bi-check-circle-fill me-2"></i>Сохранить шаблон
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </form>

    <!-- Модальное окно редакции параметров ссылки -->
    @if($editingOverrideIndex !== null)
    <div class="modal fade show d-block" tabindex="-1" wire:click.self="closeOverrideModal" @keydown.escape.window="closeOverrideModal" style="background: rgba(0,0,0,0.75); backdrop-filter: blur(8px); z-index: 1050;">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content border-0 shadow-lg rounded-4 text-white" style="background: #1a1d21; border: 1px solid rgba(255, 255, 255, 0.1) !important;">
                <div class="modal-header border-bottom border-white border-opacity-10 py-3 px-4">
                    <div class="d-flex align-items-center">
                        <div class="bg-primary bg-opacity-10 p-2 rounded-3 me-3 text-primary d-flex align-items-center justify-content-center" style="width: 40px; height: 40px;">
                            <i class="bi bi-sliders fs-5"></i>
                        </div>
                        <div>
                            <h5 class="modal-title fw-bold m-0 text-white">Редакция ссылки инбаунда</h5>
                            <div class="text-muted small" style="font-size: 12px;">
                                {{ $selectedInbounds[$editingOverrideIndex]['node_name'] ?? '' }} &bull; {{ $selectedInbounds[$editingOverrideIndex]['title'] ?? '' }} (ID: {{ $selectedInbounds[$editingOverrideIndex]['inbound_id'] ?? '' }})
                            </div>
                        </div>
                    </div>
                    <button type="button" wire:click="closeOverrideModal" class="btn-close btn-close-white shadow-none"></button>
                </div>

                <div class="modal-body p-4">
                    <!-- Главный переключатель редакции -->
                    <div class="p-3 mb-4 rounded-3 d-flex align-items-center justify-content-between {{ (!empty($editingOverrides['enabled'])) ? 'bg-primary bg-opacity-10 border border-primary border-opacity-25' : 'bg-dark bg-opacity-50 border border-white border-opacity-10' }}"
                         wire:click="$toggle('editingOverrides.enabled')" style="cursor: pointer; transition: all 0.2s ease;">
                        <div class="d-flex align-items-center">
                            <i class="bi bi-toggle2-on me-3 fs-4 {{ (!empty($editingOverrides['enabled'])) ? 'text-primary' : 'text-muted' }}"></i>
                            <div>
                                <div class="fw-bold small text-white">Включить переопределение параметров ссылки</div>
                                <div class="text-muted" style="font-size: 11px;">Подмена хоста подключения (CDN/IP), порта и любых параметров VLESS/xHTTP</div>
                            </div>
                        </div>
                        <div class="form-check form-switch m-0" onclick="event.stopPropagation()">
                            <input class="form-check-input shadow-none" type="checkbox" role="switch"
                                   wire:model.live="editingOverrides.enabled" style="cursor: pointer; width: 2.2rem; height: 1.2rem;">
                        </div>
                    </div>

                    @if(!empty($editingOverrides['enabled']))
                    <!-- Основные поля: Хост, Порт, Суффикс -->
                    <div class="p-3 rounded-3 mb-4" style="background: rgba(0, 0, 0, 0.25); border: 1px solid rgba(255, 255, 255, 0.06);">
                        <div class="row g-3">
                            <div class="col-md-5">
                                <label class="form-label small text-muted fw-bold mb-1" style="font-size: 11px;">Хост подключения (Domain / IP)</label>
                                <input type="text" wire:model="editingOverrides.host" class="form-control bg-dark text-white border-0 shadow-none py-2" placeholder="Оставьте пустым для исх. ноды">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label small text-muted fw-bold mb-1" style="font-size: 11px;">Порт</label>
                                <input type="number" min="1" max="65535" wire:model="editingOverrides.port" class="form-control bg-dark text-white border-0 shadow-none py-2" placeholder="443">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label small text-muted fw-bold mb-1" style="font-size: 11px;">Суффикс названия конфига</label>
                                <input type="text" wire:model="editingOverrides.suffix" class="form-control bg-dark text-white border-0 shadow-none py-2" placeholder="Например: (Обход)">
                            </div>
                        </div>
                    </div>

                    <!-- Произвольные query-параметры -->
                    <div class="p-3 rounded-3" style="background: rgba(0, 0, 0, 0.25); border: 1px solid rgba(255, 255, 255, 0.06);">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <div>
                                <span class="small fw-bold text-white text-uppercase" style="font-size: 11px;">
                                    <i class="bi bi-code-square me-1 text-primary"></i> Параметры ссылки (Query string)
                                </span>
                                <div class="text-muted" style="font-size: 11px;">Любые параметры: sni, host, path, type, security, mode, fp, alpn...</div>
                            </div>
                            <button type="button" wire:click="addOverrideParam('', '')" class="btn btn-sm btn-outline-primary rounded-pill px-3 py-1" style="font-size: 11px;">
                                <i class="bi bi-plus-lg me-1"></i> Добавить параметр
                            </button>
                        </div>

                        @php
                            $quickChips = [
                                'type'     => 'xhttp',
                                'security' => 'tls',
                                'path'     => '/assets/vendor.js',
                                'host'     => '',
                                'sni'      => '',
                                'fp'       => 'randomized',
                                'alpn'     => 'h3,h2,http/1.1',
                                'mode'     => 'packet-up',
                            ];
                        @endphp
                        <!-- Быстрые чипы -->
                        <div class="d-flex flex-wrap align-items-center gap-1 mb-3">
                            <span class="text-muted small me-1" style="font-size: 11px;">Быстро добавить:</span>
                            @foreach($quickChips as $chipKey => $chipVal)
                                <button type="button" wire:click="addOverrideParam('{{ $chipKey }}', '{{ $chipVal }}')"
                                        class="badge bg-dark text-muted border border-secondary border-opacity-25 text-decoration-none py-1 px-2 rounded-2 hover-chip shadow-none" style="font-size: 11px; cursor: pointer;">
                                    + {{ $chipKey }}
                                </button>
                            @endforeach
                        </div>

                        @forelse($editingOverrides['params'] ?? [] as $pIdx => $param)
                            <div class="row g-2 mb-2 align-items-center" wire:key="param-row-{{ $param['uid'] ?? $pIdx }}">
                                <div class="col-md-4">
                                    <input type="text" wire:model="editingOverrides.params.{{ $pIdx }}.key"
                                           class="form-control form-control-sm bg-dark text-white border-0 shadow-none font-monospace py-2" placeholder="Ключ (напр. sni)">
                                </div>
                                <div class="col-md-7">
                                    <input type="text" wire:model="editingOverrides.params.{{ $pIdx }}.value"
                                           class="form-control form-control-sm bg-dark text-white border-0 shadow-none py-2" placeholder="Значение параметра">
                                </div>
                                <div class="col-md-1 text-end">
                                    <button type="button" wire:click="removeOverrideParam({{ $pIdx }})" class="btn btn-sm btn-dark text-danger border-0 p-1" title="Удалить">
                                        <i class="bi bi-trash3"></i>
                                    </button>
                                </div>
                            </div>
                        @empty
                            <div class="text-muted small py-3 text-center" style="font-size: 12px;">
                                <i class="bi bi-info-circle me-1 opacity-50"></i> Дополнительные параметры не заданы. Ссылка сохранит оригинальные параметры инбаунда.
                            </div>
                        @endforelse
                    </div>
                    @else
                    <div class="p-4 rounded-3 text-center text-muted small" style="background: rgba(255, 255, 255, 0.02); border: 1px dashed rgba(255, 255, 255, 0.1);">
                        <i class="bi bi-shield-check fs-3 d-block mb-2 opacity-50 text-secondary"></i>
                        Редакция отключена. Клиентам будет выдаваться стандартная ссылка на ноду без подмены хоста и параметров.
                    </div>
                    @endif
                </div>

                <div class="modal-footer border-top border-white border-opacity-10 py-3 px-4">
                    <button type="button" wire:click="closeOverrideModal" class="btn btn-dark border-0 px-3 fw-bold">Отмена</button>
                    <button type="button" wire:click="saveOverrides" class="btn btn-primary px-4 fw-bold shadow-sm">
                        <i class="bi bi-check2 me-1"></i> Сохранить редакцию
                    </button>
                </div>
            </div>
        </div>
    </div>
    @endif
</div>

<style>
    .hover-chip {
        transition: all 0.2s ease;
    }
    .hover-chip:hover {
        color: #fff !important;
        border-color: #0d6efd !important;
        background: rgba(13, 110, 253, 0.15) !important;
    }
    .tls-toggle-card {
        background: rgba(255, 255, 255, 0.03);
        border: 1px solid rgba(255, 255, 255, 0.08);
        border-radius: 10px;
        transition: all 0.2s ease;
    }
    .tls-toggle-card.active {
        background: rgba(255, 193, 7, 0.1);
        border-color: rgba(255, 193, 7, 0.35);
    }
    .custom-checkbox {
        width: 1.4rem !important;
        height: 1.4rem !important;
        background-color: rgba(255, 255, 255, 0.1) !important;
        border: 1px solid rgba(255, 255, 255, 0.2) !important;
        cursor: pointer;
        transition: all 0.2s ease;
    }
    .custom-checkbox:checked {
        background-color: #ffc107 !important;
        border-color: #ffc107 !important;
    }
    .active-green {
        background: rgba(34, 197, 94, 0.08) !important;
        border-color: rgba(34, 197, 94, 0.35) !important;
    }
    .custom-checkbox-green:checked {
        background-color: #22c55e !important;
        border-color: #22c55e !important;
    }
</style>
