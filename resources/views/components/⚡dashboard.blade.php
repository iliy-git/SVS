<?php

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Livewire\Component;

new class extends Component {

    public $cpuLoad = 0;
    public $memUsage = 0;
    public $diskUsage = 0;
    public $diskTotal = 0;
    public $diskUsed = 0;
    public $uptime = '';
    public $serverIp = '';


    private function getCpuUsage()
    {
        $data = file_get_contents('/proc/stat');
        $cpu = preg_split('/\s+/', trim(explode("\n", $data)[0]));

        $total = array_sum(array_slice($cpu, 1));
        $idle = $cpu[4];

        $prevTotal = cache()->get('cpu_total', 0);
        $prevIdle = cache()->get('cpu_idle', 0);

        cache()->put('cpu_total', $total, 60);
        cache()->put('cpu_idle', $idle, 60);

        $totalDiff = $total - $prevTotal;
        $idleDiff = $idle - $prevIdle;

        if ($totalDiff <= 0) return 0;

        return round(100 * (1 - ($idleDiff / $totalDiff)));
    }

    private function getMemoryUsage()
    {
        $data = file_get_contents('/proc/meminfo');

        preg_match('/MemTotal:\s+(\d+)/', $data, $total);
        preg_match('/MemAvailable:\s+(\d+)/', $data, $available);

        $total = (int)$total[1];
        $available = (int)$available[1];

        return round((($total - $available) / $total) * 100);
    }

    private function getDiskUsage()
    {
        $total = disk_total_space("/");
        $free = disk_free_space("/");

        return [
            'percent' => round(100 - ($free / $total * 100)),
            'total' => round($total / 1024 / 1024 / 1024, 1),
            'used' => round(($total - $free) / 1024 / 1024 / 1024, 1),
        ];
    }

    private function getUptime()
    {
        $seconds = (int)explode(' ', file_get_contents('/proc/uptime'))[0];

        return sprintf(
            "%dд %02d:%02d",
            floor($seconds / 86400),
            floor(($seconds % 86400) / 3600),
            floor(($seconds % 3600) / 60)
        );
    }

    private function getServerIp()
    {
        return Cache::remember('server_public_ip', 86400, function () {
            try {
                return trim(Http::get('https://icanhazip.com')->body());
            } catch (\Exception $e) {
                return request()->server('SERVER_ADDR') ?? '0.0.0.0';
            }
        });
    }

    public function mount()
    {
        $this->getStats();

        $this->serverIp = $this->getServerIp();

    }

    public function getStats()
    {
        $this->cpuLoad = $this->getCpuUsage();
        $this->memUsage = $this->getMemoryUsage();

        $disk = $this->getDiskUsage();
        $this->diskUsage = $disk['percent'];
        $this->diskTotal = $disk['total'];
        $this->diskUsed = $disk['used'];

        $this->uptime = $this->getUptime();

    }
};
?>

<div wire:poll.5s="getStats" class="animate__animated animate__fadeIn">

    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2 class="fw-bold text-white m-0">Системный анализ</h2>
        <span class="badge bg-success bg-opacity-10 text-success p-2 d-flex align-items-center"
              style="border: 1px solid rgba(34, 197, 94, 0.2);">
        <i class="bi bi-circle-fill me-2 small text-success-bright blink" style="font-size: 0.6rem;"></i>
        <span style="letter-spacing: 0.5px; font-weight: 600;">LIVE MONITORING</span>
    </span>
    </div>

    <div class="row g-4">

        <div class="col-md-4">
            <div class="card p-4 h-100">
                <div class="d-flex justify-content-between mb-3">
                    <div>
                        <div class="text-secondary small">CPU</div>
                        <h3 class="text-white">{{ $cpuLoad }}%</h3>
                    </div>
                    <i class="bi bi-cpu text-info fs-3"></i>
                </div>

                <div class="progress">
                    <div class="progress-bar bg-info"
                         style="width: {{ $cpuLoad }}%"></div>
                </div>
            </div>
        </div>

        <div class="col-md-4">
            <div class="card p-4 h-100">
                <div class="d-flex justify-content-between mb-3">
                    <div>
                        <div class="text-secondary small">RAM</div>
                        <h3 class="text-white">{{ $memUsage }}%</h3>
                    </div>
                    <i class="bi bi-memory text-primary fs-3"></i>
                </div>

                <div class="progress">
                    <div class="progress-bar"
                         style="width: {{ $memUsage }}%; background: var(--accent);"></div>
                </div>
            </div>
        </div>

        <div class="col-md-4">
            <div class="card p-4 h-100">
                <div class="d-flex justify-content-between mb-3">
                    <div>
                        <div class="text-secondary small">DISK</div>
                        <h3 class="text-white">
                            {{ $diskUsage }}%
                        </h3>
                        <small class="text-secondary">
                            {{ $diskUsed }} / {{ $diskTotal }} GB
                        </small>
                    </div>
                    <i class="bi bi-hdd text-warning fs-3"></i>
                </div>

                <div class="progress">
                    <div class="progress-bar bg-warning"
                         style="width: {{ $diskUsage }}%"></div>
                </div>
            </div>
        </div>

        <div class="col-12">
            <div class="card p-4 d-flex justify-content-between flex-row">
                <div>
                    <div class="text-secondary small">UPTIME</div>
                    <div class="text-white fs-5">{{ $uptime }}</div>
                </div>

                <div class="text-end">
                    <div class="text-secondary small uppercase fw-bold" style="letter-spacing: 1px;">IP</div>
                    <div class="text-info fs-6 fw-bold">{{ $serverIp }}</div>
                </div>
            </div>
        </div>

    </div>
</div>

