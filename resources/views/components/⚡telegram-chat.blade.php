<?php

use App\Models\TelegramUser;
use App\Services\TelegramService;
use Livewire\Component;
use Livewire\Attributes\On;
use Livewire\WithFileUploads;

new class extends Component {
    use WithFileUploads;

    public $user = null;
    public $messageText = '';
    public $statusMessage = '';
    public $image = null;

    public function refreshMessages()
    {
        if ($this->user) {
            $this->user->load(['messages' => fn($q) => $q->orderBy('created_at', 'asc')]);
            $this->dispatch('scroll-to-bottom');
        }
    }

    #[On('openChat')]
    public function loadUser($userId)
    {
        $this->user = TelegramUser::with(['messages' => fn($q) => $q->orderBy('created_at', 'asc')])
            ->find($userId);

        $this->reset('messageText', 'statusMessage', 'image');
        $this->dispatch('scroll-to-bottom');
    }

    public function sendMessage(TelegramService $service)
    {
        if (!$this->user || (!trim($this->messageText) && !$this->image)) return;

        try {
            $success = $service->sendMessage(
                $this->user->telegram_id,
                $this->messageText,
                $this->image
            );

            if ($success) {
                $service->storeMessage(
                    $this->user,
                    $this->messageText,
                    isFromBot: true,
                    isImage: (bool)$this->image
                );

                $this->reset(['messageText', 'image']);
                $this->user->load('messages');
                $this->dispatch('scroll-to-bottom');
            } else {
                $this->statusMessage = '❌ Ошибка API Telegram';
            }
        } catch (\Exception $e) {
            $this->statusMessage = '❌ Ошибка сети или сервиса';
        }
    }

    public function close()
    {
        $this->user = null;
    }
}; ?>

<div x-data="{
        scrollToBottom() {
            $nextTick(() => {
                const el = document.getElementById('chat-history-window');
                if (el) el.scrollTop = el.scrollHeight;
            });
        }, {{-- Добавлена запятая --}}
        handlePaste(e) {
            const items = e.clipboardData.items;
            for (let i = 0; i < items.length; i++) {
                if (items[i].type.indexOf('image') !== -1) {
                    const blob = items[i].getAsFile();
                    @this.upload('image', blob);
                }
            }
        }
    }"
     x-on:scroll-to-bottom.window="scrollToBottom()"
     x-on:paste.window="handlePaste($event)"
     @if($user) wire:poll.3s="refreshMessages" @endif>

    @if($user)
        <div class="modal fade show d-block"
             style="background: rgba(15, 23, 42, 0.5); backdrop-filter: blur(8px); z-index: 1050;"
             x-transition:enter="transition ease-out duration-300"
             x-transition:enter-start="opacity-0"
             x-transition:enter-end="opacity-100">

            <div class="modal-dialog modal-dialog-centered shadow-lg" style="max-width: 500px; height: 400px">
                <div class="modal-content border-0 overflow-hidden h-100" style="border-radius: 20px;">

                    <div class="modal-header border-0 bg-dark p-3 d-flex align-items-center shadow-sm"
                         style="z-index: 10;">
                        <div class="d-flex align-items-center flex-grow-1">
                            <div
                                class="rounded-circle bg-primary text-white d-flex align-items-center justify-content-center fw-bold me-3"
                                style="width: 40px; height: 40px; font-size: 1.2rem;">
                                {{ mb_substr($user->first_name, 0, 1) }}
                            </div>
                            <div>
                                <h6 class="mb-0 fw-bold">{{ $user->first_name }}</h6>
                            </div>
                        </div>
                        <button type="button" class="btn-close shadow-none" wire:click="close"></button>
                    </div>

                    <div class="modal-body p-3" id="chat-history-window"
                         x-init="scrollToBottom()"
                         style="height: 450px; overflow-y: auto; background-color: #1d1f22; background-image: url('https://www.transparenttextures.com/patterns/cubes.png');">

                        <div class="vstack gap-3">
                            @forelse($user->messages as $msg)
                                <div
                                    class="d-flex {{ $msg->is_from_bot ? 'justify-content-end' : 'justify-content-start' }}"
                                    x-transition:enter="transition ease-out duration-200"
                                    x-transition:enter-start="opacity-0 transform translate-y-2">

                                    <div
                                        class="p-2 px-3 shadow-sm position-relative {{ $msg->is_from_bot ? 'bg-primary text-white' : 'bg-dark text-white' }}"
                                        style="max-width: 85%; border-radius: 18px; {{ $msg->is_from_bot ? 'border-bottom-right-radius: 4px;' : 'border-bottom-left-radius: 4px;' }}">

                                        <div style="font-size: 0.95rem; line-height: 1.4;">{{ $msg->text }}</div>

                                        <div class="text-end mt-1" style="font-size: 0.65rem; opacity: 0.7;">
                                            {{ $msg->created_at->format('H:i') }}
                                            @if($msg->is_from_bot)
                                                <i class="bi bi-check2-all ms-1"></i>
                                            @endif
                                        </div>
                                    </div>
                                </div>
                            @empty
                                <div class="text-center my-auto p-5 text-muted">
                                    <i class="bi bi-chat-dots fs-1 opacity-25"></i>
                                    <p class="mt-2 small">Напишите первое сообщение...</p>
                                </div>
                            @endforelse
                        </div>
                    </div>

                    <div class="modal-footer border-0 bg-dark p-3 shadow-lg">
                        <div class="w-100">
                            @if ($image)
                                <div class="position-relative d-inline-block mb-2 ms-2" x-transition>
                                    <img src="{{ $image->temporaryUrl() }}" style="height: 60px; border-radius: 10px; border: 2px solid #0d6efd;">
                                    <button wire:click="$set('image', null)" class="btn btn-danger btn-sm position-absolute top-0 start-100 translate-middle rounded-circle p-0" style="width: 20px; height: 20px; font-size: 10px;">✕</button>
                                </div>
                            @endif

                            @if($statusMessage)
                                <div class="small text-danger mb-2 px-2">{{ $statusMessage }}</div>
                            @endif
                            <div class="d-flex align-items-end gap-2 bg-dark p-2 rounded-4">
                                <textarea wire:model="messageText"
                                          wire:keydown.enter.prevent="sendMessage"
                                          class="form-control border-0 bg-transparent shadow-none"
                                          rows="1"
                                          placeholder="Напишите или вставьте фото..."
                                          style="resize: none; font-size: 0.95rem;"></textarea>

                                {{-- Лоадер загрузки файла --}}
                                <div wire:loading wire:target="image" class="spinner-border spinner-border-sm text-primary mb-2"></div>

                                <button wire:click="sendMessage"
                                        wire:loading.attr="disabled"
                                        class="btn btn-primary rounded-circle d-flex align-items-center justify-content-center"
                                        style="width: 40px; height: 40px; flex-shrink: 0;">
                                    <i class="bi bi-send-fill" wire:loading.remove wire:target="sendMessage, image"></i>
                                    <span class="spinner-border spinner-border-sm" wire:loading wire:target="sendMessage"></span>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    @endif
</div>
