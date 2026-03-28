<?php
use Livewire\Component;
use App\Models\Node;
use App\Models\Flag;

new class extends Component {
    public $nodeId, $name, $ip, $port, $api_key, $flag_id;
    public $flags;

    public function mount($nodeId) {
        $node = Node::findOrFail($nodeId);
        $this->nodeId = $node->id;
        $this->name = $node->name;
        $this->ip = $node->ip;
        $this->port = $node->port;
        $this->api_key = $node->api_key;
        $this->flag_id = $node->flag_id;

        $this->flags = Flag::all();
    }

    public function save() {
        $this->validate([
            'name' => 'required|min:2',
            'ip' => 'required|ip',
            'port' => 'required|numeric',
            'api_key' => 'required',
            'flag_id' => 'required|exists:flags,id'
        ]);

        Node::find($this->nodeId)->update([
            'name' => $this->name,
            'ip' => $this->ip,
            'port' => $this->port,
            'api_key' => $this->api_key,
            'flag_id' => $this->flag_id,
        ]);

        return $this->redirectRoute('nodes.index', navigate: true);
    }
}; ?>

<div class="row justify-content-center animate__animated animate__fadeIn">
    <div class="col-md-9 col-lg-8">
        <div class="card border-0 shadow-lg rounded-4 overflow-hidden" style="background: #1a1d21;">

            <div class="card-body p-4 text-white">
                <form wire:submit.prevent="save">

                    <div class="mb-5">
                        <label class="form-label small fw-bold text-secondary mb-3 text-uppercase" style="letter-spacing: 1px;">Локация сервера</label>

                        <div class="d-flex flex-wrap gap-2 overflow-auto py-2 px-1 custom-scrollbar" style="max-height: 250px;">
                            @foreach($flags as $flag)
                                <label class="m-0 cursor-pointer position-relative">
                                    <input type="radio"
                                           wire:model.live="flag_id"
                                           value="{{ $flag->id }}"
                                           class="d-none">

                                    <div class="flag-box {{ $flag_id == $flag->id ? 'active' : '' }} d-flex flex-column justify-content-center align-items-center">

                                        @if($flag_id == $flag->id)
                                            <div class="position-absolute top-0 end-0 p-1">
                                                <i class="bi bi-check-circle-fill text-primary" style="font-size: 0.8rem;"></i>
                                            </div>
                                        @endif

                                        <div class="flag-img-container mb-2 d-flex align-items-center justify-content-center">
                                            <img src="https://purecatamphetamine.github.io/country-flag-icons/3x2/{{ strtoupper($flag->code) }}.svg"
                                                 alt="{{ $flag->name }}"
                                                 class="rounded-1 shadow-sm flag-img">
                                        </div>

                                        <div class="small text-truncate w-100 text-center flag-label">
                                            {{ $flag->name }}
                                        </div>
                                    </div>
                                </label>
                            @endforeach
                        </div>
                        @error('flag_id') <span class="text-danger small mt-2 d-block">Пожалуйста, выберите локацию</span> @enderror
                    </div>

                    <div class="row g-4">
                        <div class="col-12">
                            <label class="form-label small fw-bold text-secondary text-uppercase">Название сервера</label>
                            <input type="text" wire:model="name" class="form-control bg-dark border-0 text-white py-2 shadow-none border-focus border border-white border-opacity-5" placeholder="Напр: Германия #1">
                            @error('name') <span class="text-danger small">{{ $message }}</span> @enderror
                        </div>

                        <div class="col-md-8">
                            <label class="form-label small fw-bold text-secondary text-uppercase">IP Адрес</label>
                            <input type="text" wire:model="ip" class="form-control bg-dark border-0 text-white py-2 shadow-none border-focus border border-white border-opacity-5" placeholder="0.0.0.0">
                            @error('ip') <span class="text-danger small">{{ $message }}</span> @enderror
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small fw-bold text-secondary text-uppercase">API Порт</label>
                            <input type="text" wire:model="port" class="form-control bg-dark border-0 text-white py-2 shadow-none border-focus border border-white border-opacity-5" placeholder="11223">
                            @error('port') <span class="text-danger small">{{ $message }}</span> @enderror
                        </div>

                        <div class="col-12">
                            <label class="form-label small fw-bold text-secondary text-uppercase">X-API-KEY</label>
                            <div class="input-group bg-dark rounded-3 overflow-hidden border border-white border-opacity-5" x-data="{ show: false }">
                                <span class="input-group-text bg-transparent border-0 text-muted">
                                    <i class="bi bi-shield-lock"></i>
                                </span>

                                <input :type="show ? 'text' : 'password'"
                                       wire:model="api_key"
                                       class="form-control bg-transparent border-0 text-white shadow-none py-2"
                                       placeholder="Секретный ключ доступа">

                                <button type="button"
                                        @click="show = !show"
                                        class="btn bg-transparent border-0 text-muted px-3 shadow-none">
                                    <i class="bi" :class="show ? 'bi-eye-slash-fill' : 'bi-eye-fill'"></i>
                                </button>
                            </div>
                            @error('api_key') <span class="text-danger small">{{ $message }}</span> @enderror
                        </div>
                    </div>

                    <div class="d-flex justify-content-between align-items-center mt-5 pt-3 border-top border-secondary border-opacity-10">
                        <a href="{{ route('nodes.index') }}" wire:navigate class="btn btn-link text-secondary text-decoration-none fw-bold p-0 transition-all hover-light">
                            <i class="bi bi-arrow-left me-2"></i>ОТМЕНА
                        </a>
                        <button type="submit" class="btn btn-primary px-5 py-2 fw-bold rounded-3 shadow-sm">
                            <i class="bi bi-cloud-arrow-up-fill me-2"></i>ОБНОВИТЬ
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<style>
    .flag-box {
        width: 110px;
        height: 90px;
        background: rgba(255, 255, 255, 0.03);
        border: 1px solid rgba(255, 255, 255, 0.05);
        border-radius: 12px;
        padding: 10px;
        transition: all 0.2s ease;
        cursor: pointer;
        position: relative;
    }

    .flag-box:hover {
        background: rgba(255, 255, 255, 0.06);
        border-color: rgba(255, 255, 255, 0.1);
    }

    .flag-box.active {
        background: rgba(13, 110, 253, 0.1);
        border-color: #0d6efd;
        box-shadow: 0 0 15px rgba(13, 110, 253, 0.15);
    }

    .flag-img-container {
        height: 30px;
    }

    .flag-img {
        width: 40px;
        height: auto;
    }

    .flag-label {
        font-size: 11px;
        color: #adb5bd;
        font-weight: 500;
    }

    .flag-box.active .flag-label {
        color: white;
        font-weight: bold;
    }

    .custom-scrollbar::-webkit-scrollbar {
        width: 4px;
        height: 4px;
    }

    .custom-scrollbar::-webkit-scrollbar-track {
        background: transparent;
    }

    .custom-scrollbar::-webkit-scrollbar-thumb {
        background: rgba(255, 255, 255, 0.1);
        border-radius: 10px;
    }

    .custom-scrollbar::-webkit-scrollbar-thumb:hover {
        background: rgba(255, 255, 255, 0.2);
    }

    .border-focus:focus {
        border-color: rgba(13, 110, 253, 0.5) !important;
        box-shadow: 0 0 0 0.25rem rgba(13, 110, 253, 0.1) !important;
    }

    .cursor-pointer {
        cursor: pointer;
    }

    .hover-light:hover {
        color: white !important;
    }

    .transition-all {
        transition: all 0.2s ease;
    }
</style>
