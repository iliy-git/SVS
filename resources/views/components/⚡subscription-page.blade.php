<?php

use App\Models\Subscription;
use App\Services\SubscriptionService;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('layouts.guest')] class extends Component
{
    public string $token;

    public function mount(string $token)
    {
        $this->token = $token;
    }

    #[Computed]
    public function subscription()
    {
        return Subscription::with([
            'clients',
            'configs' => fn($query) => $query->where('is_active', true),
            'configs.flag'
        ])
            ->where('token', $this->token)
            ->firstOrFail();
    }

    #[Computed]
    public function mainConfig()
    {
        return $this->subscription->configs->firstWhere('is_main', true)
            ?? $this->subscription->configs->firstWhere('is_main', 1);
    }

    #[Computed]
    public function happUrl()
    {
        return app(SubscriptionService::class)->getHappUrl($this->subscription->id);
    }

    public function formatBytes($bytes, $precision = 2): string
    {
        if (!$bytes || $bytes <= 0) return '0 B';
        $base = log((float) $bytes, 1024);
        $suffixes = ['B', 'KB', 'MB', 'GB', 'TB'];
        return round(pow(1024, $base - floor($base)), $precision) . ' ' . $suffixes[floor($base)];
    }
};
?>

<div class="sub-page-wrapper">
    <style>
        html, body {
            background-color: #0b0f19 !important;
            margin: 0 !important;
            padding: 0 !important;
            font-family: 'Inter', system-ui, -apple-system, sans-serif;
            color: #f3f4f6;
        }

        .sub-page-wrapper {
            position: fixed;
            top: 0;
            left: 0;
            width: 100vw;
            height: 100vh;
            background: radial-gradient(circle at 50% 15%, rgba(99, 102, 241, 0.15) 0%, transparent 60%),
            radial-gradient(circle at 80% 80%, rgba(168, 85, 247, 0.1) 0%, transparent 50%),
            #0b0f19;
            z-index: 99999;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 1.25rem;
            box-sizing: border-box;
            overflow-y: auto;
        }

        .remna-card {
            width: 100%;
            max-width: 480px;
            background: rgba(18, 24, 38, 0.8);
            backdrop-filter: blur(24px);
            -webkit-backdrop-filter: blur(24px);
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 24px;
            padding: 2rem 1.75rem;
            box-shadow: 0 30px 60px rgba(0, 0, 0, 0.6),
            inset 0 1px 0 rgba(255, 255, 255, 0.1);
            position: relative;
            margin: auto;
        }

        .brand-badge {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 6px 14px;
            background: rgba(99, 102, 241, 0.1);
            border: 1px solid rgba(99, 102, 241, 0.25);
            border-radius: 999px;
            color: #818cf8;
            font-size: 0.75rem;
            font-weight: 700;
            letter-spacing: 0.8px;
            text-transform: uppercase;
            margin-bottom: 1.25rem;
        }

        .status-pill {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 6px 16px;
            border-radius: 999px;
            font-size: 0.85rem;
            font-weight: 600;
        }
        .status-active {
            background: rgba(16, 185, 129, 0.12);
            color: #34d399;
            border: 1px solid rgba(16, 185, 129, 0.25);
        }
        .status-expired {
            background: rgba(244, 63, 94, 0.12);
            color: #fb7185;
            border: 1px solid rgba(244, 63, 94, 0.25);
        }
        .pulse-dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            display: inline-block;
        }
        .status-active .pulse-dot { background: #34d399; box-shadow: 0 0 10px #34d399; }
        .status-expired .pulse-dot { background: #fb7185; box-shadow: 0 0 10px #fb7185; }

        /* Визуальная карточка БС */
        .bs-main-card {
            background: rgba(99, 102, 241, 0.08);
            border: 1px solid rgba(99, 102, 241, 0.3);
            border-radius: 18px;
            padding: 1.25rem;
            margin: 1.25rem 0 1rem 0;
            box-shadow: 0 4px 20px rgba(99, 102, 241, 0.08);
        }

        .bs-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 0.75rem;
        }

        /* Трафик и прогресс-бар */
        .traffic-card {
            background: rgba(10, 14, 23, 0.7);
            border: 1px solid rgba(255, 255, 255, 0.06);
            border-radius: 18px;
            padding: 1.25rem;
            margin-bottom: 1.25rem;
        }

        .progress-bar-container {
            width: 100%;
            height: 8px;
            background: rgba(255, 255, 255, 0.08);
            border-radius: 999px;
            overflow: hidden;
            margin: 0.75rem 0;
        }
        .progress-bar-fill {
            height: 100%;
            background: linear-gradient(90deg, #6366f1 0%, #a855f7 100%);
            border-radius: 999px;
            transition: width 0.4s ease;
        }

        .traffic-stats-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px;
            margin-top: 0.75rem;
            padding-top: 0.75rem;
            border-top: 1px solid rgba(255, 255, 255, 0.05);
        }

        .info-grid {
            background: rgba(10, 14, 23, 0.5);
            border: 1px solid rgba(255, 255, 255, 0.05);
            border-radius: 16px;
            padding: 1rem 1.25rem;
            margin-bottom: 1.25rem;
            display: flex;
            flex-direction: column;
            gap: 0.75rem;
        }
        .info-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 0.875rem;
        }
        .info-label { color: #9ca3af; }
        .info-value { color: #f3f4f6; font-weight: 600; font-family: monospace; }

        /* Серверы и Конфиги */
        .section-title {
            font-size: 0.75rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 1px;
            color: #6b7280;
            margin-bottom: 0.75rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .configs-list {
            display: flex;
            flex-direction: column;
            gap: 0.65rem;
            margin-bottom: 1.25rem;
        }

        .config-item {
            background: rgba(15, 21, 33, 0.7);
            border: 1px solid rgba(255, 255, 255, 0.06);
            border-radius: 14px;
            padding: 0.85rem 1rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
            transition: all 0.2s ease;
        }
        .config-item.is-main {
            border-color: rgba(99, 102, 241, 0.4);
            background: rgba(99, 102, 241, 0.08);
        }

        .flag-box {
            width: 34px;
            height: 24px;
            border-radius: 6px;
            overflow: hidden;
            display: flex;
            align-items: center;
            justify-content: center;
            background: rgba(255, 255, 255, 0.05);
            flex-shrink: 0;
        }

        .badge-main {
            background: rgba(99, 102, 241, 0.2);
            color: #818cf8;
            border: 1px solid rgba(99, 102, 241, 0.4);
            font-size: 0.65rem;
            font-weight: 800;
            padding: 2px 6px;
            border-radius: 6px;
            letter-spacing: 0.5px;
        }

        .config-stats-box {
            text-align: right;
            font-family: monospace;
            font-size: 0.75rem;
            display: flex;
            flex-direction: column;
            gap: 2px;
        }

        .btn-action {
            width: 100%;
            padding: 0.875rem 1.25rem;
            border-radius: 14px;
            font-weight: 600;
            font-size: 0.95rem;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            cursor: pointer;
            transition: all 0.2s ease;
            text-decoration: none;
            border: none;
            outline: none;
            box-sizing: border-box;
        }
        .btn-primary-happ {
            background: linear-gradient(135deg, #6366f1 0%, #4f46e5 100%);
            color: #ffffff !important;
            box-shadow: 0 8px 20px rgba(99, 102, 241, 0.35);
        }
        .btn-primary-happ:hover {
            transform: translateY(-2px);
            box-shadow: 0 12px 25px rgba(99, 102, 241, 0.5);
        }
    </style>

    <div class="remna-card">

        {{-- Шапка --}}
        <div style="text-align: center;">
            <div class="brand-badge">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                    <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>
                </svg>
                Комар VPN Node
            </div>

            <h2 style="font-size: 1.4rem; font-weight: 700; margin: 0 0 0.25rem 0; color: #fff;">
                {{ $this->subscription->name }}
            </h2>
            <p style="font-size: 0.85rem; color: #9ca3af; margin: 0 0 1rem 0;">
                Клиент: {{ $this->subscription->client->name ?? 'Пользователь' }}
            </p>

            @php
                $isExpired = $this->subscription->expires_at && \Carbon\Carbon::parse($this->subscription->expires_at)->isPast();
                $used = $this->subscription->used_bytes ?? ($this->subscription->up + $this->subscription->down ?? 0);
                $limit = $this->subscription->traffic_limit_bytes ?? $this->subscription->total_bytes ?? 0;
            @endphp

            <div class="status-pill {{ $isExpired ? 'status-expired' : 'status-active' }}">
                <span class="pulse-dot"></span>
                {{ $isExpired ? 'Истёкла' : 'Активна' }}
            </div>
        </div>

        {{-- Главный визуальный блок Базовой Станции (БС) --}}
        @if($this->mainConfig)
            @php
                $bsUp = $this->mainConfig->up ?? 0;
                $bsDown = $this->mainConfig->down ?? 0;
                $bsTotal = $bsUp + $bsDown;
                $bsPercentOfTotal = ($used > 0) ? min(100, round(($bsTotal / $used) * 100)) : 0;
            @endphp

            <div class="bs-main-card">
                <div class="bs-header">
                    <div style="display: flex; align-items: center; gap: 10px;">
                        <div class="flag-box">
                            @if(isset($this->mainConfig->flag->code))
                                <img src="https://purecatamphetamine.github.io/country-flag-icons/3x2/{{ strtoupper($this->mainConfig->flag->code) }}.svg"
                                     alt="{{ $this->mainConfig->flag->code }}" style="width: 100%; height: 100%; object-fit: cover;">
                            @else
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#9ca3af" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><line x1="2" y1="12" x2="22" y2="12"></line></svg>
                            @endif
                        </div>
                        <div>
                            <div style="display: flex; align-items: center; gap: 6px;">
                                <span class="badge-main">БС</span>
                                <span style="font-weight: 700; font-size: 0.95rem; color: #fff;">
                                    {{ $this->mainConfig->name ?: ($this->mainConfig->email ?? 'Базовая станция') }}
                                </span>
                            </div>
                        </div>
                    </div>

                    <div style="text-align: right; font-family: monospace; font-size: 0.85rem; font-weight: 700; color: #818cf8;">
                        {{ $this->formatBytes($bsTotal) }}
                    </div>
                </div>

                {{-- Шкала участия БС в общем трафике --}}
                <div style="display: flex; justify-content: space-between; align-items: center; font-size: 0.75rem; color: #9ca3af; margin-top: 0.5rem;">
                    <span>Расход БС</span>
                    <span style="font-family: monospace; color: #a5b4fc;">{{ $bsPercentOfTotal }}% от общего расхода</span>
                </div>
                <div class="progress-bar-container" style="height: 6px; margin: 0.4rem 0 0.75rem 0; background: rgba(255,255,255,0.05);">
                    <div class="progress-bar-fill" style="width: {{ $bsPercentOfTotal }}%; background: linear-gradient(90deg, #818cf8 0%, #c084fc 100%);"></div>
                </div>

                {{-- Статистика Up/Down для БС --}}
                <div class="traffic-stats-grid" style="margin-top: 0; padding-top: 0.5rem; border-top: 1px solid rgba(255, 255, 255, 0.08);">
                    <div style="display: flex; align-items: center; gap: 6px;">
                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="#34d399" stroke-width="2.5"><line x1="12" y1="19" x2="12" y2="5"></line><polyline points="5 12 12 5 19 12"></polyline></svg>
                        <span style="font-size: 0.7rem; color: #9ca3af;">Up:</span>
                        <span style="font-size: 0.75rem; color: #9ca3af; font-weight: 600; font-family: monospace;">{{ $this->formatBytes($bsUp) }}</span>
                    </div>
                    <div style="display: flex; align-items: center; gap: 6px; justify-content: flex-end;">
                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="#60a5fa" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"></line><polyline points="19 12 12 19 5 12"></polyline></svg>
                        <span style="font-size: 0.7rem; color: #9ca3af;">Down:</span>
                        <span style="font-size: 0.75rem; color: #9ca3af; font-weight: 600; font-family: monospace;">{{ $this->formatBytes($bsDown) }}</span>
                    </div>
                </div>
            </div>
        @endif

        {{-- Блок общего трафика подписки --}}
        @php
            $percent = ($limit > 0) ? min(100, round(($used / $limit) * 100)) : 0;
        @endphp



        {{-- Сетка основной информации --}}
        <div class="info-grid">
            <div class="info-row">
                <span class="info-label">Срок действия</span>
                <span class="info-value">
                    {{ $this->subscription->expires_at ? \Carbon\Carbon::parse($this->subscription->expires_at)->format('d.m.Y в H:i') : 'Бессрочно' }}
                </span>
            </div>
            @if($this->subscription->install_limit)
                <div class="info-row" style="border-top: 1px solid rgba(255,255,255,0.05); padding-top: 0.75rem;">
                    <span class="info-label">Лимит устройств</span>
                    <span class="info-value">{{ $this->subscription->install_limit }}</span>
                </div>
            @endif
        </div>

        {{-- Список только активных конфигураций --}}
        @if($this->subscription->configs && $this->subscription->configs->count() > 0)
            <div class="section-title">
                <span>Доступные локации ({{ $this->subscription->configs->count() }})</span>
                <span style="font-size: 0.65rem; color: #818cf8; text-transform: none;">Активные ноды</span>
            </div>

            <div class="configs-list">
                @foreach($this->subscription->configs as $config)
                    <div class="config-item {{ $config->is_main ? 'is-main' : '' }}">
                        <div style="display: flex; align-items: center; gap: 12px; overflow: hidden;">
                            <div class="flag-box">
                                @if(isset($config->flag->code))
                                    <img src="https://purecatamphetamine.github.io/country-flag-icons/3x2/{{ strtoupper($config->flag->code) }}.svg"
                                         alt="{{ $config->flag->code }}" style="width: 100%; height: 100%; object-fit: cover;">
                                @else
                                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#9ca3af" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><line x1="2" y1="12" x2="22" y2="12"></line></svg>
                                @endif
                            </div>

                            <div style="display: flex; flex-direction: column; overflow: hidden;">
                                <div style="display: flex; align-items: center; gap: 6px;">
                                    <span style="font-weight: 600; font-size: 0.875rem; color: #f3f4f6; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">
                                        {{ $config->name ?: ($config->email ?? 'Локация') }}
                                    </span>
                                    @if($config->is_main)
                                        <span class="badge-main" title="Базовая станция">БС</span>
                                    @endif
                                </div>
                            </div>
                        </div>

                        {{-- Статистика конкретного конфига --}}
                        <div class="config-stats-box">
                            <span style="color: #34d399;" title="Отгрузка (Up)">
                                ↑ {{ $this->formatBytes($config->up ?? 0) }}
                            </span>
                            <span style="color: #60a5fa;" title="Загрузка (Down)">
                                ↓ {{ $this->formatBytes($config->down ?? 0) }}
                            </span>
                        </div>
                    </div>
                @endforeach
            </div>
        @endif

        {{-- Кнопка подключения --}}
        <div style="display: flex; flex-direction: column; gap: 0.65rem;">
            @if($this->happUrl)
                <a href="{{ $this->happUrl }}" class="btn-action btn-primary-happ">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"></polygon></svg>
                    Подключить в Happ
                </a>
            @endif
        </div>
    </div>
</div>
