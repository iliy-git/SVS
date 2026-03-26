<?php

use Livewire\Component;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

new class extends Component {
    public $admin_path;
    public $admin_port;

    public $email;
    public $password;

    public function mount()
    {
        $this->admin_path = Setting::where('key', 'admin_uuid')->value('value') ?? 'admin-panel';
        $this->admin_port = env('ADMIN_PORT', 8001);
        $this->email = auth()->user()->email;
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

    protected function updateEnv($key, $value)
    {
        $path = base_path('.env');
        if (File::exists($path)) {
            $content = File::get($path);
            if (str_contains($content, "{$key}=")) {
                $newContent = preg_replace("/^{$key}=.*/m", "{$key}={$value}", $content);
            } else {
                $newContent = $content . "\n{$key}={$value}";
            }
            File::put($path, $newContent);
        }
    }

    public function save()
    {
        $this->validate([
            'admin_path' => 'required|alpha_dash|min:3',
            'admin_port' => 'required|numeric|min:1024|max:65535',
            'email'      => 'required|email|unique:users,email,' . auth()->id(),
            'password'   => 'nullable|min:8'
        ]);

        Setting::updateOrCreate(['key' => 'admin_uuid'], ['value' => $this->admin_path]);
        $this->updateEnv('ADMIN_PORT', $this->admin_port);

        $user = auth()->user();
        $user->email = $this->email;
        if ($this->password) {
            $user->password = Hash::make($this->password);
        }
        $user->save();

        Artisan::call('route:clear');
        Artisan::call('view:clear');

        session()->flash('success', 'Настройки успешно обновлены!');

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
    </div>
</div>
