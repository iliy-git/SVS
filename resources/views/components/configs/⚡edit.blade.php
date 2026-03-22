<?php
use Livewire\Component;
use App\Models\Config;
use App\Models\Flag;

new class extends Component {
    public $clientId, $subId, $configId;
    public $name, $link, $flag_id;
    public $flags;
    public $traffic_limit = 0;

    public function mount($clientId, $subId, $configId) {
        $this->clientId = $clientId;
        $this->subId = $subId;
        $config = Config::findOrFail($configId);
        $this->configId = $config->id;
        $this->name = $config->name;
        $this->link = $config->link;
        $this->flag_id = $config->flag_id;
        $this->flags = Flag::all();

        $this->traffic_limit = $config->traffic_limit ?? 0;
    }

    public function save() {
        $this->validate([
            'name' => 'required',
            'link' => 'required',
            'flag_id' => 'required|exists:flags,id'
        ]);

        Config::find($this->configId)->update([
            'name' => $this->name,
            'link' => $this->link,
            'flag_id' => $this->flag_id,
            'traffic_limit' => $this->traffic_limit,
        ]);

        return $this->redirectRoute('configs.index', [$this->clientId, $this->subId], navigate: true);
    }
}; ?>

<div class="row justify-content-center animate__animated animate__fadeIn">
    <div class="col-md-8">
        <div class="card border-0 shadow-sm rounded-4 text-white" style="background: #1a1d21;">
            <div class="card-body p-4">
                <h4 class="fw-bold mb-4 text-center">Правка конфига</h4>

                <form wire:submit.prevent="save">
                    <div class="mb-4">
                        <label class="form-label small fw-bold text-secondary mb-3 text-uppercase">Локация сервера</label>

                        <div class="d-flex flex-wrap gap-2 overflow-auto py-2 px-1" style="max-height: 250px;">
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

                    <div class="mb-3">
                        <label class="form-label small fw-bold text-secondary">НАЗВАНИЕ</label>
                        <input type="text" wire:model="name" class="form-control bg-dark border-0 text-white py-2 shadow-none">
                    </div>

                    <div class="mb-4">
                        <label class="form-label small fw-bold text-secondary">ССЫЛКА (VLESS)</label>
                        <textarea wire:model="link" class="form-control bg-dark border-0 text-white shadow-none" rows="4"></textarea>
                    </div>
                    <div class="mb-4">
                        <label class="form-label small fw-bold text-secondary text-uppercase">Лимит трафика (ГБ)</label>
                        <div class="input-group">
                        <span class="input-group-text bg-dark border-0 text-secondary">
                            <i class="bi bi-speedometer2"></i>
                        </span>
                            <input type="number" wire:model="traffic_limit"
                                   class="form-control bg-dark border-0 text-white py-2 shadow-none custom-input"
                                   placeholder="0">
                        </div>
                        <div class="form-text text-muted" style="font-size: 11px;">
                            Введите 0 для <strong>безлимитного</strong> использования.
                        </div>
                        @error('traffic_limit') <span class="text-danger small">{{ $message }}</span> @enderror
                    </div>

                    <div class="d-flex justify-content-between align-items-center mt-2">
                        <a href="{{ route('configs.index', [$clientId, $subId]) }}" wire:navigate class="btn btn-link text-secondary text-decoration-none fw-bold p-0">
                            <i class="bi bi-arrow-left me-2"></i>ОТМЕНА
                        </a>
                        <button type="submit" class="btn btn-primary px-5 py-2 fw-bold rounded-3 shadow-sm">
                            <i class="bi bi-arrow-repeat me-2"></i>ОБНОВИТЬ
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
