<?php

use App\Models\TelegramUser;
use App\Services\TelegramService;
use Livewire\Component;
use Livewire\Attributes\Computed;

new class extends Component {
    public $botToken = '';
    public $chatId = '';
    public $testStatus = '';
    public $search = '';

    public $isPolling = false;
    public $notifyNodes = false;

    public function mount()
    {
        $this->botToken = env('TELEGRAM_BOT_TOKEN', '');
        $this->chatId = env('TELEGRAM_CHAT_ID', '');

        $this->isPolling = \App\Models\Setting::where('key', 'tg_poll_enabled')->value('value') === '1';
        $this->notifyNodes = \App\Models\Setting::where('key', 'notify_nodes_status')->value('value') === '1';
    }

    public function save(TelegramService $service)
    {
        $service->updateSettings(
            $this->botToken,
            $this->chatId,
            $this->isPolling,
            $this->notifyNodes
        );

        $statuses = [];
        if ($this->isPolling) $statuses[] = 'Polling запущен';
        if ($this->notifyNodes) $statuses[] = 'Мониторинг нод активирован';

        $message = count($statuses) > 0
            ? 'Настройки сохранены! Запущенные процессы: ' . implode(', ', $statuses) . '.'
            : 'Настройки сохранены, фоновые процессы отключены.';

        session()->flash('message', $message);
    }

    #[Computed]
    public function users()
    {
        return TelegramUser::query()
            ->when($this->search, fn($q) => $q->where('first_name', 'like', "%{$this->search}%")
                ->orWhere('username', 'like', "%{$this->search}%")
                ->orWhere('telegram_id', 'like', "%{$this->search}%"))
            ->orderBy('last_message_at', 'desc')->get();
    }

    public function selectUser($userId)
    {
        $this->dispatch('openChat', userId: $userId)->to('telegram-chat');
    }

    public function sendTestMessage(TelegramService $service)
    {
        $success = $service->sendMessage(
            $this->chatId,
            "🚀 Связь с SubVpnSystem установлена!"
        );

        $this->testStatus = $success ? '✅ Отправлено!' : '❌ Ошибка';
    }

    #[Computed]
    public function latestMessageAt()
    {
        return TelegramUser::max('last_message_at');
    }
}; ?>

<div class="animate-fade-in">
    <nav class="mb-3 mb-md-4">
        <ol class="breadcrumb small mb-0 flex-nowrap overflow-auto">
            <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">Главная</a></li>
            <li class="breadcrumb-item active">Telegram API</li>
        </ol>
    </nav>

    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center mb-4 gap-2">
        <h2 class="h3 fw-bold mb-0"><i class="bi bi-telegram text-info me-2"></i>Telegram Интеграция</h2>
        @if($testStatus) <span class="badge bg-light p-2 text-dark border">{{ $testStatus }}</span> @endif
    </div>

    <div class="row g-4">
        <div class="col-xl-8">
            <div class="card shadow-sm mb-4">
                <div class="card-body p-3 p-md-4">
                    @if (session()->has('message'))
                        <div class="alert alert-success border-0 small py-2 mb-4">{{ session('message') }}</div>
                    @endif

                    <form wire:submit.prevent="save">
                        <div class="row g-3">
                            <div class="col-12">
                                <label class="form-label small text-muted fw-bold">BOT TOKEN</label>
                                <input type="password" wire:model="botToken" class="form-control form-control-sm">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small text-muted fw-bold">ADMIN CHAT ID</label>
                                <input type="text" wire:model="chatId" class="form-control form-control-sm">
                            </div>
                            <div class="col-12">
                                <div class="p-3 rounded-4 border border-secondary-subtle bg-dark shadow-sm d-flex align-items-center justify-content-between transition-all hover-shadow">
                                    <div class="d-flex align-items-center gap-3">
                                        <div class="icon-shape {{ $isPolling ? 'bg-primary text-white' : 'bg-secondary bg-opacity-10 text-muted' }} rounded-3 p-2 transition-all">
                                            <i class="bi bi-broadcast fs-5"></i>
                                        </div>
                                        <div>
                                            <label class="form-check-label d-block fw-bold text-light mb-0" for="pollingCheck" style="cursor: pointer;">
                                                Прием сообщений (Polling)
                                            </label>
                                            <div class="text-muted small" style="font-size: 0.75rem;">
                                                {{ $isPolling ? 'Бот активно слушает эфир...' : 'Фоновый процесс выключен' }}
                                            </div>
                                        </div>
                                    </div>

                                    <div class="tg-switch">
                                        <input type="checkbox" wire:model.live="isPolling" id="pollingCheck" class="tg-switch-input">
                                        <label for="pollingCheck" class="tg-switch-label"></label>
                                    </div>
                                </div>
                            </div>
                            <div class="col-12 mb-3">
                                <div class="p-3 rounded-4 border border-secondary-subtle bg-dark shadow-sm d-flex align-items-center justify-content-between transition-all hover-shadow">
                                    <div class="d-flex align-items-center gap-3">
                                        <div class="icon-shape {{ $notifyNodes ? 'bg-success text-white' : 'bg-secondary bg-opacity-10 text-muted' }} rounded-3 p-2 transition-all">
                                            <i class="bi bi-activity fs-5"></i>
                                        </div>
                                        <div>
                                            <label class="form-check-label d-block fw-bold text-light mb-0" for="notifyNodesCheck" style="cursor: pointer;">
                                                Мониторинг состояния нод
                                            </label>
                                            <div class="text-muted small" style="font-size: 0.75rem;">
                                                {{ $notifyNodes ? 'Система отправит алерт при изменении статуса сервера' : 'Фоновые уведомления отключены' }}
                                            </div>
                                        </div>
                                    </div>

                                    <div class="tg-switch">
                                        <input type="checkbox" wire:model.live="notifyNodes" id="notifyNodesCheck" class="tg-switch-input">
                                        <label for="notifyNodesCheck" class="tg-switch-label"></label>
                                    </div>
                                </div>
                            </div>

                            <style>
                                /* Кастомный свитч без конфликтов с Bootstrap */
                                .tg-switch { position: relative; width: 50px; height: 26px; }
                                .tg-switch-input { opacity: 0; width: 0; height: 0; }
                                .tg-switch-label {
                                    position: absolute; cursor: pointer; top: 0; left: 0; right: 0; bottom: 0;
                                    background-color: #334155; transition: .4s; border-radius: 34px;
                                }
                                .tg-switch-label:before {
                                    position: absolute; content: ""; height: 18px; width: 18px; left: 4px; bottom: 4px;
                                    background-color: white; transition: .4s; border-radius: 50%;
                                }
                                .tg-switch-input:checked + .tg-switch-label { background-color: #0d6efd; }
                                .tg-switch-input:checked + .tg-switch-label:before { transform: translateX(24px); }
                                .hover-shadow:hover { border-color: rgba(13, 110, 253, 0.5) !important; }
                            </style>
                            <div class="col-12 d-flex gap-2">
                                <button type="submit" class="btn btn-primary btn-sm px-4 shadow-sm">Сохранить</button>
                                <button type="button" wire:click="sendTestMessage" class="btn btn-outline-secondary btn-sm">Тест</button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>

            <div class="card shadow-sm border-0">
                <div class="card-header bg-dark border-0 pt-4 px-4">
                    <h3 class="h5 fw-bold mb-3">Пользователи ({{ $this->users->count() }})</h3>
                    <input type="text" wire:model.live.debounce.300ms="search" class="form-control form-control-sm bg-light border-0" placeholder="Поиск...">
                </div>

                <div class="card-body p-0" wire:poll.15s>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="table-light d-none d-md-table-header-group">
                            <tr>
                                <th class="ps-4">Имя</th>
                                <th>ID</th>
                                <th>Активность</th>
                                <th class="text-end pe-4">Действие</th>
                            </tr>
                            </thead>
                            <tbody>
                            @foreach($this->users as $user)
                                <tr>
                                    <td class="ps-4">
                                        <div class="fw-bold">{{ $user->first_name }}</div>
                                        <div class="text-muted small">{{ $user->username ? '@'.$user->username : '—' }}</div>
                                    </td>
                                    <td class="d-none d-md-table-cell"><code class="small">{{ $user->telegram_id }}</code></td>
                                    <td class="small">{{ $user->last_message_at?->diffForHumans() }}</td>
                                    <td class="text-end pe-4">
                                        <button wire:click="selectUser({{ $user->id }})" class="btn btn-sm btn-outline-primary border-0 bg-light">
                                            <i class="bi bi-chat-dots-fill"></i>
                                        </button>
                                    </td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-xl-4">
            <div class="card border-0 shadow-sm p-4" style="background: rgba(var(--bs-primary-rgb), 0.05);">
                <h4 class="h6 fw-bold text-primary mb-4">УВЕДОМЛЕНИЯ</h4>
                <div class="vstack gap-3 small">
                    <div class="d-flex gap-3"><i class="bi bi-shield-check text-success"></i> <span>Новый HWID</span></div>
                    <div class="d-flex gap-3"><i class="bi bi-graph-down text-warning"></i> <span>Лимит трафика</span></div>
                </div>
            </div>
        </div>
    </div>

    <livewire:telegram-chat />
</div>

@push('scripts')
    <script>
        // 1. Просим разрешение на пуши
        if (Notification.permission !== "granted") Notification.requestPermission();

        let lastMsg = "{{ $this->latest_message_at }}";

        // 2. Следим за новыми сообщениями
        setInterval(() => {
            fetch('/api/check-new-tg-messages')
                .then(res => res.json())
                .then(data => {
                    if (data.last_date && data.last_date > lastMsg) {
                        new Notification("Новое сообщение!", {
                            body: "От: " + data.user,
                            icon: "https://cdn-icons-png.flaticon.com/512/2111/2111646.png"
                        });
                        lastMsg = data.last_date;
                        // Обновляем Livewire, чтобы увидеть изменения в таблице
                        Livewire.dispatch('refresh-users');
                    }
                });
        }, 10000);
    </script>
@endpush
