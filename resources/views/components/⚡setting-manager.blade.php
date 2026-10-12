<?php

use App\Services\SettingService;
use Livewire\Component;
use Illuminate\Support\Str;

new class extends Component {
    public $admin_path;
    public $admin_port;

    public $email;
    public $password;

    // Прямой доступ (Direct Domains)
    public $domainsContent = '';
    public $domainsCount = 0;

    public function mount(SettingService $service)
    {
        $this->admin_path = $service->getAdminPath();
        $this->admin_port = env('ADMIN_PORT', 8001);
        $this->email = auth()->user()->email;

        $this->loadDomains();
    }

    public function loadDomains()
    {
        $filePath = file_exists(storage_path('app/domains.txt'))
            ? storage_path('app/domains.txt')
            : (file_exists(resource_path('data/domains.txt')) ? resource_path('data/domains.txt') : null);

        if ($filePath && file_exists($filePath)) {
            $this->domainsContent = file_get_contents($filePath);
        } else {
            $this->domainsContent = "regexp:.*\\.ru$\nregexp:.*\\.su$\ndomain:xn--b1aew.xn--p1ai";
        }

        $this->updateDomainsCount();
    }

    private function updateDomainsCount()
    {
        $lines = array_filter(
            array_map('trim', explode("\n", str_replace("\r", "", $this->domainsContent))),
            fn($line) => !empty($line) && !str_starts_with($line, '#')
        );
        $this->domainsCount = count($lines);
    }

    public function saveDomains()
    {
        $lines = array_filter(
            array_map('trim', explode("\n", str_replace("\r", "", $this->domainsContent))),
            fn($line) => !empty($line)
        );
        $content = implode("\n", $lines) . "\n";

        // Сохраняем в storage/app/domains.txt (игнорируется git, не перезаписывается при pull)
        $storagePath = storage_path('app/domains.txt');
        if (!is_dir(dirname($storagePath))) {
            mkdir(dirname($storagePath), 0755, true);
        }
        file_put_contents($storagePath, $content);

        $this->domainsContent = $content;
        $this->updateDomainsCount();

        session()->flash('domains_success', "Правила маршрутизации сохранены! Всего активных правил: {$this->domainsCount}");
    }

    public function resetDomainsToDefault()
    {
        $defaultPath = resource_path('data/domains.txt');
        if (file_exists($defaultPath)) {
            $this->domainsContent = file_get_contents($defaultPath);
            $this->saveDomains();
            session()->flash('domains_success', 'Список доменов восстановлен из репозитория!');
        }
    }

    public function getHostProperty()
    {
        return parse_url(request()->getSchemeAndHttpHost(), PHP_URL_HOST);
    }

    public function generateRandomPath()
    {
        $this->admin_path = bin2hex(random_bytes(12));
    }

    public function generateRandomPassword()
    {
        $this->password = Str::random(16);
    }

    public function save(SettingService $service)
    {
        // 1. Валидация через сервис
        $validated = $service->validate($this->all(), auth()->id());

        // 2. Выполнение логики обновления
        $service->updateSystemSettings(auth()->user(), $validated);

        session()->flash('success', 'Настройки успешно обновлены!');

        // 3. Редирект на новый путь (navigate: true здесь может не сработать из-за смены роутов)
        return redirect()->to('/' . $this->admin_path . '/settings');
    }
}; ?>

<div x-data="{ showPw: false }" class="animate__animated animate__fadeIn">
    <style>
        .setting-card {
            background: #1a1d21;
            border: 1px solid rgba(255, 255, 255, 0.05);
            border-radius: 16px;
        }
        .input-custom {
            background-color: #0f1114 !important;
            border: 1px solid #334155 !important;
            color: #fff !important;
        }
        .input-custom:focus {
            border-color: #3b82f6 !important;
            box-shadow: 0 0 0 2px rgba(59, 130, 246, 0.1) !important;
        }
        .btn-gen {
            background: rgba(255, 255, 255, 0.03);
            border: 1px solid #334155;
            color: #94a3b8;
        }
        .btn-gen:hover { background: rgba(255, 255, 255, 0.08); color: #fff; }
    </style>

    <div class="mb-4">
        <h2 class="fw-bold text-white m-0">Безопасность системы</h2>
        <p class="text-muted small mb-0">Конфигурация доступа и учетных данных</p>
    </div>

    @if(session('success'))
        <div class="alert alert-success bg-success bg-opacity-10 text-success border-0 mb-4 py-2 small shadow-sm">
            <i class="bi bi-check-circle-fill me-2"></i> {{ session('success') }}
        </div>
    @endif

    <div class="row g-4">
        <div class="col-md-6">
            <div class="setting-card h-100 p-4 shadow-sm">
                <div class="d-flex align-items-center mb-4">
                    <div class="bg-primary bg-opacity-10 p-2 rounded-3 me-3 text-primary">
                        <i class="bi bi-broadcast fs-5"></i>
                    </div>
                    <h5 class="m-0 text-white">Сеть и URL</h5>
                </div>

                <div class="mb-4">
                    <label class="form-label small fw-bold text-muted uppercase">Префикс админ-панели</label>
                    <div class="input-group">
                        <span class="input-group-text bg-dark border-secondary text-muted">/</span>
                        <input type="text" wire:model.live="admin_path" class="form-control input-custom shadow-none">
                        <button type="button" wire:click="generateRandomPath" class="btn btn-gen">
                            <i class="bi bi-dice-5-fill"></i>
                        </button>
                    </div>
                </div>

                <div class="mb-0">
                    <label class="form-label small fw-bold text-muted uppercase">Порт (ADMIN_PORT)</label>
                    <input type="number" wire:model.live="admin_port" class="form-control input-custom shadow-none">
                </div>
            </div>
        </div>

        <div class="col-md-6">
            <div class="setting-card h-100 p-4 shadow-sm">
                <div class="d-flex align-items-center mb-4">
                    <div class="bg-warning bg-opacity-10 p-2 rounded-3 me-3 text-warning">
                        <i class="bi bi-person-badge fs-5"></i>
                    </div>
                    <h5 class="m-0 text-white">Авторизация</h5>
                </div>

                <div class="mb-4">
                    <label class="form-label small fw-bold text-muted uppercase">Email (Логин)</label>
                    <div class="input-group shadow-sm">
                        <span class="input-group-text bg-dark border-secondary text-muted"><i class="bi bi-envelope"></i></span>
                        <input type="email" wire:model.live="email" class="form-control input-custom shadow-none">
                    </div>
                </div>

                <div class="mb-0">
                    <label class="form-label small fw-bold text-muted uppercase">Пароль</label>
                    <div class="input-group shadow-sm">
                        <span class="input-group-text bg-dark border-secondary text-muted" @click="showPw = !showPw" style="cursor: pointer;">
                            <i class="bi" :class="showPw ? 'bi-eye' : 'bi-eye-slash'"></i>
                        </span>
                        <input :type="showPw ? 'text' : 'password'" wire:model.live="password" class="form-control input-custom shadow-none" placeholder="Оставьте пустым для сохранения">
                        <button type="button" wire:click="generateRandomPassword" class="btn btn-gen">
                            <i class="bi bi-shield-plus"></i>
                        </button>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-12 mt-4">
            <div class="setting-card p-4">
                <div class="row align-items-center g-3">
                    <div class="col-lg-8">
                        <div class="d-flex align-items-center">
                            <div class="bg-info bg-opacity-10 p-2 rounded-circle me-3">
                                <i class="bi bi-info-circle text-info fs-5"></i>
                            </div>
                            <div>
                                <p class="text-muted small mb-0 uppercase fw-bold">Будущий адрес доступа:</p>
                                <code class="text-info fs-6">
                                    {{ request()->getScheme() }}://{{ $this->host }}{{ $admin_port ? ':' . $admin_port : '' }}/{{ $admin_path }}
                                </code>
                            </div>
                        </div>
                    </div>
                    <div class="col-lg-4 text-end">
                        <button wire:click="save" class="btn btn-primary w-100 py-2 fw-bold shadow-sm rounded-3">
                            <i class="bi bi-save2 me-2"></i> СОХРАНИТЬ И ПРИМЕНИТЬ
                        </button>
                    </div>
                </div>
            </div>
        </div>

        {{-- Direct Domains Code Editor Card --}}
        <div class="col-12 mt-4">
            <div class="setting-card p-4 shadow-sm" style="border: 1px solid rgba(255, 255, 255, 0.08);">
                <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-3">
                    <div class="d-flex align-items-center">
                        <div class="p-2 rounded-3 me-3 text-warning" style="background: rgba(245, 158, 11, 0.1); border: 1px solid rgba(245, 158, 11, 0.2);">
                            <i class="bi bi-signpost-split-fill fs-5"></i>
                        </div>
                        <div>
                            <h5 class="m-0 text-white fw-bold">Исключения и прямой доступ (Direct Routing)</h5>
                            <p class="text-muted small mb-0">Трафик на указанные домены идет напрямую в обход Xray (RU-ресурсы, банки, Госуслуги)</p>
                        </div>
                    </div>
                    <div class="d-flex align-items-center gap-2">
                        <span class="badge px-3 py-2 rounded-pill font-monospace" style="background: rgba(56, 189, 248, 0.1); color: #38bdf8; border: 1px solid rgba(56, 189, 248, 0.2);">
                            <i class="bi bi-file-earmark-code me-1"></i> domains.txt
                        </span>
                        <span class="badge px-3 py-2 rounded-pill font-monospace" style="background: rgba(245, 158, 11, 0.1); color: #fbbf24; border: 1px solid rgba(245, 158, 11, 0.2);">
                            <i class="bi bi-list-check me-1"></i> {{ $domainsCount }} правил
                        </span>
                    </div>
                </div>

                @if(session('domains_success'))
                    <div class="alert alert-success bg-success bg-opacity-10 text-success border-0 mb-3 py-2 px-3 small rounded-3 d-flex align-items-center">
                        <i class="bi bi-check-circle-fill me-2 fs-6"></i>
                        <span>{{ session('domains_success') }}</span>
                    </div>
                @endif

                {{-- IDE Editor Window --}}
                <div class="rounded-3 overflow-hidden shadow" style="border: 1px solid rgba(255, 255, 255, 0.1); background: #0a0e14;">
                    {{-- IDE Title Bar --}}
                    <div class="d-flex justify-content-between align-items-center px-3 py-2" style="background: #11161d; border-bottom: 1px solid rgba(255, 255, 255, 0.06);">
                        <div class="d-flex align-items-center gap-2">
                            <span style="width: 10px; height: 10px; border-radius: 50%; background: #ff5f56; display: inline-block;"></span>
                            <span style="width: 10px; height: 10px; border-radius: 50%; background: #ffbd2e; display: inline-block;"></span>
                            <span style="width: 10px; height: 10px; border-radius: 50%; background: #27c93f; display: inline-block;"></span>
                            <span class="ms-2 small text-muted font-monospace" style="font-size: 11px;">
                                storage/app/domains.txt
                            </span>
                        </div>
                        <div class="small text-muted font-monospace" style="font-size: 11px;">
                            <span class="text-success">●</span> Live Sync
                        </div>
                    </div>

                    {{-- Code Area --}}
                    <div class="p-2">
                        <textarea 
                            wire:model="domainsContent" 
                            class="form-control border-0 shadow-none font-monospace p-3" 
                            style="background-color: transparent !important; color: #a5f3fc !important; font-size: 13px; line-height: 1.6; height: 350px; resize: vertical; tab-size: 4;"
                            placeholder="regexp:.*\.ru$&#10;domain:yandex.ru"
                            spellcheck="false"
                        ></textarea>
                    </div>

                    {{-- IDE Footer Status Bar --}}
                    <div class="d-flex justify-content-between align-items-center px-3 py-2 small text-muted" style="background: #11161d; border-top: 1px solid rgba(255, 255, 255, 0.06); font-size: 12px;">
                        <div>
                            <span class="text-secondary">Синтаксис:</span>
                            <code class="px-1 rounded" style="background: rgba(255,255,255,0.06); color: #7dd3fc;">regexp:.*\.ru$</code>
                            <code class="px-1 rounded ms-1" style="background: rgba(255,255,255,0.06); color: #7dd3fc;">domain:yandex.ru</code>
                            <code class="px-1 rounded ms-1" style="background: rgba(255,255,255,0.06); color: #7dd3fc;">full:vk.com</code>
                        </div>
                        <div>
                            <span class="text-secondary">Кодировка:</span> UTF-8
                        </div>
                    </div>
                </div>

                {{-- Action Buttons --}}
                <div class="d-flex justify-content-between align-items-center mt-3 pt-2 flex-wrap gap-2">
                    <button type="button" wire:click="resetDomainsToDefault" wire:confirm="Восстановить исходный список правил из репозитория?" class="btn btn-sm btn-outline-secondary px-3 py-2 rounded-3 text-light" style="border-color: rgba(255,255,255,0.15);">
                        <i class="bi bi-arrow-counterclockwise me-1"></i> Восстановить из репозитория
                    </button>
                    <button type="button" wire:click="saveDomains" class="btn btn-primary px-4 py-2 fw-bold rounded-3 shadow-sm" style="background: linear-gradient(135deg, #3b82f6 0%, #1d4ed8 100%); border: none;">
                        <i class="bi bi-check2-circle me-1"></i> СОХРАНИТЬ ПРАВИЛА
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>
