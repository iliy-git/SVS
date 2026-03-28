<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>SubVpnSystem</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">

    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles

    <style>
        :root {
            --sidebar-width: 260px;
            --sidebar-bg: #1a1d21;
            --main-bg: #0f1114;
            --accent: #3b82f6;
            --card-bg: #1a1d21;
            --text-main: #e2e8f0;
            --text-muted: #e2e8f0;
            --border-color: rgba(255, 255, 255, 0.08);
        }

        body {
            font-family: 'Inter', sans-serif;
            background-color: var(--main-bg);
            color: var(--text-main);
            margin: 0;
        }

        .sidebar {
            width: var(--sidebar-width);
            height: 100vh;
            position: fixed;
            left: 0; top: 0;
            background-color: var(--sidebar-bg);
            border-right: 1px solid var(--border-color);
            display: flex;
            flex-direction: column;
            z-index: 1050;
        }

        .sidebar-brand {
            padding: 1.75rem 1.5rem;
            font-size: 1.1rem;
            font-weight: 700;
            color: var(--accent);
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 12px;
            text-transform: uppercase;
        }

        .nav-list { list-style: none; padding: 0.5rem; margin: 0; flex-grow: 1; }

        .nav-item-link {
            padding: 0.85rem 1rem;
            display: flex;
            align-items: center;
            color: var(--text-muted);
            text-decoration: none;
            border-radius: 8px;
            margin-bottom: 4px;
            transition: 0.2s;
        }

        .nav-item-link:hover { color: #fff; background: rgba(255, 255, 255, 0.03); }
        .nav-item-link.active { color: #fff; background: var(--accent); }
        .nav-item-link i { font-size: 1.2rem; margin-right: 12px; }

        .sidebar-footer { padding: 1rem; background: rgba(0,0,0,0.2); }
        .logout-btn {
            width: 100%; background: rgba(239, 68, 68, 0.1); color: #ef4444;
            border: 1px solid rgba(239, 68, 68, 0.2); padding: 0.6rem;
            border-radius: 8px; font-weight: 600; cursor: pointer;
        }

        .main-content {
            margin-left: var(--sidebar-width);
            padding: 2.5rem;
            background-color: var(--main-bg);
        }

        .card {
            background-color: var(--card-bg) !important;
            border: 1px solid var(--border-color) !important;
            color: var(--text-main) !important;
        }

        table thead th {
            background-color: #262c35 !important;
            color: #94a3b8 !important;
            border-bottom: 1px solid rgba(255,255,255,0.1) !important;
            text-transform: uppercase;
            font-size: 0.7rem;
            letter-spacing: 1px;
            padding: 12px 15px !important;
        }

        .table tbody td {
            color: #e2e8f0 !important;
            border-bottom: 1px solid rgba(255,255,255,0.05) !important;
            background-color: transparent !important;
        }

        .table td, .text-muted, .small {
            color: #94a3b8 !important;
        }

        .table tbody tr td:first-child {
            color: #fff !important;
            font-weight: 600;
        }

        .card {
            background-color: #1a1d21 !important;
            border: 1px solid rgba(255,255,255,0.05) !important;
            overflow: hidden;
        }

        .form-control {
            background-color: #111827 !important;
            border: 1px solid #374151 !important;
            color: #fff !important;
        }

        .btn-link {
            color: #94a3b8 !important;
            transition: 0.2s;
        }
        .btn-link:hover {
            filter: brightness(1.5);
        }
        .btn-light {
            background-color: #111827 !important;
        }

        .form-control, .form-select {
            background-color: #0f1114 !important;
            border: 1px solid #334155 !important;
            color: #fff !important;
        }
        .breadcrumb-item {
            color: #fff !important;

        }
        .breadcrumb-item + .breadcrumb-item::before {
            color: rgba(255, 255, 255, 0.5) !important;
        }

        .breadcrumb-item.active {
            color: #fff !important;
        }

        .breadcrumb-item a {
            color: var(--accent) !important;
            text-decoration: none;
        }

        .form-control::placeholder { color: #4b5563; }

        .form-control:focus {
            border-color: var(--accent) !important;
            box-shadow: 0 0 0 2px rgba(59, 130, 246, 0.2) !important;
        }

        .btn-link { text-decoration: none; }
        .badge.bg-light { background-color: rgba(255,255,255,0.1) !important; color: var(--accent) !important; }

        h2, h3, h4, .text-dark { color: #fff !important; }

        ::-webkit-scrollbar { width: 6px; }
        ::-webkit-scrollbar-track { background: var(--main-bg); }
        ::-webkit-scrollbar-thumb { background: #333; border-radius: 10px; }
        .lw-dropdown {
            position: relative;
            display: inline-block;
        }

        .lw-dropdown-menu {
            position: absolute;
            right: 0;
            top: 100%;
            z-index: 1100;
            min-width: 160px;
            background-color: #1a1d21;
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 8px;
            box-shadow: 0 10px 25px rgba(0,0,0,0.5);
            padding: 0.5rem 0;
            margin-top: 5px;
        }

        .lw-dropdown-item {
            display: flex;
            align-items: center;
            width: 100%;
            padding: 0.6rem 1rem;
            color: #e2e8f0;
            text-decoration: none;
            background: none;
            border: none;
            text-align: left;
            font-size: 0.9rem;
            transition: 0.2s;
        }

        .lw-dropdown-item:hover {
            background-color: rgba(255, 255, 255, 0.05);
            color: #fff;
        }

        .lw-dropdown-divider {
            height: 1px;
            background-color: rgba(255, 255, 255, 0.05);
            margin: 0.4rem 0;
        }
        .progress-bar {
            transition: width 1s ease-in-out !important;
        }



        .cursor-pointer { cursor: pointer; }

        .flag-box {
            background: rgba(255, 255, 255, 0.03);
            border: 2px solid rgba(255, 255, 255, 0.05);
            border-radius: 14px;
            padding: 15px 10px;
            text-align: center;
            width: 105px;
            height: 95px;
            transition: all 0.2s ease-in-out;
            color: #888;
            position: relative;
        }

        .flag-img {
            width: 45px;
            height: 30px;
            object-fit: cover;
            transition: transform 0.2s ease;
        }

        .flag-img-container { height: 40px; }

        .flag-box:hover {
            background: rgba(255, 255, 255, 0.08);
            border-color: rgba(255, 255, 255, 0.2);
            transform: translateY(-2px);
        }

        .flag-box.active {
            background: rgba(13, 110, 253, 0.15) !important;
            border-color: #0d6efd !important;
            color: #fff !important;
            box-shadow: 0 0 15px rgba(13, 110, 253, 0.4);
            transform: scale(1.05);
            z-index: 10;
        }

        .flag-box.active .flag-img {
            transform: scale(1.1);
            box-shadow: 0 4px 10px rgba(0,0,0,0.5);
        }

        .flag-box.active .flag-label {
            font-weight: bold;
            color: #fff;
        }

        .overflow-auto::-webkit-scrollbar { width: 4px; }
        .overflow-auto::-webkit-scrollbar-thumb {
            background: rgba(255, 255, 255, 0.1);
            border-radius: 10px;
        }


        @keyframes blink {
            0% { opacity: 1; transform: scale(1); }
            50% { opacity: 0.4; transform: scale(0.9); }
            100% { opacity: 1; transform: scale(1); }
        }

        .blink {
            animation: blink 2s infinite ease-in-out;
            display: inline-block;
        }

        .text-success-bright {
            color: #22c55e !important;
            text-shadow: 0 0 8px rgba(34, 197, 94, 0.4);
        }
    </style>



</head>
<body>

<nav class="sidebar">
    <a href="{{ route('dashboard') }}" class="sidebar-brand" wire:navigate>
        <i class="bi bi-shield-lock-fill"></i> SUB VPN SYSTEM
    </a>

    <ul class="nav-list">
        <li>
            <a href="{{ route('dashboard') }}" wire:navigate
               class="nav-item-link {{ request()->routeIs('dashboard') ? 'active' : '' }}">
                <i class="bi bi-cpu"></i> Анализ системы
            </a>
        </li>
        <li>
            <a href="{{ route('clients.index') }}" wire:navigate
               class="nav-item-link {{ request()->routeIs('clients.*') ? 'active' : '' }}">
                <i class="bi bi-people"></i> Клиенты
            </a>
        </li>
        <li>
            <a href="{{ route('setting-manager') }}" wire:navigate
               class="nav-item-link {{ request()->routeIs('setting-manager') ? 'active' : '' }}">
                <i class="bi bi-gear"></i> Настройки
            </a>
        </li>
        <li>
            <a href="{{ route('database-manager') }}" wire:navigate
               class="nav-item-link {{ request()->routeIs('database-manager') ? 'active' : '' }}">
                <i class="bi bi-database"></i> База данных
            </a>
        </li>
        <li>
            <a href="{{ route('nodes.index') }}" wire:navigate
               class="nav-item-link {{ request()->routeIs('nodes.*') ? 'active' : '' }}">
                <i class="bi bi-server"></i> Ноды
            </a>
        </li>
    </ul>


    <div class="sidebar-footer">
        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <button type="submit" class="logout-btn">
                <i class="bi bi-box-arrow-left me-2"></i> Выход
            </button>
        </form>
    </div>
</nav>

<main class="main-content">
    <div class="container-fluid">
        {{ $slot }}
    </div>
</main>

@livewireScripts
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
