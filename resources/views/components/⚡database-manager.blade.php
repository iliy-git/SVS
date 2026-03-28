<?php

use Illuminate\Support\Facades\DB;
use Livewire\Component;

use Livewire\WithFileUploads;
use Illuminate\Support\Facades\Response;

new class extends Component {
    use WithFileUploads;

    public $backupFile;
    public $confirmWipe = false;
    public $dbSize = '0 B';
    public $stats = [];

    public function mount()
    {
        $this->refreshStats();
    }

    public function refreshStats()
    {
        $path = database_path('database.sqlite');
        if (file_exists($path)) {
            $size = filesize($path);
            $units = ['B', 'KB', 'MB', 'GB'];
            $power = $size > 0 ? floor(log($size, 1024)) : 0;
            $this->dbSize = number_format($size / pow(1024, $power), 2, '.', ',') . ' ' . $units[$power];
        }

        $this->stats = [
            'clients'       => DB::table('clients')->count(),
            'subscriptions' => DB::table('subscriptions')->count(),
            'configs'       => DB::table('configs')->count(),
        ];
    }

    public function export()
    {
        $dbPath = database_path('database.sqlite');

        if (!file_exists($dbPath)) {
            session()->flash('error', 'Файл базы данных не найден.');
            return;
        }

        return Response::download($dbPath, 'backup-' . now()->format('Y-m-d-H-i') . '.sqlite');
    }

    public function wipeDatabase()
    {
        if (!$this->confirmWipe) {
            session()->flash('error', 'Нужно подтвердить удаление, установив галочку.');
            return;
        }

        try {
            DB::statement('PRAGMA foreign_keys = OFF');

            DB::table('client_subscription')->delete();
            DB::table('config_subscription')->delete();
            DB::table('clients')->delete();
            DB::table('subscriptions')->delete();
            DB::table('configs')->delete();
            DB::table('nodes')->delete();

            DB::statement('PRAGMA foreign_keys = ON');

            DB::table('sqlite_sequence')->whereIn('name', [
                'clients', 'subscriptions', 'configs', 'client_subscription', 'config_subscription'
            ])->delete();

            $this->confirmWipe = false;
            session()->flash('success', 'База данных успешно очищена. Все клиенты и конфиги удалены.');
        } catch (\Exception $e) {
            session()->flash('error', 'Ошибка при очистке: ' . $e->getMessage());
        }
    }


    public function import()
    {
        $this->validate([
            'backupFile' => 'required|file|max:20480',
        ]);

        $tempPath = $this->backupFile->getRealPath();

        try {
            $testDb = new \PDO("sqlite:" . $tempPath);
            $result = $testDb->query("PRAGMA integrity_check")->fetch();
            $testDb = null;

            if ($result[0] !== 'ok') {
                session()->flash('error', 'Файл базы данных поврежден (integrity check failed).');
                return;
            }
        } catch (\Exception $e) {
            session()->flash('error', 'Загруженный файл не является валидной базой SQLite.');
            return;
        }

        $dbPath = database_path('database.sqlite');

        if (file_exists($dbPath)) {
            copy($dbPath, $dbPath . '.bak');
        }

        if (copy($tempPath, $dbPath)) {
            $this->backupFile = null;
            $this->refreshStats();
            session()->flash('success', 'Данные успешно восстановлены!');
        } else {
            session()->flash('error', 'Не удалось скопировать файл. Проверьте права.');
        }
    }
}; ?>

<div>
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h3 class="mb-1">Управление БД</h3>
            <p class="text-muted small mb-0">Бэкап и оптимизация системы</p>
        </div>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb mb-0">
                <li class="breadcrumb-item"><a href="{{ route('dashboard') }}" wire:navigate>Главная</a></li>
                <li class="breadcrumb-item active">База данных</li>
            </ol>
        </nav>
    </div>

    @if (session()->has('success'))
        <div class="alert alert-success bg-success text-white border-0 mb-4 py-2 small">
            <i class="bi bi-check-circle me-2"></i> {{ session('success') }}
        </div>
    @endif

    @if (session()->has('error'))
        <div class="alert alert-danger bg-danger text-white border-0 mb-4 py-2 small">
            <i class="bi bi-exclamation-triangle me-2"></i> {{ session('error') }}
        </div>
    @endif

    <div class="row g-4">
        <div class="col-md-5">
            <div class="card mb-4 border-0 shadow-sm" style="background: #1a1d21; border-radius: 12px;">
                <div class="card-body p-4">
                    <div class="d-flex justify-content-between align-items-center mb-4">
                        <h5 class="card-title m-0 text-white"><i class="bi bi-download me-2 text-primary"></i>Выгрузка</h5>
                        <span class="badge bg-primary bg-opacity-10 text-primary border border-primary border-opacity-25 px-2 py-1 small">
                    {{ $dbSize }}
                </span>
                    </div>
                    <p class="text-muted small mb-4">Полная копия <code>database.sqlite</code>. Рекомендуется делать бэкап перед изменениями.</p>
                    <button wire:click="export" class="btn btn-primary w-100 fw-bold py-2 shadow-sm">
                        <i class="bi bi-file-earmark-arrow-down me-2"></i> Создать дамп
                    </button>
                </div>
            </div>

            <div class="card border-0 shadow-sm" style="background: #1a1d21; border-radius: 12px;">
                <div class="card-body p-4">
                    <h6 class="text-muted small text-uppercase fw-bold mb-3" style="letter-spacing: 1px;">Текущее состояние</h6>
                    <div class="d-flex flex-column gap-3">
                        <div class="d-flex justify-content-between align-items-center">
                            <span class="text-white-50 small"><i class="bi bi-people me-2"></i>Клиенты:</span>
                            <span class="badge bg-dark text-white fw-bold">{{ $stats['clients'] ?? 0 }}</span>
                        </div>
                        <div class="d-flex justify-content-between align-items-center">
                            <span class="text-white-50 small"><i class="bi bi-key me-2"></i>Конфигурации:</span>
                            <span class="badge bg-dark text-white fw-bold">{{ $stats['configs'] ?? 0 }}</span>
                        </div>
                        <div class="d-flex justify-content-between align-items-center">
                            <span class="text-white-50 small"><i class="bi bi-card-checklist me-2"></i>Подписки:</span>
                            <span class="badge bg-dark text-white fw-bold">{{ $stats['subscriptions'] ?? 0 }}</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-md-7">
            <div class="card h-100">
                <div class="card-body d-flex flex-column">
                    <h5 class="card-title mb-4"><i class="bi bi-upload me-2 text-warning"></i>Восстановление</h5>

                    <div class="flex-grow-1">
                        <div class="alert border-0 style-3xui-alert mb-4"
                             style="background: rgba(255,193,7,0.05); border: 1px solid rgba(255,193,7,0.1) !important;">
                            <p class="text-warning small mb-0">
                                <i class="bi bi-exclamation-octagon-fill me-2"></i>
                                Загрузка файла <b>полностью перезапишет</b> текущих клиентов, токены и настройки.
                                Восстановление невозможно без наличия другого бэкапа.
                            </p>
                        </div>

                        <form wire:submit.prevent="import">
                            <div class="mb-4">
                                <label class="form-label small text-muted">Файл базы данных (.sqlite)</label>
                                <div class="input-group">
                                    <input type="file" wire:model="backupFile" class="form-control" id="uploadDb"
                                           accept=".sqlite">
                                </div>
                                <div wire:loading wire:target="backupFile" class="text-accent mt-2 small">
                                    <span class="spinner-border spinner-border-sm me-1"></span> Чтение файла...
                                </div>
                                @error('backupFile')
                                <div class="text-danger small mt-1">{{ $message }}</div> @enderror
                            </div>

                            <button type="submit" class="btn btn-warning w-100 fw-bold py-2"
                                    wire:loading.attr="disabled"
                                {{ !$backupFile ? 'disabled' : '' }}>
                                <i class="bi bi-arrow-repeat me-2"></i> Начать импорт данных
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-12 mt-4">
            <div class="card border-danger-subtle" style="background: rgba(220, 53, 69, 0.03) !important;">
                <div class="card-body">
                    <h5 class="card-title text-danger mb-3">
                        <i class="bi bi-exclamation-octagon me-2"></i> Опасная зона
                    </h5>

                    <div class="d-flex align-items-center justify-content-between flex-wrap gap-3">
                        <div style="max-width: 600px;">
                            <p class="mb-0 text-muted small">
                                Кнопка ниже полностью удалит всех <b>клиентов, их подписки и привязанные конфигурации</b>.
                                Настройки панели и системные логи затронуты не будут. Это действие необратимо.
                            </p>
                        </div>

                        <div class="d-flex align-items-center gap-3">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" wire:model.live="confirmWipe" id="wipeCheck">
                                <label class="form-check-item small cursor-pointer" for="wipeCheck">
                                    Я понимаю последствия
                                </label>
                            </div>

                            <button
                                wire:click="wipeDatabase"
                                wire:confirm="ВЫ УВЕРЕНЫ? Все данные клиентов будут удалены навсегда!"
                                class="btn btn-danger btn-sm px-4"
                                @if(!$confirmWipe) disabled @endif
                            >
                                <i class="bi bi-trash3 me-2"></i> Очистить всё
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
