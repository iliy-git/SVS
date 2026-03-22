<?php

use Livewire\Component;
use App\Models\Setting;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;

new class extends Component {
    public $admin_path;
    public $admin_port;
    public $status = '';

    public function mount()
    {
        $this->admin_path = Setting::where('key', 'admin_uuid')->value('value') ?? 'admin-panel';
        $this->admin_port = env('ADMIN_PORT', 8001);
    }

    public function generateRandomPath()
    {
        $this->admin_path = bin2hex(random_bytes(16));
    }

    protected function updateEnv($key, $value)
    {
        $path = base_path('.env');

        if (File::exists($path)) {
            $content = File::get($path);
            $newContent = preg_replace(
                "/^{$key}=.*/m",
                "{$key}={$value}",
                $content
            );
            File::put($path, $newContent);
        }
    }

    public function save()
    {
        $this->validate([
            'admin_path' => 'required|alpha_dash|min:3',
            'admin_port' => 'required|numeric|min:1024|max:65535'
        ]);

        Setting::updateOrCreate(
            ['key' => 'admin_uuid'],
            ['value' => $this->admin_path]
        );

        $this->updateEnv('ADMIN_PORT', $this->admin_port);

        Artisan::call('route:clear');
        Artisan::call('view:clear');

        if ($this->admin_port != env('ADMIN_PORT')) {
            session()->flash('warning', 'Порт в .env изменен. Чтобы он применился, перезапустите контейнеры: docker compose up -d');
        }

        return redirect()->to('/' . $this->admin_path . '/settings');
    }
}; ?>

<div class="row justify-content-center animate__animated animate__fadeIn">
    <div class="col-md-6">
        <div class="card border-0 shadow-sm rounded-4 mb-4">
            <div class="card-body p-4 p-lg-5">
                <div class="d-flex align-items-center mb-4">
                    <div class="bg-warning bg-opacity-10 p-3 rounded-circle me-3">
                        <i class="bi bi-shield-lock-fill text-warning fs-4"></i>
                    </div>
                    <div>
                        <h4 class="fw-bold m-0">Безопасность и Сеть</h4>
                        <p class="text-muted small mb-0">Настройка доступа к панели</p>
                    </div>
                </div>

                @if(session('warning'))
                    <div class="alert alert-warning border-0 small mb-4">
                        <i class="bi bi-exclamation-triangle-fill me-2"></i> {{ session('warning') }}
                    </div>
                @endif

                <form wire:submit.prevent="save">
                    <div class="mb-4">
                        <label class="form-label small fw-bold text-muted uppercase">URL префикс админ-панели</label>
                        <div class="input-group">
                            <span class="input-group-text bg-light border-0">/</span>
                            <input type="text" wire:model="admin_path"
                                   class="form-control bg-light border-0 py-2 shadow-none @error('admin_path') is-invalid @enderror"
                                   placeholder="например: secret-admin">
                            <button type="button" wire:click="generateRandomPath" class="btn btn-outline-secondary border-0 bg-light">
                                <i class="bi bi-dice-5-fill"></i>
                            </button>
                        </div>
                        @error('admin_path') <small class="text-danger">{{ $message }}</small> @enderror
                    </div>

                    <div class="mb-4">
                        <label class="form-label small fw-bold text-muted uppercase">Внешний порт (ADMIN_PORT)</label>
                        <input type="number" wire:model="admin_port"
                               class="form-control bg-light border-0 py-2 shadow-none @error('admin_port') is-invalid @enderror"
                               placeholder="8001">
                        @error('admin_port') <small class="text-danger">{{ $message }}</small> @enderror
                        <div class="form-text mt-2 small text-muted">
                            Текущий порт в Docker: <strong>{{ env('ADMIN_PORT') }}</strong>. Изменение требует перезапуска Docker.
                        </div>
                    </div>

                    <div class="alert alert-light border-0 small mb-4">
                        <strong>Будущий адрес:</strong><br>
                        <code class="text-primary">{{ request()->getSchemeAndHttpHost() }}:{{ $admin_port }}/{{ $admin_path }}</code>
                    </div>

                    <button type="submit" class="btn btn-dark w-100 py-2 fw-bold rounded-3 shadow-sm">
                        ОБНОВИТЬ НАСТРОЙКИ
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>
