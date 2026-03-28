    <?php

    use App\Models\Subscription;
    use Livewire\Component;
    use App\Models\Node;
    use App\Models\Config;
    use Illuminate\Support\Facades\Http;

    new class extends Component {
        public $clientId;
        public $subId;

        public $step = 1;
        public $selectedNode = null;
        public $remoteConfigs = [];
        public $existingLinks = [];

        public function mount($clientId, $subId)
        {
            $this->clientId = $clientId;
            $this->subId = $subId;
        }

        public function fetchConfigs($nodeId)
        {
            $this->selectedNode = Node::findOrFail($nodeId);

            try {
                $response = Http::withHeaders([
                    'X-API-KEY' => $this->selectedNode->api_key
                ])
                    ->timeout(5)
                    ->withoutVerifying()
                    ->get("https://{$this->selectedNode->ip}:11223/");

                if ($response->ok()) {
                    $allRemoteLinks = $response->json();

                    $this->existingLinks = Config::pluck('link')->toArray();

                    $this->remoteConfigs = collect($allRemoteLinks)->sortBy(function($link) {
                        return in_array(trim($link), $this->existingLinks);
                    })->values()->all();

                    $this->step = 2;
                } else {
                    session()->flash('error', 'Ошибка: ' . $response->status());
                }
            } catch (\Exception $e) {
                session()->flash('error', 'Нет связи с сервером ноды: ' . $e->getMessage());
            }
        }

        public function saveRemoteConfig($link)
        {
            $name = str_contains($link, '#') ? urldecode(explode('#', $link)[1]) : 'New Config';
    //        dd($name);
            $config = Config::create([
                'subscription_id' => $this->subId,
                'name' => $name,
                'link' => $link,
                'flag_id' => $this->selectedNode->flag_id,
            ]);
            Subscription::findOrFail($this->subId)->configs()->attach($config->id);


            return $this->redirectRoute('configs.index', [$this->clientId, $this->subId], navigate: true);
        }
    }; ?>

    <div class="animate__animated animate__fadeIn text-white">
        <div class="mb-4">
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-1 small">
                    <li class="breadcrumb-item"><a href="{{ route('subscriptions.index', $clientId) }}" wire:navigate
                                                   class="text-primary text-decoration-none">Подписки</a></li>
                    <li class="breadcrumb-item active text-muted">Импорт из ноды</li>
                </ol>
            </nav>
            <h2 class="fw-bold m-0">
                @if($step == 1)
                    <i class="bi bi-hdd-stack me-2 text-primary"></i>Выберите сервер
                @else
                    <i class="bi bi-list-ul me-2 text-primary"></i>Доступные конфигурации
                @endif
            </h2>
        </div>

        @if(session()->has('error'))
            <div
                class="alert alert-danger border-0 bg-danger bg-opacity-10 text-danger mb-4 animate__animated animate__shakeX">
                <i class="bi bi-exclamation-triangle-fill me-2"></i>{{ session('error') }}
            </div>
        @endif

        <div class="row g-3">
            @if($step == 1)
                @foreach(App\Models\Node::all() as $node)
                    <div class="col-md-6 col-lg-4">
                        <button wire:click="fetchConfigs({{ $node->id }})" wire:loading.attr="disabled"
                                class="card bg-dark border border-white border-opacity-10 rounded-4 p-3 w-100 text-start hover-node transition-all shadow-sm">
                            <div class="d-flex align-items-center justify-content-between">
                                <div class="d-flex align-items-center">
                                    @if($node->flag)
                                        <img
                                            src="https://purecatamphetamine.github.io/country-flag-icons/3x2/{{ strtoupper($node->flag->code) }}.svg"
                                            class="me-3 rounded-1 shadow-sm" style="width: 32px;">
                                    @endif
                                    <div>
                                        <div class="fw-bold text-white">{{ $node->name }}</div>
                                        <div class="text-muted small">{{ $node->ip }}</div>
                                    </div>
                                </div>
                                <div wire:loading wire:target="fetchConfigs({{ $node->id }})"
                                     class="spinner-border spinner-border-sm text-primary"></div>
                                <i wire:loading.remove wire:target="fetchConfigs({{ $node->id }})"
                                   class="bi bi-chevron-right text-muted"></i>
                            </div>
                        </button>
                    </div>
                @endforeach
            @else
                <div class="col-12 mb-2">
                    <button wire:click="$set('step', 1)" class="btn btn-link p-0 text-primary text-decoration-none small">
                        <i class="bi bi-arrow-left"></i> Вернуться к выбору сервера
                    </button>
                </div>
                @foreach($remoteConfigs as $configLink)
                    @php
                        $isExists = in_array(trim($configLink), $existingLinks);
                    @endphp
                    <div class="col-12 col-xl-6">
                        <button wire:click="saveRemoteConfig('{{ trim($configLink) }}')"
                                class="card bg-dark border rounded-4 p-3 w-100 text-start transition-all shadow-sm
                                {{ $isExists ? 'border-primary border-opacity-50 bg-opacity-50' : 'border-white border-opacity-10' }}
                                hover-border-primary">

                            <div class="d-flex justify-content-between align-items-center">
                                <div class="text-truncate me-3">
                                    <div class="d-flex align-items-center mb-1">
                                        <div class="fw-bold text-white">
                                            {{ str_contains($configLink, '#') ? urldecode(explode('#', $configLink)[1]) : 'Без имени' }}
                                        </div>
                                        @if($isExists)
                                            <span class="badge bg-primary bg-opacity-25 text-primary ms-2 small" style="font-size: 0.6rem;">Используется</span>
                                        @endif
                                    </div>
                                    <div class="text-muted text-truncate small opacity-50" style="font-size: 0.75rem;">
                                        {{ $configLink }}
                                    </div>
                                </div>

                                <div class="d-flex align-items-center">
                                    @if($isExists)
                                        <i class="bi bi-arrow-repeat text-primary fs-5 me-2" title="Добавить повторно"></i>
                                    @endif
                                    <i class="bi bi-plus-circle {{ $isExists ? 'text-info' : 'text-primary' }} fs-5"></i>
                                </div>
                            </div>
                        </button>
                    </div>
                @endforeach
            @endif
        </div>

        <style>
            .hover-node:hover {
                background: rgba(255, 255, 255, 0.05) !important;
                transform: translateY(-3px);
                border-color: rgba(13, 110, 253, 0.3) !important;
            }

            .hover-border-primary:hover {
                border-color: #0d6efd !important;
                background: rgba(13, 110, 253, 0.05) !important;
            }

            .transition-all {
                transition: all 0.2s ease-in-out;
            }
        </style>
    </div>
